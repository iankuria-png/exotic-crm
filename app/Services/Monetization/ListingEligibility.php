<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\Deal;

class ListingEligibility
{
    public function facts(Client $client): array
    {
        $deals = Deal::where('client_id', $client->id)->currentlyActive()->whereNotNull('expires_at')->where('expires_at', '>', now())->get();
        $published = $client->profile_status === 'publish' && ! $client->lifecycle_archived_at && ! $client->closed_at;
        $legacy = ! Deal::where('client_id', $client->id)->exists() && (int) $client->escort_expire > now()->timestamp;
        $paid = $deals->first(fn ($d) => ! $d->is_free_trial && ! str_starts_with((string) $d->origin, 'seo_boost'));

        return ['listing_active' => $published && ($deals->isNotEmpty() || $legacy), 'paid_listing' => $published && (bool) $paid, 'deal_ids' => $deals->pluck('id')->all(), 'legacy_expiry' => $legacy ? (int) $client->escort_expire : null, 'held' => (bool) $client->is_high_risk || \App\Models\ClientMonetizationPass::where('client_id', $client->id)->where('status', 'held')->where('expires_at', '>', now())->exists()];
    }

    public function assertOwner(int $platformId, int $postId, int $userId): Client
    {
        $client = Client::where('platform_id', $platformId)->where('wp_post_id', $postId)->firstOrFail();
        abort_unless($userId > 0 && (int) $client->wp_user_id === $userId, 403, 'This profile does not belong to this account.');

        return $client;
    }
}
