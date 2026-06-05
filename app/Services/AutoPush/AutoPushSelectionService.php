<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\Client;
use App\Models\ClientRetentionInsight;
use App\Models\Deal;
use App\Models\PushCampaignItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutoPushSelectionService
{
    /**
     * @return array{clients:\Illuminate\Support\Collection<int,\App\Models\Client>,bucket_counts:array<string,int>}
     */
    public function orderedCandidatesForPlan(AutoPushPlan $plan): array
    {
        $bucketCounts = [];
        $orderedClients = collect();
        $seen = [];

        foreach ((array) ($plan->buckets ?? []) as $bucket) {
            if (!(bool) ($bucket['enabled'] ?? false)) {
                continue;
            }

            $type = (string) ($bucket['type'] ?? '');
            $clients = match ($type) {
                'new_subscriptions' => $this->selectNewSubscriptions($plan),
                'subscription_tier' => $this->selectByTier($plan),
                'bottom_engagement' => $this->selectBottomEngagement($plan),
                default => collect(),
            };

            $bucketCounts[$type] = $clients->count();

            foreach ($clients as $client) {
                $clientId = (int) $client->id;
                if (isset($seen[$clientId])) {
                    continue;
                }

                $seen[$clientId] = true;
                $orderedClients->push($client);
            }
        }

        $excludeDays = max(0, (int) data_get($plan->reliability, 'exclude_pushed_within_days', 3));
        if ($excludeDays > 0) {
            $recentClientIds = PushCampaignItem::query()
                ->join('push_campaigns', 'push_campaigns.id', '=', 'push_campaign_items.campaign_id')
                ->where('push_campaigns.platform_id', (int) $plan->platform_id)
                ->whereNotNull('push_campaign_items.client_id')
                ->whereNotNull('push_campaign_items.sent_at')
                ->where('push_campaign_items.sent_at', '>=', now()->subDays($excludeDays))
                ->pluck('push_campaign_items.client_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            $orderedClients = $orderedClients
                ->reject(fn (Client $client) => $recentClientIds->has((int) $client->id))
                ->values();
        }

        return [
            'clients' => $orderedClients,
            'bucket_counts' => $bucketCounts,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    public function selectNewSubscriptions(AutoPushPlan $plan): Collection
    {
        $bucket = $this->bucketByType($plan, 'new_subscriptions');
        $lookbackHours = max(1, (int) data_get($bucket, 'params.lookback_hours', 48));
        $lifecycle = collect((array) data_get($bucket, 'params.lifecycle', ['new', 'renewal']))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        $limit = $this->bucketLimit($bucket);

        $rows = Deal::query()
            ->join('clients', 'clients.id', '=', 'deals.client_id')
            ->where('deals.platform_id', (int) $plan->platform_id)
            ->where('clients.platform_id', (int) $plan->platform_id)
            ->where('clients.client_type', 'escort')
            ->whereIn('deals.subscription_lifecycle', $lifecycle)
            ->whereNotNull('deals.activated_at')
            ->where('deals.activated_at', '>=', now()->subHours($lookbackHours))
            ->groupBy('clients.id')
            ->orderByRaw('MAX(deals.activated_at) DESC')
            ->limit($limit)
            ->pluck('clients.id');

        return $this->loadClientsInOrder($rows);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    public function selectByTier(AutoPushPlan $plan): Collection
    {
        $bucket = $this->bucketByType($plan, 'subscription_tier');
        $tiers = collect((array) data_get($bucket, 'params.tiers', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        $limit = $this->bucketLimit($bucket);

        if ($tiers === []) {
            return collect();
        }

        $rows = Deal::query()
            ->join('clients', 'clients.id', '=', 'deals.client_id')
            ->where('deals.platform_id', (int) $plan->platform_id)
            ->where('clients.platform_id', (int) $plan->platform_id)
            ->where('clients.client_type', 'escort')
            ->where('deals.status', 'active')
            ->whereIn('deals.plan_type', $tiers)
            ->groupBy('clients.id')
            ->orderByRaw('MAX(COALESCE(deals.activated_at, deals.created_at)) DESC')
            ->limit($limit)
            ->pluck('clients.id');

        return $this->loadClientsInOrder($rows);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    public function selectBottomEngagement(AutoPushPlan $plan): Collection
    {
        $bucket = $this->bucketByType($plan, 'bottom_engagement');
        $limit = $this->bucketLimit($bucket);

        $rows = ClientRetentionInsight::query()
            ->join('clients', 'clients.id', '=', 'client_retention_insights.client_id')
            ->where('client_retention_insights.platform_id', (int) $plan->platform_id)
            ->where('clients.platform_id', (int) $plan->platform_id)
            ->where('clients.client_type', 'escort')
            ->where('clients.profile_status', 'publish')
            ->where(function (Builder $query) {
                $query->whereNull('clients.needs_payment')->orWhere('clients.needs_payment', false);
            })
            ->where(function (Builder $query) {
                $query->whereNull('clients.notactive')->orWhere('clients.notactive', false);
            })
            ->whereExists(function ($query) use ($plan) {
                $query->select(DB::raw(1))
                    ->from('deals')
                    ->whereColumn('deals.client_id', 'clients.id')
                    ->where('deals.platform_id', (int) $plan->platform_id)
                    ->where('deals.status', 'active');
            })
            ->groupBy('clients.id')
            ->orderByRaw('MAX(client_retention_insights.score) DESC')
            ->limit($limit)
            ->pluck('clients.id');

        return $this->loadClientsInOrder($rows);
    }

    /**
     * @return array{primary:\Illuminate\Support\Collection<int,\App\Models\Client>,reserve:\Illuminate\Support\Collection<int,\App\Models\Client>,bucket_counts:array<string,int>}
     */
    public function selectForPlan(AutoPushPlan $plan): array
    {
        $ordered = $this->orderedCandidatesForPlan($plan);
        $orderedClients = $ordered['clients'];
        $bucketCounts = $ordered['bucket_counts'];

        $maxItems = max(1, (int) data_get($plan->schedule, 'max_items_per_day', 1));
        $reserveMultiplier = max(1.0, (float) data_get($plan->reliability, 'reserve_multiplier', 1.5));
        $primary = $orderedClients->take($maxItems)->values();
        $reserve = $orderedClients
            ->slice($primary->count(), (int) ceil($primary->count() * $reserveMultiplier))
            ->values();

        return [
            'primary' => $primary,
            'reserve' => $reserve,
            'bucket_counts' => $bucketCounts,
        ];
    }

    private function bucketByType(AutoPushPlan $plan, string $type): array
    {
        foreach ((array) ($plan->buckets ?? []) as $bucket) {
            if ((string) ($bucket['type'] ?? '') === $type) {
                return $bucket;
            }
        }

        return [];
    }

    private function bucketLimit(array $bucket): int
    {
        return max(1, (int) ($bucket['limit'] ?? 1));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int|string>  $clientIds
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    public function loadClientsInOrder(Collection $clientIds): Collection
    {
        $ids = $clientIds->map(fn ($id) => (int) $id)->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $clients = Client::query()
            ->whereIn('id', $ids->all())
            ->with('platform')
            ->get()
            ->keyBy('id');

        return $ids->map(fn (int $id) => $clients->get($id))
            ->filter()
            ->values();
    }
}
