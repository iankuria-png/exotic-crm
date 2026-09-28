<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentOffer;
use App\Models\VisitorContentPurchase;
use Illuminate\Support\Facades\DB;

class AssetLifecycleService
{
    public function change(Client $client, string $publicId, string $action, bool $confirmed): array
    {
        return DB::transaction(function () use ($client, $publicId, $action, $confirmed) {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $asset = PremiumContentAsset::where('platform_id', $client->platform_id)->where('client_id', $client->id)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $offers = PremiumContentOffer::where('client_id', $client->id)->whereHas('assets', fn ($q) => $q->where('premium_content_assets.id', $asset->id))->orderBy('id')->lockForUpdate()->get();
            $buyers = VisitorContentPurchase::where('platform_id', $client->platform_id)->whereIn('status', ['active', 'review', 'pending_payment'])->get()->filter(fn ($p) => collect($p->entitlement_snapshot_json)->contains('public_id', $publicId))->count();
            abort_if($action === 'delete' && $buyers > 0, 409, 'People have purchased this item or have a payment pending. Remove it from sale instead.');
            abort_if($action === 'public' && $buyers > 0 && ! $confirmed, 409, 'Buyers paid for this item. Confirm that you want to make a public copy.');
            $minimum = (int) data_get(app(\App\Services\MonetizationSettingsService::class)->forPlatform($client->platform)->offer_policy_json, 'bundle_min_items', 2);
            abort_if($offers->contains(fn ($o) => $o->kind === 'bundle' && $o->status === 'live' && $o->assets()->count() - 1 < $minimum), 409, 'Removing this item would leave a live bundle below its minimum. Edit or pause that bundle first.');
            foreach ($offers as $offer) {
                if ($offer->kind === 'bundle') {
                    $offer->assets()->detach($asset->id);
                    $offer->increment('version');
                } else {
                    $offer->update(['status' => 'retired', 'paused_by' => 'owner', 'version' => $offer->version + 1]);
                }
            }
            // Retain protected bytes for existing entitlements, even after a public copy is made.
            if ($action === 'delete') {
                $asset->update(['status' => 'deleted', 'deleted_at' => now()]);
            }
            if ($action === 'public') {
                $asset->update(['status' => 'public']);
            }

            return ['allowed' => true, 'buyers' => $buyers, 'action' => $action, 'public_id' => $asset->public_id];
        });
    }
}
