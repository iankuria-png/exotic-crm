<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentAttribution;
use App\Models\PaymentReceiptObservation;
use Illuminate\Support\Facades\Schema;

class PaymentRecoveryEvidencePolicy
{
    public function classify(Payment $p): array
    {
        if ($p->isVisitorPayment() || ! in_array($p->purpose, [null, 'subscription'], true) || $p->isSandboxTest() || $p->isClassifiedTest()) {
            return ['kind' => 'excluded', 'benefit' => null];
        }
        if ((float) $p->amount <= 0) {
            return ['kind' => 'noncash', 'benefit' => null];
        }
        if ($p->manual_payment_bundle_id && \App\Models\ManualPaymentBundle::whereKey($p->manual_payment_bundle_id)->whereIn('status', ['committing', 'compensation_failed', 'voided'])->exists()) {
            return ['kind' => 'excluded', 'benefit' => null];
        }
        if (in_array($p->resolution_code, ['reversed', 'invalid_reference'], true) || $p->status === 'reversed') {
            return ['kind' => 'exception', 'benefit' => null];
        }
        if (Schema::hasTable('payment_attributions') && PaymentAttribution::where('payment_id', $p->id)->where('attribution_state', 'disputed')->exists()) {
            return ['kind' => 'disputed', 'benefit' => null];
        }
        if (Schema::hasTable('payment_receipt_observations') && PaymentReceiptObservation::where('payment_id', $p->id)->count() > 1) {
            return ['kind' => 'settlement_review', 'benefit' => null];
        }
        $benefit = $this->benefit($p);
        if ($benefit) {
            return ['kind' => 'fulfilled', 'benefit' => $benefit];
        }
        $observation = Schema::hasTable('payment_receipt_observations') ? PaymentReceiptObservation::where('payment_id', $p->id)->first() : null;
        if ($observation) {
            $assessment = $observation->evidence_json['assessment'];

            return ['kind' => ($assessment['completion_policy'] ?? '') !== 'allow_completion' || ($assessment['review_required'] ?? true) || strtoupper((string) ($assessment['settled_currency'] ?? '')) !== strtoupper((string) $p->currency) ? 'settlement_review' : 'accepted_cash', 'benefit' => null];
        }
        if ($p->manualSubmission()->exists()) {
            return ['kind' => 'proof_pending', 'benefit' => null];
        }

        return ['kind' => (in_array($p->status, Payment::SUCCESSFUL_STATUSES, true) || (float) data_get($p->payment_data, 'settlement_assessment.settled_amount', 0) > 0) ? 'settlement_unverified' : 'not_settled', 'benefit' => null];
    }

    public function benefit(Payment $p): ?array
    {
        $deal = Deal::where('payment_id', $p->id)->where('platform_id', $p->platform_id)
            ->when($p->client_id, fn ($q) => $q->where('client_id', $p->client_id))->whereNotNull('activated_at')->first();
        if ($deal) {
            return ['deal_id' => $deal->id, 'activated_at' => $deal->activated_at, 'expires_at' => $deal->expires_at];
        }
        if (Schema::hasTable('payment_attributions')) {
            $snapshot = PaymentAttribution::find($p->id);
            $benefit = $snapshot?->evidence_json['benefit'] ?? null;
            if ($snapshot?->attribution_state === 'archived' && $benefit && (int) ($benefit['payment_id'] ?? 0) === (int) $p->id && ! empty($benefit['activated_at'])) {
                return $benefit;
            }
        }

        return null;
    }

    /** Only internal verified provider adapters call this; never accept this envelope from request input. */
    public function observe(Payment $p, array $assessment, array $authenticity): bool
    {
        if (! Schema::hasTable('payment_receipt_observations') || $p->isSandboxTest() || $p->isClassifiedTest()) {
            return true;
        }
        foreach (['account', 'environment', 'transaction_id', 'verification_method'] as $key) {
            if (empty($authenticity[$key])) {
                return true;
            }
        }
        if (($authenticity['environment'] ?? '') !== 'production' || (float) ($assessment['settled_amount'] ?? 0) <= 0 || empty($assessment['settled_currency'])) {
            return true;
        }
        $key = hash('sha256', json_encode([$p->provider_key, $authenticity['account'], $authenticity['environment'], $authenticity['transaction_id']]));
        $evidence = ['authenticity' => $authenticity, 'assessment' => $assessment, 'observed_at' => now()->toIso8601String()];
        $row = PaymentReceiptObservation::firstOrCreate(['receipt_key' => $key], ['payment_id' => $p->id, 'platform_id' => $p->platform_id, 'evidence_json' => $evidence]);
        if ((int) $row->payment_id !== (int) $p->id) {
            foreach ([$p, Payment::findOrFail($row->payment_id)] as $subject) {
                $attribution = PaymentAttribution::firstOrCreate(['payment_id' => $subject->id], ['platform_id' => $subject->platform_id, 'attribution_state' => 'unresolved', 'evidence_json' => []]);
                if ($attribution->attribution_state !== 'disputed') {
                    $attribution->update(['attribution_state' => 'disputed', 'version' => $attribution->version + 1]);
                    app(PaymentAttributionService::class)->audit($subject, 'payment_receipt_conflict', null, ['receipt_observation_id' => $row->id, 'other_payment_id' => $subject->id === $p->id ? $row->payment_id : $p->id], 'Provider receipt appears on two payment records; no duplicate activation.');
                }
                app(PaymentRecoveryCaseService::class)->synchronize($subject);
            }

            return false;
        }
        // The original authenticated observation is immutable. A different assessment needs explicit review.
        foreach (['settled_amount', 'settled_currency', 'completion_policy', 'review_required'] as $field) {
            if (($row->evidence_json['assessment'][$field] ?? null) != ($assessment[$field] ?? null)) {
                $attribution = PaymentAttribution::firstOrCreate(['payment_id' => $p->id], ['platform_id' => $p->platform_id, 'attribution_state' => 'unresolved', 'evidence_json' => []]);
                $attribution->update(['attribution_state' => 'disputed', 'version' => $attribution->version + 1]);
                app(PaymentAttributionService::class)->audit($p, 'payment_receipt_conflict', null, ['receipt_observation_id' => $row->id, 'field' => $field], 'Authenticated receipt evidence changed; preserve the original and require review.');
                app(PaymentRecoveryCaseService::class)->synchronize($p);

                return false;
            }
        }

        return true;
    }

    public function recordReviewedReceipt(Payment $p, int $auditId): void
    {
        $audit = \App\Models\AuditLog::whereKey($auditId)->where('platform_id', $p->platform_id)->where('entity_type', 'payment')->where('entity_id', $p->id)->where('action', 'payment_receipt_verified')->firstOrFail();
        $actor = $audit->actor;
        $e = $audit->after_state;
        if (! $actor || ! in_array($actor->role, ['admin', 'sub_admin'], true) || ! app(MarketAuthorizationService::class)->userCanAccessPlatform($actor, (int) $p->platform_id)
            || ! in_array($e['source'] ?? '', ['receiving_account_statement', 'reviewed_import'], true)
            || empty($e['account_digest']) || empty($e['transaction_id']) || empty($e['evidence_sha256'])
            || (float) ($e['amount'] ?? 0) !== (float) $p->amount || ($e['currency'] ?? '') !== $p->currency || (float) $p->amount <= 0) {
            throw new \InvalidArgumentException('Verified receiving-account evidence with exact amount and currency is required.');
        }
        if (! $this->observe($p, ['settled_amount' => $e['amount'], 'settled_currency' => $e['currency'], 'completion_policy' => 'allow_completion', 'review_required' => false],
            ['account' => $e['account_digest'], 'environment' => 'production', 'transaction_id' => $e['transaction_id'], 'verification_method' => $e['source'], 'audit_id' => $auditId, 'payload_sha256' => $e['evidence_sha256']])) {
            throw new \InvalidArgumentException('Receipt evidence conflicts with an existing observation.');
        }
    }

    public function canFulfill(Payment $p): bool
    {
        $purchase = data_get($p->payment_data, 'recovery_purchase');

        return $this->classify($p)['kind'] === 'accepted_cash'
            && in_array(data_get($purchase, 'beneficiary_assurance'), ['wp_session_verified', 'staff_authorized'], true)
            && (int) data_get($purchase, 'wp_user_id') === (int) $p->user_id
            && (int) data_get($purchase, 'platform_id') === (int) $p->platform_id
            && (int) data_get($purchase, 'duration_days') > 0 && (int) data_get($purchase, 'duration_days') <= 366
            && (int) data_get($purchase, 'product_id') === (int) $p->product_id
            && strtoupper((string) data_get($purchase, 'currency')) === strtoupper((string) $p->currency)
            && abs((float) data_get($purchase, 'amount') - (float) $p->amount) < 0.001;
    }
}
