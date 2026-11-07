<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use App\Models\Collections\CollectionSalesItem;
use App\Models\Collections\CollectionSalesSession;
use App\Models\Deal;
use App\Models\ManualPaymentBundle;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientLifecycleMutationLock;
use App\Services\DealPaymentService;
use App\Services\SubscriptionProvisioningService;
use App\Services\WalletLifecycleGuard;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A receipt owns cash once; immutable per-child operations own purchased coverage. */
class CollectionSalesFulfillmentService
{
    private function assertWallet(Client $client, ?Payment $payment = null): void
    {
        if (class_exists(WalletLifecycleGuard::class)) {
            app(WalletLifecycleGuard::class)->assertDispatch($client, null, $payment, true);

            return;
        }
        // The separately developed wallet workflow may be installed before its service is shipped.
        if (Schema::hasTable('crm_wallet_operations') && DB::table('crm_wallet_operations')->where('client_id', $client->id)
            ->where('platform_id', $client->platform_id)->whereIn('kind', ['subscription', 'reservation'])
            ->whereIn('status', ['initiating', 'pending', 'unknown', 'provisioning', 'needs_review'])->exists()) {
            throw new CollectionException('existing_wallet_operation_requires_review');
        }
        $pending = Payment::where('platform_id', $client->platform_id)
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhere(fn ($q) => $q->whereNull('client_id')->where('user_id', $client->wp_user_id)))
            ->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', 'subscription'))
            ->whereIn('status', ['initiated', 'pending', 'awaiting_payment'])
            ->when($payment, fn ($q) => $q->whereKeyNot($payment->id));
        if ($pending->exists()) {
            throw new CollectionException('existing_subscription_payment_requires_review');
        }
    }

    private function claimTransaction(\Closure $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return DB::transaction($callback);
        }
        $before = DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS row_timeout, @@SESSION.lock_wait_timeout AS metadata_timeout');
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
            DB::statement('SET SESSION lock_wait_timeout = 2');

            return DB::transaction($callback);
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $before->row_timeout);
            DB::statement('SET SESSION lock_wait_timeout = '.(int) $before->metadata_timeout);
        }
    }

    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function lockReceipt(CollectionSalesItem $item): void
    {
        $sale = $item->session;
        CollectionAccount::whereKey($sale->account_id)->lockForUpdate()->firstOrFail();
        CollectionReceipt::whereKey($sale->receipt_id)->lockForUpdate()->firstOrFail();
    }

    private function assertItemTerms(CollectionSalesItem $item, User $actor): void
    {
        $p = $item->purchase;
        $receipt = $item->session->receipt;
        if ($receipt->principal_minor !== $item->session->principal_minor || $receipt->currency !== $item->session->currency) {
            throw new CollectionException('prepared_receipt_principal_changed');
        }
        $client = app(CollectionPurchaseService::class)->selectableClients()->findOrFail($item->client_id);
        $intent = app(CollectionPurchaseService::class)->prepare($receipt->account, $client, $actor,
            ['product_price_id' => $p['product_price_id'], 'deal_id' => $item->deal_id, 'valid_until' => now()->addMinutes(5)], false);
        if (app(CollectionSalesService::class)->digest($intent->purchase_snapshot) !== app(CollectionSalesService::class)->digest($p)) {
            throw new CollectionException('prepared_purchase_terms_changed');
        }
        $evidence = clone $receipt;
        $evidence->principal_minor = $item->amount_minor;
        app(CollectionPurchaseService::class)->validate($intent, $evidence, false);
        if ($item->expected_site['target']['target_expiry'] <= now()->timestamp) {
            throw new CollectionException('prepared_service_interval_elapsed');
        }
    }

    private function key(CollectionReceipt $r, User $actor, array $input): string
    {
        return 'sale-activate:'.$r->id.':'.$actor->id.':'.$input['request_key'];
    }

    private function replay(CollectionReceipt $r, User $actor, string $key, string $digest): ?array
    {
        app(CollectionAccessService::class)->receipt($actor, $r->id);
        $prior = DB::table('collection_audit')->where('dedupe_key', $key)->first();
        if (! $prior) {
            return null;
        }
        $context = json_decode($prior->context, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($context['digest'], $digest)) {
            throw new CollectionException('sale_request_key_reused');
        }
        app(CollectionSalesService::class)->present(CollectionSalesSession::findOrFail($context['response']['session']['id']), $actor);

        return $context['response'] + ['replayed' => true];
    }

    public function prepare(CollectionReceipt $receipt, User $actor, array $input): array
    {
        $sales = app(CollectionSalesService::class);
        $key = $this->key($receipt, $actor, $input);
        $digest = $sales->inputDigest($input);
        if ($prior = $this->replay($receipt, $actor, $key, $digest)) {
            return $prior;
        }
        try {
            $preview = $sales->preview($receipt, $actor, $input);
            try {
                $token = json_decode(Crypt::decryptString($input['preview_token'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new CollectionException('refresh_activation_preview');
            }
            $projection = $preview;
            unset($projection['preview_token']);
            if (($token['actor'] ?? null) !== $actor->id || ($token['receipt'] ?? null) !== $receipt->id
                || ($token['expires'] ?? 0) <= now()->timestamp || ! hash_equals($token['digest'] ?? '', $sales->digest($projection))) {
                throw new CollectionException('activation_preview_changed');
            }
            if (! $preview['can_activate']) {
                throw new CollectionException($preview['blocked_reasons'][0], 503);
            }
            $ids = array_column($preview['items'], 'client_id');

            return app(ClientLifecycleMutationLock::class)->runBounded($ids, function ($remaining) use ($receipt, $actor, $input, $preview, $key, $digest, $sales) {
                $snapshots = [];
                foreach ($preview['items'] as $row) {
                    $p = $row['purchase'];
                    $client = Client::findOrFail($p['client_id']);
                    $this->assertWallet($client);
                    $read = WpSyncService::forPlatform($p['platform_id'])->withReadBudget(fn () => min(8, $remaining()))->subscriptionOperation($p['wp_post_id']);
                    if (($read['version'] ?? 0) !== 1) {
                        throw new CollectionException('exact_target_contract_required');
                    }
                    $before = $read['snapshot'] ?? [];
                    $policy = app(CollectionEntitlementPolicy::class);
                    if (($before['post_status'] ?? '') === 'publish' && $policy->expiry($before) <= 0) {
                        throw new CollectionException('indefinite_coverage_requires_review');
                    }
                    $target = $policy->target($before, $p);
                    $snapshots[$p['client_id']] = ['before' => $before, 'target' => $target];
                }
                $remaining();

                // No lease acquisition or network call below this boundary.
                return $this->claimTransaction(function () use ($receipt, $actor, $input, $preview, $key, $digest, $sales, $snapshots, $remaining) {
                    $remaining();
                    CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
                    $r = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
                    if ($prior = $this->replay($r, $actor, $key, $digest)) {
                        return $prior;
                    }
                    app(CollectionWriterGuard::class)->assertReceipt($r->id);
                    $fresh = $sales->preview($r, $actor, $input);
                    unset($fresh['preview_token']);
                    $original = $preview;
                    unset($original['preview_token']);
                    if ($sales->digest($fresh) !== $sales->digest($original)) {
                        throw new CollectionException('activation_preview_changed');
                    }
                    $refs = array_merge([$r->reference_norm], DB::table('collection_receipt_aliases')->where('receipt_id', $r->id)->where('account_id', $r->account_id)->pluck('reference')->all());
                    $cash = Payment::where('platform_id', $preview['platform_id'])->where(function ($q) use ($refs) {
                        $q->whereIn('reference_root', $refs)->orWhereIn('transaction_reference', $refs)->orWhereIn('reference_number', $refs);
                    })->lockForUpdate()->first();
                    if ($cash || ManualPaymentBundle::where('platform_id', $preview['platform_id'])->whereIn('reference_root', $refs)->lockForUpdate()->exists()) {
                        throw new CollectionException('existing_cash_requires_receipt_attachment');
                    }
                    $session = CollectionSalesSession::where('receipt_id', $r->id)->lockForUpdate()->first();
                    if (! $session) {
                        $session = new CollectionSalesSession(['id' => (string) Str::uuid(), 'receipt_id' => $r->id, 'account_id' => $r->account_id,
                            'platform_id' => $preview['platform_id'], 'actor_id' => $actor->id, 'currency' => $r->currency, 'principal_minor' => $r->principal_minor]);
                    } else {
                        $session->items()->delete(); // Only a pure draft can reach this point.
                        $session->version++;
                    }
                    $session->fill(['state' => 'prepared', 'allocated_minor' => $preview['allocated_minor'], 'remainder_minor' => $preview['remainder_minor'],
                        'remainder_reason' => $preview['remainder_reason'], 'receipt_version' => $r->version, 'request_key' => $input['request_key'], 'payload_hash' => $digest])->save();
                    $bundle = ManualPaymentBundle::create(['platform_id' => $session->platform_id, 'reference_root' => $r->reference_norm,
                        'total_amount' => $this->decimal($r->principal_minor), 'allocated_amount' => $this->decimal($preview['allocated_minor']),
                        'unallocated_amount' => $this->decimal($preview['remainder_minor']), 'currency' => $r->currency,
                        'reason' => $preview['remainder_reason'] ?: 'Reviewed incoming office payment', 'status' => 'receipt_processing',
                        'audit_state' => $preview['remainder_minor'] ? 'needs_finance_resolution' : 'pending_finance_review',
                        'idempotency_key' => $session->id, 'created_by' => $actor->id]);
                    $session->update(['bundle_id' => $bundle->id]);
                    foreach ($preview['items'] as $row) {
                        $remaining();
                        $p = $row['purchase'];
                        $client = Client::findOrFail($row['client_id']);
                        app(CollectionWriterGuard::class)->assertClient($client->id);
                        $deal = $row['deal_id'] ? Deal::lockForUpdate()->findOrFail($row['deal_id']) : app(DealPaymentService::class)->createPendingDealFromCatalog($client, $p['product_id'], $p['product_price_id'], null, $actor->id, null);
                        if ($deal->payment_id || $deal->activated_at || $deal->status !== 'pending') {
                            throw new CollectionException('existing_quote_payment_requires_receipt_review');
                        }
                        $deal->update(app(\App\Services\SubscriptionLifecycleService::class)->toPersistenceAttributes($row['lifecycle']) + ['amount' => $this->decimal($p['amount_minor']), 'origin' => 'receipt_manual_sale',
                            'discount_source' => $p['discount_source'], 'discount_approved_by' => $p['discount_approved_by'],
                            'discount_percentage' => $p['discount_percentage'], 'original_amount' => $p['original_amount']]);
                        $item = CollectionSalesItem::create(['id' => (string) Str::uuid(), 'session_id' => $session->id, 'position' => $row['position'],
                            'client_id' => $client->id, 'deal_id' => $deal->id, 'operation_key' => (string) Str::uuid(), 'state' => 'prepared',
                            'amount_minor' => $p['amount_minor'], 'purchase' => $p, 'expected_site' => $snapshots[$client->id],
                            'subsidiary' => $row['subsidiary_trial'] ? app(CollectionSalesSubsidiaryAdapter::class)->validate($r, $actor, $p, $input['items'][$row['position'] - 1]['subsidiary_trial']) : null]);
                        if (! empty($row['price_amended'])) {
                            app(CollectionPurchaseService::class)->audit($r, $actor->id, 'receipt_discount_approved',
                                ['item_id' => $item->id, 'deal_id' => $deal->id, 'saved_intent_id' => $row['saved_intent_id'],
                                    'catalogue_minor' => $p['catalogue_minor'], 'payable_minor' => $p['amount_minor'],
                                    'discount_percentage' => $p['discount_percentage'], 'approved_by' => $actor->id], 'sale-discount:'.$item->id);
                        }
                        if ($item->subsidiary) {
                            app(CollectionSalesSubsidiaryAdapter::class)->attach($deal, $actor, $item->subsidiary, $input['items'][$row['position'] - 1]['subsidiary_trial']);
                        }
                        if ($row['replaces_saved_benefit']) {
                            app(CollectionPurchaseService::class)->audit($r, $actor->id, 'receipt_benefit_terms_amended',
                                ['saved_intent_id' => $row['saved_intent_id'], 'item_id' => $item->id, 'new_terms' => $row['subsidiary_trial']], 'sale-benefit-amendment:'.$item->id);
                        }
                        DB::table('collection_sales_profile_holds')->insert(['client_id' => $client->id, 'session_id' => $session->id,
                            'item_id' => $item->id, 'operation_key' => $item->operation_key, 'created_at' => now()]);
                        app(CollectionWriterGuard::class)->ownedSale($item, function () use ($item, $deal, $client, $r, $bundle, $actor, $row, $p) {
                            $reference = $r->reference_norm.'-'.$row['position'];
                            $payment = app(DealPaymentService::class)->createManualPaymentForDeal($deal, $client, $reference, $actor->id,
                                ['amount' => $this->decimal($p['amount_minor']), 'currency' => $r->currency, 'manual_payment_bundle_id' => $bundle->id,
                                    'reference_root' => $r->reference_norm, 'reference_sequence' => $row['position'], 'transaction_uuid' => 'receipt_sale_'.$item->id,
                                    'status' => 'pending', 'confirmed_by' => null, 'reconciliation_state' => 'manual_review',
                                    'raw_payload' => ['source' => 'receipt_manual_sale', 'receipt_id' => $r->id, 'collection_account_id' => $r->account_id]]);
                            $payment->update(['phone' => $r->payer_phone, 'user_id' => $p['wp_user_id'], 'escort_post_id' => $p['wp_post_id'],
                                'purpose' => 'subscription', 'source' => 'manual_confirmation', 'provider_key' => 'manual_confirmation',
                                'provider_environment' => 'production', 'record_classification' => 'live', 'confirmed_at' => null, 'completed_at' => null,
                                'payment_data' => ['collection' => ['account_id' => $r->account_id, 'receipt_id' => $r->id, 'sales_item_id' => $item->id],
                                    'duration_days' => $p['duration_days'], 'product_price_id' => $p['product_price_id']]]);
                            $item->update(['payment_id' => $payment->id]);
                            $deal->update(['payment_id' => $payment->id, 'payment_reference' => $reference]);
                        });
                        app(CollectionIngestService::class)->outbox('sales_dispatch', $item->id, 'sales-dispatch:'.$item->id.':1');
                    }
                    // This receipt's one-time reviewed choices cannot remain available for a later payer match.
                    $authorities = \App\Models\Collections\CollectionPurchaseIntent::where('account_id', $r->account_id)
                        ->where('provider_reference', $r->reference_norm)->where('authority', 'reviewed_quote')->whereNull('revoked_at')->lockForUpdate()->get();
                    foreach ($authorities as $authority) {
                        if ($authority->used_count > 0) {
                            throw new CollectionException('saved_purchase_authority_already_consumed');
                        }
                        $authority->update(['used_count' => $authority->used_count + 1, 'revoked_at' => now(), 'version' => $authority->version + 1]);
                        app(CollectionPurchaseService::class)->audit($r, $actor->id, 'receipt_purchase_authority_consumed',
                            ['intent_id' => $authority->id, 'session_id' => $session->id], 'sale-authority:'.$authority->id.':'.$session->id);
                    }
                    $r->update(['decision_state' => 'sales_activating', 'version' => $r->version + 1]);
                    $session->update(['receipt_version' => $r->version]);
                    $response = ['session' => $sales->present($session->fresh(), $actor), 'receipt_version' => $r->version];
                    app(CollectionPurchaseService::class)->audit($r, $actor->id, 'receipt_sale_prepared', ['digest' => $digest, 'response' => $response], $key);
                    $remaining();

                    return $response;
                });
            });
        } catch (\Throwable $e) {
            // A concurrent identical request may commit while this one waits for the real profile lease.
            if ($prior = $this->replay($receipt, $actor, $key, $digest)) {
                return $prior;
            }
            throw $e;
        }
    }

    public function retryUndispatched(CollectionSalesItem $item, User $actor, array $input): array
    {
        $sales = app(CollectionSalesService::class);
        $sales->present($item->session, $actor);
        $key = 'sale-retry:'.$item->id.':'.$actor->id.':'.$input['request_key'];
        $digest = $sales->digest($input);
        $existing = DB::table('collection_audit')->where('dedupe_key', $key)->first();
        if ($existing) {
            $saved = json_decode($existing->context, true, 512, JSON_THROW_ON_ERROR);
            if (! hash_equals($saved['digest'], $digest)) {
                throw new CollectionException('sale_request_key_reused');
            }

            return $saved['response'];
        }

        return app(CollectionWriterGuard::class)->ownedSale($item, fn () => app(ClientLifecycleMutationLock::class)->runBounded([$item->client_id], function ($remaining) use ($item, $actor, $input, $key, $digest, $sales) {
            $item->refresh();
            if ($item->dispatch_started_at || $item->state !== 'prepared') {
                throw new CollectionException('only_never_dispatched_sale_can_retry');
            }
            $p = $item->purchase;
            $this->assertItemTerms($item, $actor);
            $read = WpSyncService::forPlatform($p['platform_id'])->withReadBudget(fn () => min(6, $remaining()))->subscriptionOperation($p['wp_post_id'], $item->operation_key);
            if (($read['version'] ?? 0) !== 1 || ! empty($read['operation']) || ($read['snapshot']['fingerprint'] ?? '') !== $item->expected_site['target']['expected_fingerprint']
                || $item->expected_site['target']['target_expiry'] <= now()->timestamp) {
                throw new CollectionException('prepared_site_state_requires_review');
            }

            return $this->claimTransaction(function () use ($item, $actor, $input, $key, $digest, $sales, $remaining) {
                $remaining();
                $this->lockReceipt($item);
                $session = CollectionSalesSession::lockForUpdate()->findOrFail($item->session_id);
                $row = CollectionSalesItem::lockForUpdate()->findOrFail($item->id);
                if ($session->version !== $input['session_version'] || $row->version !== $input['item_version']) {
                    throw new CollectionException('sale_version_changed');
                }
                if ($row->dispatch_started_at || $row->state !== 'prepared' || $session->active_item_id
                    || $session->items()->where('state', '!=', 'confirmed')->first()?->id !== $row->id) {
                    throw new CollectionException('sale_not_eligible_for_retry');
                }
                $sales->present($session, $actor);
                app(CollectionGate::class)->assertFunds($session->receipt);
                if (! in_array($session->account_id, config('collection_sales.verified_accounts', []), true)
                    || ! in_array((int) $session->platform_id, config('collection_sales.verified_platforms', []), true)) {
                    throw new CollectionException('manual_activation_connection_required', 503);
                }
                $row->update(['version' => $row->version + 1, 'last_error_code' => null]);
                $session->update(['version' => $session->version + 1]);
                app(CollectionIngestService::class)->outbox('sales_dispatch', $row->id, 'sales-retry:'.$row->id.':'.$row->version);
                $response = ['session' => $sales->present($session->fresh(), $actor)];
                app(CollectionPurchaseService::class)->audit($session->receipt, $actor->id, 'receipt_sale_retry_authorized',
                    ['digest' => $digest, 'reason' => $input['reason'], 'response' => $response], $key);
                $remaining();

                return $response;
            });
        }));
    }

    public function resume(string $itemId, bool $readOnly = false): CollectionSalesItem
    {
        $item = CollectionSalesItem::findOrFail($itemId);
        if ($item->state === 'confirmed') {
            return $item;
        }

        return app(CollectionWriterGuard::class)->ownedSale($item, fn () => app(ClientLifecycleMutationLock::class)->runBounded([$item->client_id], function ($remaining) use ($item, $readOnly) {
            $dispatch = $this->claimTransaction(function () use ($item, $readOnly, $remaining) {
                $remaining();
                $this->lockReceipt($item);
                $session = CollectionSalesSession::lockForUpdate()->findOrFail($item->session_id);
                $row = CollectionSalesItem::lockForUpdate()->findOrFail($item->id);
                if ($row->state === 'confirmed') {
                    return false;
                }
                $first = $session->items()->where('state', '!=', 'confirmed')->first();
                if ($first?->id !== $row->id || ($session->active_item_id && $session->active_item_id !== $row->id)) {
                    throw new CollectionException('sale_predecessor_not_confirmed');
                }
                if ($row->dispatch_started_at || $readOnly) {
                    return false;
                }
                $actor = User::findOrFail($session->actor_id);
                abort_unless($actor->isActive(), 403);
                app(CollectionSalesService::class)->present($session, $actor);
                app(CollectionGate::class)->assertFunds($session->receipt);
                $this->assertItemTerms($row, $actor);
                if (! in_array($session->account_id, config('collection_sales.verified_accounts', []), true)
                    || ! in_array((int) $session->platform_id, config('collection_sales.verified_platforms', []), true)) {
                    throw new CollectionException('manual_activation_connection_required');
                }
                $this->assertWallet(Client::findOrFail($row->client_id), Payment::findOrFail($row->payment_id));
                $hold = DB::table('collection_sales_profile_holds')->where('client_id', $row->client_id)->first();
                if (! $hold || $hold->item_id !== $row->id || $hold->operation_key !== $row->operation_key) {
                    throw new CollectionException('sale_profile_hold_changed');
                }
                $remaining();
                $row->update(['state' => 'dispatching', 'dispatch_started_at' => now(), 'version' => $row->version + 1]);
                $session->update(['active_item_id' => $row->id, 'state' => 'activating', 'version' => $session->version + 1]);

                return true;
            });
            $item->refresh();
            if ($item->state === 'confirmed') {
                return $item;
            }
            $p = $item->purchase;
            $sync = WpSyncService::forPlatform($p['platform_id'])->withReadBudget(fn () => min(6, $remaining()));
            try {
                $read = $sync->subscriptionOperation($p['wp_post_id'], $item->operation_key);
                if (($read['version'] ?? 0) !== 1) {
                    throw new CollectionException('exact_target_contract_required');
                }
                if (empty($read['operation']) && $dispatch) {
                    $remaining();
                    $target = $item->expected_site['target'];
                    $sync->applySubscriptionOperation($p['wp_post_id'], ['operation_id' => $item->operation_key, 'wp_user_id' => $p['wp_user_id'],
                        'expected_fingerprint' => $target['expected_fingerprint'], 'target_expiry' => $target['target_expiry'],
                        'product_type' => $p['tier'], 'crm_deal_id' => $item->deal_id], min(15, $remaining()));
                    $read = $sync->subscriptionOperation($p['wp_post_id'], $item->operation_key);
                }
                $operation = $read['operation'] ?? [];
                $snapshot = $operation['snapshot'] ?? [];
                app(CollectionEntitlementPolicy::class)->assertSnapshot($snapshot, $p);
                if (($operation['state'] ?? '') !== 'succeeded' || ($operation['operation_id'] ?? '') !== $item->operation_key
                    || (int) ($operation['post_id'] ?? 0) !== (int) $p['wp_post_id'] || ($snapshot['post_status'] ?? '') !== 'publish'
                    || (int) data_get($snapshot, 'meta.crm_deal_id') !== (int) $item->deal_id
                    || app(CollectionEntitlementPolicy::class)->tier($snapshot) !== $p['tier']
                    || app(CollectionEntitlementPolicy::class)->expiry($snapshot) !== $item->expected_site['target']['target_expiry']) {
                    throw new CollectionException('sale_operation_not_verified');
                }
                $remaining();
                $this->claimTransaction(function () use ($item, $operation, $remaining) {
                    $remaining();
                    $this->lockReceipt($item);
                    $session = CollectionSalesSession::lockForUpdate()->findOrFail($item->session_id);
                    $row = CollectionSalesItem::lockForUpdate()->findOrFail($item->id);
                    if ($row->state === 'confirmed') {
                        return;
                    }
                    $row->update(['state' => 'wp_verified', 'operation_result' => $operation]);
                    app(SubscriptionProvisioningService::class)->recordVerifiedManualSaleItem($row);
                    $row->update(['state' => 'confirmed', 'verified_at' => now(), 'last_error_code' => null, 'version' => $row->version + 1]);
                    DB::table('collection_sales_profile_holds')->where('item_id', $row->id)->where('operation_key', $row->operation_key)->delete();
                    $pending = $session->items()->where('state', '!=', 'confirmed')->exists();
                    $state = $pending ? 'partial' : ($session->remainder_minor ? 'needs_review' : 'confirmed');
                    $session->update(['state' => $state, 'active_item_id' => null, 'version' => $session->version + 1, 'confirmed_at' => $pending ? null : now()]);
                    ManualPaymentBundle::whereKey($session->bundle_id)->update(['status' => $state === 'confirmed' ? 'receipt_completed' : ($state === 'needs_review' ? 'receipt_review' : 'receipt_partial')]);
                    $session->receipt->update(['decision_state' => 'sales_'.$state]);
                    DB::table('collection_outbox')->where('kind', 'sales_dispatch')->whereNull('completed_at')
                        ->whereIn('subject_id', $session->items()->where('state', 'prepared')->pluck('id'))->update(['available_at' => now()]);
                    app(CollectionIngestService::class)->outbox('sales_benefits', $row->id, 'sales-benefits:'.$row->id);
                    if ($row->subsidiary) {
                        app(CollectionIngestService::class)->outbox('sales_trial', $row->id, 'sales-trial:'.$row->id);
                    }
                    $remaining();
                });
            } catch (\Throwable $e) {
                $this->claimTransaction(function () use ($item, $e) {
                    $this->lockReceipt($item);
                    $session = CollectionSalesSession::lockForUpdate()->findOrFail($item->session_id);
                    $row = CollectionSalesItem::lockForUpdate()->findOrFail($item->id);
                    if ($row->state !== 'confirmed' && $row->dispatch_started_at) {
                        $changed = $row->state !== 'unknown';
                        $row->update(['state' => 'unknown', 'version' => $row->version + (int) $changed, 'last_error_code' => $e instanceof CollectionException ? $e->reason : 'site_confirmation_required']);
                        $session->update(['state' => 'needs_review', 'active_item_id' => $row->id, 'version' => $session->version + (int) $changed]);
                        ManualPaymentBundle::whereKey($session->bundle_id)->update(['status' => 'receipt_review']);
                        $session->receipt->update(['decision_state' => 'sales_needs_review']);
                    }
                });
            }

            return $item->fresh();
        }));
    }
}
