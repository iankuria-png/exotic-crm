<?php

namespace App\Services\Collections;

use App\Exceptions\CrmWalletOperationException;
use App\Models\Client;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\MarketAuthorizationService;

/** Advisory staff context only. Never calls evaluate(), reserve(), or an external provider. */
class CollectionReviewContextService
{
    private array $status = [];

    public function clientRows(\Illuminate\Support\Collection $clients): array
    {
        $this->status += app(CollectionReceiptStatusService::class)->clients($clients);

        return $clients->map(fn ($client) => $this->clientData($client))->all();
    }

    public function context(CollectionReceipt $receipt, User $actor, array $input = []): array
    {
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        $manual = ($input['workflow'] ?? null) === 'manual';
        $payer = app(CollectionPayerEvidenceService::class)->forReceipt($receipt);
        $history = app(CollectionPaymentHistoryService::class)->forReceipt($receipt, $actor, $input['cursor'] ?? null);
        $platforms = $history['scope']['platform_ids'];
        $intents = CollectionPurchaseIntent::where('account_id', $receipt->account_id)->where('provider_reference', $receipt->reference_norm)
            ->whereNull('revoked_at')->orderByDesc('created_at')->limit(50)->get();
        $saved = $intents->first();
        $valid = [];
        foreach ($intents as $intent) {
            try {
                app(CollectionPurchaseService::class)->validate($intent, $receipt, false);
                $valid[] = $intent;
            } catch (CollectionException $e) {
                // Expired/stale choices remain visible as history, never as a selected authority.
            }
        }
        $matching = app(CollectionMatchService::class)->evidence($receipt, $history, $payer, $valid);
        $clients = $matching['clients'];
        $this->status += app(CollectionReceiptStatusService::class)->clients($clients);
        $suggestions = array_map(fn ($row) => $row + ['status' => $this->status[$row['id']] ?? null], $matching['suggestions']);
        $recommended = $matching['recommended_client_id'];
        $conflict = $matching['needs_choice'];
        $selected = ! empty($input['selected_client_id']) ? $this->selectable()->findOrFail($input['selected_client_id']) : ($recommended ? $clients[$recommended] : null);
        $options = $selected ? $this->options($receipt, $actor, $selected, ! $manual) : ['prices' => [], 'quotes' => [], 'subsidiary_clients' => [], 'subsidiary_platform_id' => null];
        $intent = null;
        if (! $manual && ! empty($input['intent_id'])) {
            $intent = CollectionPurchaseIntent::where('account_id', $receipt->account_id)->findOrFail($input['intent_id']);
            if ($intent->provider_reference !== $receipt->reference_norm) {
                throw new CollectionException('purchase_for_another_receipt', 422);
            }
            if ($selected && (int) $intent->client_id !== $selected->id) {
                throw new CollectionException('purchase_profile_binding_changed');
            }
        } elseif (! $manual && $selected && ! empty($input['product_price_id'])) {
            $intent = app(CollectionPurchaseService::class)->prepare($receipt->account, $selected, $actor, $input + [
                'authority' => 'reviewed_quote', 'valid_until' => now()->addDays(30), 'provider_reference' => $receipt->reference_norm]);
        } elseif (! $manual && $selected && count($valid) === 1 && (int) $valid[0]->client_id === $selected->id) {
            $intent = $valid[0];
        }
        $blocked = ['select_client_and_service'];
        if ($intent) {
            try {
                app(CollectionApprovalService::class)->eligibility($receipt, $intent, $actor);
                $blocked = [];
            } catch (CollectionException $e) {
                $blocked = [$e->reason];
            } catch (CrmWalletOperationException $e) {
                $blocked = [$e->errorCode];
            }
        }
        // Identity-only selection is possible while a required secondary profile is still unresolved.
        $savedData = null;
        if ($saved) {
            $savedClient = Client::whereIn('platform_id', $platforms)->find($saved->client_id);
            if ($savedClient) {
                $savedData = ['id' => $saved->id, 'purchase' => $saved->purchase_snapshot, 'client' => $this->clientData($savedClient),
                    'valid' => in_array($saved->id, array_map(fn ($i) => $i->id, $valid)), 'valid_until' => $saved->valid_until];
            }
        }
        unset($history['client_counts']);

        $exclusions = $selected && $receipt->payer_fingerprint ? \Illuminate\Support\Facades\DB::table('collection_match_exclusions')
            ->where('account_id', $receipt->account_id)->where('payer_fingerprint', $receipt->payer_fingerprint)->where('client_id', $selected->id)
            ->whereNull('cleared_at')->get(['id', 'version', 'reason', 'effective_at'])->all() : [];

        return ['match_exclusions' => $exclusions, 'payer' => $payer, 'history' => $history, 'suggestions' => $suggestions, 'recommended_client_id' => $recommended,
            'automatic_assessment' => app(CollectionJourneyAssessment::class)->forReceipt($receipt, $clients->keys()->map(fn ($id) => (int) $id)->all()),
            'needs_choice' => $conflict, 'rule_version' => $matching['rule_version'], 'receipt_version' => $receipt->version,
            'selected_client' => $selected ? $this->clientData($selected) : null, 'saved_choice' => $savedData,
            'recommended_intent' => count($valid) === 1 && $recommended === (int) $valid[0]->client_id ? ['id' => $valid[0]->id, 'deal_id' => $valid[0]->deal_id, 'purchase' => $valid[0]->purchase_snapshot] : null,
            'can_activate' => $intent !== null && ! $blocked && ! $receipt->allocation()->exists(), 'blocked_reasons' => $blocked,
            'workflow' => $manual ? 'manual' : 'legacy', 'manual_available' => in_array($receipt->account_id, config('collection_sales.verified_accounts', []), true),
            'usage' => app(CollectionReceiptStatusService::class)->forReceipts(collect([$receipt->loadMissing('allocation', 'sale')]), true)[$receipt->id],
            'capture_only' => ! $manual && $receipt->account->mode === 'shadow'] + $options;
    }

    public function selectable()
    {
        return app(CollectionPurchaseService::class)->selectableClients();
    }

    public function clientData(Client $client): array
    {
        return ['id' => $client->id, 'name' => $client->name, 'platform_id' => (int) $client->platform_id, 'wp_post_id' => (int) $client->wp_post_id, 'status' => $this->status[$client->id] ??= app(CollectionReceiptStatusService::class)->clients(collect([$client]))[$client->id]];
    }

    public function options(CollectionReceipt $receipt, User $actor, Client $client, bool $includeSubsidiary = true): array
    {
        app(CollectionPurchaseService::class)->assertScope($receipt->account, $client, $actor);
        $secondary = $includeSubsidiary ? config('collection.subsidiary_platforms.'.$client->platform_id) : null;
        $subsidiaries = collect();
        if ($secondary) {
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, (int) $secondary);
            // A full advert phone is a suggestion for a linked profile, not an inferred cross-site ID.
            if ($client->phone_normalized) {
                $subsidiaries = $this->selectable()->where('platform_id', $secondary)->where('phone_normalized', $client->phone_normalized)->limit(20)->get();
            }
        }

        return ['discount_policy' => app(CollectionSalesDiscountService::class)->availability((int) $client->platform_id),
            'prices' => ProductPrice::with('product:id,name,tier,platform_id')->where('is_active', true)->where('currency', $receipt->currency)
                ->whereHas('product', fn ($q) => $q->where('platform_id', $client->platform_id)->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('is_archived')->orWhere('is_archived', false)))->get(),
            'quotes' => Deal::where('client_id', $client->id)->whereIn('status', ['pending', 'active'])->where('currency', $receipt->currency)
                ->orderByDesc('id')->limit(60)->get(['id', 'client_id', 'product_price_id', 'plan_type', 'duration_days', 'amount', 'currency', 'discount_source', 'discount_approved_by', 'status']),
            'subsidiary_clients' => $this->clientRows($subsidiaries), 'subsidiary_platform_id' => $secondary ? (int) $secondary : null];
    }
}
