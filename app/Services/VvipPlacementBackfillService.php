<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One-time backfill: give CRM-sold VVIP profiles their WordPress homepage
 * campaign.
 *
 * WordPress calls a profile VVIP only when premium + featured AND an active
 * homepage campaign is linked to it. /activate sets just the VIP pair for a
 * `vvip` deal, so every VVIP sold through the CRM shows on the site as VIP.
 * The plugin's /clients/{id}/vvip-placement route (1.3.19) adds the campaign
 * through the theme's own wp-admin package code.
 *
 * Agencies are excluded: VVIP placement is for individual profiles only.
 */
class VvipPlacementBackfillService
{
    public function __construct(private ActiveSubscriptionProfileRepairService $deals) {}

    /**
     * Escort clients with an active, future VVIP deal and a WordPress profile.
     *
     * @return Collection<int, Client>
     */
    public function candidates(?int $platformId = null, ?int $clientId = null, int $limit = 500): Collection
    {
        $query = Client::query()
            ->with('platform')
            ->where('wp_post_id', '>', 0)
            ->where(fn (Builder $builder) => $builder
                ->whereNull('client_type')
                ->orWhere('client_type', '!=', 'agency'))
            ->whereHas('deals', fn ($deal) => $this->vvipDealScope($deal))
            ->orderBy('platform_id')
            ->orderBy('id');

        if ($platformId) {
            $query->where('platform_id', $platformId);
        }

        if ($clientId) {
            $query->whereKey($clientId);
        }

        return $query->limit(max(1, $limit))->get();
    }

    public function vvipDeal(Client $client): ?Deal
    {
        return $this->vvipDealScope($client->deals()->getQuery())
            ->orderByDesc('expires_at')
            ->first();
    }

    /**
     * The placement ends when the paid VVIP period does: the deal expiry at
     * market-local end of day, the same stamp the repair service publishes.
     */
    public function placementExpiry(Deal $deal, Client $client): int
    {
        return (int) $this->deals->repairColumnsForDeal($deal, $client)['featured_expire'];
    }

    /**
     * Ask WordPress what it would do (dry run) or apply the placement.
     *
     * @return array<string, mixed>
     */
    public function place(Client $client, Deal $deal, bool $dryRun): array
    {
        $response = WpSyncService::forPlatform((int) $client->platform_id)->setVvipPlacement(
            (int) $client->wp_post_id,
            [
                'expires_at' => $this->placementExpiry($deal, $client),
                'crm_deal_id' => (int) $deal->id,
                'dry_run' => $dryRun,
            ]
        );

        return $this->row($client, $deal, $response);
    }

    /**
     * Restore the `before` block a backup recorded for this profile.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    public function revert(int $platformId, int $wpPostId, array $before, bool $dryRun): array
    {
        return WpSyncService::forPlatform($platformId)->setVvipPlacement($wpPostId, [
            'mode' => 'revert',
            'restore' => $before,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function row(Client $client, Deal $deal, array $response): array
    {
        return [
            'platform_id' => (int) $client->platform_id,
            'market' => $client->platform?->name ?? (string) $client->platform_id,
            'client_id' => (int) $client->id,
            'wp_post_id' => (int) $client->wp_post_id,
            'name' => (string) $client->name,
            'deal_id' => (int) $deal->id,
            'deal_expires_at' => Carbon::parse($deal->expires_at)->toDateTimeString(),
            'action' => (string) ($response['action'] ?? 'unknown'),
            'reason' => $response['reason'] ?? null,
            'before' => $response['before'] ?? null,
            'after' => $response['after'] ?? null,
        ];
    }

    private function vvipDealScope($query)
    {
        return $this->deals->scopeFutureActiveDeals($query)->where('plan_type', 'vvip');
    }
}
