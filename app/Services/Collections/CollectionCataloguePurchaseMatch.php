<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Platform;
use App\Models\ProductPrice;
use App\Services\SubsidiaryWpIdentityLookup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** A separately approved policy permits one exact catalogue purchase, never an inferred discount. */
class CollectionCataloguePurchaseMatch
{
    public function prices(CollectionReceipt $receipt, Client $client)
    {
        return ProductPrice::with('product')->where('is_active', true)->where('currency', $receipt->currency)
            ->where('created_at', '<=', $receipt->provider_at)->where('updated_at', '<=', $receipt->provider_at)
            ->whereHas('product', fn ($q) => $q->where('platform_id', $client->platform_id)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('is_archived')->orWhere('is_archived', false))->whereIn('tier', ['basic', 'premium', 'vip']))
            ->get()->filter(fn ($price) => $price->duration_days > 0 && app(TzMpesaParser::class)->minor((string) $price->price) === $receipt->principal_minor)->values();
    }

    private function eligiblePrices(CollectionReceipt $receipt, Client $client)
    {
        $prices = $this->prices($receipt, $client);
        $policy = $receipt->account->policy ?? [];
        $key = $client->platform_id.':'.$receipt->currency.':'.$receipt->principal_minor;
        $defaults = $policy['catalogue_price_defaults'] ?? [];
        if (array_key_exists($key, $defaults)) {
            if (empty($policy['catalogue_price_defaults_approved_by']) || empty($policy['catalogue_price_defaults_approved_at'])
                || Carbon::parse($policy['catalogue_price_defaults_approved_at'])->gt($receipt->provider_at)) {
                throw new CollectionException('automatic_default_package_approval_required');
            }
            $prices = $prices->filter(fn ($price) => (int) $price->id === (int) $defaults[$key])->values();
            if ($prices->isEmpty()) {
                throw new CollectionException('approved_default_package_unavailable');
            }
        }

        return $prices;
    }

    /** Pure package assessment. No intent, audit, reservation or site request is created. */
    public function preview(CollectionReceipt $receipt, Client $client): array
    {
        if (! $receipt->provider_at) {
            throw new CollectionException('receipt_requires_finance_review');
        }
        $policy = $receipt->account->policy ?? [];
        if (($policy['purchase_matching'] ?? '') !== 'exact_catalogue_or_reviewed' || empty($policy['purchase_matching_approved_by'])
            || empty($policy['purchase_matching_approved_at']) || Carbon::parse($policy['purchase_matching_approved_at'])->gt($receipt->provider_at)) {
            throw new CollectionException('automatic_catalogue_matching_policy_required');
        }
        $prices = $this->eligiblePrices($receipt, $client);
        if ($prices->count() !== 1) {
            throw new CollectionException($prices->count() ? 'multiple_exact_package_matches' : 'no_exact_catalogue_package_match');
        }
        $price = $prices->first();

        return ['product_price_id' => $price->id, 'product_id' => $price->product_id, 'name' => $price->product->name,
            'tier' => $price->product->tier, 'duration_days' => $price->duration_days, 'duration_key' => $price->duration_key,
            'duration_label' => $price->duration_label, 'amount_minor' => $receipt->principal_minor, 'catalogue_minor' => $receipt->principal_minor, 'currency' => $receipt->currency,
            'approved_default' => isset($policy['catalogue_price_defaults'][$client->platform_id.':'.$receipt->currency.':'.$receipt->principal_minor])];
    }

    /** Call under the receiving-account lock after identity, age and rollout checks pass. */
    public function select(CollectionReceipt $receipt, int $clientId): CollectionPurchaseIntent
    {
        $policy = $receipt->account->policy ?? [];
        $client = app(CollectionPurchaseService::class)->selectableClients()->findOrFail($clientId);
        $preview = $this->preview($receipt, $client);
        $price = ProductPrice::with('product')->findOrFail($preview['product_price_id']);
        $secondary = null;
        if ($secondaryId = config('collection.subsidiary_platforms.'.$client->platform_id)) {
            if (! $client->phone_normalized) {
                throw new CollectionException('linked_subsidiary_identity_requires_review');
            }
            $target = Platform::findOrFail($secondaryId);
            $identity = app(SubsidiaryWpIdentityLookup::class)->find($target, ['phone_normalized' => $client->phone_normalized, 'email' => $client->email]);
            $linked = $identity ? app(CollectionPurchaseService::class)->selectableClients()->where('platform_id', $secondaryId)
                ->where('wp_post_id', $identity['wp_post_id'])->where('wp_user_id', $identity['wp_user_id'])->first() : null;
            if (! $linked) {
                throw new CollectionException('linked_subsidiary_identity_requires_review');
            }
            $secondary = ['client_id' => $linked->id, 'platform_id' => $linked->platform_id, 'wp_post_id' => $linked->wp_post_id, 'wp_user_id' => $linked->wp_user_id];
        }
        $snapshot = ['client_id' => $client->id, 'platform_id' => $client->platform_id, 'wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id,
            'product_id' => $price->product_id, 'product_price_id' => $price->id, 'tier' => $price->product->tier,
            'duration_days' => $price->duration_days, 'duration_key' => $price->duration_key, 'duration_label' => $price->duration_label,
            'amount_minor' => $receipt->principal_minor, 'catalogue_minor' => $receipt->principal_minor, 'currency' => $receipt->currency,
            'discount_approved_by' => null, 'discount_source' => null, 'original_amount' => null, 'discount_percentage' => null,
            'stacking' => 'same_tier', 'identity_authority' => 'rule_supported_payer', 'subsidiary' => $secondary,
            'catalogue_policy_version' => $policy['version'], 'catalogue_price_updated_at' => $price->updated_at->toIso8601String()];
        $intent = CollectionPurchaseIntent::create(['id' => (string) Str::uuid(), 'account_id' => $receipt->account_id, 'client_id' => $client->id,
            'platform_id' => $client->platform_id, 'authority' => 'matched_catalogue', 'provider_reference' => $receipt->reference_norm,
            'purchase_snapshot' => $snapshot, 'approved_by' => $policy['purchase_matching_approved_by'], 'approved_at' => $policy['purchase_matching_approved_at'],
            'valid_from' => $policy['purchase_matching_approved_at'], 'valid_until' => $receipt->provider_at->copy()->addSeconds($policy['max_auto_age_seconds'])->addSecond(), 'max_renewals' => 1]);
        app(CollectionPurchaseService::class)->audit($receipt, null, 'automatic_catalogue_purchase_selected', ['intent_id' => $intent->id,
            'purchase' => $snapshot, 'policy_version' => $policy['version'], 'policy_approved_by' => $policy['purchase_matching_approved_by'],
            'reason' => 'unique_current_exact_catalogue_price_after_supported_client_match']);

        return $intent;
    }

    public function assertCurrent(CollectionPurchaseIntent $intent, CollectionReceipt $receipt, Client $client): void
    {
        $policy = $receipt->account->policy ?? [];
        $prices = $this->eligiblePrices($receipt, $client);
        if (($policy['purchase_matching'] ?? '') !== 'exact_catalogue_or_reviewed'
            || (int) ($policy['purchase_matching_approved_by'] ?? 0) !== (int) $intent->approved_by
            || $intent->provider_reference !== $receipt->reference_norm || $prices->count() !== 1
            || (int) $prices->first()->id !== (int) $intent->purchase_snapshot['product_price_id']
            || (int) $intent->purchase_snapshot['amount_minor'] !== (int) $intent->purchase_snapshot['catalogue_minor']) {
            throw new CollectionException('catalogue_purchase_terms_changed');
        }
    }
}
