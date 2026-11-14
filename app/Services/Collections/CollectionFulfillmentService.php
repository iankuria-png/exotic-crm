<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionBenefitEffect;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentFulfillmentOperation;
use App\Services\ClientLifecycleMutationLock;
use App\Services\DealPaymentService;
use App\Services\SubscriptionProvisioningService;
use App\Services\SubsidiaryTrialService;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** SMS allocations extend the existing per-payment fulfillment owner. No legacy add-days calls. */
class CollectionFulfillmentService
{
    public function resume(string $allocationId): CollectionAllocation
    {
        $a = CollectionAllocation::findOrFail($allocationId);

        return app(CollectionWriterGuard::class)->owned($a, function () use ($a) {
            $ids = array_filter([$a->purchase_snapshot['client_id'], data_get($a->purchase_snapshot, 'subsidiary.client_id')]);
            sort($ids, SORT_NUMERIC);

            return $this->withLocks($ids, function () use ($a) {
                try {
                    if (in_array($a->fresh()->state, ['corrected', 'correcting', 'unassigned', 'reviewed', 'active_review_pending'])) {
                        return $a->fresh();
                    }
                    $op = PaymentFulfillmentOperation::find($a->operation_id);
                    if (! $op) {
                        $op = $this->prepare($a);
                    }
                    $primary = CollectionBenefitEffect::findOrFail($op->id);
                    if ($primary->effect_kind === 'verification_only') {
                        $this->finalizeVerification($a, $op, $primary);
                    } else {
                        $this->dispatchEffect($a, $primary);
                    }
                    if ($primary->fresh()->state !== 'succeeded') {
                        return $a->fresh();
                    }
                    if (data_get($a->purchase_snapshot, 'subsidiary.client_id')) {
                        try {
                            $dependent = CollectionBenefitEffect::where('allocation_id', $a->id)->where('effect_kind', 'subsidiary_free')->whereNull('correction_id')->first();
                            if (! $dependent) {
                                $dependent = $this->prepareSubsidiary($a, $primary->fresh());
                            }
                            $this->dispatchEffect($a, $dependent);
                            if ($dependent->fresh()->state !== 'succeeded') {
                                $a->update(['state' => 'dependent_pending']);
                                $pendingReceipt = $a->receipt->fresh();
                                if (! $pendingReceipt->conflicts && in_array($pendingReceipt->funds_state, ['observed', 'statement_verified'])) {
                                    $pendingReceipt->update(['decision_state' => 'dependent_pending']);
                                }

                                return $a->fresh();
                            }
                        } catch (\Throwable $e) {
                            $a->update(['state' => 'dependent_pending']);
                            $pendingReceipt = $a->receipt->fresh();
                            if (! $pendingReceipt->conflicts && in_array($pendingReceipt->funds_state, ['observed', 'statement_verified'])) {
                                $pendingReceipt->update(['decision_state' => 'dependent_pending']);
                            }
                            app(CollectionPurchaseService::class)->audit($a->receipt, null, 'subsidiary_exposure_pending', ['allocation_id' => $a->id, 'reason' => $e instanceof CollectionException ? $e->reason : 'site_confirmation_required']);

                            return $a->fresh();
                        }
                    }
                    DB::transaction(function () use ($a) {
                        $a->update(['state' => 'active_review_pending']);
                        DB::table('collection_profile_holds')->where('allocation_id', $a->id)->delete();
                        $r = $a->receipt->fresh();
                        if (! $r->conflicts && ! in_array($r->funds_state, ['reversed', 'disputed'])) {
                            $r->update(['decision_state' => $r->reviewed_at ? 'reviewed' : 'active_review_pending']);
                        }
                    });
                } catch (\Throwable $e) {
                    $a->update(['state' => $e instanceof CollectionException ? 'needs_review' : 'uncertain']);
                    $a->receipt->update(['decision_state' => $a->state]);
                    app(CollectionPurchaseService::class)->audit($a->receipt, null, 'collection_fulfillment_held', ['allocation_id' => $a->id, 'reason' => $e instanceof CollectionException ? $e->reason : 'site_confirmation_required']);
                }

                return $a->fresh();
            });
        });
    }

    public function withLocks(array $clientIds, \Closure $callback): mixed
    {
        if (! $clientIds) {
            return $callback();
        }
        $id = array_shift($clientIds);

        return app(ClientLifecycleMutationLock::class)->run($id, fn () => $this->withLocks($clientIds, $callback));
    }

    private function prepare(CollectionAllocation $allocation): PaymentFulfillmentOperation
    {
        $r = $allocation->receipt->fresh();
        app(CollectionGate::class)->assertFunds($r);
        $p = $allocation->purchase_snapshot;
        $intent = CollectionPurchaseIntent::findOrFail($allocation->intent_id);
        if ((int) $intent->version !== (int) $allocation->intent_version || $intent->revoked_at) {
            throw new CollectionException('purchase_authority_changed');
        }
        app(CollectionPurchaseService::class)->validate($intent, $r, $allocation->decision_source === 'automatic', true);
        app(CollectionGate::class)->assertWrites($r->account, array_filter([$p['platform_id'], data_get($p, 'subsidiary.platform_id')]), $allocation->decision_source === 'automatic', false, $allocation->id);
        $remote = WpSyncService::forPlatform($p['platform_id'])->subscriptionOperation($p['wp_post_id']);
        if (! in_array($remote['version'] ?? 0, [1, 2], true)) {
            throw new CollectionException('exact_target_contract_required');
        }
        $before = $remote['snapshot'] ?? [];
        $policy = app(CollectionEntitlementPolicy::class);
        $policy->assertSnapshot($before, $p);
        $existingPayment = app(CollectionDecisionService::class)->existingPayment($r, $intent);
        $verificationOnly = $existingPayment?->deal?->activated_at !== null;
        if ($verificationOnly) {
            $d = $existingPayment->deal;
            if ((int) $d->client_id !== (int) $p['client_id'] || (int) $d->product_price_id !== (int) $p['product_price_id']
                || (int) $d->duration_days !== (int) $p['duration_days'] || $d->plan_type !== $p['tier'] || $d->status !== 'active'
                || $before['post_status'] !== 'publish' || $policy->tier($before) !== $p['tier']
                || $policy->expiry($before) !== $d->expires_at?->timestamp || (int) data_get($before, 'meta.crm_deal_id') !== (int) $d->id) {
                throw new CollectionException('existing_benefit_provenance_requires_review');
            }
            $target = ['expected_fingerprint' => $before['fingerprint'], 'target_expiry' => $d->expires_at->timestamp, 'product_type' => $p['tier'],
                'contribution' => ['start' => $d->activated_at->timestamp, 'end' => $d->expires_at->timestamp, 'tier' => $p['tier'], 'duration_days' => $p['duration_days']]];
        } else {
            $target = $policy->target($before, $p);
        }

        return DB::transaction(function () use ($allocation, $r, $p, $before, $target, $existingPayment, $intent, $verificationOnly) {
            CollectionAccount::whereKey($r->account_id)->lockForUpdate()->firstOrFail();
            $r = CollectionReceipt::lockForUpdate()->findOrFail($r->id);
            app(CollectionGate::class)->assertFunds($r);
            $a = CollectionAllocation::lockForUpdate()->findOrFail($allocation->id);
            if ($a->version !== $allocation->version || $a->purchase_snapshot !== $allocation->purchase_snapshot || $a->state === 'correcting') {
                throw new CollectionException('allocation_changed_before_dispatch');
            }
            if ($existing = PaymentFulfillmentOperation::find($a->operation_id)) {
                return $existing;
            }
            $client = Client::findOrFail($p['client_id']);
            $deal = $existingPayment?->deal ?: ($intent->deal_id ? Deal::findOrFail($intent->deal_id) : null);
            if (! $deal) {
                $deal = app(DealPaymentService::class)->createPendingDealFromCatalog($client, $p['product_id'], $p['product_price_id'], null, (int) $intent->approved_by, null);
                $deal->update(['amount' => $this->decimal($p['amount_minor']), 'currency' => $p['currency'], 'origin' => 'manual_collection', 'assigned_to' => $client->assigned_to ?: $intent->approved_by,
                    'original_amount' => $p['original_amount'], 'discount_percentage' => $p['discount_percentage'], 'discount_approved_by' => $p['discount_approved_by'], 'discount_source' => $p['discount_source']]);
            }
            $payment = $existingPayment;
            if (! $payment) {
                $payment = app(DealPaymentService::class)->createManualPaymentForDeal($deal, $client, $r->reference_norm, (int) $intent->approved_by,
                    ['amount' => $this->decimal($r->principal_minor), 'currency' => $r->currency, 'status' => 'pending', 'transaction_uuid' => 'collection_'.$a->id,
                        'confirmed_by' => null, 'reconciliation_state' => 'manual_review', 'raw_payload' => ['source' => 'manual_collection', 'collection_account_id' => $r->account_id, 'receipt_id' => $r->id]]);
                $payment->update(['user_id' => $p['wp_user_id'], 'escort_post_id' => $p['wp_post_id'], 'purpose' => 'subscription',
                    'source' => 'manual_confirmation', 'provider_key' => 'manual_confirmation', 'provider_environment' => 'production', 'record_classification' => 'live',
                    'completed_at' => null, 'confirmed_by' => null, 'confirmed_at' => null,
                    'payment_data' => array_merge($payment->payment_data ?? [], ['duration_days' => $p['duration_days'], 'product_price_id' => $p['product_price_id'],
                        'collection' => ['account_id' => $r->account_id, 'receipt_id' => $r->id, 'allocation_id' => $a->id, 'operation_id' => $a->operation_id]])]);
            }
            if (PaymentFulfillmentOperation::where('payment_id', $payment->id)->exists()) {
                throw new CollectionException('existing_fulfillment_owner_requires_review');
            }
            $deal->update(['payment_id' => $payment->id]);
            $a->update(['payment_id' => $payment->id]);
            $effect = CollectionBenefitEffect::create(['id' => $a->operation_id, 'parent_operation_id' => $a->operation_id, 'allocation_id' => $a->id,
                'effect_kind' => $verificationOnly ? 'verification_only' : 'primary_paid', 'platform_id' => $p['platform_id'], 'client_id' => $p['client_id'],
                'wp_post_id' => $p['wp_post_id'], 'wp_user_id' => $p['wp_user_id'], 'deal_id' => $deal->id, 'before_state' => $before,
                'contribution' => $target['contribution'], 'target_state' => array_diff_key($target, ['contribution' => true]) + ['crm_deal_id' => $deal->id],
                'state' => $verificationOnly ? 'wp_verified' : 'prepared', 'verified_state' => $verificationOnly ? $before : null]);

            return PaymentFulfillmentOperation::create(['id' => $a->operation_id, 'payment_id' => $payment->id, 'platform_id' => $p['platform_id'],
                'client_id' => $p['client_id'], 'wp_post_id' => $p['wp_post_id'], 'wp_user_id' => $p['wp_user_id'], 'deal_id' => $deal->id,
                'state' => $verificationOnly ? 'wp_verified' : 'prepared', 'purchase_snapshot' => $p, 'target_snapshot' => $effect->target_state,
                'result_json' => $verificationOnly ? ['verification_only' => true, 'snapshot' => $before] : null]);
        }, 3);
    }

    public function dispatchEffect(CollectionAllocation $allocation, CollectionBenefitEffect $effect): void
    {
        $effect->refresh();
        if ($effect->state === 'succeeded') {
            return;
        }
        $sync = WpSyncService::forPlatform($effect->platform_id);
        try {
            if ($effect->state !== 'wp_verified') {
                $read = $sync->subscriptionOperation($effect->wp_post_id, $effect->id);
                $result = $read['operation'] ?? null;
                if (! $result) {
                    if ($effect->state !== 'prepared') {
                        throw new CollectionException('operation_result_unavailable');
                    }
                    $r = $allocation->receipt->fresh();
                    app(CollectionGate::class)->assertFunds($r);
                    app(CollectionGate::class)->assertWrites($r->account, [$effect->platform_id], $effect->correction_id === null && $allocation->decision_source === 'automatic', $effect->correction_id !== null, $allocation->id);
                    DB::transaction(function () use ($effect, $allocation) {
                        $account = CollectionAccount::whereKey($allocation->receipt->account_id)->lockForUpdate()->firstOrFail();
                        $receipt = CollectionReceipt::lockForUpdate()->findOrFail($allocation->receipt_id);
                        $receipt->setRelation('account', $account);
                        $automatic = $effect->correction_id === null && $allocation->decision_source === 'automatic';
                        app(CollectionGate::class)->assertFunds($receipt);
                        app(CollectionGate::class)->assertWrites($account, [$effect->platform_id], $automatic, $effect->correction_id !== null, $allocation->id);
                        if (($allocation->purchase_snapshot['subsidiary_mode'] ?? null) === 'independent' && in_array($effect->effect_kind, ['subsidiary_free', 'target_subsidiary'])) {
                            $owner = \App\Models\Collections\CollectionSubsidiaryObligation::where('dependent_effect_id', $effect->id)->firstOrFail();
                            app(CollectionSubsidiaryFulfillmentService::class)->assertCurrent($owner);
                            $trial = \App\Models\FreeTrialRequest::lockForUpdate()->findOrFail($owner->trial_request_id);
                            app(\App\Services\Trials\TrialApprovalService::class)->assertDispatch($trial);
                        }
                        if ($automatic && $effect->effect_kind !== 'subsidiary_free') {
                            app(CollectionAutomationEpoch::class)->assertReceipt($receipt);
                        }
                        $locked = CollectionBenefitEffect::lockForUpdate()->findOrFail($effect->id);
                        if ($locked->state !== 'prepared') {
                            throw new CollectionException('effect_dispatch_already_claimed');
                        }
                        $locked->update(['state' => 'dispatching', 'attempts' => $locked->attempts + 1, 'dispatch_started_at' => now()]);
                        PaymentFulfillmentOperation::whereKey($effect->id)->update(['state' => 'dispatching', 'dispatch_started_at' => now()]);
                    });
                    $proof = $effect->correction_id && ! (($allocation->purchase_snapshot['subsidiary_mode'] ?? null) === 'independent' && $effect->effect_kind === 'target_subsidiary') ? app(CollectionCorrectionOwnership::class)->proof($effect->correction_id, $effect->platform_id, $effect->wp_post_id) : [];
                    $sync->applySubscriptionOperation($effect->wp_post_id, $effect->target_state + ['operation_id' => $effect->id, 'wp_user_id' => $effect->wp_user_id] + $proof);
                    $read = $sync->subscriptionOperation($effect->wp_post_id, $effect->id);
                    $result = $read['operation'] ?? null;
                }
                $this->assertResult($effect, $result, $read['snapshot'] ?? []);
                $effect->update(['state' => 'wp_verified', 'verified_state' => $read['snapshot'], 'verified_at' => now(), 'last_error_code' => null]);
            }
            DB::transaction(function () use ($effect, $allocation) {
                $effect = CollectionBenefitEffect::lockForUpdate()->findOrFail($effect->id);
                if ($effect->state === 'succeeded') {
                    return;
                }
                app(SubscriptionProvisioningService::class)->cacheVerifiedCollectionSnapshot($effect->client_id, $effect->verified_state);
                if ($effect->correction_id && ! (($allocation->purchase_snapshot['subsidiary_mode'] ?? null) === 'independent' && $effect->effect_kind === 'target_subsidiary')) {
                    $effect->update(['state' => 'succeeded']);

                    return;
                }
                app(SubscriptionProvisioningService::class)->recordVerifiedCollectionEffect($effect, $allocation);
                $effect->update(['state' => 'succeeded']);
                if ($effect->effect_kind === 'primary_paid' && ($allocation->purchase_snapshot['subsidiary_mode'] ?? null) === 'independent') {
                    app(CollectionSubsidiaryFulfillmentService::class)->ensure($allocation, $effect);
                    DB::table('collection_profile_holds')->where('allocation_id', $allocation->id)->where('client_id', $effect->client_id)->delete();
                }
                if ($effect->effect_kind === 'primary_paid') {
                    PaymentFulfillmentOperation::whereKey($effect->id)->update(['state' => 'succeeded', 'result_json' => json_encode(['snapshot' => $effect->verified_state, 'collection_effect_id' => $effect->id]), 'last_error_code' => null]);
                    $receipt = $allocation->receipt->fresh();
                    if (! $receipt->conflicts && in_array($receipt->funds_state, ['observed', 'statement_verified'])) {
                        $receipt->update(['decision_state' => 'active_review_pending']);
                    }
                }
            }, 3);
        } catch (\Throwable $e) {
            $effect->refresh();
            if (! in_array($effect->state, ['succeeded', 'wp_verified'])) {
                $effect->update(['state' => $effect->state === 'prepared' ? 'needs_review' : 'effect_unknown', 'last_error_code' => $e instanceof CollectionException ? $e->reason : 'site_confirmation_required']);
            }
            PaymentFulfillmentOperation::whereKey($effect->id)->where('state', '!=', 'succeeded')->update(['state' => 'effect_unknown', 'last_error_code' => 'site_confirmation_required']);
            if (! (($allocation->purchase_snapshot['subsidiary_mode'] ?? null) === 'independent' && in_array($effect->effect_kind, ['subsidiary_free', 'target_subsidiary']))) {
                $allocation->update(['state' => 'uncertain']);
                $allocation->receipt->update(['decision_state' => 'uncertain']);
            }
            throw $e;
        }
    }

    public function assertResult(CollectionBenefitEffect $effect, ?array $result, array $current): void
    {
        $policy = app(CollectionEntitlementPolicy::class);
        $policy->assertSnapshot($current, ['wp_post_id' => $effect->wp_post_id, 'wp_user_id' => $effect->wp_user_id]);
        if (($result['state'] ?? '') !== 'succeeded' || ($result['operation_id'] ?? '') !== $effect->id
            || (int) ($result['post_id'] ?? 0) !== (int) $effect->wp_post_id
            || (int) ($result['escort_expire'] ?? 0) !== (int) $effect->target_state['target_expiry']
            || ($result['product_type'] ?? '') !== $effect->target_state['product_type']
            || $policy->expiry($current) !== (int) $effect->target_state['target_expiry']
            || $policy->tier($current) !== $effect->target_state['product_type']
            || (int) data_get($current, 'meta.crm_deal_id') !== (int) $effect->target_state['crm_deal_id']
            || $current['post_status'] !== ($effect->target_state['post_status'] ?? 'publish')) {
            throw new CollectionException('wordpress_result_differs_from_pinned_effect');
        }
        if (isset($effect->target_state['restore_snapshot'])) {
            foreach ($effect->target_state['restore_snapshot']['meta'] as $key => $value) {
                if ((string) data_get($current, 'meta.'.$key, '') !== (string) $value) {
                    throw new CollectionException('compensated_entitlement_field_differs');
                }
            }
        }
    }

    private function finalizeVerification(CollectionAllocation $allocation, PaymentFulfillmentOperation $op, CollectionBenefitEffect $effect): void
    {
        DB::transaction(function () use ($allocation, $op, $effect) {
            $effect = CollectionBenefitEffect::lockForUpdate()->findOrFail($effect->id);
            if ($effect->state === 'succeeded') {
                return;
            }
            $payment = Payment::findOrFail($allocation->payment_id);
            app(SubscriptionProvisioningService::class)->cacheVerifiedCollectionSnapshot($effect->client_id, $effect->verified_state);
            $payment->update(['status' => 'completed', 'reconciliation_state' => 'manual_review', 'completed_at' => $payment->completed_at ?? now()]);
            $effect->update(['state' => 'succeeded', 'verified_at' => now()]);
            $op->update(['state' => 'succeeded']);
            app(CollectionPurchaseService::class)->audit($allocation->receipt, null, 'existing_benefit_verified_without_extension', ['allocation_id' => $allocation->id], 'verify:'.$allocation->id);
        });
    }

    private function prepareSubsidiary(CollectionAllocation $allocation, CollectionBenefitEffect $primary, ?\App\Models\Collections\CollectionCorrection $correction = null): CollectionBenefitEffect
    {
        $p = $correction ? $correction->preview['target_purchase'] : $allocation->purchase_snapshot;
        $identity = $p['subsidiary'];
        $client = Client::findOrFail($identity['client_id']);
        if ((int) $client->wp_post_id !== (int) $identity['wp_post_id'] || (int) $client->wp_user_id !== (int) $identity['wp_user_id']
            || $client->profile_paused_at || $client->profile_removal_requested_at || $client->closed_at || $client->profile_reverted_at || $client->is_high_risk) {
            throw new CollectionException('subsidiary_profile_changed');
        }
        $remote = WpSyncService::forPlatform($identity['platform_id'])->subscriptionOperation($identity['wp_post_id']);
        if (! in_array($remote['version'] ?? 0, [1, 2], true)) {
            throw new CollectionException('subsidiary_exact_target_contract_required');
        }
        $target = app(CollectionEntitlementPolicy::class)->target($remote['snapshot'] ?? [], $identity + ['tier' => $p['tier'], 'duration_days' => $p['duration_days']], $primary->contribution);
        $product = app(SubsidiaryTrialService::class)->collectionProduct($client->platform, Deal::findOrFail($primary->deal_id));
        if (! $product || $product->tier !== $p['tier']) {
            throw new CollectionException('subsidiary_matching_tier_required');
        }

        return DB::transaction(function () use ($allocation, $primary, $p, $remote, $target, $client, $product, $correction) {
            CollectionAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($existing = CollectionBenefitEffect::where('allocation_id', $allocation->id)->where('effect_kind', $correction ? 'target_subsidiary' : 'subsidiary_free')->where('correction_id', $correction?->id)->first()) {
                return $existing;
            }
            $deal = Deal::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'product_id' => $product->id, 'plan_type' => $p['tier'],
                'amount' => 0, 'currency' => $client->platform->currency_code, 'duration' => 'weekly', 'duration_days' => $p['duration_days'],
                'status' => 'pending', 'is_free_trial' => true, 'free_trial_approved_by' => 'collection_subsidiary_policy', 'origin' => 'manual_collection_subsidiary', 'linked_deal_id' => $primary->deal_id]);
            $payment = app(DealPaymentService::class)->createManualPaymentForDeal($deal, $client, 'COLLECTION-FREE-'.($correction?->id ?? $allocation->id), 0,
                ['amount' => 0, 'confirmed_by' => null, 'transaction_uuid' => 'collection_free_'.($correction?->id ?? $allocation->id), 'status' => 'pending', 'raw_payload' => ['source' => 'subsidiary_trial_from_deal', 'main_deal_id' => $primary->deal_id, 'allocation_id' => $allocation->id]]);
            $deal->update(['payment_id' => $payment->id]);

            return CollectionBenefitEffect::create(['id' => (string) Str::uuid(), 'parent_operation_id' => $primary->parent_operation_id, 'allocation_id' => $allocation->id,
                'correction_id' => $correction?->id, 'effect_kind' => $correction ? 'target_subsidiary' : 'subsidiary_free', 'platform_id' => $client->platform_id, 'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id,
                'wp_user_id' => $client->wp_user_id, 'deal_id' => $deal->id, 'before_state' => $remote['snapshot'], 'contribution' => $primary->contribution,
                'target_state' => array_diff_key($target, ['contribution' => true]) + ['crm_deal_id' => $deal->id]]);
        });
    }

    public function applyCorrectedSubsidiary(CollectionAllocation $a, \App\Models\Collections\CollectionCorrection $c, CollectionBenefitEffect $primary): void
    {
        $effect = CollectionBenefitEffect::where('correction_id', $c->id)->where('effect_kind', 'target_subsidiary')->first();
        $effect ??= $this->prepareSubsidiary($a, $primary, $c);
        $this->dispatchEffect($a, $effect);
    }

    public function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
