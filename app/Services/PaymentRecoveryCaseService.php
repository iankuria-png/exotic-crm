<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRecoveryCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentRecoveryCaseService
{
    public function synchronize(Payment $p): ?PaymentRecoveryCase
    {
        if (! Schema::hasTable('payment_recovery_cases') || ! $p->platform_id) {
            return null;
        }
        $evidence = app(PaymentRecoveryEvidencePolicy::class)->classify($p);
        $kind = $evidence['kind'];
        if ($kind === 'fulfilled' && data_get($p->payment_data, 'canonical_state.provisioning_status') && data_get($p->payment_data, 'canonical_state.provisioning_status') !== 'completed') {
            DB::transaction(function () use ($p, $evidence) {
                $locked = Payment::whereKey($p->id)->lockForUpdate()->firstOrFail();
                $data = $locked->payment_data ?? [];
                $before = ['provisioning_status' => data_get($data, 'canonical_state.provisioning_status')];
                data_set($data, 'canonical_state.provisioning_status', 'completed');
                data_set($data, 'canonical_state.transition', 'existing_benefit_reconciled');
                Payment::withoutEvents(fn () => $locked->update(['payment_data' => $data]));
                app(PaymentAttributionService::class)->audit($locked, 'payment_service_state_reconciled', $before, ['provisioning_status' => 'completed', 'benefit' => $evidence['benefit']], 'Existing recorded benefit verified; no new service or cash change.');
                $p->payment_data = $data;
                $p->syncOriginalAttribute('payment_data');
            });
        }
        $existing = PaymentRecoveryCase::where('payment_id', $p->id)->first();
        if (in_array($kind, ['excluded', 'noncash', 'not_settled'], true)) {
            if ($existing && $kind === 'excluded' && $existing->state !== 'closed') {
                DB::transaction(function () use ($existing, $p) {
                    $case = PaymentRecoveryCase::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                    $before = $case->toArray();
                    $case->update(['state' => 'closed', 'disposition' => 'excluded', 'closed_at' => now(), 'next_retry_at' => null, 'version' => $case->version + 1]);
                    app(PaymentAttributionService::class)->audit($p, 'payment_recovery_excluded', $before, ['case_id' => $case->id], 'Test or non-business receipt excluded from recovery; original evidence retained.');
                });
            }

            return $existing;
        }
        if ($kind === 'fulfilled' && ! $existing) {
            return null;
        }

        return DB::transaction(function () use ($p, $evidence, $kind) {
            $case = PaymentRecoveryCase::firstOrCreate(['payment_id' => $p->id], [
                'public_reference' => (string) Str::uuid(), 'platform_id' => $p->platform_id,
                'state' => 'open', 'reason_code' => $kind, 'queue_key' => 'payments:market:'.$p->platform_id,
                'opened_at' => $p->completed_at ?? now(), 'next_retry_at' => now(),
            ]);
            $case = PaymentRecoveryCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->state === 'closed' && ! in_array($kind, ['disputed', 'exception'], true)) {
                return $case;
            } // Explicit audited reopen required.
            $state = match ($kind) {
                'fulfilled' => 'closed', 'exception','disputed','settlement_review','settlement_unverified','proof_pending' => 'settlement_review',
                default => $p->client_id ? 'awaiting_fulfillment' : 'awaiting_identity',
            };
            if ($case->state === 'effect_unknown' && $kind !== 'fulfilled') {
                $state = 'effect_unknown';
            }
            if ($state !== $case->state || $case->reason_code !== $kind) {
                $before = $case->only(['state', 'reason_code', 'version', 'disposition', 'evidence_json', 'closed_at']);
                $updates = ['state' => $state, 'reason_code' => $kind, 'version' => $case->version + 1];
                if ($state !== 'closed' && $case->closed_at) {
                    $updates += ['closed_at' => null, 'disposition' => null, 'next_retry_at' => now()];
                }
                if ($kind === 'fulfilled') {
                    $updates += ['disposition' => 'fulfilled', 'evidence_json' => $evidence['benefit'], 'closed_at' => now(), 'next_retry_at' => null];
                }
                $case->update($updates);
                app(PaymentAttributionService::class)->audit($p, 'payment_recovery_transition', $before, $case->only(['state', 'reason_code', 'version']), 'Recovery visibility is independent of financial reporting.');
            }
            if ($state !== 'closed' && $case->opened_at->lte(now()->subMinutes(5)) && ! $case->escalated_at) {
                $case->update(['escalated_at' => now(), 'version' => $case->version + 1]);
                app(PaymentAttributionService::class)->audit($p, 'payment_recovery_escalated', null, ['case_id' => $case->id, 'queue_key' => $case->queue_key], 'Paid receipt needs support attention.');
            }
            if ($state !== 'closed' && $case->opened_at->lte(now()->subMinutes(15)) && ! $case->alerted_at) {
                $case->update(['alerted_at' => now(), 'version' => $case->version + 1]);
                app(PaymentAttributionService::class)->audit($p, 'payment_recovery_alerted', null, ['case_id' => $case->id, 'queue_key' => $case->queue_key], 'Paid receipt overdue for support resolution.');
            }
            if (in_array($state, ['awaiting_identity', 'awaiting_fulfillment'], true)) {
                \App\Jobs\ResumePaidPaymentService::dispatch((int) $p->id);
            }

            return $case;
        });
    }
}
