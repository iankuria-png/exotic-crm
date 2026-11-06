<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\DealPaymentService;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionPurchaseService
{
    public function reviewPayer(CollectionReceipt $receipt, Client $client, User $actor, bool $allowAuto, $validUntil): CollectionPayerLink
    {
        $this->assertScope($receipt->account, $client, $actor);
        if (! $receipt->payer_phone || $receipt->conflicts || in_array($receipt->funds_state, ['disputed', 'reversed'])) {
            throw new CollectionException('complete_unconflicted_payer_required', 422);
        }

        return DB::transaction(function () use ($receipt, $client, $actor, $allowAuto, $validUntil) {
            CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
            $link = CollectionPayerLink::create(['id' => (string) Str::uuid(), 'account_id' => $receipt->account_id,
                'payer_phone' => $receipt->payer_phone, 'payer_fingerprint' => $receipt->payer_fingerprint,
                'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id,
                'auto_allowed' => $allowAuto, 'evidence_receipt_ids' => [$receipt->id], 'reviewed_by' => $actor->id,
                'reviewed_at' => now(), 'valid_until' => $validUntil]);
            $this->audit($receipt, $actor->id, 'payer_relationship_reviewed', ['link_id' => $link->id, 'client_id' => $client->id, 'auto_allowed' => $allowAuto]);

            return $link;
        });
    }

    /** Capture exact existing pricing authority; never invent a duration or approve a new discount here. */
    public function create(CollectionAccount $account, Client $client, User $actor, array $input): CollectionPurchaseIntent
    {
        $this->assertScope($account, $client, $actor);
        $standing = ($input['authority'] ?? '') === 'standing_agreement';
        if ($standing) {
            app(MarketAuthorizationService::class)->ensureManager($actor);
        }
        $price = app(DealPaymentService::class)->resolveScopedProductPrice((int) $input['product_price_id'],
            app(DealPaymentService::class)->resolveScopedProduct((int) ProductPrice::findOrFail($input['product_price_id'])->product_id, $client->platform_id));
        $product = $price->product;
        $deal = ! empty($input['deal_id']) ? Deal::where('client_id', $client->id)->where('platform_id', $client->platform_id)->findOrFail($input['deal_id']) : null;
        $minor = app(TzMpesaParser::class)->minor((string) ($deal?->amount ?? $price->price));
        $catalogueMinor = app(TzMpesaParser::class)->minor((string) $price->price);
        if (! $price->is_active || ! $product->is_active || $product->is_archived || $minor <= 0 || $price->duration_days <= 0
            || ! in_array($product->tier, ['basic', 'premium', 'vip', 'vvip'], true)
            || $price->currency !== $account->currency
            || ($deal && ((int) $deal->product_price_id !== (int) $price->id || (int) $deal->duration_days !== (int) $price->duration_days
                || $deal->plan_type !== $product->tier || $deal->currency !== $price->currency || ! in_array($deal->status, ['pending', 'active'])))) {
            throw new CollectionException('purchase_terms_incompatible', 422);
        }
        if ($minor !== $catalogueMinor && (! $deal?->discount_approved_by || ! $deal?->discount_source || ! $deal?->original_amount)) {
            throw new CollectionException('existing_discount_authority_required', 422);
        }
        if ($standing && $minor !== $catalogueMinor && ! data_get($account->policy, 'recurring_negotiated_enabled', false)) {
            throw new CollectionException('recurring_negotiated_policy_disabled', 422);
        }
        $link = ! empty($input['payer_link_id']) ? CollectionPayerLink::where('account_id', $account->id)->where('client_id', $client->id)->findOrFail($input['payer_link_id']) : null;
        if ($standing && (! $link || ! $link->auto_allowed || $link->revoked_at || $link->valid_until->lte(now()))) {
            throw new CollectionException('reviewed_current_payer_required', 422);
        }
        $subsidiary = null;
        $subsidiaryPlatform = config('collection.subsidiary_platforms.'.$client->platform_id);
        if ($subsidiaryPlatform) {
            $subsidiary = Client::where('platform_id', $subsidiaryPlatform)->findOrFail($input['subsidiary_client_id'] ?? 0);
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $subsidiaryPlatform);
            if (! $subsidiary->wp_post_id || ! $subsidiary->wp_user_id) {
                throw new CollectionException('subsidiary_profile_binding_required', 422);
            }
        }
        $snapshot = ['client_id' => $client->id, 'platform_id' => $client->platform_id, 'wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id,
            'product_id' => $product->id, 'product_price_id' => $price->id, 'tier' => $product->tier,
            'duration_days' => $price->duration_days, 'duration_key' => $price->duration_key, 'duration_label' => $price->duration_label,
            'amount_minor' => $minor, 'currency' => $price->currency, 'catalogue_minor' => $catalogueMinor,
            'discount_approved_by' => $deal?->discount_approved_by, 'discount_source' => $deal?->discount_source,
            'original_amount' => $deal?->original_amount, 'discount_percentage' => $deal?->discount_percentage,
            'stacking' => 'same_tier', 'identity_authority' => $link ? 'reviewed_payer' : 'staff_reviewed_quote',
            'subsidiary' => $subsidiary ? ['client_id' => $subsidiary->id, 'platform_id' => $subsidiary->platform_id, 'wp_post_id' => $subsidiary->wp_post_id, 'wp_user_id' => $subsidiary->wp_user_id] : null];

        return DB::transaction(function () use ($account, $client, $actor, $input, $snapshot, $standing, $link, $deal) {
            $intent = CollectionPurchaseIntent::create(['id' => (string) Str::uuid(), 'account_id' => $account->id, 'client_id' => $client->id, 'platform_id' => $client->platform_id,
                'payer_link_id' => $link?->id, 'authority' => $standing ? 'standing_agreement' : 'reviewed_quote', 'provider_reference' => $input['provider_reference'] ?? null,
                'purchase_snapshot' => $snapshot, 'deal_id' => $standing ? null : $deal?->id, 'payment_id' => $standing ? null : $deal?->payment_id,
                'approved_by' => $actor->id, 'approved_at' => now(), 'valid_from' => now(), 'valid_until' => $input['valid_until'], 'max_renewals' => $standing ? ($input['max_renewals'] ?? 1) : 1]);
            DB::table('collection_audit')->insert(['account_id' => $account->id, 'actor_id' => $actor->id, 'action' => 'purchase_authority_reviewed', 'context' => json_encode(['intent_id' => $intent->id, 'purchase' => $snapshot]), 'created_at' => now()]);

            return $intent;
        });
    }

    public function validate(CollectionPurchaseIntent $intent, CollectionReceipt $receipt, bool $automatic, bool $reserved = false): Client
    {
        $p = $intent->purchase_snapshot;
        $client = Client::findOrFail($intent->client_id);
        $price = ProductPrice::with('product')->find($p['product_price_id']);
        if (! in_array($intent->authority, ['standing_agreement', 'reviewed_quote'], true)
            || ! in_array($p['identity_authority'] ?? '', ['reviewed_payer', 'staff_reviewed_quote'], true)
            || ($p['stacking'] ?? '') !== 'same_tier') {
            throw new CollectionException('reviewed_purchase_authority_required');
        }
        if ($intent->account_id !== $receipt->account_id || $intent->revoked_at || ! $intent->approved_at
            || ($automatic && $intent->valid_from->gt($receipt->provider_at)) || $intent->valid_until->lte(now()) || $intent->valid_until->lt($receipt->provider_at)
            || (! $reserved && $intent->used_count >= $intent->max_renewals)
            || (int) $client->platform_id !== (int) $intent->platform_id || (int) $client->wp_post_id !== (int) $p['wp_post_id'] || (int) $client->wp_user_id !== (int) $p['wp_user_id']
            || ! $client->wp_post_id || ! $client->wp_user_id || $client->profile_removal_requested_at || $client->profile_paused_at || $client->closed_at || $client->profile_reverted_at || $client->is_high_risk
            || in_array($client->lifecycle_state, ['archived', 'removed']) || ! $price || ! $price->is_active || ! $price->product?->is_active || $price->product->is_archived
            || $price->product->tier !== $p['tier'] || (int) $price->duration_days !== (int) $p['duration_days']
            || (int) $price->product_id !== (int) $p['product_id'] || $price->currency !== $p['currency']
            || (int) $p['amount_minor'] !== $receipt->principal_minor || $p['currency'] !== $receipt->currency) {
            throw new CollectionException('purchase_authority_stale_or_incompatible', 422);
        }
        if (! DB::table('collection_account_platforms')->where('account_id', $receipt->account_id)->where('platform_id', $client->platform_id)
            ->where('valid_from', '<=', $receipt->provider_at)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $receipt->provider_at))->exists()) {
            throw new CollectionException('destination_binding_missing', 422);
        }
        if ($intent->deal_id) {
            $quote = Deal::find($intent->deal_id);
            if (! $quote || (int) $quote->client_id !== (int) $client->id || ! in_array($quote->status, ['pending', 'active'])
                || (int) $quote->product_price_id !== (int) $p['product_price_id'] || (int) $quote->duration_days !== (int) $p['duration_days']
                || app(TzMpesaParser::class)->minor((string) $quote->amount) !== (int) $p['amount_minor'] || $quote->currency !== $p['currency']
                || (string) $quote->discount_approved_by !== (string) ($p['discount_approved_by'] ?? '')
                || (string) $quote->discount_source !== (string) ($p['discount_source'] ?? '')) {
                throw new CollectionException('quote_changed_since_purchase_review');
            }
        }
        if ($intent->authority === 'standing_agreement' && (int) $p['amount_minor'] !== (int) $p['catalogue_minor']
            && (! data_get($receipt->account->policy, 'recurring_negotiated_enabled') || empty($p['discount_approved_by']) || empty($p['discount_source']))) {
            throw new CollectionException('recurring_negotiated_policy_disabled');
        }
        if ($automatic) {
            $ageLimit = data_get($receipt->account->policy, 'max_auto_age_seconds');
            if (! is_int($ageLimit) || $ageLimit <= 0 || ! $receipt->provider_at || $receipt->provider_at->lt(now()->subSeconds($ageLimit))) {
                throw new CollectionException('automatic_receipt_time_window_requires_review');
            }
            $links = CollectionPayerLink::where('account_id', $receipt->account_id)->where('payer_fingerprint', $receipt->payer_fingerprint)
                ->whereNull('revoked_at')->where('valid_until', '>', now())->get();
            if (! $receipt->payer_fingerprint || $links->pluck('client_id')->unique()->count() !== 1
                || ! $links->contains(fn ($link) => $link->id === $intent->payer_link_id && $link->auto_allowed
                    && (int) $link->client_id === (int) $client->id && (int) $link->wp_post_id === (int) $client->wp_post_id && (int) $link->wp_user_id === (int) $client->wp_user_id)) {
                throw new CollectionException('reviewed_unique_payer_required');
            }
        }

        return $client;
    }

    public function assertScope(CollectionAccount $account, Client $client, User $actor): void
    {
        $auth = app(MarketAuthorizationService::class);
        $auth->ensureRole($actor, ['admin', 'sub_admin', 'sales']);
        $auth->ensureUserCanAccessPlatform($actor, (int) $client->platform_id);
        if (! DB::table('collection_account_platforms')->where('account_id', $account->id)->where('platform_id', $client->platform_id)->whereNull('valid_until')->exists()) {
            throw new CollectionException('client_outside_receiving_account', 403);
        }
    }

    public function audit(CollectionReceipt $receipt, ?int $actor, string $action, array $context, ?string $key = null): void
    {
        DB::table('collection_audit')->insertOrIgnore(['receipt_id' => $receipt->id, 'account_id' => $receipt->account_id, 'actor_id' => $actor,
            'action' => $action, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'dedupe_key' => $key, 'created_at' => now()]);
    }
}
