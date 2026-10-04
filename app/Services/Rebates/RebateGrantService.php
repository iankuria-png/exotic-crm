<?php

namespace App\Services\Rebates;

use App\Models\Client;
use App\Models\Payment;
use App\Models\RebateBudgetPeriod;
use App\Models\RebateProgram;
use App\Models\WalletRebate;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RebateGrantService
{
    public function __construct(private readonly RebateProgramService $programs, private readonly RebateChannelResolver $channels, private readonly RebateCalculator $calculator, private readonly WalletService $wallet) {}

    public function afterSettlement(Payment $p): void
    {
        DB::afterCommit(function () use ($p) {
            try {
                $this->grantFor($p->fresh() ?? $p);
            } catch (Throwable $e) {
                Log::error('Rebate callback could not start', ['payment_id' => $p->id, 'error' => $e->getMessage()]);
            }
        });
    }

    // This boundary never rethrows into the base payment's completion path.
    public function grantFor(Payment $payment): ?WalletRebate
    {
        $rowData = null;
        try {
            if (! Schema::hasTable('rebate_programs')) {
                return null;
            }
            $channel = $this->channels->resolve($payment);
            if (! $channel || ! $payment->client_id) {
                return null;
            }

            return DB::transaction(function () use ($payment, $channel, &$rowData) {
                // Serialize all grants and reversals in one market, then lock the client.
                // This also makes first-period creation and monthly caps race-safe.
                $s = RebateProgram::where('platform_id', $payment->platform_id)->lockForUpdate()->first();
                if (! $s || ! $s->published_revision) {
                    return null;
                }
                $client = Client::where('platform_id', $payment->platform_id)->lockForUpdate()->findOrFail($payment->client_id);
                $trigger = $channel === 'topup' ? 'topup' : ($channel === 'auto_renew' ? 'auto_renew' : 'self_service');
                $key = "rebate:{$trigger}:{$payment->id}";
                $existing = WalletRebate::where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing && $existing->status !== 'failed') {
                    return $existing;
                }
                $snapshot = $existing ? data_get($existing->metadata, 'snapshot') : $this->programs->snapshot($s);
                if (! $snapshot) {
                    return $existing;
                }
                $period = $existing?->period_key ?? now()->timezone($client->platform->timezone ?: 'UTC')->format('Y-m');
                $rowData = ['platform_id' => $payment->platform_id, 'client_id' => $client->id, 'rebate_program_id' => $s->id, 'program_revision' => $existing?->program_revision ?? $s->published_revision,
                    'trigger' => $trigger, 'channel' => $channel, 'source_payment_id' => $payment->id, 'base_amount' => $payment->amount, 'currency' => $s->currency, 'period_key' => $period, 'idempotency_key' => $key,
                    'status' => 'failed', 'metadata' => ['snapshot' => $snapshot]];
                $reason = $this->programs->eligibility($s, $client, $snapshot);
                if (strtoupper((string) $payment->currency) !== $s->currency) {
                    $reason = 'currency_mismatch';
                }
                // A failed decision remains retryable while paused; do not lose the failure.
                if ($existing && in_array($reason, ['paused', 'not_active'], true)) {
                    return $existing;
                }
                $budget = RebateBudgetPeriod::firstOrCreate(['platform_id' => $s->platform_id, 'period_key' => $period], ['budget_amount' => data_get($snapshot, 'guard.budget'), 'issued_amount' => 0]);
                $budget = RebateBudgetPeriod::whereKey($budget->id)->lockForUpdate()->firstOrFail();
                $earned = WalletRebate::where('client_id', $client->id)->where('period_key', $period)->whereIn('status', ['credited', 'capped', 'reversed'])->sum('amount');
                // Eligibility belongs to the first completed companion top-up, even if it is below the bonus minimum.
                $first = $existing ? (bool) data_get($existing->metadata, 'first_topup', false) : $this->programs->firstTopupEligible($client, $payment);
                $quote = $this->calculator->quote($snapshot, ['channel' => $channel, 'amount' => $payment->amount, 'first_topup' => $first, 'earned_this_month' => $earned, 'budget_remaining' => max(0, (float) $budget->budget_amount - (float) $budget->issued_amount)]);
                $sandbox = $this->channels->sandbox($payment) || $s->rollout_mode === 'sandbox';
                // Production payments cannot enter a sandbox program, even for its test audience.
                if ($s->rollout_mode === 'sandbox' && ! $this->channels->sandbox($payment)) {
                    $reason = 'environment_mismatch';
                }
                $reason = $reason ?? $quote['reason'];
                $status = $reason ? 'skipped' : ($sandbox ? 'simulated' : ($quote['capped_by'] ? 'capped' : 'credited'));
                $rowData = array_merge($rowData, ['rate_percent' => $quote['rate'], 'calculated_amount' => $quote['calculated'], 'amount' => $reason ? 0 : $quote['total'], 'status' => $status, 'reason' => $reason ?? ($sandbox ? 'sandbox' : $quote['capped_by']),
                    'metadata' => ['snapshot' => $snapshot, 'quote' => $quote, 'first_topup' => $first, 'is_sandbox' => $sandbox]]);
                $row = $existing ?? new WalletRebate;
                $row->fill($rowData)->save();
                if (in_array($status, ['credited', 'capped'], true) && $quote['total'] > 0) {
                    // Passing payment_id, rather than payment, preserves the base transaction pointer.
                    $credit = $this->wallet->credit($client, $s->currency, $quote['total'], ['payment_id' => $payment->id, 'reference_type' => 'wallet_rebate', 'reference_id' => $row->id,
                        'idempotency_key' => 'wallet-rebate:'.$row->id, 'description' => "Wallet rebate · {$channel}",
                        'metadata' => ['channel' => $channel, 'rate_percent' => $quote['rate'], 'bonus' => $quote['bonus'], 'program_revision' => $row->program_revision, 'rebate_id' => $row->id]]);
                    $row->update(['wallet_transaction_id' => $credit['transaction']->id]);
                    $budget->update(['issued_amount' => (float) $budget->issued_amount + $quote['total']]);
                }

                return $row->fresh();
            }, 3);
        } catch (Throwable $e) {
            // Wallet+ledger+budget roll back together. Persist a retry record outside that transaction.
            try {
                if ($rowData) {
                    $row = WalletRebate::firstOrNew(['idempotency_key' => $rowData['idempotency_key']]);
                    if (! $row->exists || $row->status === 'failed') {
                        $row->fill(array_merge($rowData, ['status' => 'failed', 'amount' => 0, 'reason' => 'grant_failed', 'wallet_transaction_id' => null,
                            'metadata' => array_merge($rowData['metadata'], ['error' => mb_substr($e->getMessage(), 0, 500)])]))->save();
                    }
                }
                app(\App\Services\ErrorLogRecorder::class)->record('error', $e, 'Wallet rebate failed', ['payment_id' => $payment->id], 'wallet_rebate');
            } catch (Throwable $loggingError) {
                Log::error('Rebate failure could not be recorded', ['payment_id' => $payment->id, 'error' => $loggingError->getMessage()]);
            }

            return null;
        }
    }

    public function reverse(WalletRebate $rebate, string $reason, int $actor): WalletRebate
    {
        $this->programs->reason($reason);

        return DB::transaction(function () use ($rebate, $reason, $actor) {
            RebateProgram::whereKey($rebate->rebate_program_id)->lockForUpdate()->firstOrFail();
            $c = Client::whereKey($rebate->client_id)->lockForUpdate()->firstOrFail();
            $r = WalletRebate::whereKey($rebate->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($r->status, ['credited', 'capped'], true), 409, 'Only a credited rebate can be reversed, once.');
            $debitAmount = min((float) $r->amount, $this->wallet->balanceFor($c, $r->currency));
            $tx = $debitAmount > 0 ? $this->wallet->debit($c, $r->currency, $debitAmount, ['reference_type' => 'wallet_rebate_reversal', 'reference_id' => $r->id, 'idempotency_key' => 'wallet-rebate-reversal:'.$r->id, 'description' => 'Wallet rebate reversal', 'performed_by' => $actor, 'metadata' => ['reason' => $reason, 'rebate_id' => $r->id]])['transaction'] : null;
            $r->update(['status' => 'reversed', 'reversal_transaction_id' => $tx?->id, 'reversal_shortfall' => (float) $r->amount - $debitAmount, 'metadata' => array_merge($r->metadata ?? [], ['reversal' => ['reason' => $reason, 'actor_id' => $actor, 'at' => now()->toIso8601String(), 'recovered' => $debitAmount]])]);

            // Issuance caps/budget are deliberately not replenished by reversals.
            return $r->fresh();
        }, 3);
    }
}
