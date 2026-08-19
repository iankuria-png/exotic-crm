<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushBoostUsage;
use App\Models\AutoPushPlan;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutoPushBoostLimitService
{
    public const DEFAULT_ENABLED = true;
    public const DEFAULT_MAX_BOOSTS = 3;
    public const DEFAULT_WINDOW_HOURS = 6;

    private const LIMITED_ROLES = ['sales', 'field_sales'];

    public function __construct(
        private readonly AutoPushBoostPlanResolver $planResolver,
    ) {
    }

    public static function defaultConfig(): array
    {
        return [
            'enabled' => self::DEFAULT_ENABLED,
            'max_boosts' => self::DEFAULT_MAX_BOOSTS,
            'window_hours' => self::DEFAULT_WINDOW_HOURS,
        ];
    }

    public static function normalizeConfig(?array $config): array
    {
        $config = is_array($config) ? $config : [];

        return [
            'enabled' => array_key_exists('enabled', $config) ? (bool) $config['enabled'] : self::DEFAULT_ENABLED,
            'max_boosts' => min(100, max(1, (int) ($config['max_boosts'] ?? self::DEFAULT_MAX_BOOSTS))),
            'window_hours' => min(168, max(1, (int) ($config['window_hours'] ?? self::DEFAULT_WINDOW_HOURS))),
        ];
    }

    public function configForPlan(?AutoPushPlan $plan): array
    {
        return self::normalizeConfig(is_array($plan?->reliability)
            ? data_get($plan->reliability, 'boost_limit')
            : null);
    }

    public function stateForPlan(?AutoPushPlan $plan, ?User $actor = null): array
    {
        $config = $this->configForPlan($plan);
        $state = $this->windowState((int) ($plan?->platform_id ?? 0), $config);

        return $state + [
            'applies_to_actor' => $actor instanceof User ? $this->appliesTo($actor) : null,
            'auto_push_plan_id' => $plan ? (int) $plan->id : null,
        ];
    }

    public function stateForClient(Client $client, ?User $actor = null): array
    {
        $plan = $this->planResolver->forClient($client);
        $config = $this->configForPlan($plan);
        $state = $this->windowState((int) $client->platform_id, $config);

        return $state + [
            'applies_to_actor' => $actor instanceof User ? $this->appliesTo($actor) : null,
            'auto_push_plan_id' => $plan ? (int) $plan->id : null,
        ];
    }

    public function reserveForClient(Client $client, User $actor, int $boostHours): array
    {
        $plan = $this->planResolver->forClient($client);
        $config = $this->configForPlan($plan);

        if (!$this->appliesTo($actor) || !$config['enabled']) {
            return $this->windowState((int) $client->platform_id, $config) + [
                'allowed' => true,
                'bypassed' => true,
                'usage_id' => null,
                'auto_push_plan_id' => $plan ? (int) $plan->id : null,
                'applies_to_actor' => $this->appliesTo($actor),
            ];
        }

        return DB::transaction(function () use ($client, $actor, $boostHours, $plan, $config) {
            Platform::query()
                ->whereKey((int) $client->platform_id)
                ->lockForUpdate()
                ->firstOrFail();

            $state = $this->windowState((int) $client->platform_id, $config);

            if (($state['remaining'] ?? 0) <= 0) {
                return $state + [
                    'allowed' => false,
                    'bypassed' => false,
                    'usage_id' => null,
                    'auto_push_plan_id' => $plan ? (int) $plan->id : null,
                    'applies_to_actor' => true,
                ];
            }

            $usage = AutoPushBoostUsage::query()->create([
                'platform_id' => (int) $client->platform_id,
                'client_id' => (int) $client->id,
                'actor_id' => (int) $actor->id,
                'auto_push_plan_id' => $plan ? (int) $plan->id : null,
                'boost_hours' => $boostHours,
                'limit_snapshot' => $config,
            ]);

            $nextState = $this->windowState((int) $client->platform_id, $config);

            return $nextState + [
                'allowed' => true,
                'bypassed' => false,
                'usage_id' => (int) $usage->id,
                'auto_push_plan_id' => $plan ? (int) $plan->id : null,
                'applies_to_actor' => true,
            ];
        });
    }

    private function windowState(int $platformId, array $config): array
    {
        $config = self::normalizeConfig($config);
        $windowStart = now()->subHours($config['window_hours']);

        $query = AutoPushBoostUsage::query()
            ->where('platform_id', $platformId)
            ->where('created_at', '>=', $windowStart);

        $used = $platformId > 0 ? (int) $query->count() : 0;
        $oldest = $platformId > 0
            ? AutoPushBoostUsage::query()
                ->where('platform_id', $platformId)
                ->where('created_at', '>=', $windowStart)
                ->orderBy('created_at')
                ->value('created_at')
            : null;

        $oldestAt = $oldest ? Carbon::parse($oldest) : null;
        $resetsAt = $oldestAt instanceof Carbon
            ? $oldestAt->copy()->addHours($config['window_hours'])
            : now()->addHours($config['window_hours']);

        return [
            'enabled' => (bool) $config['enabled'],
            'max_boosts' => (int) $config['max_boosts'],
            'window_hours' => (int) $config['window_hours'],
            'used' => $used,
            'remaining' => $config['enabled'] ? max(0, (int) $config['max_boosts'] - $used) : null,
            'resets_at' => $resetsAt->toIso8601String(),
        ];
    }

    private function appliesTo(User $actor): bool
    {
        return in_array((string) $actor->role, self::LIMITED_ROLES, true);
    }
}
