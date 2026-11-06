<?php

namespace App\Services\Collections;

use App\Exceptions\CrmWalletOperationException;
use App\Models\Client;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\MarketAuthorizationService;

/** Advisory staff context only. Never calls evaluate(), reserve(), or an external provider. */
class CollectionReviewContextService
{
    public function context(CollectionReceipt $receipt, User $actor, array $input = []): array
    {
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        $payer = app(CollectionPayerEvidenceService::class)->forReceipt($receipt);
        $history = app(CollectionPaymentHistoryService::class)->forReceipt($receipt, $actor, $input['cursor'] ?? null);
        $platforms = $history['scope']['platform_ids'];
        $links = $receipt->payer_fingerprint ? CollectionPayerLink::where('account_id', $receipt->account_id)
            ->where('payer_fingerprint', $receipt->payer_fingerprint)->whereNotNull('reviewed_at')
            ->whereNull('revoked_at')->where('valid_until', '>', now())->get() : collect();
        $phoneClients = $receipt->payer_phone ? $this->selectable()->whereIn('platform_id', $platforms)->where('phone_normalized', $receipt->payer_phone)->get() : collect();
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
        $counts = $history['client_counts'];
        $ids = array_unique(array_merge(array_keys($counts), $links->pluck('client_id')->all(), $phoneClients->pluck('id')->all(), array_map(fn ($i) => $i->client_id, $valid)));
        $clients = $this->selectable()->whereIn('platform_id', $platforms)->whereIn('id', $ids)->get()->keyBy('id');
        $evidenceIds = [];
        $suggestions = [];
        $unresolved = count(array_diff($ids, $clients->keys()->all())) > 0;
        foreach ($clients as $client) {
            $score = 0;
            $reasons = [];
            $link = $links->first(fn ($l) => (int) $l->client_id === $client->id
                && (int) $l->wp_user_id === (int) $client->wp_user_id && (int) $l->wp_post_id === (int) $client->wp_post_id);
            if ($link) {
                $score = 95;
                $reasons[] = 'A staff member previously reviewed this payer for this profile.';
            }
            if ($count = ($counts[$client->id] ?? 0)) {
                $score = max($score, $count >= 3 ? 90 : 65);
                $reasons[] = $count.' prior cash '.($count === 1 ? 'payment is' : 'payments are').' linked to this profile.';
            }
            if ($phoneClients->contains('id', $client->id)) {
                $score = max($score, 80);
                $reasons[] = 'The full SMS payer phone matches this profile’s advert phone.';
            }
            if (count($valid) === 1 && (int) $valid[0]->client_id === $client->id) {
                $score = max($score, 95);
                $reasons[] = 'A current reviewed purchase names this profile for this receipt.';
            }
            if (! $score) {
                continue;
            }
            $evidenceIds[] = $client->id;
            $suggestions[] = $this->clientData($client) + ['score' => $score, 'reasons' => $reasons];
        }
        $conflict = count($evidenceIds) > 1 || $unresolved || $history['identity_conflict'] || ! $history['complete'] || ! $payer['complete'] || (bool) $receipt->conflicts;
        if ($conflict) {
            foreach ($suggestions as &$suggestion) {
                $suggestion['score'] = min(49, $suggestion['score']);
                $suggestion['reasons'][] = ! $history['complete'] ? 'History coverage is incomplete; choose the profile explicitly.' : 'The available identity evidence needs a staff choice.';
            }
            unset($suggestion);
        }
        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);
        $recommended = ! $conflict && count($suggestions) === 1 && $suggestions[0]['score'] >= 80 ? $suggestions[0]['id'] : null;
        $selected = ! empty($input['selected_client_id']) ? $this->selectable()->findOrFail($input['selected_client_id']) : ($recommended ? $clients[$recommended] : null);
        $options = $selected ? $this->options($receipt, $actor, $selected) : ['prices' => [], 'quotes' => [], 'subsidiary_clients' => [], 'subsidiary_platform_id' => null];
        $intent = null;
        if (! empty($input['intent_id'])) {
            $intent = CollectionPurchaseIntent::where('account_id', $receipt->account_id)->findOrFail($input['intent_id']);
            if ($intent->provider_reference !== $receipt->reference_norm) {
                throw new CollectionException('purchase_for_another_receipt', 422);
            }
            if ($selected && (int) $intent->client_id !== $selected->id) {
                throw new CollectionException('purchase_profile_binding_changed');
            }
        } elseif ($selected && ! empty($input['product_price_id'])) {
            $intent = app(CollectionPurchaseService::class)->prepare($receipt->account, $selected, $actor, $input + [
                'authority' => 'reviewed_quote', 'valid_until' => now()->addDays(30), 'provider_reference' => $receipt->reference_norm]);
        } elseif ($selected && count($valid) === 1 && (int) $valid[0]->client_id === $selected->id) {
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

        return ['payer' => $payer, 'history' => $history, 'suggestions' => $suggestions, 'recommended_client_id' => $recommended,
            'needs_choice' => $conflict, 'rule_version' => 'collection-review-v1', 'receipt_version' => $receipt->version,
            'selected_client' => $selected ? $this->clientData($selected) : null, 'saved_choice' => $savedData,
            'recommended_intent' => count($valid) === 1 && $recommended === (int) $valid[0]->client_id ? ['id' => $valid[0]->id, 'deal_id' => $valid[0]->deal_id, 'purchase' => $valid[0]->purchase_snapshot] : null,
            'can_activate' => $intent !== null && ! $blocked && ! $receipt->allocation()->exists(), 'blocked_reasons' => $blocked,
            'capture_only' => $receipt->account->mode === 'shadow'] + $options;
    }

    public function selectable()
    {
        return app(CollectionPurchaseService::class)->selectableClients();
    }

    public function clientData(Client $client): array
    {
        return ['id' => $client->id, 'name' => $client->name, 'platform_id' => (int) $client->platform_id, 'wp_post_id' => (int) $client->wp_post_id];
    }

    public function options(CollectionReceipt $receipt, User $actor, Client $client): array
    {
        app(CollectionPurchaseService::class)->assertScope($receipt->account, $client, $actor);
        $secondary = config('collection.subsidiary_platforms.'.$client->platform_id);
        $subsidiaries = collect();
        if ($secondary) {
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, (int) $secondary);
            // A full advert phone is a suggestion for a linked profile, not an inferred cross-site ID.
            if ($client->phone_normalized) {
                $subsidiaries = $this->selectable()->where('platform_id', $secondary)->where('phone_normalized', $client->phone_normalized)->limit(20)->get();
            }
        }

        return ['prices' => ProductPrice::with('product:id,name,tier,platform_id')->where('is_active', true)->where('currency', $receipt->currency)
            ->whereHas('product', fn ($q) => $q->where('platform_id', $client->platform_id)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('is_archived')->orWhere('is_archived', false)))->get(),
            'quotes' => Deal::where('client_id', $client->id)->whereIn('status', ['pending', 'active'])->where('currency', $receipt->currency)
                ->orderByDesc('id')->limit(60)->get(['id', 'client_id', 'product_price_id', 'plan_type', 'duration_days', 'amount', 'currency', 'discount_source', 'discount_approved_by']),
            'subsidiary_clients' => $subsidiaries->map(fn ($c) => $this->clientData($c)), 'subsidiary_platform_id' => $secondary ? (int) $secondary : null];
    }
}
