<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Services\ClientSyncService;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $client = Client::where('platform_id', $platformId)->where('wp_post_id', $postId)->first();

        if (! $client) {
            try {
                $client = (new ClientSyncService(Platform::findOrFail($platformId)))->syncOne($postId);
            } catch (Throwable $exception) {
                Log::warning('Private-content owner profile sync is pending', [
                    'platform_id' => $platformId,
                    'wp_post_id' => $postId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                abort(503, 'Your profile setup is still syncing. Try again in a moment.');
            }
        }

        abort_unless($userId > 0 && (int) $client->wp_user_id === $userId, 403, 'This profile does not belong to this account.');

        return $client;
    }
}
