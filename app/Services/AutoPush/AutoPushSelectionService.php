<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\Client;
use App\Models\ClientRetentionInsight;
use App\Models\Deal;
use App\Models\PushCampaignItem;
use App\Services\MarketAuthorizationService;
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
                'signup_source' => $this->selectBySignupSource($plan),
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
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    public function selectBySignupSource(AutoPushPlan $plan): Collection
    {
        $bucket = $this->bucketByType($plan, 'signup_source');
        $sources = collect((array) data_get($bucket, 'params.sources', ['field']))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
        $limit = $this->bucketLimit($bucket);

        if ($sources->isEmpty()) {
            return collect();
        }

        $includesField = $sources->contains('field');
        $directSources = $sources->reject(fn (string $source) => $source === 'field')->values()->all();

        $rows = Client::query()
            ->where('platform_id', (int) $plan->platform_id)
            ->where('client_type', 'escort')
            ->active()
            ->where(function (Builder $query) use ($includesField, $directSources) {
                if ($directSources !== []) {
                    $query->whereIn('signup_source', $directSources);
                }

                if ($includesField) {
                    $method = $directSources === [] ? 'where' : 'orWhere';
                    $query->{$method}(function (Builder $fieldQuery) {
                        $fieldQuery->where('signup_source', 'field')
                            ->orWhereHas('creator', fn ($creatorQuery) => $creatorQuery->where('role', MarketAuthorizationService::ROLE_FIELD_SALES));
                    });
                }
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('id');

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

        // Boosted clients (sales force-prioritised) jump to the front and bypass
        // the recent-push exclusion — the whole point of a boost is "push now".
        $boosted = $this->selectBoosted($plan);
        if ($boosted->isNotEmpty()) {
            $boostedIds = $boosted->map(fn (Client $client) => (int) $client->id)->flip();
            $orderedClients = $boosted
                ->concat($orderedClients->reject(fn (Client $client) => $boostedIds->has((int) $client->id)))
                ->values();
            $bucketCounts['boosted'] = $boosted->count();
        }

        $maxItems = max(1, (int) data_get($plan->schedule, 'max_items_per_day', 1));
        $reserveMultiplier = max(1.0, (float) data_get($plan->reliability, 'reserve_multiplier', 1.5));

        // Fallback top-up: when the bucket filters fill fewer than the daily target
        // (+ reserve headroom), auto-select active published escorts so quiet markets
        // still run instead of skipping with "no candidates". Configurable per plan.
        $fallbackEnabled = (bool) data_get($plan->reliability, 'fallback_enabled', true);
        $targetTotal = $maxItems + (int) ceil($maxItems * $reserveMultiplier);

        if ($fallbackEnabled && $orderedClients->count() < $targetTotal) {
            $needed = $targetTotal - $orderedClients->count();
            $excludeIds = $orderedClients->map(fn (Client $client) => (int) $client->id)->all();
            $fallbackClients = $this->selectFallback($plan, $excludeIds, $needed);

            if ($fallbackClients->isNotEmpty()) {
                $orderedClients = $orderedClients->concat($fallbackClients)->values();
                $bucketCounts['fallback'] = $fallbackClients->count();
            }
        }

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

    /**
     * Currently-boosted escort profiles on the market, most recently boosted first.
     * Boost is a deliberate sales override, so it bypasses bucket filters and the
     * recent-push exclusion window.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    public function selectBoosted(AutoPushPlan $plan): Collection
    {
        return Client::query()
            ->where('platform_id', (int) $plan->platform_id)
            ->where('client_type', 'escort')
            ->boosted()
            ->orderByDesc('boosted_at')
            ->with('platform')
            ->get()
            ->values();
    }

    /**
     * Fallback pool: active, published escort profiles on the market, used to top
     * up when the bucket filters fall short.
     *
     * "Active" here matches the CRM's own definition (Client::scopeActive):
     * profile_status = publish, not needs_payment, not notactive. We deliberately
     * do NOT require an active `deals` row — synced WordPress profiles in quieter
     * markets (Botswana, Malawi, etc.) are published and live but frequently have
     * no CRM deal record, so a deal requirement would exclude exactly the profiles
     * this fallback exists to reach.
     *
     * @param  array<int, int>  $excludeClientIds
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    public function selectFallback(AutoPushPlan $plan, array $excludeClientIds, int $limit): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        $ordering = (string) data_get($plan->reliability, 'fallback_ordering', 'random');

        $query = Client::query()
            ->where('platform_id', (int) $plan->platform_id)
            ->where('client_type', 'escort')
            ->active();

        if ($excludeClientIds !== []) {
            $query->whereNotIn('id', $excludeClientIds);
        }

        // Honor the same recent-push exclusion window the buckets use.
        $excludeDays = max(0, (int) data_get($plan->reliability, 'exclude_pushed_within_days', 3));
        if ($excludeDays > 0) {
            $query->whereNotExists(function ($sub) use ($plan, $excludeDays) {
                $sub->select(DB::raw(1))
                    ->from('push_campaign_items')
                    ->join('push_campaigns', 'push_campaigns.id', '=', 'push_campaign_items.campaign_id')
                    ->whereColumn('push_campaign_items.client_id', 'clients.id')
                    ->where('push_campaigns.platform_id', (int) $plan->platform_id)
                    ->whereNotNull('push_campaign_items.sent_at')
                    ->where('push_campaign_items.sent_at', '>=', now()->subDays($excludeDays));
            });
        }

        $query = match ($ordering) {
            'recent' => $query->orderByRaw('COALESCE(last_online_at, 0) DESC'),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->inRandomOrder(),
        };

        return $query->with('platform')->limit($limit)->get()->values();
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
