<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionSalesCorrection;
use App\Models\Collections\CollectionSalesItem;
use App\Models\Collections\CollectionSalesSession;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClientLifecycleMutationLock;
use App\Services\DealPaymentService;
use App\Services\SubscriptionProvisioningService;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Per-child correction journal. Cash and confirmed siblings retain their original owners. */
class CollectionSalesCorrectionService
{
    private function authorize(CollectionSalesItem $item, User $actor): void
    {
        app(CollectionAccessService::class)->receipt($actor, $item->session->receipt_id);
        app(CollectionPurchaseService::class)->assertScope($item->session->receipt->account, $item->client, $actor);
    }

    private function reversible(CollectionSalesItem $item): void
    {
        if (data_get($item->subsidiary, 'enabled') || data_get($item->purchase, 'subsidiary')) {
            throw new CollectionException('subsidiary_sale_correction_requires_owned_benefit_review');
        }
        if (Commission::where('deal_id', $item->deal_id)->where(fn ($q) => $q->whereIn('status', ['paid', 'processing'])->orWhereNotNull('payout_id'))->exists()
            || DB::table('client_monetization_passes')->where('source_deal_id', $item->deal_id)->whereNotNull('claimed_at')->exists()
            || DB::table('wallet_rebates')->where('source_payment_id', $item->payment_id)->where('status', '!=', 'reversed')->exists()) {
            throw new CollectionException('consumed_or_paid_benefit_requires_review');
        }
    }

    public function preview(CollectionSalesItem $item, User $actor, array $input): CollectionSalesCorrection
    {
        $this->authorize($item, $actor);
        if ($item->version !== (int) $input['item_version'] || ! in_array($input['action'], ['undo_effect', 'reassign', 'change_package'], true)) {
            throw new CollectionException('correction_version_or_action_invalid');
        }
        $preview = ['can_execute' => false, 'reasons' => [], 'cash_changed' => false,
            'source' => ['client_id' => $item->client_id, 'deal_id' => $item->deal_id, 'payment_id' => $item->payment_id,
                'operation_key' => $item->operation_key, 'purchase' => $item->purchase, 'expected_site' => $item->expected_site,
                'operation_result' => $item->operation_result, 'state' => $item->state, 'generation' => $item->generation], 'target' => null];
        try {
            if (! in_array($item->state, ['confirmed', 'prepared', 'unassigned'], true) || ($item->state === 'prepared' && $item->dispatch_started_at)) {
                throw new CollectionException('finish_or_recover_existing_operation_first');
            }
            $this->reversible($item);
            $receipt = $item->session->receipt;
            app(CollectionGate::class)->assertFunds($receipt);
            if ($input['action'] !== 'undo_effect') {
                $client = app(CollectionPurchaseService::class)->selectableClients()->findOrFail($input['client_id']);
                app(CollectionPurchaseService::class)->assertScope($receipt->account, $client, $actor);
                if ((int) $client->platform_id !== (int) $item->purchase['platform_id']
                    || $item->session->items()->where('id', '!=', $item->id)->where('client_id', $client->id)->exists()) {
                    throw new CollectionException('correction_target_conflicts_with_sibling_or_market');
                }
                $target = app(CollectionPurchaseService::class)->prepare($receipt->account, $client, $actor,
                    $input + ['valid_until' => now()->addMinutes(15), 'authority' => 'reviewed_quote'], false)->purchase_snapshot;
                if ($target['amount_minor'] !== $item->amount_minor) {
                    throw new CollectionException('correction_cash_allocation_requires_finance_review');
                }
                $preview['target'] = $target;
            }
            $read = WpSyncService::forPlatform($item->purchase['platform_id'])->subscriptionOperation($item->purchase['wp_post_id'], $item->operation_key);
            $preview['source_current'] = $read['snapshot'];
            if (data_get($read, 'capabilities.durable_correction_owner') !== 1) {
                throw new CollectionException('wordpress_correction_owner_required');
            }
            $preview['pre_dispatch'] = $item->state === 'prepared' && ! $item->dispatch_started_at && empty($read['operation']);
            if ($item->state === 'confirmed') {
                if (data_get($read, 'capabilities.compensate') !== 2 || data_get($read, 'operation.contract_version') !== 2
                    || data_get($read, 'operation.snapshot.fingerprint') !== data_get($read, 'snapshot.fingerprint')
                    || app(CollectionSalesService::class)->digest(data_get($read, 'operation.before_state', [])) !== app(CollectionSalesService::class)->digest($item->expected_site['before'])) {
                    throw new CollectionException('original_compensation_preimage_required');
                }
            } elseif ($preview['pre_dispatch'] && $read['snapshot']['fingerprint'] !== data_get($item->expected_site, 'before.fingerprint')) {
                throw new CollectionException('prepared_profile_state_changed');
            }
            $platforms = [(int) $item->purchase['platform_id']];
            app(CollectionGate::class)->assertWrites($receipt->account, $platforms, false, $item->state === 'confirmed');
            if ($preview['target']) {
                $p = $preview['target'];
                $site = WpSyncService::forPlatform($p['platform_id'])->subscriptionOperation($p['wp_post_id']);
                if ($item->state === 'confirmed' && data_get($site, 'capabilities.durable_correction_owner') !== 1) {
                    throw new CollectionException('wordpress_correction_owner_required');
                }
                $base = (int) $p['client_id'] === (int) $item->client_id && $item->state === 'confirmed' ? $item->expected_site['before'] : $site['snapshot'];
                $preview['target_current'] = $site['snapshot'];
                app(CollectionEntitlementPolicy::class)->target($base, $p);
            }
            $preview['can_execute'] = true;
        } catch (CollectionException $e) {
            $preview['reasons'][] = $e->reason;
        }

        return CollectionSalesCorrection::create(['id' => (string) Str::uuid(), 'item_id' => $item->id, 'actor_id' => $actor->id,
            'item_version' => $item->version, 'generation' => $item->generation + 1, 'action' => $input['action'], 'reason' => $input['reason'],
            'preview' => $preview, 'compensation_key' => (string) Str::uuid(), 'target_key' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(10)]);
    }

    public function execute(CollectionSalesCorrection $correction, User $actor, string $requestKey, int $version): CollectionSalesCorrection
    {
        $item = CollectionSalesItem::findOrFail($correction->item_id);
        $this->authorize($item, $actor);
        if ($target = $correction->preview['target']) {
            app(CollectionPurchaseService::class)->assertScope($item->session->receipt->account, Client::findOrFail($target['client_id']), $actor);
        }
        $ids = array_values(array_unique(array_filter([$item->client_id, data_get($correction->preview, 'target.client_id')])));
        sort($ids, SORT_NUMERIC);

        return app(CollectionWriterGuard::class)->ownedSaleCorrection($item, $correction->id, fn () => app(ClientLifecycleMutationLock::class)->runBounded($ids, function () use ($item, $correction, $actor, $requestKey, $version) {
            DB::transaction(function () use ($item, $correction, $actor, $requestKey, $version) {
                CollectionAccount::whereKey($item->session->account_id)->lockForUpdate()->firstOrFail();
                $session = CollectionSalesSession::lockForUpdate()->findOrFail($item->session_id);
                $row = CollectionSalesItem::lockForUpdate()->findOrFail($item->id);
                $c = CollectionSalesCorrection::lockForUpdate()->findOrFail($correction->id);
                if ($c->request_key) {
                    if ($c->request_key !== $requestKey) {
                        throw new CollectionException('correction_request_key_conflict');
                    }

                    return;
                }
                if ($c->version !== $version || $c->item_version !== $row->version || $c->expires_at->isPast() || ! $c->preview['can_execute']
                    || CollectionSalesCorrection::where('item_id', $row->id)->where('id', '!=', $c->id)->whereNotNull('request_key')->whereNotIn('state', ['confirmed'])->exists()) {
                    throw new CollectionException('correction_preview_stale_or_held');
                }
                $this->reversible($row);
                app(CollectionGate::class)->assertFunds($session->receipt);
                app(CollectionMatchExclusionService::class)->record($session->receipt, $row->client_id, data_get($c->preview, 'target.client_id'),
                    'sale-correction:'.$c->id, $actor->id, $c->reason);
                $affected = array_values(array_unique(array_filter([$row->client_id, data_get($c->preview, 'target.client_id')])));
                sort($affected, SORT_NUMERIC);
                foreach ($affected as $clientId) {
                    $hold = DB::table('collection_sales_profile_holds')->where('client_id', $clientId)->lockForUpdate()->first();
                    if ($hold && $hold->item_id !== $row->id) {
                        throw new CollectionException('correction_profile_already_owned');
                    }
                    DB::table('collection_sales_profile_holds')->updateOrInsert(['client_id' => $clientId], ['session_id' => $session->id,
                        'item_id' => $row->id, 'operation_key' => $c->target_key, 'created_at' => now()]);
                }
                $row->update(['state' => 'correcting', 'generation' => $c->generation, 'version' => $row->version + 1]);
                DB::table('collection_outbox')->where('subject_id', $row->id)->whereIn('kind', ['sales_dispatch', 'sales_check', 'sales_benefits', 'sales_trial'])
                    ->whereNull('completed_at')->update(['completed_at' => now(), 'last_error_code' => 'superseded_by_correction']);
                $session->update(['state' => 'correcting', 'version' => $session->version + 1]);
                $c->update(['state' => 'reserved', 'request_key' => $requestKey]);
                app(CollectionPurchaseService::class)->audit($session->receipt, $actor->id, 'sale_correction_reserved', ['correction_id' => $c->id, 'item_id' => $row->id, 'reason' => $c->reason, 'cash_changed' => false]);
            }, 3);
            if (in_array($correction->fresh()->state, ['confirmed', 'release_pending'], true)) {
                $this->releaseOwners($correction->fresh());
                $correction->update(['state' => 'confirmed']);

                return $correction->fresh();
            }
            try {
                $this->apply($correction->fresh(), $actor);
            } catch (\Throwable $e) {
                if ($correction->fresh()->state === 'confirmed') {
                    $correction->update(['state' => 'release_pending']);
                } else {
                    $correction->update(['state' => 'unknown']);
                    $item->update(['state' => 'correction_unknown', 'last_error_code' => $e instanceof CollectionException ? $e->reason : 'site_confirmation_required']);
                }
            }

            return $correction->fresh();
        }));
    }

    private function apply(CollectionSalesCorrection $c, User $actor): void
    {
        $source = $c->preview['source'];
        $p = $source['purchase'];
        $target = $c->preview['target'];
        $profiles = [['platform_id' => $p['platform_id'], 'client_id' => $source['client_id'], 'wp_post_id' => $p['wp_post_id'],
            'source_operation_id' => $source['state'] === 'confirmed' ? $source['operation_key'] : null, 'expected_fingerprint' => $c->preview['source_current']['fingerprint']]];
        if ($target && (int) $target['client_id'] !== (int) $source['client_id']) {
            $profiles[] = ['platform_id' => $target['platform_id'], 'client_id' => $target['client_id'], 'wp_post_id' => $target['wp_post_id'],
                'expected_fingerprint' => $c->preview['target_current']['fingerprint']];
        }
        app(CollectionCorrectionOwnership::class)->acquireAll($c->id, $profiles);
        if ($source['state'] === 'confirmed') {
            $before = $source['expected_site']['before'];
            $body = ['contract_version' => 2, 'operation_kind' => 'compensate', 'source_operation_id' => $source['operation_key'],
                'wp_user_id' => $p['wp_user_id'], 'expected_fingerprint' => $c->preview['source_current']['fingerprint'],
                'restore_snapshot' => $before, 'target_expiry' => app(CollectionEntitlementPolicy::class)->expiry($before),
                'product_type' => app(CollectionEntitlementPolicy::class)->tier($before), 'crm_deal_id' => (int) data_get($before, 'meta.crm_deal_id', 0)];
            $c->update(['state' => 'source_compensating']);
            $result = $this->operation($c, $p, $c->compensation_key, $body);
            $c->update(['compensation_result' => $result]);
        }
        if ($target) {
            $c->update(['state' => 'target_applying']);
            $sync = WpSyncService::forPlatform($target['platform_id']);
            $read = $sync->subscriptionOperation($target['wp_post_id'], $c->target_key);
            if (! $c->target_deal_id) {
                DB::transaction(function () use ($c, $target, $source, $actor) {
                    $saved = CollectionSalesCorrection::lockForUpdate()->findOrFail($c->id);
                    if ($saved->target_deal_id) {
                        $c->target_deal_id = $saved->target_deal_id;

                        return;
                    }
                    $deal = app(DealPaymentService::class)->createPendingDealFromCatalog(Client::findOrFail($target['client_id']), $target['product_id'], $target['product_price_id'], null, $actor->id, null);
                    $deal->update(['payment_id' => $source['payment_id'], 'origin' => 'manual_collection_correction',
                        'amount' => app(CollectionFulfillmentService::class)->decimal($target['amount_minor']),
                        'original_amount' => $target['original_amount'], 'discount_percentage' => $target['discount_percentage'],
                        'discount_approved_by' => $target['discount_approved_by'], 'discount_source' => $target['discount_source']]);
                    $c->update(['target_deal_id' => $deal->id]);
                });
            }
            if (empty($read['operation'])) {
                $exact = app(CollectionEntitlementPolicy::class)->target($read['snapshot'], $target);
                $body = $exact + ['wp_user_id' => $target['wp_user_id'], 'crm_deal_id' => $c->target_deal_id];
                // Freeze target terms before POST; recovery reuses this payload exactly.
                $preview = $c->preview;
                if (! isset($preview['target_exact'])) {
                    $preview['target_exact'] = $body;
                    $preview['target_before'] = $read['snapshot'];
                    $c->update(['preview' => $preview]);
                }
            }
            $result = $this->operation($c, $target, $c->target_key, $c->preview['target_exact'] ?? []);
            $c->update(['target_result' => $result]);
        }
        $this->finalize($c->fresh(), $actor);
        $this->releaseOwners($c->fresh());
    }

    private function operation(CollectionSalesCorrection $c, array $purchase, string $key, array $body): array
    {
        $sync = WpSyncService::forPlatform($purchase['platform_id']);
        $read = $sync->subscriptionOperation($purchase['wp_post_id'], $key);
        if (empty($read['operation'])) {
            app(CollectionGate::class)->assertWrites(CollectionSalesItem::findOrFail($c->item_id)->session->receipt->account, [(int) $purchase['platform_id']], false, true);
            $proof = app(CollectionCorrectionOwnership::class)->proof($c->id, $purchase['platform_id'], $purchase['wp_post_id']);
            $sync->applySubscriptionOperation($purchase['wp_post_id'], $body + ['operation_id' => $key] + $proof);
            $read = $sync->subscriptionOperation($purchase['wp_post_id'], $key);
        }
        $result = $read['operation'] ?? [];
        if (($result['state'] ?? null) !== 'succeeded' || ($result['operation_id'] ?? null) !== $key
            || ($result['correction_id'] ?? null) !== $c->id || (int) ($result['snapshot']['post_id'] ?? 0) !== (int) $purchase['wp_post_id']) {
            throw new CollectionException('sale_correction_operation_unconfirmed');
        }

        if ($body && ((int) data_get($result, 'snapshot.wp_user_id') !== (int) $purchase['wp_user_id']
            || app(CollectionEntitlementPolicy::class)->expiry($result['snapshot']) !== (int) $body['target_expiry']
            || app(CollectionEntitlementPolicy::class)->tier($result['snapshot']) !== $body['product_type']
            || (int) data_get($result, 'snapshot.meta.crm_deal_id', 0) !== (int) $body['crm_deal_id'])) {
            throw new CollectionException('sale_correction_post_state_differs');
        }

        return $result;
    }

    private function finalize(CollectionSalesCorrection $c, User $actor): void
    {
        DB::transaction(function () use ($c, $actor) {
            $item = CollectionSalesItem::findOrFail($c->item_id);
            CollectionAccount::whereKey($item->session->account_id)->lockForUpdate()->firstOrFail();
            $session = CollectionSalesSession::lockForUpdate()->findOrFail($item->session_id);
            $item = CollectionSalesItem::lockForUpdate()->findOrFail($item->id);
            $this->reversible($item);
            $source = $c->preview['source'];
            $target = $c->preview['target'];
            Deal::whereKey($source['deal_id'])->update(['status' => 'cancelled', 'cancellation_notes' => 'Receipt correction: '.$c->reason]);
            Commission::where('deal_id', $source['deal_id'])->whereNotIn('status', ['paid', 'processing'])->update(['status' => 'void']);
            DB::table('client_monetization_passes')->where('source_deal_id', $source['deal_id'])->whereNull('claimed_at')->update(['status' => 'revoked', 'active_marker' => null]);
            if ($c->compensation_result) {
                app(SubscriptionProvisioningService::class)->cacheVerifiedCollectionSnapshot($source['client_id'], $c->compensation_result['snapshot']);
            }
            $payment = Payment::lockForUpdate()->findOrFail($source['payment_id']);
            if ($target) {
                $payment->update(['client_id' => $target['client_id'], 'user_id' => $target['wp_user_id'], 'escort_post_id' => $target['wp_post_id'],
                    'deal_id' => $c->target_deal_id, 'product_id' => $target['product_id']]);
                $item->update(['client_id' => $target['client_id'], 'deal_id' => $c->target_deal_id, 'purchase' => $target,
                    'operation_key' => $c->target_key, 'expected_site' => ['before' => $c->preview['target_before'], 'target' => $c->preview['target_exact']],
                    'operation_result' => $c->target_result, 'state' => 'wp_verified', 'subsidiary' => null]);
                app(SubscriptionProvisioningService::class)->recordVerifiedManualSaleItem($item);
                $item->update(['state' => 'confirmed', 'verified_at' => now(), 'last_error_code' => null]);
            } else {
                $payment->update(['client_id' => null, 'deal_id' => null, 'user_id' => null, 'escort_post_id' => null]);
                $item->update(['state' => 'unassigned', 'last_error_code' => null]);
            }
            $item->increment('version');
            $session->update(['state' => $session->items()->where('state', '!=', 'confirmed')->exists() ? 'needs_review' : 'confirmed', 'version' => $session->version + 1]);
            $c->update(['state' => 'confirmed']);
            app(CollectionPurchaseService::class)->audit($session->receipt, $actor->id, 'sale_correction_confirmed',
                ['correction_id' => $c->id, 'item_id' => $item->id, 'payment_id' => $payment->id, 'cash_changed' => false, 'reason' => $c->reason]);
        }, 3);
    }

    private function releaseOwners(CollectionSalesCorrection $c): void
    {
        $operations = [];
        foreach ([$c->compensation_result, $c->target_result] as $result) {
            if ($result) {
                $operations[] = ['id' => $result['operation_id'], 'platform_id' => $c->preview['source']['purchase']['platform_id'], 'wp_post_id' => $result['post_id']];
            }
        }
        app(CollectionCorrectionOwnership::class)->releaseAll($c->id, $operations);
        DB::table('collection_sales_profile_holds')->where('item_id', $c->item_id)->delete();
    }
}
