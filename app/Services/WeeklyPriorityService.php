<?php

namespace App\Services;

use App\Models\User;
use App\Models\WeeklyPriority;
use App\Services\Ai\MetricsSnapshotService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WeeklyPriorityService
{
    public function __construct(private readonly MetricsSnapshotService $snapshots) {}

    public function weekWindow(?string $from = null, ?string $to = null): array
    {
        $tz = config('app.timezone', 'Africa/Nairobi');

        if ($from && $to) {
            return [
                'from' => Carbon::parse($from, $tz)->startOfDay(),
                'to' => Carbon::parse($to, $tz)->endOfDay(),
            ];
        }

        $start = Carbon::now($tz)->startOfWeek(Carbon::MONDAY);

        return [
            'from' => $start,
            'to' => $start->copy()->endOfWeek(Carbon::SUNDAY),
        ];
    }

    public function queryForUser(User $user, array $filters = []): Builder
    {
        $window = $this->weekWindow($filters['from'] ?? null, $filters['to'] ?? null);

        return WeeklyPriority::query()
            ->with(['platform:id,name,country', 'owner:id,name,role', 'creator:id,name,role'])
            ->whereDate('week_start', '<=', $window['to']->toDateString())
            ->whereDate('week_end', '>=', $window['from']->toDateString())
            ->when(($filters['status'] ?? null) && $filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(($filters['audience'] ?? null) && $filters['audience'] !== 'all', fn (Builder $query) => $query->whereIn('audience', ['all', $filters['audience']]))
            ->where(fn (Builder $query) => $this->visibilityConstraint($query, $user))
            ->orderByRaw("CASE priority_level WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")
            ->orderBy('due_at')
            ->orderByDesc('id');
    }

    public function create(User $creator, array $payload): WeeklyPriority
    {
        $window = $this->weekWindow($payload['week_start'] ?? null, $payload['week_end'] ?? null);
        $priority = WeeklyPriority::query()->create([
            'title' => trim((string) $payload['title']),
            'description' => $payload['description'] ?? null,
            'status' => 'pending',
            'priority_level' => $payload['priority_level'] ?? 'normal',
            'audience' => $payload['audience'] ?? 'all',
            'platform_id' => $payload['platform_id'] ?? null,
            'owner_user_id' => $payload['owner_user_id'] ?? null,
            'created_by' => (int) $creator->id,
            'source_type' => $payload['source_type'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'week_start' => $window['from']->toDateString(),
            'week_end' => $window['to']->toDateString(),
            'due_at' => $payload['due_at'] ?? null,
            'completion_mode' => $payload['completion_mode'] ?? 'manual',
            'metric_key' => $payload['metric_key'] ?? null,
            'target_operator' => $payload['target_operator'] ?? null,
            'target_value' => $payload['target_value'] ?? null,
            'target_currency' => $payload['target_currency'] ?? null,
            'metadata' => $payload['metadata'] ?? [],
        ]);

        return $this->refreshProgress($priority);
    }

    public function update(WeeklyPriority $priority, array $payload, User $actor): WeeklyPriority
    {
        $status = $payload['status'] ?? null;

        if ($status === 'completed' && $priority->status !== 'completed') {
            $payload['completed_at'] = now();
        } elseif ($status === 'pending') {
            $payload['completed_at'] = null;
        }

        unset($payload['week_start'], $payload['week_end']);
        $priority->fill($payload);
        $priority->save();

        return $this->refreshProgress($priority);
    }

    public function refreshVisibleProgress(Collection $priorities): Collection
    {
        return $priorities->map(fn (WeeklyPriority $priority) => $this->refreshProgress($priority));
    }

    public function refreshProgress(WeeklyPriority $priority): WeeklyPriority
    {
        if (! in_array($priority->completion_mode, ['metric', 'hybrid'], true) || ! $priority->metric_key || $priority->target_value === null) {
            return $priority->fresh(['platform:id,name,country', 'owner:id,name,role', 'creator:id,name,role']);
        }

        $value = $this->metricValue($priority);
        if ($value === null) {
            $priority->forceFill([
                'current_value' => null,
                'last_evaluated_at' => now(),
            ])->save();

            return $priority->fresh(['platform:id,name,country', 'owner:id,name,role', 'creator:id,name,role']);
        }

        $complete = $priority->target_operator === 'lte'
            ? $value <= (float) $priority->target_value
            : $value >= (float) $priority->target_value;

        $updates = [
            'current_value' => round($value, 2),
            'last_evaluated_at' => now(),
        ];

        if ($complete && $priority->status === 'pending') {
            $updates['status'] = 'completed';
            $updates['completed_at'] = now();
        }

        $priority->forceFill($updates)->save();

        return $priority->fresh(['platform:id,name,country', 'owner:id,name,role', 'creator:id,name,role']);
    }

    public function serialize(WeeklyPriority $priority): array
    {
        $now = now();
        $isOverdue = $priority->status === 'pending'
            && $priority->due_at
            && $priority->due_at->lt($now);
        $progress = null;

        if ($priority->target_value !== null) {
            $current = (float) ($priority->current_value ?? 0);
            $target = max(0.01, (float) $priority->target_value);
            $raw = $priority->target_operator === 'lte'
                ? (($target - max(0, $current - $target)) / $target) * 100
                : ($current / $target) * 100;
            $progress = max(0, min(100, round($raw, 1)));
        }

        return [
            'id' => (int) $priority->id,
            'title' => $priority->title,
            'description' => $priority->description,
            'status' => $priority->status,
            'is_overdue' => $isOverdue,
            'priority_level' => $priority->priority_level,
            'audience' => $priority->audience,
            'week_start' => optional($priority->week_start)->toDateString(),
            'week_end' => optional($priority->week_end)->toDateString(),
            'due_at' => optional($priority->due_at)->toIso8601String(),
            'completion_mode' => $priority->completion_mode,
            'metric_key' => $priority->metric_key,
            'target_operator' => $priority->target_operator,
            'target_value' => $priority->target_value,
            'target_currency' => $priority->target_currency,
            'current_value' => $priority->current_value,
            'progress_percent' => $progress,
            'last_evaluated_at' => optional($priority->last_evaluated_at)->toIso8601String(),
            'completed_at' => optional($priority->completed_at)->toIso8601String(),
            'platform' => $priority->platform ? [
                'id' => (int) $priority->platform->id,
                'name' => $priority->platform->name,
                'country' => $priority->platform->country,
            ] : null,
            'owner' => $priority->owner ? [
                'id' => (int) $priority->owner->id,
                'name' => $priority->owner->name,
                'role' => $priority->owner->role,
            ] : null,
            'creator' => $priority->creator ? [
                'id' => (int) $priority->creator->id,
                'name' => $priority->creator->name,
                'role' => $priority->creator->role,
            ] : null,
            'source_type' => $priority->source_type,
            'source_id' => $priority->source_id,
            'metadata' => $priority->metadata ?? [],
        ];
    }

    public function canCreate(User $user): bool
    {
        return (bool) ($user->is_ceo ?? false) || in_array($user->role ?? null, ['admin', 'sub_admin'], true);
    }

    public function canUpdate(User $user, WeeklyPriority $priority, array $payload): bool
    {
        if ($this->canCreate($user)) {
            return true;
        }

        $onlyStatus = array_diff(array_keys($payload), ['status']) === [];

        return $onlyStatus && $this->isVisibleToUser($priority, $user);
    }

    public function isVisibleToUser(WeeklyPriority $priority, User $user): bool
    {
        if ($this->canCreate($user)) {
            return true;
        }

        if ((int) $priority->owner_user_id === (int) $user->id) {
            return true;
        }

        $role = $this->audienceRole($user);
        if (! in_array($priority->audience, ['all', $role], true)) {
            return false;
        }

        if (! $priority->platform_id) {
            return true;
        }

        return in_array((int) $priority->platform_id, $user->assignedMarketIds(), true);
    }

    private function visibilityConstraint(Builder $query, User $user): void
    {
        if ($this->canCreate($user)) {
            return;
        }

        $role = $this->audienceRole($user);
        $marketIds = $user->assignedMarketIds();

        $query->where(function (Builder $visible) use ($user, $role, $marketIds) {
            $visible->where('owner_user_id', $user->id)
                ->orWhere(function (Builder $audience) use ($role, $marketIds) {
                    $audience->whereIn('audience', ['all', $role])
                        ->where(function (Builder $scope) use ($marketIds) {
                            $scope->whereNull('platform_id');

                            if ($marketIds !== []) {
                                $scope->orWhereIn('platform_id', $marketIds);
                            }
                        });
                });
        });
    }

    private function metricValue(WeeklyPriority $priority): ?float
    {
        $from = Carbon::parse($priority->week_start)->startOfDay();
        $to = Carbon::parse($priority->week_end)->endOfDay();
        $platformIds = $priority->platform_id ? [(int) $priority->platform_id] : null;
        $snapshot = $this->snapshots->forScope($platformIds, $from, $to);

        return match ($priority->metric_key) {
            'revenue' => data_get($snapshot, 'revenue.normalized_total'),
            'average_daily_revenue' => data_get($snapshot, 'revenue.average_daily'),
            'payment_recovery_rate' => data_get($snapshot, 'payment_recovery.payment_recovery_rate'),
            'new_paid_customers' => data_get($snapshot, 'customer_movement.new_paid_customers'),
            'active_subscriber_snapshot' => data_get($snapshot, 'customer_movement.active_subscribers_snapshot.current'),
            'churned_profiles' => data_get($snapshot, 'customer_movement.churned_profiles'),
            'lost_value_to_churn' => data_get($snapshot, 'customer_movement.lost_value_to_churn'),
            'team_active_hours' => data_get($snapshot, 'team_execution.active_hours'),
            default => null,
        };
    }

    private function audienceRole(User $user): string
    {
        $role = (string) ($user->role ?? '');

        return $role === 'field_sales' ? 'sales' : $role;
    }
}
