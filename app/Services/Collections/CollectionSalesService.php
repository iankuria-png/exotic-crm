<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Collections\CollectionSalesItem;
use App\Models\Collections\CollectionSalesSession;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Receipt-owned drafts and pure validation. Cash/dispatch preparation is a separate boundary. */
class CollectionSalesService
{
    public function current(CollectionReceipt $receipt, User $actor): array
    {
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        $session = Schema::hasTable('collection_sales_sessions') ? CollectionSalesSession::where('receipt_id', $receipt->id)->first() : null;
        $seed = null;
        if (! $session) {
            $intent = CollectionPurchaseIntent::where('account_id', $receipt->account_id)->where('provider_reference', $receipt->reference_norm)
                ->whereNull('revoked_at')->where('authority', 'reviewed_quote')->orderByDesc('created_at')->first();
            if ($intent) {
                app(CollectionPurchaseService::class)->assertScope($receipt->account, Client::findOrFail($intent->client_id), $actor);
                $seed = ['saved_intent_id' => $intent->id, 'client_id' => $intent->client_id, 'deal_id' => $intent->deal_id,
                    'product_price_id' => $intent->purchase_snapshot['product_price_id'], 'purchase' => $intent->purchase_snapshot,
                    'client' => app(CollectionReviewContextService::class)->clientData(Client::findOrFail($intent->client_id))];
            }
        }

        return ['session' => $session ? $this->present($session, $actor) : null, 'seed' => $seed,
            'receipt_version' => $receipt->version, 'storage_ready' => Schema::hasTable('collection_sales_sessions')];
    }

    public function preview(CollectionReceipt $receipt, User $actor, array $input): array
    {
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        app(CollectionGate::class)->assertFunds($receipt);
        if ((int) $input['receipt_version'] !== $receipt->version) {
            throw new CollectionException('receipt_version_changed');
        }
        if ($receipt->allocation()->exists()) {
            throw new CollectionException('receipt_already_allocated');
        }
        $session = CollectionSalesSession::where('receipt_id', $receipt->id)->first();
        if ((int) ($input['session_version'] ?? 0) !== (int) ($session?->version ?? 0) || ($session && $session->state !== 'draft')) {
            throw new CollectionException('sale_version_changed');
        }
        $raw = $input['items'] ?? [];
        if (! count($raw) || count($raw) > min(20, (int) config('collection_sales.max_items', 20))) {
            throw new CollectionException('select_one_to_twenty_profiles', 422);
        }
        $items = [];
        $seen = [];
        $platform = null;
        $blockers = [];
        foreach ($raw as $position => $value) {
            $client = app(CollectionPurchaseService::class)->selectableClients()->findOrFail((int) $value['client_id']);
            if (isset($seen[$client->id])) {
                throw new CollectionException('profile_already_in_payment', 422);
            }
            $seen[$client->id] = true;
            if ($platform !== null && $platform !== (int) $client->platform_id) {
                throw new CollectionException('shared_payment_requires_one_market', 422);
            }
            $platform = (int) $client->platform_id;
            $saved = ! empty($value['saved_intent_id']) ? CollectionPurchaseIntent::where('account_id', $receipt->account_id)
                ->where('provider_reference', $receipt->reference_norm)->where('authority', 'reviewed_quote')->whereNull('revoked_at')->findOrFail($value['saved_intent_id'])
                : CollectionPurchaseIntent::where('account_id', $receipt->account_id)->where('provider_reference', $receipt->reference_norm)
                    ->where('client_id', $client->id)->where('authority', 'reviewed_quote')->whereNull('revoked_at')->orderByDesc('created_at')->first();
            if ($saved && ((int) $saved->client_id !== $client->id || (int) $saved->purchase_snapshot['product_price_id'] !== (int) $value['product_price_id']
                || $saved->valid_until->lte(now()) || $saved->used_count >= $saved->max_renewals)) {
                throw new CollectionException('saved_purchase_changed');
            }
            $terms = ['authority' => 'reviewed_quote', 'valid_until' => now()->addMinutes(5), 'provider_reference' => $receipt->reference_norm,
                'product_price_id' => (int) $value['product_price_id'], 'deal_id' => $value['deal_id'] ?? $saved?->deal_id,
                'subsidiary_client_id' => $value['subsidiary_client_id'] ?? data_get($saved?->purchase_snapshot, 'subsidiary.client_id')];
            $discount = $value['discount'] ?? [];
            $newDiscount = ! empty($discount['enabled']);
            $replacePrice = ! empty($value['replace_saved_price']);
            $quoteToAmend = null;
            if ($replacePrice) {
                if (! $saved || ! $newDiscount) {
                    throw new CollectionException('saved_price_amendment_requires_discount_approval', 422);
                }
                $oldEvidence = clone $receipt;
                $oldEvidence->principal_minor = (int) $saved->purchase_snapshot['amount_minor'];
                app(CollectionPurchaseService::class)->validate($saved, $oldEvidence, false);
                $quoteToAmend = $saved->deal_id;
                $terms['deal_id'] = null; // Rebuild catalogue terms; never compound a saved discount.
            }
            $amendBenefit = ! empty($value['replace_saved_benefit']) && (bool) data_get($saved?->purchase_snapshot, 'subsidiary');
            $legacyBenefit = (bool) data_get($saved?->purchase_snapshot, 'subsidiary') && ! $amendBenefit;
            $prepared = app(CollectionPurchaseService::class)->prepare($receipt->account, $client, $actor, $terms, $legacyBenefit);
            if ($quoteToAmend) {
                $prepared->deal_id = $quoteToAmend;
            }
            if ($newDiscount) {
                $prepared->purchase_snapshot = app(CollectionSalesDiscountService::class)->apply($prepared->purchase_snapshot, $actor, $discount, $position);
            }
            // Validate the allocated row against the existing authority without changing the received total.
            $rowEvidence = clone $receipt;
            $rowEvidence->principal_minor = $prepared->purchase_snapshot['amount_minor'];
            // Original quote terms were checked during prepare (or saved-authority validation).
            // The approved new amount is checked without asserting it equals the pre-amendment DB amount.
            $amountAuthority = clone $prepared;
            if ($newDiscount) {
                $amountAuthority->deal_id = null;
            }
            app(CollectionPurchaseService::class)->validate($amountAuthority, $rowEvidence, false);
            $purchase = $prepared->purchase_snapshot;
            if ($saved && ((! $newDiscount && (int) $saved->purchase_snapshot['amount_minor'] !== (int) $purchase['amount_minor'])
                || (int) $saved->purchase_snapshot['duration_days'] !== (int) $purchase['duration_days']
                || $saved->purchase_snapshot['tier'] !== $purchase['tier'] || (! $amendBenefit && $saved->purchase_snapshot['subsidiary'] !== $purchase['subsidiary']))) {
                throw new CollectionException('saved_purchase_terms_changed');
            }
            if ($prepared->deal_id && \App\Models\Deal::findOrFail($prepared->deal_id)->status !== 'pending') {
                throw new CollectionException('quote_already_activated_requires_review', 422);
            }
            if ($purchase['subsidiary']) {
                $blockers[] = 'saved_same_period_benefit_adapter_required';
            }
            $lifecycle = app(\App\Services\SubscriptionLifecycleService::class)->resolveForClient($client, $platform, $value['subscription_lifecycle'] ?? null, $value['subscription_lifecycle_reason'] ?? null, ['exclude_deal_id' => $prepared->deal_id]);
            $trial = ! $legacyBenefit ? app(CollectionSalesSubsidiaryAdapter::class)->validate($receipt, $actor, $purchase, $value['subsidiary_trial'] ?? []) : null;
            $items[] = ['lifecycle' => $lifecycle, 'subsidiary_trial' => $trial ? array_diff_key($trial, ['identity' => true]) : null,
                'price_amended' => $newDiscount, 'subsidiary_fingerprint' => $trial ? $this->digest($trial) : null, 'replaces_saved_benefit' => $amendBenefit, 'client_id' => $client->id, 'client' => app(CollectionReviewContextService::class)->clientData($client),
                'position' => $position + 1, 'deal_id' => $prepared->deal_id, 'purchase' => $purchase,
                'amount_minor' => $purchase['amount_minor'], 'saved_intent_id' => $saved?->id];
        }
        $total = array_sum(array_column($items, 'amount_minor'));
        $remainder = $receipt->principal_minor - $total;
        if ($remainder < 0) {
            throw new CollectionException('allocations_exceed_received_payment', 422);
        }
        if ($remainder > 0 && (empty($input['leave_remainder']) || mb_strlen(trim($input['remainder_reason'] ?? '')) < 3)) {
            throw new CollectionException('allocate_remainder_or_add_finance_reason', 422);
        }
        if (! in_array($receipt->account_id, config('collection_sales.verified_accounts', []), true)
            || ! in_array($platform, config('collection_sales.verified_platforms', []), true)) {
            $blockers[] = 'manual_activation_connection_required';
        }
        $projection = ['receipt_id' => $receipt->id, 'receipt_version' => $receipt->version, 'session_version' => $session?->version ?? 0,
            'platform_id' => $platform, 'currency' => $receipt->currency, 'principal_minor' => $receipt->principal_minor,
            'allocated_minor' => $total, 'remainder_minor' => $remainder,
            'remainder_reason' => $remainder ? trim($input['remainder_reason']) : null, 'items' => $items,
            'blocked_reasons' => array_values(array_unique($blockers)), 'can_activate' => ! $blockers];
        $projection['preview_token'] = Crypt::encryptString(json_encode(['actor' => $actor->id, 'receipt' => $receipt->id,
            'digest' => $this->digest($projection), 'expires' => now()->addMinutes(5)->timestamp], JSON_THROW_ON_ERROR));

        return $projection;
    }

    public function save(CollectionReceipt $receipt, User $actor, array $input): array
    {
        return DB::transaction(function () use ($receipt, $actor, $input) {
            CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
            $receipt = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
            $key = 'sale-draft:'.$receipt->id.':'.$actor->id.':'.$input['request_key'];
            $digest = $this->inputDigest($input);
            $prior = DB::table('collection_audit')->where('dedupe_key', $key)->first();
            if ($prior) {
                app(CollectionAccessService::class)->receipt($actor, $receipt->id);
                $context = json_decode($prior->context, true, 512, JSON_THROW_ON_ERROR);
                if (! hash_equals($context['digest'], $digest)) {
                    throw new CollectionException('sale_request_key_reused');
                }
                // Reauthorize every stored child before replaying its original response.
                $this->present(CollectionSalesSession::findOrFail($context['response']['session']['id']), $actor);
                foreach ($context['response']['session']['items'] as $oldItem) {
                    app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $oldItem['purchase']['platform_id']);
                    if ($oldTrial = data_get($oldItem, 'subsidiary_trial.platform_id')) {
                        app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $oldTrial);
                    }
                    if ($oldPlatform = data_get($oldItem, 'purchase.subsidiary.platform_id')) {
                        app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $oldPlatform);
                    }
                }

                return $context['response'] + ['replayed' => true];
            }
            $preview = $this->preview($receipt, $actor, $input);
            $session = CollectionSalesSession::where('receipt_id', $receipt->id)->lockForUpdate()->first();
            $values = ['receipt_id' => $receipt->id, 'account_id' => $receipt->account_id, 'platform_id' => $preview['platform_id'],
                'actor_id' => $actor->id, 'receipt_version' => $receipt->version, 'principal_minor' => $receipt->principal_minor,
                'allocated_minor' => $preview['allocated_minor'], 'remainder_minor' => $preview['remainder_minor'],
                'remainder_reason' => $preview['remainder_reason'], 'currency' => $receipt->currency];
            if ($session) {
                $session->fill($values)->forceFill(['version' => $session->version + 1])->save();
                $session->items()->delete(); // Draft-only, no external or financial records exist.
            } else {
                $session = CollectionSalesSession::create($values + ['id' => (string) Str::uuid(), 'state' => 'draft']);
            }
            foreach ($preview['items'] as $row) {
                CollectionSalesItem::create(['id' => (string) Str::uuid(), 'session_id' => $session->id, 'client_id' => $row['client_id'],
                    'position' => $row['position'], 'operation_key' => (string) Str::uuid(), 'amount_minor' => $row['amount_minor'],
                    'purchase' => $row['purchase'] + ['source_deal_id' => $row['deal_id'], 'saved_intent_id' => $row['saved_intent_id'], 'benefit_amendment' => $row['replaces_saved_benefit'],
                        'receipt_discount_requested' => $row['price_amended'],
                        'replace_saved_price' => (bool) ($input['items'][$row['position'] - 1]['replace_saved_price'] ?? false), 'lifecycle' => $row['lifecycle']],
                    'subsidiary' => $row['subsidiary_trial'] ? app(CollectionSalesSubsidiaryAdapter::class)->validate($receipt, $actor, $row['purchase'], $input['items'][$row['position'] - 1]['subsidiary_trial']) : null]);
            }
            $response = ['session' => $this->present($session, $actor), 'receipt_version' => $receipt->version];
            app(CollectionPurchaseService::class)->audit($receipt, $actor->id, 'sale_allocations_saved', ['digest' => $digest, 'response' => $response], $key);

            return $response + ['replayed' => false];
        }, 3);
    }

    public function present(CollectionSalesSession $session, User $actor): array
    {
        app(CollectionAccessService::class)->receipt($actor, $session->receipt_id);
        $session->load('items.client');
        $items = $session->items->map(function ($item) use ($actor, $session) {
            app(CollectionPurchaseService::class)->assertScope($session->receipt->account, $item->client, $actor);
            if ($platform = data_get($item->purchase, 'subsidiary.platform_id')) {
                app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $platform);
            }

            if ($trialPlatform = data_get($item->subsidiary, 'platform_id')) {
                app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $trialPlatform);
            }

            $corrections = \App\Models\Collections\CollectionSalesCorrection::where('item_id', $item->id)->orderByDesc('created_at')->limit(10)->get();
            foreach ($corrections as $correction) {
                foreach (array_filter([data_get($correction->preview, 'source.client_id'), data_get($correction->preview, 'target.client_id')]) as $clientId) {
                    app(CollectionPurchaseService::class)->assertScope($session->receipt->account, Client::findOrFail($clientId), $actor);
                }
            }
            $correctionReady = in_array($item->state, ['confirmed', 'prepared', 'unassigned'], true)
                && ! data_get($item->subsidiary, 'enabled') && ! data_get($item->purchase, 'subsidiary');
            $correctionPlatforms = config('collection.verified_correction_platforms', []);
            $correctionReady = $correctionReady && in_array((int) $item->purchase['platform_id'], $correctionPlatforms, true);

            return ['correction_available' => $correctionReady, 'generation' => $item->generation, 'corrections' => $corrections->map(fn ($c) => ['id' => $c->id, 'state' => $c->state, 'action' => $c->action, 'reason' => $c->reason, 'version' => $c->version, 'request_key' => $c->request_key])->all(), 'subsidiary_trial' => $item->subsidiary ? array_diff_key($item->subsidiary, ['identity' => true]) : null, 'id' => $item->id, 'version' => $item->version, 'state' => $item->state, 'client' => app(CollectionReviewContextService::class)->clientData($item->client),
                'client_id' => $item->client_id, 'purchase' => $item->purchase, 'amount_minor' => $item->amount_minor,
                'deal_id' => $item->deal_id, 'payment_id' => $item->payment_id, 'last_error_code' => $item->last_error_code,
                'verified_at' => $item->verified_at?->toIso8601String()];
        })->all();

        return ['id' => $session->id, 'version' => $session->version, 'state' => $session->state, 'receipt_version' => $session->receipt_version,
            'principal_minor' => $session->principal_minor, 'allocated_minor' => $session->allocated_minor, 'remainder_minor' => $session->remainder_minor,
            'remainder_reason' => $session->remainder_reason, 'currency' => $session->currency, 'items' => $items];
    }

    public function inputDigest(array $input): string
    {
        unset($input['request_key'], $input['preview_token']);
        foreach ($input['items'] ?? [] as $i => $item) {
            unset($input['items'][$i]['discount']['pin']);
            unset($input['items'][$i]['subsidiary_trial']['pin'], $input['items'][$i]['subsidiary_trial']['preview_token'], $input['items'][$i]['subsidiary_trial']['request_key']);
        }

        return $this->digest($input);
    }

    public function digest(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }
}
