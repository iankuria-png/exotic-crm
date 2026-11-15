<?php

namespace App\Services\PayLink;

use App\Models\{Client, Platform, ProductPrice};
use App\Services\{DealPaymentService, WpSyncFactory};

class EligibilityService
{
    public function price(Platform $p, int $id): ProductPrice
    {
        $price = ProductPrice::with('product')->whereKey($id)->where('is_active', true)->where('price', '>', 0)->first();
        $product = $price?->product;
        abort_unless($product && (int) $product->platform_id === (int) $p->id && $product->is_active && $product->is_public && !$product->is_archived
            && strtoupper($price->currency) === strtoupper($p->currency_code) && $price->duration_days >= 1 && $price->duration_days <= 365
            && preg_match('/^[0-9]+\.00$/D', (string) $price->price), 422, 'This catalogue plan is unavailable.');
        return $price;
    }

    public function snapshot(Client $c): array
    {
        abort_unless($c->platform?->is_active && $c->client_type === 'escort' && $c->wp_post_id > 0 && !$c->closed_at
            && !$c->profile_paused_at && !$c->profile_removal_requested_at && !$c->profile_reverted_at
            && !$c->is_high_risk && !in_array($c->lifecycle_state, ['archived', 'removed', 'paused', 'closed', 'reverted'], true), 409, 'This profile needs a team check before renewing.');
        $state = app(WpSyncFactory::class)->forPlatform($c->platform_id)->payLinkState($c->wp_post_id);
        abort_unless(($state['version'] ?? 0) === 1 && ($state['writers_ready'] ?? false) && ($state['eligible'] ?? false)
            && (int) ($state['post_id'] ?? 0) === (int) $c->wp_post_id && (int) ($state['wp_user_id'] ?? 0) === (int) $c->wp_user_id, 409, 'This profile needs a team check before renewing.');
        return $state;
    }

    public function offers(Platform $p, array $configured, array $state): array
    {
        $out = [];
        foreach ($configured as $offer) {
            try { $price = $this->price($p, (int) $offer['product_price_id']); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() === 422) continue; throw $e; }
            $tier = app(DealPaymentService::class)->derivePlanTypeFromProduct($price->product);
            $expiry = max(now()->timestamp, (int) ($state['escort_expire'] ?? 0));
            $out[] = ['product_price_id' => $price->id, 'product_id' => $price->product_id, 'tier' => $tier,
                'label' => $price->product->display_name ?: $price->product->name, 'days' => $price->duration_days,
                'amount' => (string) $price->price, 'currency' => strtoupper($price->currency),
                'duration_label' => $price->duration_label, 'featured' => (bool) ($offer['featured'] ?? false), 'badge' => $offer['badge'] ?? null,
                'locked' => ($state['live'] ?? false) && ($state['current_tier'] ?? '') !== $tier,
                'estimated_until' => \Carbon\Carbon::createFromTimestamp($expiry, $p->timezone ?: 'Africa/Nairobi')->addDays($price->duration_days)->toIso8601String()];
        }
        return $out;
    }
}
