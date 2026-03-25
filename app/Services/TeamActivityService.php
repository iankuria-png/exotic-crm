<?php

namespace App\Services;

use App\Models\AgentDailyStat;
use App\Models\AgentGoal;
use App\Models\AgentGoalOverride;
use App\Models\AgentSession;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class TeamActivityService
{
    public const PERIOD_TODAY = 'today';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';

    public const GOAL_PERIOD_WEEKLY = 'weekly';
    public const GOAL_PERIOD_MONTHLY = 'monthly';

    public const AGENT_ROLES = [
        MarketAuthorizationService::ROLE_SALES,
        MarketAuthorizationService::ROLE_MARKETING,
    ];

    public const GOAL_ROLE_SCOPE_SALES = MarketAuthorizationService::ROLE_SALES;
    public const GOAL_ROLE_SCOPE_MARKETING = MarketAuthorizationService::ROLE_MARKETING;
    public const GOAL_ROLE_SCOPE_ALL = 'all';

    public const GOAL_ROLE_SCOPES = [
        self::GOAL_ROLE_SCOPE_SALES,
        self::GOAL_ROLE_SCOPE_MARKETING,
        self::GOAL_ROLE_SCOPE_ALL,
    ];

    public const GOAL_METRICS = [
        'profiles_created',
        'subs_activated',
        'subs_renewed',
        'payments_matched',
        'subscriptions_created',
        'leads_contacted',
        'leads_converted',
        'chats_replied',
        'sms_sent',
        'credentials_sent',
        'free_trials_given',
        'total_actions',
    ];

    private const COUNT_METRIC_KEYS = [
        'profiles_created',
        'subs_activated',
        'subs_renewed',
        'payments_matched',
        'subscriptions_created',
        'leads_contacted',
        'leads_converted',
        'chats_replied',
        'sms_sent',
        'credentials_sent',
        'free_trials_given',
        'total_actions',
    ];

    private const GOAL_METRIC_ROLE_SCOPES = [
        'profiles_created' => [self::GOAL_ROLE_SCOPE_SALES],
        'subs_activated' => [self::GOAL_ROLE_SCOPE_SALES],
        'subs_renewed' => [self::GOAL_ROLE_SCOPE_SALES],
        'payments_matched' => [self::GOAL_ROLE_SCOPE_SALES],
        'subscriptions_created' => [self::GOAL_ROLE_SCOPE_SALES],
        'leads_contacted' => [self::GOAL_ROLE_SCOPE_SALES],
        'leads_converted' => [self::GOAL_ROLE_SCOPE_SALES],
        'chats_replied' => [self::GOAL_ROLE_SCOPE_SALES],
        'sms_sent' => [self::GOAL_ROLE_SCOPE_SALES],
        'credentials_sent' => [self::GOAL_ROLE_SCOPE_SALES],
        'free_trials_given' => [self::GOAL_ROLE_SCOPE_SALES],
        'total_actions' => [
            self::GOAL_ROLE_SCOPE_SALES,
            self::GOAL_ROLE_SCOPE_MARKETING,
            self::GOAL_ROLE_SCOPE_ALL,
        ],
    ];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function recordHeartbeat(User $user, string $sessionToken, string $ip, string $ua): AgentSession
    {
        $now = now();
        $staleCutoff = $this->staleCutoff($now);
        $trimmedUa = mb_substr(trim($ua), 0, 500);
        $trimmedIp = mb_substr(trim($ip), 0, 45);

        $existing = AgentSession::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('session_token', $sessionToken)
            ->latest('id')
            ->first();

        if ($existing && $existing->last_heartbeat_at && $existing->last_heartbeat_at->lt($staleCutoff)) {
            $existing->forceFill([
                'ended_at' => $existing->last_heartbeat_at ?: $existing->started_at,
            ])->save();
            $existing = null;
        }

        if ($existing) {
            $existing->forceFill([
                'last_heartbeat_at' => $now,
                'ip_address' => $trimmedIp !== '' ? $trimmedIp : null,
                'user_agent' => $trimmedUa !== '' ? $trimmedUa : null,
            ])->save();

            return $existing->fresh();
        }

        AgentSession::query()
            ->open()
            ->where('session_token', $sessionToken)
            ->where('user_id', '!=', $user->id)
            ->get()
            ->each(function (AgentSession $session): void {
                $session->forceFill([
                    'ended_at' => $session->last_heartbeat_at ?: $session->started_at,
                ])->save();
            });

        return AgentSession::query()->create([
            'user_id' => $user->id,
            'session_token' => $sessionToken,
            'started_at' => $now,
            'last_heartbeat_at' => $now,
            'ended_at' => null,
            'ip_address' => $trimmedIp !== '' ? $trimmedIp : null,
            'user_agent' => $trimmedUa !== '' ? $trimmedUa : null,
        ]);
    }

    public function closeUserSession(User $user, string $sessionToken): void
    {
        $session = AgentSession::query()
            ->open()
            ->where('user_id', $user->id)
            ->where('session_token', $sessionToken)
            ->latest('id')
            ->first();

        if (!$session) {
            return;
        }

        $session->forceFill([
            'ended_at' => $session->last_heartbeat_at ?: $session->started_at ?: now(),
        ])->save();
    }

    public function closeStaleSessionsJob(): int
    {
        $cutoff = $this->staleCutoff();

        $sessions = AgentSession::query()
            ->open()
            ->where('last_heartbeat_at', '<', $cutoff)
            ->get();

        foreach ($sessions as $session) {
            $session->forceFill([
                'ended_at' => $session->last_heartbeat_at ?: $session->started_at,
            ])->save();
        }

        return $sessions->count();
    }

    public function getPresence(User $viewer, ?int $platformId = null): array
    {
        $this->assertPlatformAccessible($viewer, $platformId);

        $agents = $this->visibleAgentsForViewer($viewer, $platformId);

        if ($agents->isEmpty()) {
            return [
                'summary' => [
                    'online_now' => 0,
                    'active_today' => 0,
                    'total_actions_today' => 0,
                ],
                'data' => [],
            ];
        }

        $agentIds = $agents->pluck('id')->all();
        $cutoff = $this->staleCutoff();
        $sessionsByUser = AgentSession::query()
            ->whereIn('user_id', $agentIds)
            ->orderByDesc('last_heartbeat_at')
            ->get()
            ->groupBy('user_id');

        $latestActions = $this->latestActionsByUser($agentIds, $viewer, $platformId);
        $todayMetrics = $this->aggregateActionMetricsForRange(
            now()->startOfDay(),
            now(),
            $viewer,
            $platformId,
            $agentIds
        );

        $rows = $agents->map(function (User $agent) use ($sessionsByUser, $cutoff, $latestActions) {
            $sessions = $sessionsByUser->get($agent->id, collect());
            $recentOpenSessions = $sessions
                ->filter(fn (AgentSession $session) => $session->ended_at === null && $session->last_heartbeat_at && $session->last_heartbeat_at->gte($cutoff))
                ->values();

            $isOnline = $recentOpenSessions->isNotEmpty();
            $currentDuration = 0;

            if ($isOnline) {
                $startedAt = $recentOpenSessions
                    ->pluck('started_at')
                    ->filter()
                    ->sort()
                    ->first();

                if ($startedAt instanceof CarbonInterface) {
                    $currentDuration = $startedAt->diffInSeconds(now());
                }
            }

            $lastSeen = $sessions
                ->map(fn (AgentSession $session) => $session->ended_at ?: $session->last_heartbeat_at ?: $session->started_at)
                ->filter()
                ->sortDesc()
                ->first();

            return [
                'user_id' => (int) $agent->id,
                'name' => $agent->name,
                'role' => $agent->role,
                'is_online' => $isOnline,
                'session_count' => $recentOpenSessions->count(),
                'current_session_duration_seconds' => $currentDuration,
                'last_seen_at' => $lastSeen?->toIso8601String(),
                'last_action' => $latestActions[$agent->id] ?? null,
            ];
        })->values();

        return [
            'summary' => [
                'online_now' => $rows->where('is_online', true)->count(),
                'active_today' => collect($todayMetrics)->filter(fn (array $row) => ($row['total_actions'] ?? 0) > 0)->count(),
                'total_actions_today' => collect($todayMetrics)->sum('total_actions'),
            ],
            'data' => $rows->all(),
        ];
    }

    public function getLeaderboard(string $period, ?int $platformId, User $viewer): array
    {
        $this->assertManager($viewer);
        $this->assertPlatformAccessible($viewer, $platformId);

        $agents = $this->visibleAgentsForViewer($viewer);
        $agentIds = $agents->pluck('id')->all();

        if (empty($agentIds)) {
            return [
                'period' => $this->normalizeNamedPeriod($period),
                'platform_id' => $platformId,
                'data' => [],
            ];
        }

        ['start' => $start, 'end' => $end] = $this->resolveNamedPeriodRange($period);

        $actionMetrics = $this->aggregateActionMetricsForRange($start, $end, $viewer, $platformId, $agentIds);
        $sessionTotals = $this->aggregateSessionTotals($agentIds, $start, $end);
        $revenueTotals = $this->aggregateRevenueByUser($agentIds, $start, $end, $viewer, $platformId);
        $presenceFlags = $this->presenceFlagsByUser($agentIds);

        $rowIds = collect(array_merge(array_keys($actionMetrics), array_keys($revenueTotals)))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $rows = $rowIds
            ->map(function (int $userId) use ($agents, $actionMetrics, $sessionTotals, $revenueTotals, $presenceFlags, $platformId) {
                /** @var User|null $agent */
                $agent = $agents->firstWhere('id', $userId);
                if (!$agent) {
                    return null;
                }

                $metrics = $actionMetrics[$userId] ?? $this->emptyMetricRow();
                $session = $sessionTotals[$userId] ?? [
                    'active_seconds' => 0,
                    'session_count' => 0,
                ];
                $revenue = $revenueTotals[$userId] ?? $this->emptyRevenuePayload($platformId);

                return array_merge([
                    'user_id' => $userId,
                    'name' => $agent->name,
                    'role' => $agent->role,
                    'is_online' => $presenceFlags[$userId] ?? false,
                    'active_seconds' => (int) ($session['active_seconds'] ?? 0),
                    'session_count' => (int) ($session['session_count'] ?? 0),
                ], $metrics, $revenue);
            })
            ->filter()
            ->sort(function (array $left, array $right) {
                return [
                    $right['total_actions'] <=> $left['total_actions'],
                    $right['subs_activated'] <=> $left['subs_activated'],
                    $right['subs_renewed'] <=> $left['subs_renewed'],
                    strcasecmp($left['name'], $right['name']),
                ];
            })
            ->values()
            ->map(function (array $row, int $index) {
                $row['rank'] = $index + 1;

                return $row;
            })
            ->values();

        return [
            'period' => $this->normalizeNamedPeriod($period),
            'platform_id' => $platformId,
            'data' => $rows->all(),
        ];
    }

    public function getAgentStats(
        User $agent,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $platformId = null,
        ?User $viewer = null
    ): array {
        if ($viewer) {
            $this->assertAgentVisibleToViewer($viewer, $agent);
            $this->assertPlatformAccessible($viewer, $platformId);
        }

        $start = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->addDay()->startOfDay();
        $previousRange = $this->previousRange($start, $end);
        $viewerForScope = $viewer ?? $agent;

        $currentMetrics = $this->aggregateActionMetricsForRange($start, $end, $viewerForScope, $platformId, [$agent->id]);
        $previousMetrics = $this->aggregateActionMetricsForRange($previousRange['start'], $previousRange['end'], $viewerForScope, $platformId, [$agent->id]);
        $currentSessions = $this->aggregateSessionTotals([$agent->id], $start, $end);
        $previousSessions = $this->aggregateSessionTotals([$agent->id], $previousRange['start'], $previousRange['end']);
        $currentRevenue = $this->aggregateRevenueByUser([$agent->id], $start, $end, $viewerForScope, $platformId);
        $previousRevenue = $this->aggregateRevenueByUser([$agent->id], $previousRange['start'], $previousRange['end'], $viewerForScope, $platformId);
        $goalProgress = $this->getGoalProgress($agent, $platformId);

        $currentSummary = $this->buildUserSummary(
            $currentMetrics[$agent->id] ?? $this->emptyMetricRow(),
            $currentSessions[$agent->id] ?? ['active_seconds' => 0, 'session_count' => 0],
            $currentRevenue[$agent->id] ?? $this->emptyRevenuePayload($platformId)
        );

        $previousSummary = $this->buildUserSummary(
            $previousMetrics[$agent->id] ?? $this->emptyMetricRow(),
            $previousSessions[$agent->id] ?? ['active_seconds' => 0, 'session_count' => 0],
            $previousRevenue[$agent->id] ?? $this->emptyRevenuePayload($platformId)
        );

        return [
            'agent' => [
                'id' => (int) $agent->id,
                'name' => $agent->name,
                'role' => $agent->role,
                'status' => $agent->status ?? 'active',
            ],
            'from' => $start->toDateString(),
            'to' => Carbon::instance($to)->toDateString(),
            'platform_id' => $platformId,
            'summary' => $currentSummary,
            'previous_summary' => $previousSummary,
            'trend' => $this->buildTrendPayload($currentSummary, $previousSummary),
            'goals' => $goalProgress,
        ];
    }

    public function getMyStats(User $user, string $period = self::PERIOD_WEEK, ?int $platformId = null): array
    {
        $this->assertPlatformAccessible($user, $platformId);

        ['start' => $start, 'end' => $end] = $this->resolveNamedPeriodRange($period);
        $previousRange = $this->previousRange($start, $end);

        $currentMetrics = $this->aggregateActionMetricsForRange($start, $end, $user, $platformId, [$user->id]);
        $previousMetrics = $this->aggregateActionMetricsForRange($previousRange['start'], $previousRange['end'], $user, $platformId, [$user->id]);
        $currentSessions = $this->aggregateSessionTotals([$user->id], $start, $end);
        $previousSessions = $this->aggregateSessionTotals([$user->id], $previousRange['start'], $previousRange['end']);
        $currentRevenue = $this->aggregateRevenueByUser([$user->id], $start, $end, $user, $platformId);
        $previousRevenue = $this->aggregateRevenueByUser([$user->id], $previousRange['start'], $previousRange['end'], $user, $platformId);

        $summary = $this->buildUserSummary(
            $currentMetrics[$user->id] ?? $this->emptyMetricRow(),
            $currentSessions[$user->id] ?? ['active_seconds' => 0, 'session_count' => 0],
            $currentRevenue[$user->id] ?? $this->emptyRevenuePayload($platformId)
        );

        $previousSummary = $this->buildUserSummary(
            $previousMetrics[$user->id] ?? $this->emptyMetricRow(),
            $previousSessions[$user->id] ?? ['active_seconds' => 0, 'session_count' => 0],
            $previousRevenue[$user->id] ?? $this->emptyRevenuePayload($platformId)
        );

        return [
            'period' => $this->normalizeNamedPeriod($period),
            'platform_id' => $platformId,
            'platforms' => $this->availablePlatformsForUser($user),
            'summary' => $summary,
            'previous_summary' => $previousSummary,
            'trend' => $this->buildTrendPayload($summary, $previousSummary),
            'goals' => $this->getGoalProgress($user, $platformId),
            'activity' => $this->recentActivity($user, $user, $platformId),
        ];
    }

    public function getAgentActivityFeed(
        User $agent,
        CarbonInterface $date,
        ?int $platformId = null,
        ?User $viewer = null
    ): array {
        if ($viewer) {
            $this->assertAgentVisibleToViewer($viewer, $agent);
            $this->assertPlatformAccessible($viewer, $platformId);
        }

        $viewerForScope = $viewer ?? $agent;
        $start = Carbon::instance($date)->startOfDay();
        $end = $start->copy()->addDay();

        $query = AuditLog::query()
            ->where('actor_id', $agent->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->orderByDesc('created_at');

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } else {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewerForScope);
        }

        $logs = $query->get();

        return [
            'date' => $start->toDateString(),
            'platform_id' => $platformId,
            'data' => $logs->map(fn (AuditLog $log) => $this->formatActivityLog($log))->all(),
        ];
    }

    public function getGoals(?int $platformId, User $viewer, string $period = self::GOAL_PERIOD_WEEKLY): array
    {
        $this->assertManager($viewer);
        $this->assertPlatformAccessible($viewer, $platformId);

        $normalizedPeriod = $this->normalizeGoalPeriod($period);
        $agents = $this->visibleAgentsForViewer($viewer, $platformId);
        $defaults = $this->goalsQuery($viewer, $platformId, $normalizedPeriod)
            ->with(['platform:id,name', 'setter:id,name'])
            ->orderBy('platform_id')
            ->orderBy('role_scope')
            ->orderBy('metric')
            ->get();
        $overrides = $this->goalOverridesQuery($viewer, $platformId, $normalizedPeriod)
            ->with(['platform:id,name', 'setter:id,name', 'user:id,name,role,status'])
            ->orderBy('platform_id')
            ->orderBy('user_id')
            ->orderBy('metric')
            ->get();

        $formattedDefaults = $defaults
            ->map(fn (AgentGoal $goal) => $this->formatGoal($goal, $agents, $viewer))
            ->all();

        return [
            'period' => $normalizedPeriod,
            'available_metrics' => $this->availableGoalMetrics(),
            'role_scopes' => $this->availableGoalRoleScopes(),
            'assignable_agents' => $agents
                ->map(fn (User $agent) => $this->formatAssignableGoalAgent($agent))
                ->values()
                ->all(),
            'data' => $formattedDefaults,
            'defaults' => $formattedDefaults,
            'overrides' => $overrides
                ->map(fn (AgentGoalOverride $goalOverride) => $this->formatGoalOverride($goalOverride, $viewer))
                ->all(),
        ];
    }

    public function setGoal(string $metric, int $target, string $period, ?int $platformId, string $roleScope, User $setter): AgentGoal
    {
        $this->assertManager($setter);
        $this->assertPlatformAccessible($setter, $platformId);

        $metric = $this->normalizeGoalMetric($metric);
        $period = $this->normalizeGoalPeriod($period);
        $roleScope = $this->normalizeGoalRoleScope($roleScope);
        $this->assertGoalMetricAllowedForRoleScope($metric, $roleScope);

        $goal = AgentGoal::query()
            ->when($platformId === null, fn ($query) => $query->whereNull('platform_id'))
            ->when($platformId !== null, fn ($query) => $query->where('platform_id', $platformId))
            ->where('role_scope', $roleScope)
            ->where('metric', $metric)
            ->where('period', $period)
            ->first();

        if (!$goal) {
            $goal = new AgentGoal([
                'platform_id' => $platformId,
                'role_scope' => $roleScope,
                'metric' => $metric,
                'period' => $period,
            ]);
        }

        $goal->fill([
            'role_scope' => $roleScope,
            'target' => $target,
            'set_by' => $setter->id,
        ]);
        $goal->save();

        return $goal->fresh(['platform:id,name', 'setter:id,name']);
    }

    public function deleteGoal(AgentGoal $goal, User $viewer): void
    {
        $this->assertManager($viewer);
        $this->assertPlatformAccessible($viewer, $goal->platform_id ? (int) $goal->platform_id : null);

        $goal->delete();
    }

    public function setGoalOverride(int $userId, string $metric, int $target, string $period, int $platformId, User $setter): AgentGoalOverride
    {
        $this->assertManager($setter);
        $this->assertPlatformAccessible($setter, $platformId);

        $user = User::query()
            ->with('platforms:id')
            ->findOrFail($userId);

        $this->assertGoalAssigneeAccessible($setter, $user, $platformId);

        $metric = $this->normalizeGoalMetric($metric);
        $period = $this->normalizeGoalPeriod($period);
        $this->assertGoalMetricAllowedForRole($metric, $user->role);

        $goalOverride = AgentGoalOverride::query()
            ->where('user_id', $user->id)
            ->where('platform_id', $platformId)
            ->where('metric', $metric)
            ->where('period', $period)
            ->first();

        if (!$goalOverride) {
            $goalOverride = new AgentGoalOverride([
                'user_id' => $user->id,
                'platform_id' => $platformId,
                'metric' => $metric,
                'period' => $period,
            ]);
        }

        $goalOverride->fill([
            'target' => $target,
            'set_by' => $setter->id,
        ]);
        $goalOverride->save();

        return $goalOverride->fresh(['platform:id,name', 'setter:id,name', 'user:id,name,role,status']);
    }

    public function deleteGoalOverride(AgentGoalOverride $goalOverride, User $viewer): void
    {
        $this->assertManager($viewer);
        $this->assertPlatformAccessible($viewer, (int) $goalOverride->platform_id);

        $goalOverride->delete();
    }

    public function getGoalProgress(User $user, ?int $platformId = null): array
    {
        $this->assertPlatformAccessible($user, $platformId);

        $accessiblePlatforms = $this->accessiblePlatformIdsForUser($user);
        $defaults = AgentGoal::query()
            ->with('platform:id,name')
            ->where(function ($query) use ($platformId, $accessiblePlatforms) {
                $query->whereNull('platform_id');

                if ($platformId) {
                    $query->orWhere('platform_id', $platformId);
                    return;
                }

                if (is_array($accessiblePlatforms) && !empty($accessiblePlatforms)) {
                    $query->orWhereIn('platform_id', $accessiblePlatforms);
                } elseif ($accessiblePlatforms === null) {
                    $query->orWhereNotNull('platform_id');
                }
            })
            ->orderBy('period')
            ->orderBy('platform_id')
            ->orderBy('metric')
            ->orderBy('role_scope')
            ->get();

        $overrides = AgentGoalOverride::query()
            ->with('platform:id,name')
            ->where('user_id', $user->id)
            ->when($platformId !== null, fn ($query) => $query->where('platform_id', $platformId))
            ->when($platformId === null && is_array($accessiblePlatforms), function ($query) use ($accessiblePlatforms) {
                if (empty($accessiblePlatforms)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->whereIn('platform_id', $accessiblePlatforms);
            })
            ->orderBy('period')
            ->orderBy('platform_id')
            ->orderBy('metric')
            ->get();

        $effectiveGoals = [];

        foreach ($defaults as $goal) {
            if (!$this->goalAppliesToUser($goal, $user)) {
                continue;
            }

            $effectiveGoals[$this->goalKey($goal->platform_id ? (int) $goal->platform_id : null, $goal->metric, $goal->period)] = $this->formatSingleUserGoalProgress($goal, $user);
        }

        foreach ($overrides as $goalOverride) {
            $effectiveGoals[$this->goalKey((int) $goalOverride->platform_id, $goalOverride->metric, $goalOverride->period)] = $this->formatSingleUserGoalOverrideProgress($goalOverride, $user);
        }

        return collect($effectiveGoals)
            ->values()
            ->all();
    }

    public function computeDailyStats(CarbonInterface $date): int
    {
        $dayStart = Carbon::instance($date)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        $metrics = $this->aggregateAuditMetrics($dayStart, $dayEnd, null, null, null, true);
        $revenueByRow = $this->aggregateDailyRevenueRows($dayStart, $dayEnd);
        $platformCurrencies = Platform::query()->pluck('currency_code', 'id');

        $payloads = [];
        $rowKeys = collect(array_merge(array_keys($metrics), array_keys($revenueByRow)))
            ->unique()
            ->values();

        foreach ($rowKeys as $key) {
            [$userId, $platformId] = explode(':', (string) $key);
            $metricRow = $metrics[$key] ?? $this->emptyMetricRow();
            $revenueRow = $revenueByRow[$key] ?? [
                'revenue' => '0.00',
                'revenue_currency' => (string) ($platformCurrencies[(int) $platformId] ?? ''),
            ];

            $payloads[] = [
                'user_id' => (int) $userId,
                'platform_id' => (int) $platformId,
                'date' => $dayStart->toDateString(),
                'profiles_created' => (int) $metricRow['profiles_created'],
                'subs_activated' => (int) $metricRow['subs_activated'],
                'subs_renewed' => (int) $metricRow['subs_renewed'],
                'payments_matched' => (int) $metricRow['payments_matched'],
                'subscriptions_created' => (int) $metricRow['subscriptions_created'],
                'leads_contacted' => (int) $metricRow['leads_contacted'],
                'leads_converted' => (int) $metricRow['leads_converted'],
                'chats_replied' => (int) $metricRow['chats_replied'],
                'sms_sent' => (int) $metricRow['sms_sent'],
                'credentials_sent' => (int) $metricRow['credentials_sent'],
                'revenue' => $revenueRow['revenue'],
                'revenue_currency' => $revenueRow['revenue_currency'] ?: (string) ($platformCurrencies[(int) $platformId] ?? ''),
                'free_trials_given' => (int) $metricRow['free_trials_given'],
                'avg_lead_response_secs' => $metricRow['avg_lead_response_secs'],
                'total_actions' => (int) $metricRow['total_actions'],
            ];
        }

        if (empty($payloads)) {
            return 0;
        }

        AgentDailyStat::query()->upsert(
            $payloads,
            ['user_id', 'platform_id', 'date'],
            [
                'profiles_created',
                'subs_activated',
                'subs_renewed',
                'payments_matched',
                'subscriptions_created',
                'leads_contacted',
                'leads_converted',
                'chats_replied',
                'sms_sent',
                'credentials_sent',
                'revenue',
                'revenue_currency',
                'free_trials_given',
                'avg_lead_response_secs',
                'total_actions',
            ]
        );

        return count($payloads);
    }

    public function availableGoalMetrics(): array
    {
        return collect(self::GOAL_METRICS)
            ->map(fn (string $metric) => [
                'value' => $metric,
                'label' => $this->metricLabel($metric),
                'allowed_role_scopes' => $this->allowedGoalRoleScopesForMetric($metric),
            ])
            ->values()
            ->all();
    }

    public function availableGoalRoleScopes(): array
    {
        return collect(self::GOAL_ROLE_SCOPES)
            ->map(fn (string $scope) => [
                'value' => $scope,
                'label' => $this->goalRoleScopeLabel($scope),
            ])
            ->values()
            ->all();
    }

    private function aggregateActionMetricsForRange(
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        ?array $userIds
    ): array {
        if ($start->gte($end)) {
            return [];
        }

        $todayStart = now()->startOfDay();
        $metrics = [];

        if ($start->lt($todayStart)) {
            $historicalEnd = $end->lt($todayStart) ? Carbon::instance($end) : $todayStart->copy();
            $metrics = $this->mergeMetricMaps(
                $metrics,
                $this->aggregateDailyStatMetrics($start, $historicalEnd, $viewer, $platformId, $userIds)
            );
        }

        if ($end->gt($todayStart)) {
            $liveStart = $start->gt($todayStart) ? Carbon::instance($start) : $todayStart->copy();
            $metrics = $this->mergeMetricMaps(
                $metrics,
                $this->aggregateAuditMetrics($liveStart, $end, $viewer, $platformId, $userIds)
            );
        }

        return $this->finalizeMetricMap($metrics);
    }

    private function aggregateDailyStatMetrics(
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        ?array $userIds
    ): array {
        if ($start->gte($end)) {
            return [];
        }

        $query = AgentDailyStat::query()
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $end->toDateString());

        if ($userIds !== null) {
            if (empty($userIds)) {
                return [];
            }

            $query->whereIn('user_id', $userIds);
        }

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
        }

        $rows = $query->get();
        $metrics = [];

        foreach ($rows as $row) {
            $userKey = (int) $row->user_id;
            $entry = $metrics[$userKey] ?? $this->emptyMetricRow();

            foreach (self::COUNT_METRIC_KEYS as $metric) {
                $entry[$metric] += (int) ($row->{$metric} ?? 0);
            }

            $leadCount = (int) ($row->leads_contacted ?? 0);
            if ($leadCount > 0 && $row->avg_lead_response_secs !== null) {
                $entry['_lead_response_total'] += ((int) $row->avg_lead_response_secs) * $leadCount;
                $entry['_lead_response_count'] += $leadCount;
            }

            $metrics[$userKey] = $entry;
        }

        return $metrics;
    }

    private function aggregateAuditMetrics(
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        ?array $userIds,
        bool $groupByPlatform = false
    ): array {
        if ($start->gte($end)) {
            return [];
        }

        $query = AuditLog::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);

        if ($userIds !== null) {
            if (empty($userIds)) {
                return [];
            }

            $query->whereIn('actor_id', $userIds);
        }

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
        }

        $logs = $query
            ->orderBy('id')
            ->get([
                'id',
                'platform_id',
                'actor_id',
                'action',
                'entity_type',
                'entity_id',
                'after_state',
                'created_at',
                'reason',
            ]);

        $contactLeadIds = $logs
            ->filter(fn (AuditLog $log) => $this->isLeadContactedLog($log))
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $leadsById = Lead::query()
            ->whereIn('id', $contactLeadIds)
            ->get(['id', 'created_at'])
            ->keyBy('id');

        $metrics = [];

        foreach ($logs as $log) {
            $key = $groupByPlatform
                ? ((int) $log->actor_id . ':' . (int) $log->platform_id)
                : (int) $log->actor_id;

            $entry = $metrics[$key] ?? $this->emptyMetricRow();
            $this->applyAuditLogToMetricRow($entry, $log, $leadsById);
            $metrics[$key] = $entry;
        }

        return $metrics;
    }

    private function applyAuditLogToMetricRow(array &$entry, AuditLog $log, Collection $leadsById): void
    {
        switch ((string) $log->action) {
            case 'client_create':
                $entry['profiles_created']++;
                break;

            case 'deal_activate':
                if (($log->after_state['deal_status'] ?? null) === 'active') {
                    $entry['subs_activated']++;
                }
                break;

            case 'deal_renew':
                if (($log->after_state['new_status'] ?? null) === 'active') {
                    $entry['subs_renewed']++;
                }
                break;

            case 'deal_free_trial':
                $entry['free_trials_given']++;
                break;

            case 'payment_match_confirm':
            case 'payment_match_auto':
                $entry['payments_matched']++;
                break;

            case 'payment_create_subscription':
                $entry['subscriptions_created']++;
                break;

            case 'conversation_sms_sent':
            case 'renewal_sms_sent':
                $entry['sms_sent']++;
                break;

            case 'lead_status_update':
                if ($this->isLeadContactedLog($log)) {
                    $entry['leads_contacted']++;

                    $lead = $leadsById->get((int) $log->entity_id);
                    if ($lead && $lead->created_at instanceof CarbonInterface && $log->created_at instanceof CarbonInterface) {
                        $entry['_lead_response_total'] += $lead->created_at->diffInSeconds($log->created_at);
                        $entry['_lead_response_count']++;
                    }
                }
                break;

            case 'lead_convert_to_client':
                $entry['leads_converted']++;
                break;

            case 'support_chat_reply':
                $entry['chats_replied']++;
                break;

            case 'client_credential_send':
                $entry['credentials_sent']++;
                break;
        }

        $entry['total_actions'] = $this->recalculateTotalActions($entry);
    }

    private function aggregateRevenueByUser(
        array $userIds,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId
    ): array {
        if (empty($userIds) || $start->gte($end)) {
            return [];
        }

        $query = Deal::query()
            ->whereIn('assigned_to', $userIds)
            ->whereNotNull('activated_at')
            ->where('activated_at', '>=', $start)
            ->where('activated_at', '<', $end)
            ->where('is_free_trial', false);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
        }

        $rows = $query->get(['assigned_to', 'amount', 'currency']);
        $grouped = [];

        foreach ($rows as $row) {
            $userKey = (int) $row->assigned_to;
            $currency = strtoupper((string) ($row->currency ?: ''));
            if ($currency === '') {
                continue;
            }

            $grouped[$userKey][$currency] = ($grouped[$userKey][$currency] ?? 0.0) + (float) $row->amount;
        }

        $payloads = [];
        foreach ($grouped as $userKey => $currencyBreakdown) {
            ksort($currencyBreakdown);
            $payloads[$userKey] = $this->buildRevenuePayload($currencyBreakdown, $platformId);
        }

        return $payloads;
    }

    private function aggregateDailyRevenueRows(CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = Deal::query()
            ->whereNotNull('activated_at')
            ->where('activated_at', '>=', $start)
            ->where('activated_at', '<', $end)
            ->whereNotNull('assigned_to')
            ->where('is_free_trial', false)
            ->get(['assigned_to', 'platform_id', 'amount', 'currency']);

        $grouped = [];

        foreach ($rows as $row) {
            $key = (int) $row->assigned_to . ':' . (int) $row->platform_id;
            $grouped[$key] ??= [
                'revenue' => 0.0,
                'revenue_currency' => strtoupper((string) ($row->currency ?: '')),
            ];
            $grouped[$key]['revenue'] += (float) $row->amount;

            if ($grouped[$key]['revenue_currency'] === '' && $row->currency) {
                $grouped[$key]['revenue_currency'] = strtoupper((string) $row->currency);
            }
        }

        foreach ($grouped as $key => $payload) {
            $grouped[$key]['revenue'] = number_format((float) $payload['revenue'], 2, '.', '');
        }

        return $grouped;
    }

    private function aggregateSessionTotals(array $userIds, CarbonInterface $start, CarbonInterface $end): array
    {
        if (empty($userIds) || $start->gte($end)) {
            return [];
        }

        $sessions = AgentSession::query()
            ->whereIn('user_id', $userIds)
            ->where('started_at', '<', $end)
            ->where(function ($query) use ($start) {
                $query->where(function ($subQuery) use ($start) {
                    $subQuery->whereNull('ended_at')
                        ->where('last_heartbeat_at', '>', $start);
                })->orWhere('ended_at', '>', $start);
            })
            ->get(['user_id', 'started_at', 'last_heartbeat_at', 'ended_at']);

        $totals = [];

        foreach ($sessions as $session) {
            $effectiveEnd = $session->ended_at ?: $session->last_heartbeat_at;
            if (!$effectiveEnd || !$session->started_at) {
                continue;
            }

            $clampedStart = $session->started_at->greaterThan($start) ? $session->started_at : Carbon::instance($start);
            $clampedEnd = $effectiveEnd->lessThan($end) ? $effectiveEnd : Carbon::instance($end);

            if ($clampedEnd->lte($clampedStart)) {
                continue;
            }

            $userId = (int) $session->user_id;
            $totals[$userId] ??= [
                'active_seconds' => 0,
                'session_count' => 0,
            ];
            $totals[$userId]['active_seconds'] += $clampedStart->diffInSeconds($clampedEnd);
            $totals[$userId]['session_count']++;
        }

        return $totals;
    }

    private function visibleAgentsForViewer(User $viewer, ?int $platformId = null): Collection
    {
        $agents = User::query()
            ->where('status', 'active')
            ->whereIn('role', self::AGENT_ROLES)
            ->with('platforms:id')
            ->orderBy('name')
            ->get();

        if ($viewer->role === MarketAuthorizationService::ROLE_ADMIN) {
            return $agents
                ->filter(fn (User $agent) => $platformId === null || $this->userHasPlatform($agent, $platformId))
                ->values();
        }

        $viewerPlatforms = $this->accessiblePlatformIdsForUser($viewer);
        if (!is_array($viewerPlatforms) || empty($viewerPlatforms)) {
            return collect();
        }

        return $agents
            ->filter(function (User $agent) use ($platformId, $viewerPlatforms) {
                if ($platformId !== null) {
                    return in_array($platformId, $viewerPlatforms, true) && $this->userHasPlatform($agent, $platformId);
                }

                return $this->userHasPlatformOverlap($viewerPlatforms, $agent);
            })
            ->values();
    }

    private function userHasPlatform(User $candidate, int $platformId): bool
    {
        $candidatePlatforms = $this->accessiblePlatformIdsForUser($candidate);

        if ($candidatePlatforms === null) {
            return true;
        }

        return in_array($platformId, $candidatePlatforms, true);
    }

    private function userHasPlatformOverlap(array $viewerPlatformIds, User $candidate): bool
    {
        if (empty($viewerPlatformIds)) {
            return false;
        }

        $candidatePlatforms = $this->accessiblePlatformIdsForUser($candidate);
        if ($candidatePlatforms === null) {
            return true;
        }

        return !empty(array_intersect($viewerPlatformIds, $candidatePlatforms));
    }

    private function accessiblePlatformIdsForUser(User $user): ?array
    {
        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($user);

        if ($platformIds === null) {
            return null;
        }

        return collect($platformIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function availablePlatformsForUser(User $user): array
    {
        $platformIds = $this->accessiblePlatformIdsForUser($user);

        return Platform::query()
            ->where('is_active', true)
            ->when(is_array($platformIds), function ($query) use ($platformIds) {
                if (empty($platformIds)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->whereIn('id', $platformIds);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'country', 'currency_code'])
            ->map(fn (Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'platform_name' => $platform->name,
                'country' => $platform->country,
                'currency' => $platform->currency_code,
            ])
            ->values()
            ->all();
    }

    private function assertManager(User $viewer): void
    {
        $this->marketAuthorizationService->ensureManager($viewer);
    }

    private function assertAgentVisibleToViewer(User $viewer, User $agent): void
    {
        $this->assertManager($viewer);

        if (!in_array($agent->role, self::AGENT_ROLES, true) || !$agent->isActive()) {
            abort(404, 'Agent not found.');
        }

        if ($viewer->role === MarketAuthorizationService::ROLE_ADMIN) {
            return;
        }

        $viewerPlatforms = $this->accessiblePlatformIdsForUser($viewer);
        if (!is_array($viewerPlatforms) || empty($viewerPlatforms) || !$this->userHasPlatformOverlap($viewerPlatforms, $agent)) {
            abort(403, 'You do not have access to this agent.');
        }
    }

    private function assertPlatformAccessible(User $user, ?int $platformId): void
    {
        if ($platformId === null) {
            return;
        }

        $this->marketAuthorizationService->ensureUserCanAccessPlatform($user, $platformId);
    }

    private function latestActionsByUser(array $userIds, ?User $viewer, ?int $platformId): array
    {
        if (empty($userIds)) {
            return [];
        }

        $query = AuditLog::query()
            ->whereIn('actor_id', $userIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
        }

        $logs = $query->get(['id', 'actor_id', 'action', 'entity_type', 'entity_id', 'platform_id', 'created_at', 'reason']);
        $latest = [];

        foreach ($logs as $log) {
            $userId = (int) $log->actor_id;
            if (isset($latest[$userId])) {
                continue;
            }

            $latest[$userId] = [
                'action' => $log->action,
                'label' => $this->activityLabel($log),
                'entity_type' => $log->entity_type,
                'entity_id' => (int) $log->entity_id,
                'platform_id' => (int) $log->platform_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        }

        return $latest;
    }

    private function presenceFlagsByUser(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $cutoff = $this->staleCutoff();

        return AgentSession::query()
            ->open()
            ->whereIn('user_id', $userIds)
            ->where('last_heartbeat_at', '>=', $cutoff)
            ->get(['user_id'])
            ->pluck('user_id')
            ->mapWithKeys(fn ($userId) => [(int) $userId => true])
            ->all();
    }

    private function recentActivity(User $agent, ?User $viewer, ?int $platformId, int $limit = 20): array
    {
        $query = AuditLog::query()
            ->where('actor_id', $agent->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
        }

        return $query->get()->map(fn (AuditLog $log) => $this->formatActivityLog($log))->all();
    }

    private function formatActivityLog(AuditLog $log): array
    {
        return [
            'id' => (int) $log->id,
            'action' => $log->action,
            'label' => $this->activityLabel($log),
            'entity_type' => $log->entity_type,
            'entity_id' => (int) $log->entity_id,
            'entity_url' => $this->entityUrl($log->entity_type, (int) $log->entity_id),
            'platform_id' => (int) $log->platform_id,
            'reason' => $log->reason,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function activityLabel(AuditLog $log): string
    {
        return match ((string) $log->action) {
            'client_create' => 'Created client profile',
            'deal_activate' => (($log->after_state['deal_status'] ?? null) === 'active')
                ? 'Activated subscription'
                : 'Started subscription activation',
            'deal_renew' => (($log->after_state['new_status'] ?? null) === 'active')
                ? 'Renewed subscription'
                : 'Started renewal',
            'deal_free_trial' => 'Approved free trial',
            'payment_match_confirm' => 'Matched payment manually',
            'payment_match_auto' => 'Matched payment automatically',
            'payment_create_subscription' => 'Created subscription from payment',
            'conversation_sms_sent' => 'Sent conversation SMS',
            'renewal_sms_sent' => 'Sent renewal SMS',
            'lead_status_update' => (($log->after_state['status'] ?? null) === 'contacted')
                ? 'Contacted lead'
                : 'Updated lead status',
            'lead_convert_to_client' => 'Converted lead to client',
            'support_chat_reply' => 'Replied in support chat',
            'client_credential_send' => 'Sent credentials',
            default => ucwords(str_replace('_', ' ', (string) $log->action)),
        };
    }

    private function entityUrl(string $entityType, int $entityId): ?string
    {
        return match ($entityType) {
            'client' => '/clients/' . $entityId,
            'lead' => '/leads/' . $entityId,
            'deal' => '/deals/' . $entityId,
            'payment' => '/payments/' . $entityId,
            default => null,
        };
    }

    private function formatGoal(AgentGoal $goal, Collection $agents, User $viewer): array
    {
        $progress = $agents
            ->filter(fn (User $agent) => $this->goalAppliesToUser($goal, $agent))
            ->map(fn (User $agent) => $this->formatGoalProgressRow($goal, $agent, $viewer))
            ->values()
            ->all();

        return [
            'id' => (int) $goal->id,
            'goal_type' => 'default',
            'metric' => $goal->metric,
            'label' => $this->metricLabel($goal->metric),
            'target' => (int) $goal->target,
            'period' => $goal->period,
            'platform_id' => $goal->platform_id ? (int) $goal->platform_id : null,
            'platform_name' => $goal->platform?->name,
            'role_scope' => $goal->role_scope,
            'role_scope_label' => $this->goalRoleScopeLabel($goal->role_scope),
            'set_by' => $goal->setter ? [
                'id' => (int) $goal->setter->id,
                'name' => $goal->setter->name,
            ] : null,
            'progress' => $progress,
        ];
    }

    private function formatGoalOverride(AgentGoalOverride $goalOverride, User $viewer): array
    {
        return [
            'id' => (int) $goalOverride->id,
            'goal_type' => 'individual',
            'metric' => $goalOverride->metric,
            'label' => $this->metricLabel($goalOverride->metric),
            'target' => (int) $goalOverride->target,
            'period' => $goalOverride->period,
            'platform_id' => (int) $goalOverride->platform_id,
            'platform_name' => $goalOverride->platform?->name,
            'user' => $goalOverride->user ? [
                'id' => (int) $goalOverride->user->id,
                'name' => $goalOverride->user->name,
                'role' => $goalOverride->user->role,
            ] : null,
            'set_by' => $goalOverride->setter ? [
                'id' => (int) $goalOverride->setter->id,
                'name' => $goalOverride->setter->name,
            ] : null,
            'progress' => $goalOverride->user
                ? $this->formatGoalOverrideProgressRow($goalOverride, $goalOverride->user, $viewer)
                : null,
        ];
    }

    private function formatGoalProgressRow(AgentGoal $goal, User $agent, User $viewer): array
    {
        $range = $this->goalPeriodRange($goal->period);
        $platformId = $goal->platform_id ? (int) $goal->platform_id : null;
        $metrics = $this->aggregateActionMetricsForRange($range['start'], $range['end'], $viewer, $platformId, [$agent->id]);
        $current = (int) (($metrics[$agent->id][$goal->metric] ?? 0));
        $percentage = $goal->target > 0
            ? (int) min(100, round(($current / $goal->target) * 100))
            : 0;

        return [
            'user_id' => (int) $agent->id,
            'name' => $agent->name,
            'role' => $agent->role,
            'current' => $current,
            'target' => (int) $goal->target,
            'percentage' => $percentage,
        ];
    }

    private function formatGoalOverrideProgressRow(AgentGoalOverride $goalOverride, User $agent, User $viewer): array
    {
        $range = $this->goalPeriodRange($goalOverride->period);
        $platformId = (int) $goalOverride->platform_id;
        $metrics = $this->aggregateActionMetricsForRange($range['start'], $range['end'], $viewer, $platformId, [$agent->id]);
        $current = (int) (($metrics[$agent->id][$goalOverride->metric] ?? 0));
        $percentage = $goalOverride->target > 0
            ? (int) min(100, round(($current / $goalOverride->target) * 100))
            : 0;

        return [
            'user_id' => (int) $agent->id,
            'name' => $agent->name,
            'role' => $agent->role,
            'current' => $current,
            'target' => (int) $goalOverride->target,
            'percentage' => $percentage,
        ];
    }

    private function formatAssignableGoalAgent(User $agent): array
    {
        return [
            'user_id' => (int) $agent->id,
            'name' => $agent->name,
            'role' => $agent->role,
        ];
    }

    private function formatSingleUserGoalProgress(AgentGoal $goal, User $user): array
    {
        $range = $this->goalPeriodRange($goal->period);
        $platformId = $goal->platform_id ? (int) $goal->platform_id : null;
        $metrics = $this->aggregateActionMetricsForRange($range['start'], $range['end'], $user, $platformId, [$user->id]);
        $current = (int) (($metrics[$user->id][$goal->metric] ?? 0));
        $percentage = $goal->target > 0
            ? (int) min(100, round(($current / $goal->target) * 100))
            : 0;

        return [
            'goal_id' => (int) $goal->id,
            'goal_type' => 'default',
            'source_type' => 'default',
            'metric' => $goal->metric,
            'label' => $this->metricLabel($goal->metric),
            'period' => $goal->period,
            'target' => (int) $goal->target,
            'current' => $current,
            'percentage' => $percentage,
            'platform_id' => $goal->platform_id ? (int) $goal->platform_id : null,
            'platform_name' => $goal->platform?->name,
            'role_scope' => $goal->role_scope,
            'role_scope_label' => $this->goalRoleScopeLabel($goal->role_scope),
        ];
    }

    private function formatSingleUserGoalOverrideProgress(AgentGoalOverride $goalOverride, User $user): array
    {
        $range = $this->goalPeriodRange($goalOverride->period);
        $platformId = (int) $goalOverride->platform_id;
        $metrics = $this->aggregateActionMetricsForRange($range['start'], $range['end'], $user, $platformId, [$user->id]);
        $current = (int) (($metrics[$user->id][$goalOverride->metric] ?? 0));
        $percentage = $goalOverride->target > 0
            ? (int) min(100, round(($current / $goalOverride->target) * 100))
            : 0;

        return [
            'goal_id' => (int) $goalOverride->id,
            'goal_type' => 'individual',
            'source_type' => 'override',
            'metric' => $goalOverride->metric,
            'label' => $this->metricLabel($goalOverride->metric),
            'period' => $goalOverride->period,
            'target' => (int) $goalOverride->target,
            'current' => $current,
            'percentage' => $percentage,
            'platform_id' => (int) $goalOverride->platform_id,
            'platform_name' => $goalOverride->platform?->name,
            'role_scope' => null,
            'role_scope_label' => null,
        ];
    }

    private function goalsQuery(User $viewer, ?int $platformId, string $period)
    {
        return AgentGoal::query()
            ->where('period', $period)
            ->where(function ($query) use ($viewer, $platformId) {
                $query->whereNull('platform_id');

                if ($platformId) {
                    $query->orWhere('platform_id', $platformId);
                    return;
                }

                $accessiblePlatforms = $this->accessiblePlatformIdsForUser($viewer);
                if ($accessiblePlatforms === null) {
                    $query->orWhereNotNull('platform_id');
                    return;
                }

                if (!empty($accessiblePlatforms)) {
                    $query->orWhereIn('platform_id', $accessiblePlatforms);
                }
            });
    }

    private function goalOverridesQuery(User $viewer, ?int $platformId, string $period)
    {
        $visibleAgentIds = $this->visibleAgentsForViewer($viewer, $platformId)
            ->pluck('id')
            ->all();

        return AgentGoalOverride::query()
            ->where('period', $period)
            ->when(empty($visibleAgentIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(!empty($visibleAgentIds), fn ($query) => $query->whereIn('user_id', $visibleAgentIds))
            ->when($platformId !== null, fn ($query) => $query->where('platform_id', $platformId))
            ->when($platformId === null, function ($query) use ($viewer) {
                $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
            });
    }

    private function goalKey(?int $platformId, string $metric, string $period): string
    {
        return implode(':', [
            $platformId === null ? 'all' : (string) $platformId,
            $metric,
            $period,
        ]);
    }

    private function goalAppliesToUser(AgentGoal $goal, User $user): bool
    {
        if (!$this->goalRoleScopeMatchesUser($goal->role_scope, $user)) {
            return false;
        }

        return $this->goalMetricAllowedForRole($goal->metric, $user->role);
    }

    private function goalRoleScopeMatchesUser(string $roleScope, User $user): bool
    {
        if ($roleScope === self::GOAL_ROLE_SCOPE_ALL) {
            return in_array($user->role, self::AGENT_ROLES, true);
        }

        return $user->role === $roleScope;
    }

    private function allowedGoalRoleScopesForMetric(string $metric): array
    {
        return self::GOAL_METRIC_ROLE_SCOPES[$metric] ?? [self::GOAL_ROLE_SCOPE_SALES];
    }

    private function goalMetricAllowedForRoleScope(string $metric, string $roleScope): bool
    {
        return in_array($roleScope, $this->allowedGoalRoleScopesForMetric($metric), true);
    }

    private function goalMetricAllowedForRole(string $metric, string $role): bool
    {
        if (!in_array($role, self::AGENT_ROLES, true)) {
            return false;
        }

        return in_array($role, $this->allowedGoalRoleScopesForMetric($metric), true)
            || in_array(self::GOAL_ROLE_SCOPE_ALL, $this->allowedGoalRoleScopesForMetric($metric), true);
    }

    private function assertGoalMetricAllowedForRoleScope(string $metric, string $roleScope): void
    {
        if (!$this->goalMetricAllowedForRoleScope($metric, $roleScope)) {
            abort(422, 'This metric is not supported for the selected role scope.');
        }
    }

    private function assertGoalMetricAllowedForRole(string $metric, string $role): void
    {
        if (!$this->goalMetricAllowedForRole($metric, $role)) {
            abort(422, 'This metric is not supported for the selected user role.');
        }
    }

    private function assertGoalAssigneeAccessible(User $viewer, User $agent, int $platformId): void
    {
        $this->assertAgentVisibleToViewer($viewer, $agent);

        if (!$this->userHasPlatform($agent, $platformId)) {
            abort(422, 'The selected user does not have access to this market.');
        }
    }

    private function buildUserSummary(array $metrics, array $sessions, array $revenue): array
    {
        return array_merge($metrics, [
            'active_seconds' => (int) ($sessions['active_seconds'] ?? 0),
            'session_count' => (int) ($sessions['session_count'] ?? 0),
        ], $revenue);
    }

    private function buildTrendPayload(array $current, array $previous): array
    {
        $keys = [
            'profiles_created',
            'subs_activated',
            'subs_renewed',
            'payments_matched',
            'subscriptions_created',
            'leads_contacted',
            'leads_converted',
            'chats_replied',
            'sms_sent',
            'credentials_sent',
            'free_trials_given',
            'total_actions',
            'active_seconds',
        ];

        $payload = [];
        foreach ($keys as $key) {
            $currentValue = (int) ($current[$key] ?? 0);
            $previousValue = (int) ($previous[$key] ?? 0);
            $delta = $currentValue - $previousValue;

            $payload[$key] = [
                'current' => $currentValue,
                'previous' => $previousValue,
                'delta' => $delta,
                'direction' => $delta === 0 ? 'flat' : ($delta > 0 ? 'up' : 'down'),
                'percentage_change' => $previousValue === 0
                    ? ($currentValue > 0 ? 100 : 0)
                    : (int) round(($delta / $previousValue) * 100),
            ];
        }

        return $payload;
    }

    private function emptyMetricRow(): array
    {
        return [
            'profiles_created' => 0,
            'subs_activated' => 0,
            'subs_renewed' => 0,
            'payments_matched' => 0,
            'subscriptions_created' => 0,
            'leads_contacted' => 0,
            'leads_converted' => 0,
            'chats_replied' => 0,
            'sms_sent' => 0,
            'credentials_sent' => 0,
            'free_trials_given' => 0,
            'avg_lead_response_secs' => null,
            'total_actions' => 0,
            '_lead_response_total' => 0,
            '_lead_response_count' => 0,
        ];
    }

    private function mergeMetricMaps(array $left, array $right): array
    {
        foreach ($right as $key => $row) {
            $left[$key] = $this->mergeMetricRow($left[$key] ?? $this->emptyMetricRow(), $row);
        }

        return $left;
    }

    private function mergeMetricRow(array $left, array $right): array
    {
        foreach (self::COUNT_METRIC_KEYS as $metric) {
            $left[$metric] += (int) ($right[$metric] ?? 0);
        }

        $left['_lead_response_total'] += (int) ($right['_lead_response_total'] ?? 0);
        $left['_lead_response_count'] += (int) ($right['_lead_response_count'] ?? 0);
        $left['total_actions'] = $this->recalculateTotalActions($left);

        return $left;
    }

    private function finalizeMetricMap(array $metrics): array
    {
        foreach ($metrics as $key => $row) {
            $metrics[$key]['avg_lead_response_secs'] = ($row['_lead_response_count'] ?? 0) > 0
                ? (int) round($row['_lead_response_total'] / $row['_lead_response_count'])
                : null;

            unset($metrics[$key]['_lead_response_total'], $metrics[$key]['_lead_response_count']);
        }

        return $metrics;
    }

    private function recalculateTotalActions(array $row): int
    {
        return (int) (
            ($row['profiles_created'] ?? 0)
            + ($row['subs_activated'] ?? 0)
            + ($row['subs_renewed'] ?? 0)
            + ($row['payments_matched'] ?? 0)
            + ($row['subscriptions_created'] ?? 0)
            + ($row['leads_contacted'] ?? 0)
            + ($row['leads_converted'] ?? 0)
            + ($row['chats_replied'] ?? 0)
            + ($row['sms_sent'] ?? 0)
            + ($row['credentials_sent'] ?? 0)
            + ($row['free_trials_given'] ?? 0)
        );
    }

    private function buildRevenuePayload(array $currencyBreakdown, ?int $platformId): array
    {
        $rows = collect($currencyBreakdown)
            ->filter(fn ($amount) => round((float) $amount, 2) > 0)
            ->map(fn ($amount, $currency) => [
                'currency' => (string) $currency,
                'amount' => number_format((float) $amount, 2, '.', ''),
            ])
            ->values()
            ->all();

        $display = collect($rows)
            ->map(fn (array $row) => $row['currency'] . ' ' . $this->formatMoney((float) $row['amount']))
            ->implode(' | ');

        if ($platformId !== null) {
            $single = $rows[0] ?? [
                'currency' => null,
                'amount' => '0.00',
            ];

            return [
                'revenue_total' => $single['amount'],
                'revenue_currency' => $single['currency'],
                'revenue_by_currency' => $rows,
                'revenue_display' => $single['currency']
                    ? ($single['currency'] . ' ' . $this->formatMoney((float) $single['amount']))
                    : '--',
            ];
        }

        return [
            'revenue_total' => null,
            'revenue_currency' => null,
            'revenue_by_currency' => $rows,
            'revenue_display' => $display !== '' ? $display : '--',
        ];
    }

    private function emptyRevenuePayload(?int $platformId): array
    {
        return [
            'revenue_total' => $platformId !== null ? '0.00' : null,
            'revenue_currency' => null,
            'revenue_by_currency' => [],
            'revenue_display' => '--',
        ];
    }

    private function metricLabel(string $metric): string
    {
        return match ($metric) {
            'profiles_created' => 'Profiles Created',
            'subs_activated' => 'Subscriptions Activated',
            'subs_renewed' => 'Subscriptions Renewed',
            'payments_matched' => 'Payments Matched',
            'subscriptions_created' => 'Subscriptions Created',
            'leads_contacted' => 'Leads Contacted',
            'leads_converted' => 'Leads Converted',
            'chats_replied' => 'Chats Replied',
            'sms_sent' => 'SMS Sent',
            'credentials_sent' => 'Credentials Sent',
            'free_trials_given' => 'Free Trials Given',
            'total_actions' => 'Total Actions',
            default => ucwords(str_replace('_', ' ', $metric)),
        };
    }

    private function goalRoleScopeLabel(string $roleScope): string
    {
        return match ($roleScope) {
            self::GOAL_ROLE_SCOPE_SALES => 'Sales only',
            self::GOAL_ROLE_SCOPE_MARKETING => 'Marketing only',
            self::GOAL_ROLE_SCOPE_ALL => 'Everyone',
            default => ucwords(str_replace('_', ' ', $roleScope)),
        };
    }

    private function formatMoney(float $amount): string
    {
        $decimals = abs($amount - floor($amount)) < 0.00001 ? 0 : 2;

        return number_format($amount, $decimals, '.', ',');
    }

    private function normalizeNamedPeriod(string $period): string
    {
        $period = strtolower(trim($period));

        return match ($period) {
            self::PERIOD_TODAY, self::PERIOD_WEEK, self::PERIOD_MONTH => $period,
            default => self::PERIOD_WEEK,
        };
    }

    private function normalizeGoalPeriod(string $period): string
    {
        $period = strtolower(trim($period));

        return match ($period) {
            self::GOAL_PERIOD_WEEKLY, self::GOAL_PERIOD_MONTHLY => $period,
            default => self::GOAL_PERIOD_WEEKLY,
        };
    }

    private function normalizeGoalMetric(string $metric): string
    {
        $metric = strtolower(trim($metric));

        if (!in_array($metric, self::GOAL_METRICS, true)) {
            abort(422, 'Unsupported goal metric.');
        }

        return $metric;
    }

    private function normalizeGoalRoleScope(string $roleScope): string
    {
        $roleScope = strtolower(trim($roleScope));

        return match ($roleScope) {
            self::GOAL_ROLE_SCOPE_SALES,
            self::GOAL_ROLE_SCOPE_MARKETING,
            self::GOAL_ROLE_SCOPE_ALL => $roleScope,
            default => self::GOAL_ROLE_SCOPE_SALES,
        };
    }

    private function resolveNamedPeriodRange(string $period): array
    {
        $now = now();

        return match ($this->normalizeNamedPeriod($period)) {
            self::PERIOD_TODAY => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy(),
            ],
            self::PERIOD_MONTH => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy(),
            ],
            default => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy(),
            ],
        };
    }

    private function previousRange(CarbonInterface $start, CarbonInterface $end): array
    {
        $seconds = max(1, $start->diffInSeconds($end));

        return [
            'start' => Carbon::instance($start)->subSeconds($seconds),
            'end' => Carbon::instance($start),
        ];
    }

    private function goalPeriodRange(string $period): array
    {
        $now = now();

        return match ($this->normalizeGoalPeriod($period)) {
            self::GOAL_PERIOD_MONTHLY => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy(),
            ],
            default => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy(),
            ],
        };
    }

    private function isLeadContactedLog(AuditLog $log): bool
    {
        return $log->action === 'lead_status_update'
            && ($log->after_state['status'] ?? null) === 'contacted';
    }

    private function staleCutoff(?CarbonInterface $reference = null): Carbon
    {
        return Carbon::instance($reference ?: now())->subMinutes(2);
    }
}
