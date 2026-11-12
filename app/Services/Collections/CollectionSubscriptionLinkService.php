<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionBenefitEffect;
use App\Models\Collections\CollectionCorrection;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentFulfillmentOperation;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\ClientLifecycleMutationLock;
use App\Services\SubscriptionActivationAuditService;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reconcile an existing manual subscription. This service never writes to WordPress. */
class CollectionSubscriptionLinkService
{
    public function preview(CollectionReceipt $receipt, User $actor, int $paymentId, int $version, int $allocationVersion): CollectionCorrection
    {
        $receipt = app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        $allocation = $receipt->allocation;
        if (! $allocation || $receipt->version !== $version || $allocation->version !== $allocationVersion) {
            throw new CollectionException('subscription_link_preview_stale');
        }
        $preview = ['can_execute' => false, 'reasons' => [], 'receipt_version' => $version, 'payment_id' => $paymentId];
        try {
            $facts = app(CollectionWriterGuard::class)->owned($allocation, fn () => $this->inspect($receipt, $paymentId, $actor));
            $preview += $this->describe($receipt, $facts);
            $preview['can_execute'] = true;
        } catch (CollectionException $exception) {
            $preview['reasons'][] = $exception->reason;
        }

        return CollectionCorrection::create(['id' => (string) Str::uuid(), 'allocation_id' => $allocation->id,
            'allocation_version' => $allocationVersion, 'action' => 'link_existing', 'actor_id' => $actor->id,
            'reason' => 'Staff reviewed an existing subscription against the original received payment code.',
            'preview' => $preview, 'expires_at' => now()->addMinutes(10)]);
    }

    public function execute(CollectionCorrection $link, User $actor, int $version, string $key): CollectionCorrection
    {
        if ($link->action !== 'link_existing') {
            throw new CollectionException('subscription_link_action_required');
        }
        $allocation = CollectionAllocation::findOrFail($link->allocation_id);
        $receipt = app(CollectionAccessService::class)->receipt($actor, $allocation->receipt_id);
        if ($link->request_key) {
            return $this->completed($link, $key);
        }
        if ($link->version !== $version || $link->expires_at->isPast() || ! $link->preview['can_execute']) {
            throw new CollectionException('subscription_link_preview_stale');
        }
        $client = Client::findOrFail($link->preview['client']['id']);
        app(CollectionPurchaseService::class)->assertScope($receipt->account, $client, $actor);

        return app(CollectionWriterGuard::class)->owned($allocation, fn () => app(ClientLifecycleMutationLock::class)->run($client, function () use ($allocation, $receipt, $link, $actor, $version, $key) {
            // Native reads occur outside the short financial transaction; no website POST is made.
            $sync = WpSyncService::forPlatform($link->preview['client']['platform_id']);
            $native = ['subscription' => $sync->subscriptionOperation($link->preview['client']['wp_post_id']),
                'owner' => $sync->subscriptionCorrectionOwner($link->preview['client']['wp_post_id'])];

            return DB::transaction(function () use ($allocation, $receipt, $link, $actor, $version, $key, $native) {
                CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
                $r = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
                $a = CollectionAllocation::lockForUpdate()->findOrFail($allocation->id);
                $c = CollectionCorrection::lockForUpdate()->findOrFail($link->id);
                if ($c->request_key) {
                    return $this->completed($c, $key);
                }
                if ($c->version !== $version || $c->expires_at->isPast() || $r->version !== $c->preview['receipt_version'] || $a->version !== $c->allocation_version) {
                    throw new CollectionException('subscription_link_preview_stale');
                }
                Payment::whereIn('id', [$c->preview['original_payment_id'], $c->preview['payment_id']])->orderBy('id')->lockForUpdate()->get();
                Deal::whereKey($c->preview['deal']['id'])->lockForUpdate()->firstOrFail();
                Client::whereKey($c->preview['client']['id'])->lockForUpdate()->firstOrFail();
                $facts = $this->inspect($r, $c->preview['payment_id'], $actor, $native);
                $current = $this->describe($r, $facts);
                if (app(CollectionSalesService::class)->digest($current['pins']) !== app(CollectionSalesService::class)->digest($c->preview['pins'])) {
                    throw new CollectionException('subscription_link_preview_stale');
                }
                ['original' => $original, 'payment' => $payment, 'deal' => $deal, 'client' => $client, 'snapshot' => $snapshot] = $facts;
                $summary = ['id' => $c->id, 'payment_id' => $payment->id, 'original_payment_id' => $original->id,
                    'allocation_id' => $a->id, 'receipt_id' => $r->id, 'original_reference' => $r->reference_norm,
                    'temporary_reference' => $current['temporary_reference'], 'previous_references' => $current['previous_references'],
                    'client' => $current['client'], 'deal' => $current['deal'], 'amount_minor' => $r->principal_minor, 'currency' => $r->currency,
                    'activation' => $current['activation'], 'linked_by' => ['id' => $actor->id, 'name' => $actor->name], 'linked_at' => now()->toIso8601String()];
                $original->update(['status' => 'cancelled', 'reconciliation_state' => 'resolved',
                    'resolution_code' => Payment::RESOLUTION_RECEIPT_DUPLICATE,
                    'resolution_meta_json' => array_merge($original->resolution_meta_json ?? [], ['receipt_link' => $summary]),
                    'payment_data' => array_merge($original->payment_data ?? [], ['duplicate_receipt_link' => $summary])]);
                $operationId = (string) Str::uuid();
                $purchase = ['client_id' => $client->id, 'platform_id' => $client->platform_id, 'wp_post_id' => $client->wp_post_id,
                    'wp_user_id' => $client->wp_user_id, 'product_id' => $deal->product_id, 'product_price_id' => $deal->product_price_id,
                    'tier' => $deal->plan_type, 'duration_days' => $deal->duration_days, 'amount_minor' => $r->principal_minor,
                    'currency' => $r->currency, 'subsidiary' => null, 'receipt_link' => $summary];
                $a->update(['payment_id' => $payment->id, 'operation_id' => $operationId, 'purchase_snapshot' => $purchase,
                    'decision_source' => 'staff', 'state' => 'reviewed', 'version' => $a->version + 1]);
                PaymentFulfillmentOperation::create(['id' => $operationId, 'payment_id' => $payment->id, 'platform_id' => $client->platform_id,
                    'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'deal_id' => $deal->id,
                    'state' => 'succeeded', 'purchase_snapshot' => $purchase, 'target_snapshot' => $snapshot,
                    'result_json' => ['verification_only' => true, 'receipt_link_id' => $c->id, 'snapshot' => $snapshot]]);
                CollectionBenefitEffect::create(['id' => $operationId, 'parent_operation_id' => $operationId, 'allocation_id' => $a->id,
                    'effect_kind' => 'verification_only', 'platform_id' => $client->platform_id, 'client_id' => $client->id,
                    'wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'deal_id' => $deal->id,
                    'before_state' => $snapshot, 'target_state' => $snapshot, 'verified_state' => $snapshot,
                    'contribution' => ['start' => $deal->activated_at->timestamp, 'end' => $deal->expires_at->timestamp, 'tier' => $deal->plan_type, 'duration_days' => $deal->duration_days],
                    'state' => 'succeeded', 'verified_at' => now()]);
                $payment->update(['transaction_reference' => $r->reference_norm, 'reference_number' => $r->reference_norm,
                    'reference_root' => $r->reference_norm, 'reconciliation_state' => 'resolved',
                    'user_id' => $client->wp_user_id, 'escort_post_id' => $client->wp_post_id,
                    'payment_data' => array_merge($payment->payment_data ?? [], ['receipt_link' => $summary,
                        'collection' => ['account_id' => $r->account_id, 'receipt_id' => $r->id, 'allocation_id' => $a->id, 'operation_id' => $operationId]])]);
                $r->update(['decision_state' => 'reviewed', 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'version' => $r->version + 1]);
                app(CollectionExposureService::class)->release($r, 'review');
                app(CollectionPurchaseService::class)->audit($r, $actor->id, 'existing_subscription_linked', [
                    'correction_id' => $c->id, 'client_id' => $client->id, 'receipt_link' => $summary,
                    'before' => ['original' => $c->preview['original_payment'], 'selected' => $c->preview['selected_payment']],
                    'cash_received_unchanged' => true, 'service_changed' => false,
                ], 'subscription-link:'.$c->id);
                $c->update(['state' => 'verified', 'request_key' => $key, 'preview' => array_merge($c->preview, ['receipt_link' => $summary])]);

                return $c->fresh();
            }, 3);
        }));
    }

    private function completed(CollectionCorrection $link, string $key): CollectionCorrection
    {
        if ($link->request_key !== $key || $link->state !== 'verified') {
            throw new CollectionException('subscription_link_request_conflict');
        }

        return $link;
    }

    private function inspect(CollectionReceipt $receipt, int $paymentId, User $actor, ?array $native = null): array
    {
        app(CollectionGate::class)->assertFunds($receipt);
        $a = $receipt->allocation;
        if (! $a || $a->state !== 'unassigned' || $receipt->sale()->exists()
            || $a->effects()->whereNull('superseded_at')->whereIn('effect_kind', ['primary_paid', 'target_paid', 'subsidiary_free', 'target_subsidiary', 'verification_only'])->exists()
            || CollectionCorrection::where('allocation_id', $a->id)->whereNotNull('request_key')->whereNotIn('state', ['verified', 'canceled'])->exists()
            || DB::table('collection_site_correction_holds')->whereIn('correction_id', CollectionCorrection::where('allocation_id', $a->id)->select('id'))->where('state', '!=', 'released')->exists()) {
            throw new CollectionException('finish_saved_removal_before_linking');
        }
        $related = app(CollectionReceiptStatusService::class)->relatedPayments($receipt);
        if (! $related['complete'] || count($related['matches']) !== 1 || (int) $related['matches'][0]['payment_id'] !== $paymentId) {
            throw new CollectionException('subscription_link_candidates_require_review');
        }
        $original = Payment::findOrFail($a->payment_id);
        $payment = Payment::with(['deal', 'client'])->where('platform_id', $a->purchase_snapshot['platform_id'])->findOrFail($paymentId);
        $deal = $payment->deal;
        $client = $payment->client;
        if (! $client) {
            throw new CollectionException('subscription_link_existing_service_required');
        }
        app(CollectionPurchaseService::class)->assertScope($receipt->account, $client, $actor);
        app(CollectionWriterGuard::class)->assertClient($client->id);
        if ($original->client_id || $original->deal_id || $original->user_id || $original->escort_post_id
            || (int) data_get($original->payment_data, 'collection.receipt_id') !== $receipt->id
            || data_get($original->payment_data, 'collection.account_id') !== $receipt->account_id
            || $original->platform_id !== $payment->platform_id || $original->manualSubmission()->exists()) {
            throw new CollectionException('subscription_link_original_payment_requires_review');
        }
        foreach ([$original, $payment] as $subject) {
            if (! in_array($subject->status, Payment::SUCCESSFUL_STATUSES, true) || $subject->isClassifiedTest() || $subject->isSandboxTest()
                || ! in_array($subject->purpose, [null, 'subscription'], true) || $subject->source === 'wallet' || $subject->wallet_transaction_id
                || $subject->manual_payment_bundle_id || $subject->reference_sequence || $subject->resolution_code
                || ! in_array($subject->provider_key, [null, 'manual_confirmation'], true)
                || app(TzMpesaParser::class)->minor((string) $subject->amount) !== $receipt->principal_minor || $subject->currency !== $receipt->currency
                || DB::table('payment_receipt_observations')->where('payment_id', $subject->id)->exists()
                || DB::table('billing_provider_transactions')->where('payment_id', $subject->id)->exists()
                || DB::table('payment_attributions')->where('payment_id', $subject->id)->where('attribution_state', 'disputed')->exists()
                || DB::table('payment_recovery_cases')->where('payment_id', $subject->id)->where('state', '!=', 'closed')->exists()) {
                throw new CollectionException('subscription_link_cash_requires_review');
            }
        }
        if (! $deal || $deal->status !== 'active' || $deal->origin !== 'manual' || $deal->is_free_trial || ! $payment->confirmed_by
            || ! $deal->activated_at || ! $deal->expires_at || $deal->expires_at->isPast() || (int) $deal->payment_id !== $payment->id
            || (int) $deal->client_id !== $client->id || (int) $deal->platform_id !== $payment->platform_id
            || ! in_array($deal->plan_type, ['basic', 'premium', 'vip'], true) || (int) $deal->duration_days < 1
            || app(TzMpesaParser::class)->minor((string) $deal->amount) !== $receipt->principal_minor || $deal->currency !== $receipt->currency
            || ($payment->user_id && (int) $payment->user_id !== (int) $client->wp_user_id)
            || ($payment->escort_post_id && (int) $payment->escort_post_id !== (int) $client->wp_post_id)
            || $client->profile_paused_at || $client->profile_removal_requested_at || $client->profile_reverted_at || $client->closed_at || $client->is_high_risk
            || CollectionAllocation::where('payment_id', $payment->id)->exists() || PaymentFulfillmentOperation::where('payment_id', $payment->id)->exists()
            || DB::table('collection_sales_items')->where('payment_id', $payment->id)->exists()
            || (data_get($payment->payment_data, 'collection.account_id') && data_get($payment->payment_data, 'collection.account_id') !== $receipt->account_id)) {
            throw new CollectionException('subscription_link_existing_service_required');
        }
        if ($payment->manualSubmission && $payment->manualSubmission->review_decision !== 'approved') {
            throw new CollectionException('subscription_link_cash_requires_review');
        }
        $product = Product::find($deal->product_id);
        $price = ProductPrice::find($deal->product_price_id);
        if (! $product || ! $price || (int) $payment->product_id !== $product->id || (int) $product->platform_id !== $client->platform_id
            || (int) $price->product_id !== $product->id || $price->currency !== $receipt->currency) {
            throw new CollectionException('subscription_link_existing_service_required');
        }
        if (! $native) {
            $sync = WpSyncService::forPlatform($client->platform_id);
            $native = ['subscription' => $sync->subscriptionOperation($client->wp_post_id), 'owner' => $sync->subscriptionCorrectionOwner($client->wp_post_id)];
        }
        $snapshot = $native['subscription']['snapshot'] ?? [];
        $policy = app(CollectionEntitlementPolicy::class);
        $policy->assertSnapshot($snapshot, ['wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id]);
        if ($snapshot['post_status'] !== 'publish' || $policy->tier($snapshot) !== $deal->plan_type || $policy->expiry($snapshot) !== $deal->expires_at->timestamp
            || (int) data_get($snapshot, 'meta.crm_deal_id') !== $deal->id
            || data_get($snapshot, 'meta.needs_payment') === '1' || data_get($snapshot, 'meta.notactive') === '1'
            || in_array(data_get($snapshot, 'meta.crm_lifecycle_state'), ['archived', 'removed', 'paused', 'closed', 'reverted'], true)
            || (data_get($native, 'owner.owner.state') && data_get($native, 'owner.owner.state') !== 'released')) {
            throw new CollectionException('subscription_link_site_requires_review');
        }

        return compact('original', 'payment', 'deal', 'client', 'snapshot');
    }

    private function describe(CollectionReceipt $receipt, array $facts): array
    {
        ['original' => $original, 'payment' => $payment, 'deal' => $deal, 'client' => $client, 'snapshot' => $snapshot] = $facts;
        $digest = fn (array $values) => app(CollectionSalesService::class)->digest($values);
        $fields = ['id', 'status', 'amount', 'currency', 'client_id', 'deal_id', 'user_id', 'escort_post_id', 'product_id', 'confirmed_by', 'completed_at', 'transaction_reference', 'reference_number', 'reference_root', 'reconciliation_state', 'resolution_code'];

        return ['original_payment_id' => $original->id, 'original_reference' => $receipt->reference_norm,
            'temporary_reference' => $payment->transaction_reference ?: $payment->reference_root,
            'previous_references' => $payment->only(['transaction_reference', 'reference_number', 'reference_root']),
            'original_payment' => $original->only($fields), 'selected_payment' => $payment->only($fields),
            'amount_minor' => $receipt->principal_minor, 'currency' => $receipt->currency,
            'client' => $client->only(['id', 'name', 'platform_id', 'wp_post_id', 'wp_user_id']),
            'deal' => ['id' => $deal->id, 'tier' => $deal->plan_type, 'duration_days' => $deal->duration_days,
                'activated_at' => $deal->activated_at->toIso8601String(), 'expires_at' => $deal->expires_at->toIso8601String()],
            'activation' => array_intersect_key(app(SubscriptionActivationAuditService::class)->forPayment($payment), array_flip(['state', 'actor', 'activated_at', 'source'])),
            'pins' => ['original' => $digest($original->attributesToArray()), 'payment' => $digest($payment->attributesToArray()),
                'deal' => $digest($deal->attributesToArray()), 'client' => $digest($client->only(['id', 'platform_id', 'wp_post_id', 'wp_user_id', 'profile_paused_at', 'profile_removal_requested_at', 'profile_reverted_at', 'closed_at', 'is_high_risk'])),
                'native' => $snapshot['fingerprint']]];
    }
}
