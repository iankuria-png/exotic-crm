<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\TimelineEvent;
use App\Support\ClientLifecycleState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ActiveSubscriptionProfileRepairService
{
    public function hasFutureActiveDeal(Client $client): bool
    {
        return $this->futureActiveDealQuery($client)->exists();
    }

    public function bestFutureActiveDeal(Client $client): ?Deal
    {
        return $this->futureActiveDealQuery($client)
            ->orderByDesc('expires_at')
            ->orderByRaw("
                CASE plan_type
                    WHEN 'vvip' THEN 1
                    WHEN 'vip' THEN 2
                    WHEN 'premium' THEN 3
                    WHEN 'basic' THEN 4
                    ELSE 99
                END
            ")
            ->first();
    }

    /**
     * @return Collection<int, Client>
     */
    public function affectedClients(?int $platformId = null, ?int $clientId = null, ?int $wpPostId = null, int $limit = 200): Collection
    {
        $query = Client::query()
            ->with(['platform', 'deals' => fn ($dealQuery) => $this->applyFutureActiveDealScope($dealQuery)])
            ->whereHas('deals', fn ($dealQuery) => $this->applyFutureActiveDealScope($dealQuery))
            ->where(function (Builder $builder): void {
                $builder
                    ->where('profile_status', '!=', 'publish')
                    ->orWhere('needs_payment', true)
                    ->orWhere('notactive', true)
                    ->orWhereIn('lifecycle_state', [
                        ClientLifecycleState::EXPIRED,
                        ClientLifecycleState::ARCHIVED,
                    ])
                    ->orWhereNull('escort_expire')
                    ->orWhere('escort_expire', '<=', now()->timestamp);
            })
            ->orderBy('id');

        if ($platformId) {
            $query->where('platform_id', $platformId);
        }

        if ($clientId) {
            $query->whereKey($clientId);
        }

        if ($wpPostId) {
            $query->where('wp_post_id', $wpPostId);
        }

        return $query->limit(max(1, $limit))->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function repairClient(
        Client $client,
        ?Deal $deal = null,
        bool $dryRun = false,
        string $source = 'active_subscription_profile_repair',
        bool $syncWordPress = true
    ): array {
        $client->loadMissing('platform');
        $deal ??= $this->bestFutureActiveDeal($client);

        $row = [
            'client_id' => (int) $client->id,
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'platform_id' => (int) $client->platform_id,
            'market' => $client->platform?->name ?? (string) $client->platform_id,
            'name' => (string) $client->name,
            'action' => 'skipped',
            'deal_id' => $deal?->id ? (int) $deal->id : null,
            'expires_at' => $deal?->expires_at ? Carbon::parse($deal->expires_at)->toDateTimeString() : null,
        ];

        if (! $deal || ! $this->isFutureActiveDeal($deal)) {
            $row['reason'] = 'no_future_active_deal';

            return $row;
        }

        $columns = $this->repairColumnsForDeal($deal);
        $changes = [];
        foreach ($columns as $key => $value) {
            if ($client->{$key} != $value) {
                $changes[$key] = ['from' => $client->{$key}, 'to' => $value];
            }
        }

        if ($changes === []) {
            $row['action'] = 'already_ok';

            return $row;
        }

        $row['changes'] = array_keys($changes);

        if ($dryRun) {
            $row['action'] = 'would_repair';

            return $row;
        }

        if ($syncWordPress && (int) ($client->wp_post_id ?? 0) > 0) {
            WpSyncService::forPlatform((int) $client->platform_id)->setLifecycleState(
                (int) $client->wp_post_id,
                ClientLifecycleState::ACTIVE,
                [
                    'escort_expire' => $columns['escort_expire'],
                    'product_type' => (string) $deal->plan_type,
                    'crm_deal_id' => (int) $deal->id,
                ]
            );
            $row['wp_lifecycle_state_synced'] = true;
        }

        Client::withoutRetentionRefresh(function () use ($client, $columns): void {
            $client->forceFill($columns)->save();
        });

        app(ClientChurnStamper::class)->clear($client, 'active_subscription_profile_repair');

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'profile_subscription_state_repaired',
            'actor_id' => null,
            'content' => [
                'source' => $source,
                'deal_id' => (int) $deal->id,
                'plan_type' => (string) $deal->plan_type,
                'expires_at' => Carbon::parse($deal->expires_at)->toDateTimeString(),
                'changed_columns' => array_keys($changes),
            ],
            'created_at' => now(),
        ]);

        $row['action'] = 'repaired';

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function repairColumnsForDeal(Deal $deal): array
    {
        $planType = strtolower((string) $deal->plan_type);
        $expiryTimestamp = Carbon::parse($deal->expires_at)->timestamp;
        $isPremium = in_array($planType, ['premium', 'vip', 'vvip'], true);
        $isFeatured = in_array($planType, ['vip', 'vvip'], true);

        return [
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'lifecycle_state' => ClientLifecycleState::ACTIVE,
            'lifecycle_expired_at' => null,
            'lifecycle_archived_at' => null,
            'lifecycle_restored_at' => null,
            'lifecycle_restore_run_id' => null,
            'escort_expire' => $expiryTimestamp,
            'premium' => $isPremium,
            'premium_expire' => $isPremium ? $expiryTimestamp : null,
            'featured' => $isFeatured,
            'featured_expire' => $isFeatured ? $expiryTimestamp : null,
        ];
    }

    private function futureActiveDealQuery(Client $client): Builder
    {
        return $this->applyFutureActiveDealScope($client->deals()->getQuery());
    }

    private function applyFutureActiveDealScope($query)
    {
        return $query
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now());
    }

    private function isFutureActiveDeal(Deal $deal): bool
    {
        return (string) $deal->status === 'active'
            && $deal->expires_at !== null
            && Carbon::parse($deal->expires_at)->isFuture();
    }
}
