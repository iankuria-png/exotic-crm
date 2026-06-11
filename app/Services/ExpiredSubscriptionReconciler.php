<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\CrmClientChurnReason;
use App\Support\MarketTimezone;
use App\Support\SubscriptionExpiry;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Single source of truth for force-expiring a CRM profile whose synced WordPress
 * expiry (escort_expire) has passed while it is still publicly active.
 *
 * Shared by the daily reconciliation command (crm:reconcile-expired-subscriptions)
 * and the manual "Expire now" action so both behave identically. This is the
 * natural-expiry sibling of ClientSubscriptionDeactivationService::deactivate
 * (deal lands as `expired`, not `cancelled`, and no payment is mutated).
 */
class ExpiredSubscriptionReconciler
{
    /**
     * Resolve the market timezone for a client's platform.
     */
    public function timezoneFor(Client $client): string
    {
        return MarketTimezone::resolve($client->platform?->timezone, config('app.timezone', 'UTC'));
    }

    /**
     * Whether this client is "stuck": still publicly active but past its
     * timezone-aware expiry cutoff.
     */
    public function isStuck(Client $client): bool
    {
        if ((string) $client->profile_status !== 'publish') {
            return false;
        }

        if ($client->needs_payment || $client->notactive) {
            return false;
        }

        return SubscriptionExpiry::isExpired((int) $client->escort_expire, $this->timezoneFor($client));
    }

    /**
     * Find publicly-active clients whose escort_expire cutoff is in the past.
     *
     * @return Collection<int, Client>
     */
    public function findStuck(?int $platformId, int $limit): Collection
    {
        $query = Client::query()
            ->with('platform')
            ->active() // profile_status=publish AND not needs_payment AND not notactive
            ->whereNotNull('escort_expire')
            ->where('escort_expire', '>', 0)
            // Raw "< now" is a necessary condition (end-of-day grace only moves the
            // cutoff later), so this safely narrows the set before the precise check.
            ->where('escort_expire', '<', now()->timestamp)
            ->orderBy('escort_expire');

        if ($platformId) {
            $query->forPlatform($platformId);
        }

        // Fetch a bounded superset, then apply the precise per-market cutoff.
        $candidates = $query->limit(max($limit * 2, $limit + 50))->get();

        return $candidates
            ->filter(fn (Client $client) => $this->isStuck($client))
            ->take($limit)
            ->values();
    }

    /**
     * Reconcile a batch of (already authorized) clients. Each is guarded by
     * isStuck, so ineligible clients are skipped with no writes; per-client
     * failures are isolated so one bad profile cannot abort the batch.
     *
     * @param  Collection<int, Client>  $clients
     * @return array{results: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function reconcileMany(Collection $clients, ?int $actorId): array
    {
        $results = [];
        $summary = ['total' => 0, 'expired' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($clients as $client) {
            $summary['total']++;

            try {
                $row = $this->reconcileClient($client, $actorId, false);
                $results[] = $row;

                if (($row['action'] ?? null) === 'deactivated') {
                    $summary['expired']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                $results[] = [
                    'client_id' => (int) $client->id,
                    'action' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['results' => $results, 'summary' => $summary];
    }

    /**
     * Reconcile one client. Returns a result row describing what happened (or,
     * in dry-run, what would happen). Throws on WordPress / sync failure so the
     * caller can tally it.
     *
     * @return array<string, mixed>
     */
    public function reconcileClient(Client $client, ?int $actorId, bool $dryRun): array
    {
        $client->loadMissing('platform');
        $tz = $this->timezoneFor($client);
        $escortExpire = (int) $client->escort_expire;

        $row = [
            'client_id' => (int) $client->id,
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'platform_id' => (int) $client->platform_id,
            'market' => $client->platform?->name ?? (string) $client->platform_id,
            'name' => (string) $client->name,
            'escort_expire' => $escortExpire,
            'cutoff' => SubscriptionExpiry::effectiveCutoff($escortExpire, $tz),
            'action' => 'skipped',
        ];

        if (! $this->isStuck($client)) {
            $row['action'] = 'not_expired';

            return $row;
        }

        if ($dryRun) {
            $row['action'] = 'would_deactivate';

            return $row;
        }

        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new \InvalidArgumentException('Client is not linked to a WordPress profile.');
        }

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);

        // 1. Authoritative WP deactivation (sets private, clears expiry meta).
        WpSyncService::forPlatform((int) $client->platform_id)->deactivateClient($wpPostId);

        // 2. Refresh the CRM mirror immediately (no 15-min sync lag).
        $syncedClient = (new ClientSyncService($platform))->syncOne($wpPostId);

        // 3. Flip any still-active deal to expired (natural expiry).
        $expiredDeals = Deal::query()
            ->where('client_id', (int) $client->id)
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        app(ClientChurnStamper::class)->stamp(
            $syncedClient,
            CrmClientChurnReason::EXPIRED_UNRENEWED,
            'profile_inactive',
            now(),
        );

        // 4. Audit trail parity with the manual deactivation flow.
        TimelineEvent::create([
            'platform_id' => (int) $syncedClient->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $syncedClient->id,
            'event_type' => 'profile_deactivated',
            'actor_id' => $actorId,
            'content' => [
                'deal_id' => null,
                'reason' => $actorId ? 'manual_expire_now' : 'auto_expiry_reconciliation',
                'deactivation_scope' => 'expired_subscription_reconcile',
                'escort_expire' => $escortExpire,
                'cutoff' => $row['cutoff'],
                'deals_expired' => $expiredDeals,
            ],
            'created_at' => now(),
        ]);

        $row['action'] = 'deactivated';
        $row['deals_expired'] = $expiredDeals;

        return $row;
    }
}
