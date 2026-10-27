<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentFulfillmentOperation;
use App\Models\TimelineEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentFulfillmentRecoveryService
{
    public function resume(Payment $payment): ?PaymentFulfillmentOperation
    {
        if (! in_array((int) $payment->platform_id, config('payment_recovery.automatic_platforms', []), true)
            || ! in_array((int) $payment->platform_id, config('payment_recovery.verified_operation_platforms', []), true)) {
            return null;
        }
        $identity = app(PaymentClientIdentityService::class)->resolve($payment);
        if (! $identity['matched']) {
            return null;
        }
        $client = $identity['client'];

        return app(ClientLifecycleMutationLock::class)->run($client, function () use ($payment, $client) {
            $payment = $payment->fresh();
            $client = $client->fresh();
            $existing = PaymentFulfillmentOperation::where('payment_id', $payment->id)->first();
            if ($existing?->state === 'succeeded') {
                return $existing;
            }
            if (! $this->eligibleClient($client) || ! app(PaymentRecoveryEvidencePolicy::class)->canFulfill($payment)) {
                return $existing;
            }
            if (class_exists(WalletLifecycleGuard::class)) {
                app(WalletLifecycleGuard::class)->assertDispatch($client, null, $payment);
            }
            $purchase = data_get($payment->payment_data, 'recovery_purchase');
            if ((int) ($purchase['wp_post_id'] ?? 0) !== (int) $client->wp_post_id || (int) ($purchase['wp_user_id'] ?? 0) !== (int) $client->wp_user_id) {
                return $existing;
            }
            // Any later/compensating benefit is held for an operator, never guessed from amount.
            if (! $existing && Deal::where('client_id', $client->id)->whereNotNull('activated_at')->where('activated_at', '>=', $payment->created_at)->exists()) {
                return null;
            }
            $sync = WpSyncService::forPlatform((int) $payment->platform_id);
            if (! $existing) {
                $remote = $sync->subscriptionOperation((int) $client->wp_post_id);
                if (($remote['version'] ?? 0) !== 1 || empty($remote['snapshot']['fingerprint'])) {
                    return null;
                }
                $snapshot = $remote['snapshot'];
                if ((int) $snapshot['wp_user_id'] !== (int) $client->wp_user_id) {
                    return null;
                }
                $type = $purchase['tier'] ?? '';
                if (! in_array($type, ['basic', 'premium', 'vip'], true)) {
                    return null;
                }
                $current = max((int) ($snapshot['meta']['escort_expire'] ?? 0), (int) ($snapshot['meta']['agency_expire'] ?? 0));
                $expiry = max(now()->timestamp, $current) + (int) $purchase['duration_days'] * 86400;
                $existing = DB::transaction(function () use ($payment, $client, $purchase, $snapshot, $expiry, $type) {
                    $locked = Payment::lockForUpdate()->findOrFail($payment->id);
                    $op = PaymentFulfillmentOperation::where('payment_id', $payment->id)->first();
                    if ($op) {
                        return $op;
                    }
                    $deal = Deal::create(['platform_id' => $payment->platform_id, 'client_id' => $client->id, 'payment_id' => $payment->id,
                        'product_id' => $payment->product_id, 'product_price_id' => $purchase['product_price_id'] ?? null, 'plan_type' => $type,
                        'amount' => $payment->amount, 'currency' => $payment->currency, 'duration' => $payment->duration,
                        'duration_days' => $purchase['duration_days'], 'status' => 'pending', 'assigned_to' => $client->assigned_to]);
                    $op = PaymentFulfillmentOperation::create(['id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'platform_id' => $payment->platform_id,
                        'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id,
                        'purchase_snapshot' => $purchase, 'target_snapshot' => ['expected_fingerprint' => $snapshot['fingerprint'], 'target_expiry' => $expiry, 'product_type' => $type, 'crm_deal_id' => $deal->id],
                        'deal_id' => $deal->id, 'state' => 'prepared', 'next_retry_at' => now()]);
                    app(PaymentAttributionService::class)->audit($payment, 'payment_recovery_prepared', null, ['operation_id' => $op->id, 'deal_id' => $deal->id], 'Pinned beneficiary and full purchased duration; no external effect yet.');

                    return $op;
                });
            }
            // Lookup is mandatory after a lost response. Dispatch state survives a CRM rollback/crash.
            try {
                $remote = $sync->subscriptionOperation((int) $client->wp_post_id, $existing->id);
                $result = $remote['operation'] ?? null;
                if (! $result && $existing->state === 'prepared') {
                    $existing->update(['state' => 'dispatching', 'dispatch_started_at' => now(), 'attempts' => $existing->attempts + 1]);
                    $result = $sync->applySubscriptionOperation((int) $client->wp_post_id, $existing->target_snapshot + ['operation_id' => $existing->id, 'wp_user_id' => $existing->wp_user_id]);
                } elseif (! $result) {
                    // A known not-applied result may be manually retried using the same immutable operation.
                    $existing->update(['state' => 'effect_unknown', 'last_error_code' => 'operation_result_unavailable', 'next_retry_at' => now()->addMinutes(5)]);
                    $this->markUnknown($payment);

                    return $existing;
                }
                if (($result['state'] ?? '') !== 'succeeded' || ($result['operation_id'] ?? '') !== $existing->id || (int) ($result['post_id'] ?? 0) !== (int) $existing->wp_post_id || (int) ($result['escort_expire'] ?? 0) !== (int) $existing->target_snapshot['target_expiry'] || (! empty($result['product_type']) && $result['product_type'] !== $existing->target_snapshot['product_type'])) {
                    throw new \RuntimeException('Operation result does not match pinned effect.');
                }

                return $this->finalize($payment, $client, $existing, $result);
            } catch (\Throwable $e) {
                if ($existing->fresh()?->state === 'succeeded') {
                    report($e);

                    return $existing->fresh();
                }
                $existing->update(['state' => 'effect_unknown', 'last_error_code' => 'site_confirmation_required', 'next_retry_at' => now()->addMinutes(5)]);
                $this->markUnknown($payment);

                return $existing;
            }
        });
    }

    private function eligibleClient(Client $c): bool
    {
        return ! $c->profile_removal_requested_at && ! $c->profile_paused_at && ! $c->closed_at && ! $c->profile_reverted_at && ! $c->is_high_risk
            && ! in_array($c->lifecycle_state, ['archived', 'removed'], true) && (int) $c->wp_post_id > 0;
    }

    private function markUnknown(Payment $p): void
    {
        $case = app(PaymentRecoveryCaseService::class)->synchronize($p->fresh());
        if ($case && $case->state !== 'closed') {
            $case->update(['state' => 'effect_unknown', 'reason_code' => 'site_confirmation_required', 'next_retry_at' => now()->addMinutes(5), 'version' => $case->version + 1]);
        }
    }

    private function finalize(Payment $payment, Client $client, PaymentFulfillmentOperation $op, array $result): PaymentFulfillmentOperation
    {
        return DB::transaction(function () use ($payment, $client, $op, $result) {
            $op = PaymentFulfillmentOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($op->state === 'succeeded') {
                return $op;
            }
            $deal = Deal::lockForUpdate()->findOrFail($op->deal_id);
            $at = \Illuminate\Support\Carbon::parse($result['applied_at']);
            $expiry = \Illuminate\Support\Carbon::createFromTimestamp((int) $result['escort_expire']);
            $deal->update(['status' => 'active', 'activated_at' => $at, 'expires_at' => $expiry, 'is_free_trial' => false]);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $data = $payment->payment_data ?? [];
            data_set($data, 'canonical_state.provisioning_status', 'completed');
            data_set($data, 'canonical_state.transition', 'subscription_recovery_verified');
            $payment->update(['client_id' => $client->id, 'deal_id' => $deal->id, 'start_date' => $at, 'end_date' => $expiry,
                'payment_data' => $data, 'reconciliation_state' => 'resolved', 'reconciliation_confidence' => 'high']);
            $op->update(['state' => 'succeeded', 'result_json' => $result, 'next_retry_at' => null, 'last_error_code' => null]);
            TimelineEvent::create(['platform_id' => $payment->platform_id, 'entity_type' => 'payment', 'entity_id' => $payment->id, 'event_type' => 'payment_service_recovered', 'content' => ['deal_id' => $deal->id, 'operation_id' => $op->id, 'expires_at' => $expiry->toIso8601String()], 'created_at' => now()]);
            app(PaymentAttributionService::class)->audit($payment, 'payment_service_recovered', null, ['deal_id' => $deal->id, 'operation_id' => $op->id, 'expires_at' => $expiry->toIso8601String()], 'Verified exact WordPress operation; full purchased duration from recovery.');
            app(PaymentRecoveryCaseService::class)->synchronize($payment->fresh());
            \App\Jobs\CompleteRecoveredPaymentBenefits::dispatch((int) $deal->id)->afterCommit();

            return $op;
        });
    }
}
