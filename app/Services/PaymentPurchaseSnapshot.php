<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Product;

class PaymentPurchaseSnapshot
{
    public function pin(Payment $p): void
    {
        if (! in_array($p->purpose, [null, 'subscription'], true)) {
            return;
        }
        $data = $p->payment_data ?? [];
        $v = request()->attributes->get('recovery_verified_beneficiary');
        $trusted = is_array($v) && (int) ($v['platform_id'] ?? 0) === (int) $p->platform_id && (int) ($v['wp_user_id'] ?? 0) === (int) $p->user_id;
        $client = $p->client_id ? \App\Models\Client::find($p->client_id) : null;
        $product = $p->product_id ? Product::find($p->product_id) : null;
        $tier = strtolower((string) ($product?->tier ?: $product?->name));
        $days = (int) ($data['duration_days'] ?? 0);
        $data['recovery_purchase'] = ['version' => 1, 'beneficiary_assurance' => $trusted ? 'wp_session_verified' : 'legacy_unverified',
            'platform_id' => (int) $p->platform_id, 'wp_user_id' => (int) $p->user_id, 'wp_post_id' => $trusted ? (int) $v['wp_post_id'] : (int) ($p->escort_post_id ?: $client?->wp_post_id),
            'product_id' => (int) $p->product_id, 'product_price_id' => $data['product_price_id'] ?? null, 'duration_days' => $days,
            'checkout_request_key' => request()->attributes->get('recovery_checkout_request_key'),
            'tier' => $tier, 'amount' => (string) $p->amount, 'currency' => $p->currency, 'pinned_at' => now()->toIso8601String()];
        if ($trusted) {
            $p->escort_post_id = (int) $v['wp_post_id'];
        }
        $p->payment_data = $data;
    }
}
