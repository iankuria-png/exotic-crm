<?php

namespace App\Services\Monetization;

use App\Models\ContentMonetizationSetting;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Models\VisitorContentPurchase;
use App\Services\MonetizationSettingsService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class AccessService
{
    public function device(ContentMonetizationSetting $s, string $proof): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $proof), 403, 'A valid device cookie is required.');

        return hash_hmac('sha256', $proof, $s->device_pepper);
    }

    public function bind(VisitorContentPurchase $p, string $hash, int $limit): bool
    {
        return DB::transaction(function () use ($p, $hash, $limit) {
            VisitorContentPurchase::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $q = DB::table('premium_content_purchase_devices')->where('purchase_id', $p->id);
            (clone $q)->where('last_used_at', '<', now()->subDays(90))->delete();
            if (! (clone $q)->where('device_hash', $hash)->exists() && (clone $q)->count() >= $limit) {
                return false;
            }
            DB::table('premium_content_purchase_devices')->updateOrInsert(['purchase_id' => $p->id, 'device_hash' => $hash], ['last_used_at' => now(), 'updated_at' => now(), 'created_at' => now()]);

            return true;
        });
    }

    public function assertDevice(VisitorContentPurchase $p, string $hash): void
    {
        $q = DB::table('premium_content_purchase_devices')->where('purchase_id', $p->id)->where('device_hash', $hash);
        abort_unless($q->exists(), 403, 'Restore this purchase on this device.');
        $q->update(['last_used_at' => now()]);
    }

    public function entitlements(Platform $platform, string $hash): array
    {
        return VisitorContentPurchase::where('platform_id', $platform->id)->whereIn('status', ['active', 'refunded', 'revoked'])->whereIn('id', DB::table('premium_content_purchase_devices')->where('device_hash', $hash)->select('purchase_id'))->latest('id')->limit(200)->get()->map(fn ($p) => $this->present($p))->all();
    }

    public function present(VisitorContentPurchase $p): array
    {
        return ['public_id' => $p->public_id, 'status' => $p->status, 'amount' => $p->gross_amount, 'currency' => $p->currency, 'kind' => $p->offer_kind, 'assets' => $p->entitlement_snapshot_json, 'is_sandbox' => $p->is_sandbox];
    }

    public function restore(Platform $platform, string $phone, string $hash, string $ip): array
    {
        $s = app(MonetizationSettingsService::class)->forPlatform($platform);
        $normalized = PhoneNormalizer::normalize($phone, (string) $platform->phone_prefix);
        $phoneHash = hash_hmac('sha256', (string) $normalized, $s->device_pepper);
        foreach (['phone:'.$phoneHash, 'device:'.$hash, 'ip:'.$ip] as $suffix) {
            $key = 'pc-restore:'.$platform->id.':'.$suffix;
            abort_if(RateLimiter::tooManyAttempts($key, (int) data_get($s->checkout_policy_json, 'restore_per_hour', 5)), 429, 'Please wait before restoring again.');
            RateLimiter::hit($key, 3600);
        }
        $full = false;
        foreach (VisitorContentPurchase::where('platform_id', $platform->id)->where('visitor_phone_hash', $phoneHash)->where('status', 'active')->get() as $p) {
            if (! $this->bind($p, $hash, (int) data_get($s->checkout_policy_json, 'device_slots', 3))) {
                $full = true;
            }
        }

        return ['entitlements' => $this->entitlements($platform, $hash), 'device_limit_reached' => $full];
    }

    public function grant(ContentMonetizationSetting $s, PremiumContentAsset $asset, ?VisitorContentPurchase $purchase, ?string $device, ?int $staff = null): array
    {
        abort_unless($asset->platform_id === $s->platform_id && in_array($asset->status, $staff ? ['ready', 'public', 'held'] : ['ready', 'public'], true), 410, 'This item is unavailable. Contact support.');
        if (! $staff) {
            abort_unless($purchase && $purchase->platform_id === $s->platform_id && $purchase->status === 'active', 410, 'This purchase is no longer available.');
            $this->assertDevice($purchase, $device);
            $snapshot = collect($purchase->entitlement_snapshot_json)->firstWhere('public_id', $asset->public_id);
            abort_unless($snapshot && hash_equals($snapshot['content_fingerprint'], $asset->content_fingerprint), 403, 'This item is not in your purchase.');
        }
        if ($purchase) {
            $purchase->increment('view_count');
            $purchase->update(['last_viewed_at' => now()]);
        }
        $ttl = $staff ? 120 : (int) data_get($s->delivery_policy_json, 'grant_ttl', 300);
        $claims = ['type' => $staff ? 'staff' : 'purchase', 'market' => $s->platform_id, 'asset' => $asset->public_id, 'purchase' => $purchase?->public_id, 'device' => $device, 'fingerprint' => $asset->content_fingerprint, 'exp' => now()->timestamp + $ttl, 'staff' => $staff];
        $body = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $grant = $body.'.'.hash_hmac('sha256', $body, $s->grant_secret);
        $url = rtrim($s->platform->wp_api_url ?: 'https://'.$s->platform->domain.'/wp-json', '/');
        $url = preg_replace('~/exotic-crm-sync/v1$~', '', $url);

        return ['delivery_url' => $url.'/exotic-crm-sync/v1/premium-content/assets/'.$asset->public_id.'?grant='.$grant, 'expires_in' => $ttl];
    }
}
