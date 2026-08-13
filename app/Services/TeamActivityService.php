<?php

namespace App\Services;

use App\Models\AgentDailyStat;
use App\Models\AgentGoal;
use App\Models\AgentGoalOverride;
use App\Models\AgentSession;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\MarketRevenueTarget;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Support\CrmAuditAction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamActivityService
{
    public const PERIOD_TODAY = 'today';

    public const PERIOD_WEEK = 'week';

    public const PERIOD_MONTH = 'month';

    public const PERIOD_30_DAYS = '30d';

    public const GOAL_PERIOD_WEEKLY = 'weekly';

    public const GOAL_PERIOD_MONTHLY = 'monthly';

    public const AGENT_ROLES = [
        MarketAuthorizationService::ROLE_SUB_ADMIN,
        MarketAuthorizationService::ROLE_SALES,
        MarketAuthorizationService::ROLE_FIELD_SALES,
        MarketAuthorizationService::ROLE_MARKETING,
    ];

    public const ROLE_FILTER_ALL = 'all';

    public const ROLE_FILTERS = [
        self::ROLE_FILTER_ALL,
        MarketAuthorizationService::ROLE_ADMIN,
        MarketAuthorizationService::ROLE_SUB_ADMIN,
        MarketAuthorizationService::ROLE_SALES,
        MarketAuthorizationService::ROLE_FIELD_SALES,
        MarketAuthorizationService::ROLE_MARKETING,
    ];

    private const ADMIN_VISIBLE_TEAM_ROLES = [
        MarketAuthorizationService::ROLE_ADMIN,
        MarketAuthorizationService::ROLE_SUB_ADMIN,
        MarketAuthorizationService::ROLE_SALES,
        MarketAuthorizationService::ROLE_FIELD_SALES,
        MarketAuthorizationService::ROLE_MARKETING,
    ];

    private const SYSTEM_ACTIONS = [
        CrmAuditAction::SYSTEM_DEPLOY_START,
        CrmAuditAction::SYSTEM_DEPLOY_SUCCESS,
        CrmAuditAction::SYSTEM_DEPLOY_FAILED,
        CrmAuditAction::INTEGRATION_SYNC_RUN,
        CrmAuditAction::INTEGRATION_CONNECTION_TEST,
        CrmAuditAction::SCRAPER_RUN,
        CrmAuditAction::WHATSAPP_DELIVERED,
        CrmAuditAction::WHATSAPP_READ,
        CrmAuditAction::WHATSAPP_INBOUND_RECEIVED,
    ];

    public const GOAL_ROLE_SCOPE_SALES = MarketAuthorizationService::ROLE_SALES;

    public const GOAL_ROLE_SCOPE_MARKETING = MarketAuthorizationService::ROLE_MARKETING;

    public const GOAL_ROLE_SCOPE_SUB_ADMIN = MarketAuthorizationService::ROLE_SUB_ADMIN;

    public const GOAL_ROLE_SCOPE_ALL = 'all';

    public const GOAL_ROLE_SCOPES = [
        self::GOAL_ROLE_SCOPE_SALES,
        self::GOAL_ROLE_SCOPE_MARKETING,
        self::GOAL_ROLE_SCOPE_SUB_ADMIN,
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
        'discounts_given',
        'revenue',
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
        'discounts_given',
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
        'discounts_given' => [self::GOAL_ROLE_SCOPE_SALES],
        'revenue' => [
            self::GOAL_ROLE_SCOPE_SALES,
            self::GOAL_ROLE_SCOPE_SUB_ADMIN,
            self::GOAL_ROLE_SCOPE_ALL,
        ],
        'total_actions' => [
            self::GOAL_ROLE_SCOPE_SALES,
            self::GOAL_ROLE_SCOPE_MARKETING,
            self::GOAL_ROLE_SCOPE_SUB_ADMIN,
            self::GOAL_ROLE_SCOPE_ALL,
        ],
    ];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly PaymentPresenter $paymentPresenter
    ) {}

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

        if (! $session) {
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

        $agents = $this->visibleTeamMembersForViewer($viewer, $platformId);

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
        $recentOpenSessionsByUser = AgentSession::query()
            ->whereIn('user_id', $agentIds)
            ->whereNull('ended_at')
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '>=', $cutoff)
            ->orderBy('started_at')
            ->get(['user_id', 'started_at', 'last_heartbeat_at'])
            ->groupBy('user_id');
        $lastSeenByUser = AgentSession::query()
            ->whereIn('user_id', $agentIds)
            ->select('user_id')
            ->selectRaw('MAX(COALESCE(ended_at, last_heartbeat_at, started_at)) as last_seen_at')
            ->groupBy('user_id')
            ->pluck('last_seen_at', 'user_id');

        $latestActions = $this->latestActionsByUser($agentIds, $viewer, $platformId);
        $todayMetrics = $this->aggregateActionMetricsForRange(
            now()->startOfDay(),
            now(),
            $viewer,
            $platformId,
            $agentIds
        );

        $rows = $agents->map(function (User $agent) use ($recentOpenSessionsByUser, $lastSeenByUser, $latestActions) {
            $recentOpenSessions = $recentOpenSessionsByUser->get($agent->id, collect())->values();
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

            $lastSeen = $this->safeTimestamp($lastSeenByUser->get($agent->id));

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

    private function safeTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getLeaderboard(
        string $period,
        ?int $platformId,
        User $viewer,
        string $roleFilter = self::ROLE_FILTER_ALL,
        ?string $currencyMode = null,
        ?string $targetCurrency = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null
    ): array {
        $this->assertManager($viewer);
        $this->assertPlatformAccessible($viewer, $platformId);

        $roleFilter = $this->normalizeLeaderboardRoleFilter($roleFilter);
        $currencyMode = $this->reportingCurrencyService->resolveMode($currencyMode, $platformId === null);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);
        $agents = $this->visibleTeamMembersForViewer($viewer, $platformId, $roleFilter);
        $agentIds = $agents->pluck('id')->all();

        if (empty($agentIds)) {
            return [
                'period' => $this->normalizeNamedPeriod($period),
                'from' => $from ? Carbon::instance($from)->toDateString() : null,
                'to' => $to ? Carbon::instance($to)->toDateString() : null,
                'platform_id' => $platformId,
                'role_filter' => $roleFilter,
                'currency_mode' => $currencyMode,
                'reporting_currency' => $targetCurrency,
                'data' => [],
            ];
        }

        ['start' => $start, 'end' => $end] = $this->resolveReportingRange($period, $from, $to);

        $actionMetrics = $this->aggregateActionMetricsForRange($start, $end, $viewer, $platformId, $agentIds);
        $sessionTotals = $this->aggregateSessionTotals($agentIds, $start, $end);
        $revenueTotals = $this->aggregateRevenueByUser($agentIds, $start, $end, $viewer, $platformId, $targetCurrency);
        $presenceFlags = $this->presenceFlagsByUser($agentIds);

        $rowIds = collect(array_merge(array_keys($actionMetrics), array_keys($revenueTotals)))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $rows = $rowIds
            ->map(function (int $userId) use ($agents, $actionMetrics, $sessionTotals, $revenueTotals, $presenceFlags, $platformId) {
                /** @var User|null $agent */
                $agent = $agents->firstWhere('id', $userId);
                if (! $agent) {
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
            ->sort(function (array $left, array $right) use ($currencyMode, $roleFilter) {
                $leftRevenue = $this->leaderboardRevenueSortValue($left, $currencyMode);
                $rightRevenue = $this->leaderboardRevenueSortValue($right, $currencyMode);

                if ($roleFilter === MarketAuthorizationService::ROLE_SALES) {
                    $leftPositive = $leftRevenue !== null && $leftRevenue > 0;
                    $rightPositive = $rightRevenue !== null && $rightRevenue > 0;
                    if ($leftPositive !== $rightPositive) {
                        return $rightPositive ? 1 : -1;
                    }
                }

                if ($leftRevenue !== null && $rightRevenue !== null && $leftRevenue !== $rightRevenue) {
                    return $rightRevenue <=> $leftRevenue;
                }

                if (($cmp = $right['total_actions'] <=> $left['total_actions']) !== 0) {
                    return $cmp;
                }
                if (($cmp = $right['subs_activated'] <=> $left['subs_activated']) !== 0) {
                    return $cmp;
                }
                if (($cmp = $right['subs_renewed'] <=> $left['subs_renewed']) !== 0) {
                    return $cmp;
                }

                return strcasecmp($left['name'], $right['name']);
            })
            ->values()
            ->map(function (array $row, int $index) {
                $row['rank'] = $index + 1;

                return $row;
            })
            ->values();

        return [
            'period' => $this->normalizeNamedPeriod($period),
            'from' => $start->toDateString(),
            'to' => $end->copy()->subSecond()->toDateString(),
            'platform_id' => $platformId,
            'role_filter' => $roleFilter,
            'currency_mode' => $currencyMode,
            'reporting_currency' => $targetCurrency,
            'data' => $rows->all(),
        ];
    }

    public function getAgentStats(
        User $agent,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $platformId = null,
        ?User $viewer = null,
        ?string $targetCurrency = null
    ): array {
        if ($viewer) {
            $this->assertTeamMemberVisibleToViewer($viewer, $agent);
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
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);
        $currentRevenue = $this->aggregateRevenueByUser([$agent->id], $start, $end, $viewerForScope, $platformId, $targetCurrency);
        $previousRevenue = $this->aggregateRevenueByUser([$agent->id], $previousRange['start'], $previousRange['end'], $viewerForScope, $platformId, $targetCurrency);
        $contribution = $this->agentRevenueContribution($agent, $start, $end, $viewerForScope, $platformId, $targetCurrency);
        $clientPerformance = $this->agentClientPerformance($agent, $start, $end, $viewerForScope, $platformId, $targetCurrency);
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
            'reporting_currency' => $targetCurrency,
            'summary' => $currentSummary,
            'previous_summary' => $previousSummary,
            'trend' => $this->buildTrendPayload($currentSummary, $previousSummary),
            'goals' => $goalProgress,
            'contribution' => $contribution,
            'client_performance' => $clientPerformance,
        ];
    }

    public function getMyStats(
        User $user,
        string $period = self::PERIOD_WEEK,
        ?int $platformId = null,
        ?string $targetCurrency = null,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null
    ): array {
        $this->assertPlatformAccessible($user, $platformId);

        ['start' => $start, 'end' => $end] = $this->resolveReportingRange($period, $from, $to);
        $previousRange = $this->previousRange($start, $end);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);

        $currentMetrics = $this->aggregateActionMetricsForRange($start, $end, $user, $platformId, [$user->id]);
        $previousMetrics = $this->aggregateActionMetricsForRange($previousRange['start'], $previousRange['end'], $user, $platformId, [$user->id]);
        $currentSessions = $this->aggregateSessionTotals([$user->id], $start, $end);
        $previousSessions = $this->aggregateSessionTotals([$user->id], $previousRange['start'], $previousRange['end']);
        $currentRevenue = $this->aggregateRevenueByUser([$user->id], $start, $end, $user, $platformId, $targetCurrency);
        $previousRevenue = $this->aggregateRevenueByUser([$user->id], $previousRange['start'], $previousRange['end'], $user, $platformId, $targetCurrency);

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
            'reporting_currency' => $targetCurrency,
            'platforms' => $this->availablePlatformsForUser($user),
            'summary' => $summary,
            'previous_summary' => $previousSummary,
            'trend' => $this->buildTrendPayload($summary, $previousSummary),
            'goals' => $this->getGoalProgress($user, $platformId),
            'activity' => $this->recentActivity($user, $start, $end, $user, $platformId),
        ];
    }

    public function getAgentActivityFeed(
        User $agent,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $platformId = null,
        ?User $viewer = null,
        array $options = []
    ): array {
        if ($viewer) {
            $this->assertTeamMemberVisibleToViewer($viewer, $agent);
            $this->assertPlatformAccessible($viewer, $platformId);
        }

        $start = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->addDay()->startOfDay();
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($options['per_page'] ?? 25)));
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($options['target_currency'] ?? null);
        $entityType = $this->normalizeActivityEntityType($options['entity_type'] ?? null);

        if ($entityType === 'payment') {
            return $this->getAgentPaymentActivityFeed($agent, $start, $end, $platformId, $viewer, $options, $targetCurrency);
        }

        $query = AuditLog::query()
            ->with('actor:id,name')
            ->where('actor_id', $agent->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
        }

        if ($entityType !== '') {
            $query->where('entity_type', $entityType);
        }

        if (($options['action_focus'] ?? null) === 'free_trials_discounts') {
            $query->whereIn('action', [CrmAuditAction::DEAL_FREE_TRIAL, CrmAuditAction::DEAL_DISCOUNT]);
        }

        $search = trim((string) ($options['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $searchQuery
                    ->where('action', 'like', $like)
                    ->orWhere('reason', 'like', $like);

                if (ctype_digit($search)) {
                    $searchQuery->orWhere('entity_id', (int) $search);
                }
            });
        }

        if (! ((bool) ($options['include_system'] ?? false))) {
            $query->whereNotIn('action', self::SYSTEM_ACTIONS);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $items = $this->enrichActivityLogs($paginator->getCollection(), $targetCurrency);

        return [
            'from' => $start->toDateString(),
            'to' => Carbon::instance($to)->toDateString(),
            'platform_id' => $platformId,
            'data' => $items,
            'meta' => [
                'current_page' => (int) $paginator->currentPage(),
                'last_page' => (int) $paginator->lastPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
            ],
        ];
    }

    private function getAgentPaymentActivityFeed(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $platformId,
        ?User $viewer,
        array $options,
        string $targetCurrency
    ): array {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($options['per_page'] ?? 25)));

        $query = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->where('deals.assigned_to', $agent->id)
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$start->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) < ?', [$end->toDateTimeString()])
            ->select('payments.*')
            ->with([
                'client:id,name',
                'deal.client:id,name',
                'deal.product:id,name,display_name,tier',
                'deal.platform:id,name,country,currency_code',
                'platform:id,name,country,currency_code',
                'manualSubmission',
                'routingDecisions',
                'providerTransactions',
            ])
            ->orderByRaw('COALESCE(payments.completed_at, payments.created_at) DESC')
            ->orderByDesc('payments.id');

        if ($platformId) {
            $query->where('payments.platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'payments.platform_id');
        }

        $search = trim((string) ($options['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $searchQuery
                    ->where('payments.transaction_reference', 'like', $like)
                    ->orWhere('payments.reference_number', 'like', $like)
                    ->orWhere('payments.phone', 'like', $like)
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', $like))
                    ->orWhereHas('deal.client', fn ($clientQuery) => $clientQuery->where('name', 'like', $like));

                if (ctype_digit($search)) {
                    $searchQuery->orWhere('payments.id', (int) $search);
                }
            });
        }

        $paginator = $query->paginate($perPage, ['payments.*'], 'page', $page);
        $payments = $paginator->getCollection();
        $paymentPayloads = $this->paymentActivityPayloads($payments->keyBy('id'), $targetCurrency);

        return [
            'from' => Carbon::instance($start)->toDateString(),
            'to' => Carbon::instance($end)->copy()->subSecond()->toDateString(),
            'platform_id' => $platformId,
            'data' => $payments
                ->map(fn (Payment $payment) => $this->formatPaymentActivityRecord($payment, $paymentPayloads[(int) $payment->id] ?? null))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => (int) $paginator->currentPage(),
                'last_page' => (int) $paginator->lastPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
            ],
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
        $marketTargets = $this->marketRevenueTargetsQuery($viewer, $platformId, $normalizedPeriod)
            ->with(['platform:id,name,country', 'setter:id,name'])
            ->orderBy('platform_id')
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
            'market_targets' => $marketTargets
                ->map(fn (MarketRevenueTarget $target) => $this->formatMarketRevenueTarget($target, $overrides))
                ->values()
                ->all(),
            'overrides' => $overrides
                ->map(fn (AgentGoalOverride $goalOverride) => $this->formatGoalOverride($goalOverride, $viewer))
                ->all(),
        ];
    }

    public function setMarketRevenueTarget(int $platformId, float $target, string $period, ?string $targetCurrency, User $setter): MarketRevenueTarget
    {
        $this->assertManager($setter);
        $this->assertPlatformAccessible($setter, $platformId);

        $period = $this->normalizeGoalPeriod($period);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);

        $marketTarget = MarketRevenueTarget::query()
            ->where('platform_id', $platformId)
            ->where('period', $period)
            ->first();

        if (! $marketTarget) {
            $marketTarget = new MarketRevenueTarget([
                'platform_id' => $platformId,
                'period' => $period,
            ]);
        }

        $marketTarget->fill([
            'target' => $target,
            'target_currency' => $targetCurrency,
            'set_by' => $setter->id,
        ]);
        $marketTarget->save();

        return $marketTarget->fresh(['platform:id,name,country', 'setter:id,name']);
    }

    public function deleteMarketRevenueTarget(MarketRevenueTarget $target, User $viewer): void
    {
        $this->assertManager($viewer);
        $this->assertPlatformAccessible($viewer, (int) $target->platform_id);

        $target->delete();
    }

    public function setGoal(string $metric, int $target, string $period, ?int $platformId, string $roleScope, ?string $targetCurrency, User $setter): AgentGoal
    {
        $this->assertManager($setter);
        $this->assertPlatformAccessible($setter, $platformId);

        $metric = $this->normalizeGoalMetric($metric);
        $period = $this->normalizeGoalPeriod($period);
        $roleScope = $this->normalizeGoalRoleScope($roleScope);
        $targetCurrency = $this->normalizeGoalTargetCurrency($metric, $targetCurrency);
        $this->assertGoalMetricAllowedForRoleScope($metric, $roleScope);

        $goal = AgentGoal::query()
            ->when($platformId === null, fn ($query) => $query->whereNull('platform_id'))
            ->when($platformId !== null, fn ($query) => $query->where('platform_id', $platformId))
            ->where('role_scope', $roleScope)
            ->where('metric', $metric)
            ->where('period', $period)
            ->first();

        if (! $goal) {
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
            'target_currency' => $targetCurrency,
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

    public function setGoalOverride(int $userId, string $metric, int $target, string $period, int $platformId, ?string $targetCurrency, User $setter): AgentGoalOverride
    {
        $this->assertManager($setter);
        $this->assertPlatformAccessible($setter, $platformId);

        $user = User::query()
            ->with('platforms:id')
            ->findOrFail($userId);

        $this->assertGoalAssigneeAccessible($setter, $user, $platformId);

        $metric = $this->normalizeGoalMetric($metric);
        $period = $this->normalizeGoalPeriod($period);
        $targetCurrency = $this->normalizeGoalTargetCurrency($metric, $targetCurrency);
        $this->assertGoalMetricAllowedForRole($metric, $user->role);

        $goalOverride = AgentGoalOverride::query()
            ->where('user_id', $user->id)
            ->where('platform_id', $platformId)
            ->where('metric', $metric)
            ->where('period', $period)
            ->first();

        if (! $goalOverride) {
            $goalOverride = new AgentGoalOverride([
                'user_id' => $user->id,
                'platform_id' => $platformId,
                'metric' => $metric,
                'period' => $period,
            ]);
        }

        $goalOverride->fill([
            'target' => $target,
            'target_currency' => $targetCurrency,
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

                if (is_array($accessiblePlatforms) && ! empty($accessiblePlatforms)) {
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
            if (! $this->goalAppliesToUser($goal, $user)) {
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
                'discounts_given' => (int) $metricRow['discounts_given'],
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
                'discounts_given',
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
                'value_type' => $this->goalMetricValueType($metric),
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
                ? ((int) $log->actor_id.':'.(int) $log->platform_id)
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

            case 'deal_discount':
                $entry['discounts_given']++;
                break;

            case 'payment_match_confirm':
            case 'payment_match_auto':
                $entry['payments_matched']++;
                break;

            case 'payment_create_subscription':
                $entry['subscriptions_created']++;
                break;

            case 'conversation_sms_sent':
            case 'conversation_whatsapp_sent':
            case 'renewal_sms_sent':
            case 'renewal_whatsapp_sent':
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
        ?int $platformId,
        string $targetCurrency
    ): array {
        if (empty($userIds) || $start->gte($end)) {
            return [];
        }

        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
        $currencyExpression = "COALESCE(payments.currency, (SELECT currency_code FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1), '{$targetCurrency}')";
        $platformCountryExpression = '(SELECT country FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)';
        $platformNameExpression = '(SELECT name FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)';

        $query = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->whereIn('deals.assigned_to', $userIds)
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$start->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) < ?', [$end->toDateTimeString()]);

        if ($platformId) {
            $query->where('payments.platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'payments.platform_id');
        }

        $rows = $query
            ->select(DB::raw('deals.assigned_to as user_id'))
            ->selectRaw("{$dateExpression} as event_date")
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("{$platformCountryExpression} as platform_country")
            ->selectRaw("{$platformNameExpression} as platform_name")
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('deals.assigned_to')
            ->groupByRaw($dateExpression)
            ->groupBy('payments.platform_id')
            ->groupByRaw($platformCountryExpression)
            ->groupByRaw($platformNameExpression)
            ->groupByRaw($currencyExpression)
            ->get();
        $grouped = [];
        $eventRowsByUser = [];

        foreach ($rows as $row) {
            $userKey = (int) $row->user_id;
            $currency = strtoupper((string) ($row->currency ?: ''));
            if ($currency === '') {
                continue;
            }

            $amount = (float) $row->amount;
            $grouped[$userKey][$currency] = ($grouped[$userKey][$currency] ?? 0.0) + $amount;
            $eventRowsByUser[$userKey][] = [
                'event_date' => (string) $row->event_date,
                'platform_id' => $row->platform_id,
                'platform_country' => $row->platform_country,
                'platform_name' => $row->platform_name,
                'currency' => $currency,
                'amount' => $amount,
            ];
        }

        $payloads = [];
        foreach ($grouped as $userKey => $currencyBreakdown) {
            ksort($currencyBreakdown);
            $normalized = $this->reportingCurrencyService->normalizeEventRows($eventRowsByUser[$userKey] ?? [], $targetCurrency);
            $payloads[$userKey] = $this->buildRevenuePayload($currencyBreakdown, $platformId, $normalized);
        }

        return $payloads;
    }

    private function aggregateDailyRevenueRows(CarbonInterface $start, CarbonInterface $end): array
    {
        $currencyExpression = "COALESCE(payments.currency, (SELECT currency_code FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1), '')";

        $rows = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->whereNotNull('deals.assigned_to')
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$start->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) < ?', [$end->toDateTimeString()])
            ->selectRaw('deals.assigned_to as assigned_to')
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('deals.assigned_to')
            ->groupBy('payments.platform_id')
            ->groupByRaw($currencyExpression)
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $key = (int) $row->assigned_to.':'.(int) $row->platform_id;
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

    private function agentRevenueContribution(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        string $targetCurrency
    ): array {
        if ($start->gte($end)) {
            return $this->emptyContributionPayload($targetCurrency);
        }

        $rows = $this->agentRevenueContributionRows($agent, $start, $end, $viewer, $platformId, $targetCurrency);
        if ($rows->isEmpty()) {
            return $this->emptyContributionPayload($targetCurrency);
        }

        $total = $this->normalizeContributionRows($rows, $targetCurrency);
        $totalValue = (float) ($total['normalized_total'] ?? 0);
        $platformRows = $this->platformContributionRows($rows, $targetCurrency, $totalValue);
        $packageRows = $this->packageContributionRows($rows, $targetCurrency, $totalValue);
        $topPlatform = $platformRows[0] ?? null;
        $topPackage = $packageRows[0] ?? null;

        return [
            'target_currency' => $targetCurrency,
            'total_normalized' => $total['normalized_total'],
            'total_display' => $total['normalized_display'],
            'normalization_meta' => $total['normalization_meta'],
            'platforms' => $platformRows,
            'packages' => $packageRows,
            'summary' => [
                'platform_count' => count($platformRows),
                'package_count' => count($packageRows),
                'top_platform' => $topPlatform ? [
                    'platform_id' => $topPlatform['platform_id'],
                    'name' => $topPlatform['name'],
                    'country' => $topPlatform['country'],
                    'share_percent' => $topPlatform['share_percent'],
                    'normalized_total' => $topPlatform['normalized_total'],
                    'display' => $topPlatform['normalized_display'],
                ] : null,
                'top_package' => $topPackage ? [
                    'key' => $topPackage['key'],
                    'label' => $topPackage['label'],
                    'share_percent' => $topPackage['share_percent'],
                    'normalized_total' => $topPackage['normalized_total'],
                    'display' => $topPackage['normalized_display'],
                ] : null,
            ],
        ];
    }

    private function agentRevenueContributionRows(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        string $targetCurrency
    ): Collection {
        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
        $currencyExpression = "COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')";
        $clientExpression = 'COALESCE(payments.client_id, deals.client_id)';

        $query = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->leftJoin('products', 'products.id', '=', 'deals.product_id')
            ->where('deals.assigned_to', (int) $agent->id)
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$start->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) < ?', [$end->toDateTimeString()]);

        if ($platformId) {
            $query->where('payments.platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'payments.platform_id');
        }

        return $query
            ->selectRaw("{$dateExpression} as event_date")
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("COALESCE(platforms.name, 'Unassigned market') as platform_name")
            ->selectRaw("COALESCE(platforms.country, '') as platform_country")
            ->selectRaw('platforms.currency_code as platform_currency')
            ->selectRaw('deals.product_id as product_id')
            ->selectRaw('products.tier as product_tier')
            ->selectRaw('products.display_name as product_display_name')
            ->selectRaw('products.name as product_name')
            ->selectRaw('products.slug as product_slug')
            ->selectRaw('deals.plan_type as deal_plan_type')
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->selectRaw("COUNT(DISTINCT {$clientExpression}) as clients_count")
            ->selectRaw("GROUP_CONCAT(DISTINCT {$clientExpression}) as client_ids")
            ->groupByRaw($dateExpression)
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country', 'platforms.currency_code')
            ->groupBy('deals.product_id', 'products.tier', 'products.display_name', 'products.name', 'products.slug', 'deals.plan_type')
            ->groupByRaw($currencyExpression)
            ->get();
    }

    private function platformContributionRows(Collection $rows, string $targetCurrency, float $totalValue): array
    {
        return $rows
            ->groupBy(fn ($row) => (string) ((int) $row->platform_id))
            ->map(function (Collection $platformRows) use ($targetCurrency, $totalValue): array {
                $normalized = $this->normalizeContributionRows($platformRows, $targetCurrency);
                $value = (float) ($normalized['normalized_total'] ?? 0);
                $packages = $this->packageContributionRows($platformRows, $targetCurrency, $value, 3);

                return [
                    'platform_id' => (int) $platformRows->first()->platform_id,
                    'name' => (string) $platformRows->first()->platform_name,
                    'country' => (string) $platformRows->first()->platform_country,
                    'currency' => strtoupper((string) ($platformRows->first()->platform_currency ?: '')),
                    'payments_count' => (int) $platformRows->sum('payments_count'),
                    'clients_count' => $this->countUniqueContributionClients($platformRows),
                    'source_breakdown' => $this->nativeContributionBreakdown($platformRows),
                    'native_display' => $this->nativeContributionDisplay($platformRows),
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'normalized_display' => $normalized['normalized_display'],
                    'normalization_meta' => $normalized['normalization_meta'],
                    'share_percent' => $totalValue > 0 ? round(($value / $totalValue) * 100, 1) : 0.0,
                    'top_packages' => $packages,
                ];
            })
            ->sortByDesc(fn (array $row) => (float) ($row['normalized_total'] ?? 0))
            ->values()
            ->all();
    }

    private function packageContributionRows(Collection $rows, string $targetCurrency, float $totalValue, ?int $limit = null): array
    {
        $payload = $rows
            ->groupBy(fn ($row) => $this->packageContributionKey($row))
            ->map(function (Collection $packageRows) use ($targetCurrency, $totalValue): array {
                $normalized = $this->normalizeContributionRows($packageRows, $targetCurrency);
                $value = (float) ($normalized['normalized_total'] ?? 0);
                $presentation = $this->packageContributionPresentation($packageRows->first());
                $platforms = $packageRows
                    ->groupBy(fn ($row) => (string) ((int) $row->platform_id))
                    ->map(fn (Collection $platformRows) => [
                        'platform_id' => (int) $platformRows->first()->platform_id,
                        'name' => (string) $platformRows->first()->platform_name,
                        'country' => (string) $platformRows->first()->platform_country,
                        'share_percent' => $value > 0
                            ? round(((float) ($this->normalizeContributionRows($platformRows, $targetCurrency)['normalized_total'] ?? 0) / $value) * 100, 1)
                            : 0.0,
                    ])
                    ->sortByDesc('share_percent')
                    ->values()
                    ->all();

                return [
                    'key' => $presentation['key'],
                    'label' => $presentation['label'],
                    'payments_count' => (int) $packageRows->sum('payments_count'),
                    'clients_count' => $this->countUniqueContributionClients($packageRows),
                    'source_breakdown' => $this->nativeContributionBreakdown($packageRows),
                    'native_display' => $this->nativeContributionDisplay($packageRows),
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'normalized_display' => $normalized['normalized_display'],
                    'normalization_meta' => $normalized['normalization_meta'],
                    'share_percent' => $totalValue > 0 ? round(($value / $totalValue) * 100, 1) : 0.0,
                    'platforms' => $platforms,
                ];
            })
            ->sortByDesc(fn (array $row) => (float) ($row['normalized_total'] ?? 0))
            ->values();

        return $limit !== null ? $payload->take($limit)->all() : $payload->all();
    }

    private function countUniqueContributionClients(Collection $rows): int
    {
        return $rows
            ->flatMap(fn ($row) => explode(',', (string) ($row->client_ids ?? '')))
            ->map(fn (string $id) => trim($id))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->count();
    }

    private function normalizeContributionRows(Collection $rows, string $targetCurrency): array
    {
        $eventRows = $rows->map(fn ($row) => [
            'event_date' => (string) $row->event_date,
            'platform_id' => $row->platform_id,
            'platform_country' => $row->platform_country,
            'platform_name' => $row->platform_name,
            'currency' => strtoupper((string) ($row->currency ?: $targetCurrency)),
            'amount' => (float) $row->amount,
        ])->all();

        return $this->reportingCurrencyService->normalizeEventRows($eventRows, $targetCurrency);
    }

    private function nativeContributionBreakdown(Collection $rows): array
    {
        return $rows
            ->groupBy(fn ($row) => strtoupper((string) ($row->currency ?: '')))
            ->map(fn (Collection $currencyRows) => round((float) $currencyRows->sum('amount'), 2))
            ->filter(fn (float $amount, string $currency) => $currency !== '' && $amount > 0)
            ->sortKeys()
            ->all();
    }

    private function nativeContributionDisplay(Collection $rows): string
    {
        $breakdown = $this->nativeContributionBreakdown($rows);
        if (empty($breakdown)) {
            return '--';
        }

        return collect($breakdown)
            ->map(fn (float $amount, string $currency) => $currency.' '.$this->formatMoney($amount))
            ->implode(' | ');
    }

    private function packageContributionKey(object $row): string
    {
        return $this->packageContributionPresentation($row)['key'];
    }

    private function packageContributionPresentation(object $row): array
    {
        $presentation = Client::planPresentationFromPackageValues(
            $row->product_tier,
            $row->product_display_name ?: $row->product_name ?: $row->deal_plan_type,
            $row->product_slug,
        );

        return $presentation ?: [
            'key' => 'unknown',
            'label' => 'Unknown package',
        ];
    }

    private function emptyContributionPayload(string $targetCurrency): array
    {
        return [
            'target_currency' => $targetCurrency,
            'total_normalized' => 0.0,
            'total_display' => $targetCurrency.' '.$this->formatMoney(0),
            'normalization_meta' => $this->reportingCurrencyService->normalizeBreakdown([])['normalization_meta'],
            'platforms' => [],
            'packages' => [],
            'summary' => [
                'platform_count' => 0,
                'package_count' => 0,
                'top_platform' => null,
                'top_package' => null,
            ],
        ];
    }

    private function agentClientPerformance(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        string $targetCurrency
    ): array {
        if ($start->gte($end)) {
            return $this->emptyClientPerformancePayload($targetCurrency);
        }

        $payments = $this->agentSuccessfulPaymentDetailRows($agent, $start, $end, $viewer, $platformId, $targetCurrency);
        $total = $this->normalizeClientPerformanceRows($payments, $targetCurrency);
        $totalValue = (float) ($total['normalized_total'] ?? 0);
        $newUserRows = $payments->filter(fn ($row) => $this->isNewUserRevenueRow($row, $start, $end))->values();
        $existingUserRows = $payments->reject(fn ($row) => $this->isNewUserRevenueRow($row, $start, $end))->values();
        $winbackRows = $payments->filter(fn ($row) => $this->isWinbackRevenueRow($row))->values();
        $recovery = $this->agentPaymentRecoveryPerformance($agent, $start, $end, $viewer, $platformId, $targetCurrency, $payments);
        $conversion = $this->agentNewUserConversionPerformance($agent, $start, $end, $viewer, $platformId);
        $winback = $this->agentWinbackPerformance($agent, $start, $viewer, $platformId, $winbackRows);
        $workload = $this->agentWorkloadPosition($agent, $start, $end, $viewer, $platformId);
        $segments = [
            $this->clientRevenueSegment('new_users', 'New users', $newUserRows, $targetCurrency, $totalValue),
            $this->clientRevenueSegment('existing_users', 'Existing users', $existingUserRows, $targetCurrency, $totalValue),
        ];
        $plays = [
            array_merge(
                $this->clientRevenueSegment('payment_recovery', 'Payment recovery', $recovery['revenue_rows'], $targetCurrency, $totalValue),
                [
                    'attempted_count' => $recovery['failed_payments'],
                    'recovered_count' => $recovery['recovered_clients'],
                    'rate' => $recovery['rate'],
                    'rate_label' => $this->formatRateLabel($recovery['rate']),
                ]
            ),
            array_merge(
                $this->clientRevenueSegment('winbacks', 'Won-back clients', $winbackRows, $targetCurrency, $totalValue),
                [
                    'attempted_count' => $winback['lost_clients_at_start'],
                    'recovered_count' => $winback['won_back_clients'],
                    'rate' => $winback['rate'],
                    'rate_label' => $this->formatRateLabel($winback['rate']),
                ]
            ),
        ];

        return [
            'target_currency' => $targetCurrency,
            'total_normalized' => $total['normalized_total'],
            'total_display' => $total['normalized_display'],
            'normalization_meta' => $total['normalization_meta'],
            'customer_mix' => $segments,
            'plays' => $plays,
            'conversion' => $conversion,
            'payment_recovery' => [
                'failed_payments' => $recovery['failed_payments'],
                'failed_clients' => $recovery['failed_clients'],
                'recovered_clients' => $recovery['recovered_clients'],
                'rate' => $recovery['rate'],
                'rate_label' => $this->formatRateLabel($recovery['rate']),
                'recovered_revenue_display' => $plays[0]['normalized_display'],
            ],
            'winback' => [
                'lost_clients_at_start' => $winback['lost_clients_at_start'],
                'won_back_clients' => $winback['won_back_clients'],
                'rate' => $winback['rate'],
                'rate_label' => $this->formatRateLabel($winback['rate']),
                'revenue_display' => $plays[1]['normalized_display'],
            ],
            'workload' => $workload,
            'insights' => $this->agentClientPerformanceInsights($segments, $plays, $conversion, $recovery, $winback, $workload),
        ];
    }

    private function agentSuccessfulPaymentDetailRows(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        string $targetCurrency
    ): Collection {
        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
        $currencyExpression = "COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')";

        $query = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('clients', function ($join) {
                $join->on('clients.id', '=', DB::raw('COALESCE(payments.client_id, deals.client_id)'));
            })
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->where('deals.assigned_to', (int) $agent->id)
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$start->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) < ?', [$end->toDateTimeString()]);

        if ($platformId) {
            $query->where('payments.platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'payments.platform_id');
        }

        return $query
            ->selectRaw('payments.id as payment_id')
            ->selectRaw("{$dateExpression} as event_date")
            ->selectRaw('COALESCE(payments.completed_at, payments.created_at) as event_at')
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("COALESCE(platforms.name, 'Unassigned market') as platform_name")
            ->selectRaw("COALESCE(platforms.country, '') as platform_country")
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('payments.amount as amount')
            ->selectRaw('payments.phone as phone')
            ->selectRaw('COALESCE(payments.client_id, deals.client_id) as client_id')
            ->selectRaw('clients.created_at as client_created_at')
            ->selectRaw('clients.first_activated_at as client_first_activated_at')
            ->selectRaw('clients.churned_at as client_churned_at')
            ->selectRaw('clients.profile_status as client_profile_status')
            ->selectRaw('clients.needs_payment as client_needs_payment')
            ->selectRaw('clients.notactive as client_notactive')
            ->selectRaw('deals.subscription_lifecycle as deal_subscription_lifecycle')
            ->selectRaw('payments.subscription_lifecycle as payment_subscription_lifecycle')
            ->orderByRaw('COALESCE(payments.completed_at, payments.created_at) DESC')
            ->get();
    }

    private function clientRevenueSegment(
        string $key,
        string $label,
        Collection $rows,
        string $targetCurrency,
        float $totalValue
    ): array {
        $normalized = $this->normalizeClientPerformanceRows($rows, $targetCurrency);
        $value = (float) ($normalized['normalized_total'] ?? 0);

        return [
            'key' => $key,
            'label' => $label,
            'payments_count' => $rows->count(),
            'clients_count' => $this->countUniqueRowValues($rows, 'client_id'),
            'native_display' => $this->nativeContributionDisplay($rows),
            'normalized_total' => $normalized['normalized_total'],
            'normalized_currency' => $normalized['normalized_currency'],
            'normalized_display' => $normalized['normalized_display'],
            'normalization_meta' => $normalized['normalization_meta'],
            'share_percent' => $totalValue > 0 ? round(($value / $totalValue) * 100, 1) : 0.0,
        ];
    }

    private function normalizeClientPerformanceRows(Collection $rows, string $targetCurrency): array
    {
        if ($rows->isEmpty()) {
            return [
                'source_breakdown' => [],
                'normalized_total' => 0.0,
                'normalized_currency' => $targetCurrency,
                'normalized_display' => $targetCurrency.' '.$this->formatMoney(0),
                'normalization_meta' => $this->reportingCurrencyService->normalizeBreakdown([])['normalization_meta'],
            ];
        }

        $eventRows = $rows->map(fn ($row) => [
            'event_date' => (string) $row->event_date,
            'platform_id' => $row->platform_id,
            'platform_country' => $row->platform_country,
            'platform_name' => $row->platform_name,
            'currency' => strtoupper((string) ($row->currency ?: $targetCurrency)),
            'amount' => (float) $row->amount,
        ])->all();

        $normalized = $this->reportingCurrencyService->normalizeEventRows($eventRows, $targetCurrency);

        return [
            ...$normalized,
            'normalized_total' => $normalized['normalized_total'] ?? 0.0,
            'normalized_display' => $normalized['normalized_display'] ?? ($targetCurrency.' '.$this->formatMoney(0)),
        ];
    }

    private function isNewUserRevenueRow(object $row, CarbonInterface $start, CarbonInterface $end): bool
    {
        $activatedAt = $this->safeTimestamp($row->client_first_activated_at ?? null);
        if ($activatedAt && $activatedAt->gte($start) && $activatedAt->lt($end)) {
            return true;
        }

        $createdAt = $this->safeTimestamp($row->client_created_at ?? null);

        return $createdAt && $createdAt->gte($start) && $createdAt->lt($end);
    }

    private function isWinbackRevenueRow(object $row): bool
    {
        $eventAt = $this->safeTimestamp($row->event_at ?? $row->event_date ?? null);
        $churnedAt = $this->safeTimestamp($row->client_churned_at ?? null);
        $dealLifecycle = strtolower((string) ($row->deal_subscription_lifecycle ?? ''));
        $paymentLifecycle = strtolower((string) ($row->payment_subscription_lifecycle ?? ''));

        return ($eventAt && $churnedAt && $churnedAt->lt($eventAt))
            || in_array($dealLifecycle, ['reactivation', 'win_back', 'winback'], true)
            || in_array($paymentLifecycle, ['reactivation', 'win_back', 'winback'], true);
    }

    private function agentNewUserConversionPerformance(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId
    ): array {
        $query = Client::query()
            ->where('assigned_to', (int) $agent->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'clients.platform_id');
        }

        $created = (clone $query)->count();
        $activated = (clone $query)
            ->whereNotNull('first_activated_at')
            ->where('first_activated_at', '>=', $start)
            ->where('first_activated_at', '<', $end)
            ->count();
        $rate = $created > 0 ? round(($activated / $created) * 100, 1) : null;

        return [
            'new_clients' => (int) $created,
            'converted_clients' => (int) $activated,
            'rate' => $rate,
            'rate_label' => $this->formatRateLabel($rate),
        ];
    }

    private function agentPaymentRecoveryPerformance(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        string $targetCurrency,
        Collection $successfulPayments
    ): array {
        $failedRows = $this->agentFailedPaymentRows($agent, $start, $end, $viewer, $platformId, $targetCurrency);
        $failedClientIds = $failedRows
            ->pluck('client_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $failedPhones = $failedRows
            ->map(fn ($row) => $this->normalizeRecoveryPhone($row->phone ?? null))
            ->filter()
            ->unique()
            ->values();
        $recoveredRows = $successfulPayments
            ->filter(function ($row) use ($failedClientIds, $failedPhones): bool {
                $clientId = (int) ($row->client_id ?? 0);
                if ($clientId > 0 && $failedClientIds->contains($clientId)) {
                    return true;
                }

                $phone = $this->normalizeRecoveryPhone($row->phone ?? null);

                return $phone !== null && $failedPhones->contains($phone);
            })
            ->values();
        $recoveredIdentityCount = $this->countRecoveryIdentities($recoveredRows);
        $failedIdentityCount = $this->countRecoveryIdentities($failedRows);
        $rate = $failedIdentityCount > 0 ? round(($recoveredIdentityCount / $failedIdentityCount) * 100, 1) : null;

        return [
            'failed_payments' => $failedRows->count(),
            'failed_clients' => $failedIdentityCount,
            'recovered_clients' => $recoveredIdentityCount,
            'rate' => $rate,
            'revenue_rows' => $recoveredRows,
        ];
    }

    private function agentFailedPaymentRows(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        string $targetCurrency
    ): Collection {
        $currencyExpression = "COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')";
        $agentIdentities = $this->agentRecoveryIdentitySets($agent, $viewer, $platformId);

        $query = Payment::query()
            ->businessVisible()
            ->excludingWalletTopups()
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->where('payments.status', 'failed')
            ->where('payments.created_at', '>=', $start)
            ->where('payments.created_at', '<', $end);

        if ($platformId) {
            $query->where('payments.platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'payments.platform_id');
        }

        return $query
            ->selectRaw('payments.id as payment_id')
            ->selectRaw('payments.created_at as event_date')
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("COALESCE(platforms.name, 'Unassigned market') as platform_name")
            ->selectRaw("COALESCE(platforms.country, '') as platform_country")
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('payments.amount as amount')
            ->selectRaw('payments.phone as phone')
            ->selectRaw('COALESCE(payments.client_id, deals.client_id) as client_id')
            ->selectRaw('deals.assigned_to as deal_assigned_to')
            ->get()
            ->filter(function ($row) use ($agent, $agentIdentities): bool {
                if ((int) ($row->deal_assigned_to ?? 0) === (int) $agent->id) {
                    return true;
                }

                $clientId = (int) ($row->client_id ?? 0);
                if ($clientId > 0 && $agentIdentities['client_ids']->contains($clientId)) {
                    return true;
                }

                $phone = $this->normalizeRecoveryPhone($row->phone ?? null);

                return $phone !== null && $agentIdentities['phones']->contains($phone);
            })
            ->values();
    }

    private function agentRecoveryIdentitySets(User $agent, ?User $viewer, ?int $platformId): array
    {
        $query = Client::query()
            ->where('assigned_to', (int) $agent->id);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'clients.platform_id');
        }

        $clients = $query->get(['id', 'phone_normalized']);

        return [
            'client_ids' => $clients
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values(),
            'phones' => $clients
                ->map(fn (Client $client) => $this->normalizeRecoveryPhone($client->phone_normalized))
                ->filter()
                ->unique()
                ->values(),
        ];
    }

    private function countRecoveryIdentities(Collection $rows): int
    {
        return $rows
            ->map(function ($row): ?string {
                $clientId = (int) ($row->client_id ?? 0);
                if ($clientId > 0) {
                    return 'client:'.$clientId;
                }

                $phone = $this->normalizeRecoveryPhone($row->phone ?? null);

                return $phone ? 'phone:'.$phone : null;
            })
            ->filter()
            ->unique()
            ->count();
    }

    private function normalizeRecoveryPhone(?string $phone): ?string
    {
        $value = preg_replace('/\D/', '', (string) $phone);

        if (! is_string($value)) {
            return null;
        }

        $value = ltrim($value, '0');

        return $value !== '' ? $value : null;
    }

    private function agentWinbackPerformance(
        User $agent,
        CarbonInterface $start,
        ?User $viewer,
        ?int $platformId,
        Collection $winbackRows
    ): array {
        $query = Client::query()
            ->where('assigned_to', (int) $agent->id)
            ->whereNotNull('churned_at')
            ->where('churned_at', '<', $start);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer, 'clients.platform_id');
        }

        $wonBackClients = $this->countUniqueRowValues($winbackRows, 'client_id');
        $lostClients = max((int) $query->count(), $wonBackClients);
        $rate = $lostClients > 0 ? round(($wonBackClients / $lostClients) * 100, 1) : null;

        return [
            'lost_clients_at_start' => $lostClients,
            'won_back_clients' => $wonBackClients,
            'rate' => $rate,
        ];
    }

    private function agentWorkloadPosition(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId
    ): array {
        $team = $this->visibleAgentsForViewer($viewer ?? $agent, $platformId);
        $agentIds = $team->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (empty($agentIds)) {
            return [
                'rank' => null,
                'team_size' => 0,
                'band' => 'No team benchmark',
                'score' => 0,
                'total_actions' => 0,
                'active_seconds' => 0,
                'most_busy' => null,
                'least_busy' => null,
            ];
        }

        $metrics = $this->aggregateActionMetricsForRange($start, $end, $viewer ?? $agent, $platformId, $agentIds);
        $sessions = $this->aggregateSessionTotals($agentIds, $start, $end);
        $rows = $team
            ->map(function (User $teamMember) use ($metrics, $sessions): array {
                $metric = $metrics[$teamMember->id] ?? $this->emptyMetricRow();
                $session = $sessions[$teamMember->id] ?? ['active_seconds' => 0, 'session_count' => 0];
                $totalActions = (int) ($metric['total_actions'] ?? 0);
                $activeSeconds = (int) ($session['active_seconds'] ?? 0);
                $score = ($totalActions * 10)
                    + ((int) ($metric['subs_activated'] ?? 0) * 4)
                    + ((int) ($metric['payments_matched'] ?? 0) * 3)
                    + round($activeSeconds / 300, 1);

                return [
                    'user_id' => (int) $teamMember->id,
                    'name' => $teamMember->name,
                    'role' => $teamMember->role,
                    'score' => round($score, 1),
                    'total_actions' => $totalActions,
                    'active_seconds' => $activeSeconds,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;

                return $row;
            });

        $current = $rows->firstWhere('user_id', (int) $agent->id);
        $rank = $current['rank'] ?? null;
        $teamSize = $rows->count();

        return [
            'rank' => $rank,
            'team_size' => $teamSize,
            'band' => $this->workloadBand($rank, $teamSize),
            'score' => (float) ($current['score'] ?? 0),
            'total_actions' => (int) ($current['total_actions'] ?? 0),
            'active_seconds' => (int) ($current['active_seconds'] ?? 0),
            'most_busy' => $rows->first(),
            'least_busy' => $rows->last(),
        ];
    }

    private function agentClientPerformanceInsights(
        array $segments,
        array $plays,
        array $conversion,
        array $recovery,
        array $winback,
        array $workload
    ): array {
        $newSegment = collect($segments)->firstWhere('key', 'new_users') ?? [];
        $existingSegment = collect($segments)->firstWhere('key', 'existing_users') ?? [];
        $recoveryPlay = collect($plays)->firstWhere('key', 'payment_recovery') ?? [];
        $winbackPlay = collect($plays)->firstWhere('key', 'winbacks') ?? [];

        return [
            [
                'key' => 'revenue_shape',
                'label' => 'Revenue shape',
                'value' => ((float) ($newSegment['share_percent'] ?? 0)) >= ((float) ($existingSegment['share_percent'] ?? 0))
                    ? 'New-user led'
                    : 'Existing-user led',
                'detail' => sprintf(
                    '%s from new users vs %s from existing users.',
                    $this->formatRateLabel($newSegment['share_percent'] ?? 0),
                    $this->formatRateLabel($existingSegment['share_percent'] ?? 0)
                ),
            ],
            [
                'key' => 'conversion',
                'label' => 'New-user conversion',
                'value' => $conversion['rate_label'],
                'detail' => sprintf('%d of %d assigned new users activated.', $conversion['converted_clients'], $conversion['new_clients']),
            ],
            [
                'key' => 'recovery',
                'label' => 'Payment recovery',
                'value' => $recovery['rate'] === null ? 'No failures' : $this->formatRateLabel($recovery['rate']),
                'detail' => sprintf('%d recovered clients, %s recovered revenue.', $recovery['recovered_clients'], $recoveryPlay['normalized_display'] ?? '0'),
            ],
            [
                'key' => 'winback',
                'label' => 'Win-back',
                'value' => $winback['rate'] === null ? 'No lost base' : $this->formatRateLabel($winback['rate']),
                'detail' => sprintf('%d clients won back, %s revenue.', $winback['won_back_clients'], $winbackPlay['normalized_display'] ?? '0'),
            ],
            [
                'key' => 'workload',
                'label' => 'Workload',
                'value' => $workload['rank'] ? sprintf('#%d of %d', $workload['rank'], $workload['team_size']) : 'No benchmark',
                'detail' => $workload['band'],
            ],
        ];
    }

    private function workloadBand(?int $rank, int $teamSize): string
    {
        if (! $rank || $teamSize <= 0) {
            return 'No team benchmark';
        }

        if ($rank === 1) {
            return 'Most busy visible team member';
        }

        if ($rank === $teamSize) {
            return 'Least busy visible team member';
        }

        if ($rank <= max(1, (int) ceil($teamSize / 3))) {
            return 'Upper workload band';
        }

        if ($rank > (int) floor(($teamSize * 2) / 3)) {
            return 'Lower workload band';
        }

        return 'Middle workload band';
    }

    private function countUniqueRowValues(Collection $rows, string $key): int
    {
        return $rows
            ->pluck($key)
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->count();
    }

    private function formatRateLabel(?float $rate): string
    {
        return $rate === null ? 'No baseline' : number_format($rate, $rate >= 10 ? 0 : 1).'%';
    }

    private function emptyClientPerformancePayload(string $targetCurrency): array
    {
        $emptySegment = fn (string $key, string $label): array => [
            'key' => $key,
            'label' => $label,
            'payments_count' => 0,
            'clients_count' => 0,
            'native_display' => '--',
            'normalized_total' => 0.0,
            'normalized_currency' => $targetCurrency,
            'normalized_display' => $targetCurrency.' '.$this->formatMoney(0),
            'normalization_meta' => $this->reportingCurrencyService->normalizeBreakdown([])['normalization_meta'],
            'share_percent' => 0.0,
        ];

        return [
            'target_currency' => $targetCurrency,
            'total_normalized' => 0.0,
            'total_display' => $targetCurrency.' '.$this->formatMoney(0),
            'normalization_meta' => $this->reportingCurrencyService->normalizeBreakdown([])['normalization_meta'],
            'customer_mix' => [
                $emptySegment('new_users', 'New users'),
                $emptySegment('existing_users', 'Existing users'),
            ],
            'plays' => [
                $emptySegment('payment_recovery', 'Payment recovery'),
                $emptySegment('winbacks', 'Won-back clients'),
            ],
            'conversion' => [
                'new_clients' => 0,
                'converted_clients' => 0,
                'rate' => null,
                'rate_label' => 'No baseline',
            ],
            'payment_recovery' => [
                'failed_payments' => 0,
                'failed_clients' => 0,
                'recovered_clients' => 0,
                'rate' => null,
                'rate_label' => 'No baseline',
                'recovered_revenue_display' => $targetCurrency.' '.$this->formatMoney(0),
            ],
            'winback' => [
                'lost_clients_at_start' => 0,
                'won_back_clients' => 0,
                'rate' => null,
                'rate_label' => 'No baseline',
                'revenue_display' => $targetCurrency.' '.$this->formatMoney(0),
            ],
            'workload' => [
                'rank' => null,
                'team_size' => 0,
                'band' => 'No team benchmark',
                'score' => 0,
                'total_actions' => 0,
                'active_seconds' => 0,
                'most_busy' => null,
                'least_busy' => null,
            ],
            'insights' => [],
        ];
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
            if (! $effectiveEnd || ! $session->started_at) {
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
        if (! is_array($viewerPlatforms) || empty($viewerPlatforms)) {
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

    private function visibleTeamMembersForViewer(
        User $viewer,
        ?int $platformId = null,
        string $roleFilter = self::ROLE_FILTER_ALL
    ): Collection {
        $visibleRoles = $this->visibleTeamRolesForViewer($viewer, $roleFilter);
        if (empty($visibleRoles)) {
            return collect();
        }

        $teamMembers = User::query()
            ->where('status', 'active')
            ->whereIn('role', $visibleRoles)
            ->with('platforms:id')
            ->orderBy('name')
            ->get();

        if ($viewer->role === MarketAuthorizationService::ROLE_ADMIN) {
            return $teamMembers
                ->filter(fn (User $teamMember) => $platformId === null || $this->userHasPlatform($teamMember, $platformId))
                ->values();
        }

        $viewerPlatforms = $this->accessiblePlatformIdsForUser($viewer);
        if (! is_array($viewerPlatforms) || empty($viewerPlatforms)) {
            return collect();
        }

        return $teamMembers
            ->filter(function (User $teamMember) use ($platformId, $viewer, $viewerPlatforms) {
                if ($teamMember->is($viewer)) {
                    return false;
                }

                if ($platformId !== null) {
                    return in_array($platformId, $viewerPlatforms, true) && $this->userHasPlatform($teamMember, $platformId);
                }

                return $this->userHasPlatformOverlap($viewerPlatforms, $teamMember);
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

        return ! empty(array_intersect($viewerPlatformIds, $candidatePlatforms));
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

    private function assertTeamMemberVisibleToViewer(User $viewer, User $teamMember): void
    {
        $this->assertManager($viewer);

        $visibleRoles = $this->visibleTeamRolesForViewer($viewer);
        if (! in_array($teamMember->role, $visibleRoles, true) || ! $teamMember->isActive()) {
            abort(404, 'Agent not found.');
        }

        if ($viewer->role === MarketAuthorizationService::ROLE_ADMIN) {
            return;
        }

        $viewerPlatforms = $this->accessiblePlatformIdsForUser($viewer);
        if (! is_array($viewerPlatforms) || empty($viewerPlatforms) || ! $this->userHasPlatformOverlap($viewerPlatforms, $teamMember)) {
            abort(403, 'You do not have access to this agent.');
        }
    }

    private function assertAgentVisibleToViewer(User $viewer, User $agent): void
    {
        $this->assertManager($viewer);

        if (! in_array($agent->role, self::AGENT_ROLES, true) || ! $agent->isActive()) {
            abort(404, 'Agent not found.');
        }

        if ($viewer->role === MarketAuthorizationService::ROLE_ADMIN) {
            return;
        }

        $viewerPlatforms = $this->accessiblePlatformIdsForUser($viewer);
        if (! is_array($viewerPlatforms) || empty($viewerPlatforms) || ! $this->userHasPlatformOverlap($viewerPlatforms, $agent)) {
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

        $floor = now()->subDays(14);
        $rankedQuery = AuditLog::query()
            ->whereIn('actor_id', $userIds)
            ->where('created_at', '>=', $floor);

        if ($platformId) {
            $rankedQuery->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($rankedQuery, $viewer);
        }

        $latestIds = DB::query()
            ->fromSub(
                $rankedQuery
                    ->select(['id', 'actor_id'])
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY actor_id ORDER BY created_at DESC, id DESC) as row_rank'),
                'ranked_audit_log'
            )
            ->where('row_rank', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($latestIds)) {
            return [];
        }

        $query = AuditLog::query()
            ->whereIn('id', $latestIds);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        } elseif ($viewer) {
            $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
        }

        $logs = $query->get(['id', 'actor_id', 'action', 'entity_type', 'entity_id', 'platform_id', 'created_at', 'reason', 'after_state']);
        $latest = [];

        foreach ($logs as $log) {
            $userId = (int) $log->actor_id;
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

    private function recentActivity(
        User $agent,
        CarbonInterface $start,
        CarbonInterface $end,
        ?User $viewer,
        ?int $platformId,
        int $limit = 20
    ): array {
        $query = AuditLog::query()
            ->where('actor_id', $agent->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
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

    private function enrichActivityLogs(Collection $logs, string $targetCurrency): array
    {
        if ($logs->isEmpty()) {
            return [];
        }

        $paymentLogs = $logs
            ->filter(fn (AuditLog $log) => (string) $log->entity_type === 'payment')
            ->values();
        $paymentsById = $this->paymentsForActivityLogs($paymentLogs);
        $paymentPayloads = $this->paymentActivityPayloads($paymentsById, $targetCurrency);

        $dealLogs = $logs
            ->filter(fn (AuditLog $log) => (string) $log->entity_type === 'deal')
            ->values();
        $dealsById = $this->dealsForActivityLogs($dealLogs);
        $approversById = $this->approversForDealActivityLogs($dealLogs);

        return $logs
            ->map(function (AuditLog $log) use ($paymentPayloads, $dealsById, $approversById) {
                $extras = [];

                if ((string) $log->entity_type === 'payment') {
                    $paymentPayload = $paymentPayloads[(int) $log->entity_id] ?? null;
                    if ($paymentPayload) {
                        $extras['payment'] = $paymentPayload;
                    }
                }

                if ((string) $log->entity_type === 'deal') {
                    $dealMeta = $this->dealActivityMeta($log, $dealsById[(int) $log->entity_id] ?? null, $approversById);
                    if ($dealMeta !== null) {
                        $extras['deal_meta'] = $dealMeta;
                    }
                }

                return $this->formatActivityLog($log, $extras);
            })
            ->all();
    }

    private function paymentsForActivityLogs(Collection $paymentLogs): Collection
    {
        $paymentIds = $paymentLogs
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($paymentIds->isEmpty()) {
            return collect();
        }

        return Payment::query()
            ->whereIn('id', $paymentIds)
            ->with([
                'client:id,name',
                'deal.client:id,name',
                'platform:id,name,country,currency_code',
                'deal.assignedAgent:id,name,role',
                'confirmedBy:id,name,role',
                'manualSubmission',
                'routingDecisions',
                'providerTransactions',
            ])
            ->get()
            ->keyBy('id');
    }

    private function paymentActivityPayloads(Collection $paymentsById, string $targetCurrency): array
    {
        $payloads = [];

        foreach ($paymentsById as $payment) {
            $eventDate = $payment->completed_at ?: $payment->created_at;
            $currency = strtoupper((string) ($payment->currency ?: $payment->platform?->currency_code ?: $targetCurrency));
            $normalized = $this->reportingCurrencyService->normalizeEventRows([
                (object) [
                    'event_date' => $eventDate?->toDateString() ?: now()->toDateString(),
                    'currency' => $currency,
                    'amount' => (float) $payment->amount,
                    'platform_id' => $payment->platform_id,
                    'platform_country' => $payment->platform?->country,
                    'platform_name' => $payment->platform?->name,
                ],
            ], $targetCurrency, false);
            $client = $payment->client ?: $payment->deal?->client;

            $payloads[(int) $payment->id] = [
                'amount' => (float) $payment->amount,
                'currency' => $currency,
                'normalized_total' => $normalized['normalized_total'],
                'normalized_currency' => $normalized['normalized_currency'],
                'normalization_meta' => $normalized['normalization_meta'],
                'status' => $payment->status,
                'client' => $client ? [
                    'id' => (int) $client->id,
                    'name' => $client->name,
                ] : null,
                'method' => $this->paymentPresenter->paymentMethod($payment),
                'channel' => $this->paymentPresenter->paymentChannel($payment),
            ];
        }

        return $payloads;
    }

    private function dealsForActivityLogs(Collection $dealLogs): Collection
    {
        $dealIds = $dealLogs
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($dealIds->isEmpty()) {
            return collect();
        }

        return Deal::query()
            ->whereIn('id', $dealIds)
            ->with(['platform:id,name,currency_code,country', 'client:id,name', 'product:id,name,display_name,tier'])
            ->get()
            ->keyBy('id');
    }

    private function approversForDealActivityLogs(Collection $dealLogs): Collection
    {
        $approverIds = $dealLogs
            ->map(fn (AuditLog $log) => (int) ($log->after_state['discount_approved_by'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($approverIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $approverIds)
            ->get(['id', 'name'])
            ->keyBy('id');
    }

    private function dealActivityMeta(AuditLog $log, ?Deal $deal, Collection $approversById): ?array
    {
        $afterState = is_array($log->after_state) ? $log->after_state : [];
        $actorPayload = $log->relationLoaded('actor') && $log->actor ? [
            'id' => (int) $log->actor->id,
            'name' => $log->actor->name,
        ] : null;
        $currency = strtoupper((string) ($deal?->currency ?: $deal?->platform?->currency_code ?: ''));
        $base = [
            'type' => 'subscription',
            'amount' => $deal?->amount !== null ? (float) $deal->amount : null,
            'currency' => $currency,
            'amount_display' => $deal?->amount !== null && $currency !== ''
                ? ($currency.' '.$this->formatMoney((float) $deal->amount))
                : null,
            'status' => $deal?->status,
            'expires_at' => $deal?->expires_at?->toIso8601String(),
            'activated_at' => $deal?->activated_at?->toIso8601String(),
            'duration' => $deal?->duration,
            'duration_days' => $deal?->duration_days !== null ? (int) $deal->duration_days : null,
            'plan_type' => $deal?->plan_type,
            'product' => $deal?->product ? [
                'id' => (int) $deal->product->id,
                'name' => $deal->product->display_name ?: $deal->product->name,
                'tier' => $deal->product->tier,
            ] : null,
            'client' => $deal?->client ? [
                'id' => (int) $deal->client->id,
                'name' => $deal->client->name,
            ] : null,
        ];

        if ((string) $log->action === CrmAuditAction::DEAL_DISCOUNT) {
            $approverId = (int) ($afterState['discount_approved_by'] ?? 0);
            $approver = $approverId > 0 && $approversById->has($approverId)
                ? [
                    'id' => $approverId,
                    'name' => $approversById[$approverId]->name,
                ]
                : $actorPayload;

            return array_merge($base, [
                'type' => 'discount',
                'discount_percentage' => isset($afterState['discount_percentage'])
                    ? (float) $afterState['discount_percentage']
                    : ($deal?->discount_percentage !== null ? (float) $deal->discount_percentage : null),
                'original_amount' => isset($afterState['original_amount'])
                    ? (float) $afterState['original_amount']
                    : ($deal?->original_amount !== null ? (float) $deal->original_amount : null),
                'discounted_amount' => isset($afterState['amount'])
                    ? (float) $afterState['amount']
                    : ($deal?->amount !== null ? (float) $deal->amount : null),
                'discount_source' => $afterState['discount_source'] ?? $deal?->discount_source,
                'approver' => $approver,
            ]);
        }

        if ((string) $log->action === CrmAuditAction::DEAL_FREE_TRIAL) {
            return array_merge($base, [
                'type' => 'free_trial',
                'is_free_trial' => true,
                'duration_days' => (int) ($afterState['duration_days'] ?? $afterState['additional_days'] ?? $deal?->duration_days ?? 0),
                'approval_mode' => $afterState['approval_mode'] ?? null,
                'approver' => $actorPayload,
            ]);
        }

        return $deal ? $base : null;
    }

    private function formatPaymentActivityRecord(Payment $payment, ?array $paymentPayload): array
    {
        $eventAt = $payment->completed_at ?: $payment->created_at;
        $deal = $payment->deal;

        return [
            'id' => (int) $payment->id,
            'action' => 'payment_record',
            'label' => 'Collected payment',
            'entity_type' => 'payment',
            'entity_id' => (int) $payment->id,
            'entity_url' => $this->entityUrl('payment', (int) $payment->id),
            'platform_id' => (int) $payment->platform_id,
            'reason' => $payment->transaction_reference ?: $payment->reference_number,
            'created_at' => $eventAt?->toIso8601String(),
            'payment' => $paymentPayload,
            'deal_meta' => $deal ? [
                'type' => 'subscription',
                'amount' => $deal->amount !== null ? (float) $deal->amount : null,
                'currency' => strtoupper((string) ($deal->currency ?: $deal->platform?->currency_code ?: $payment->currency)),
                'amount_display' => $deal->amount !== null
                    ? (strtoupper((string) ($deal->currency ?: $deal->platform?->currency_code ?: $payment->currency)).' '.$this->formatMoney((float) $deal->amount))
                    : null,
                'status' => $deal->status,
                'expires_at' => $deal->expires_at?->toIso8601String(),
                'activated_at' => $deal->activated_at?->toIso8601String(),
                'duration' => $deal->duration,
                'duration_days' => $deal->duration_days !== null ? (int) $deal->duration_days : null,
                'plan_type' => $deal->plan_type,
                'product' => $deal->product ? [
                    'id' => (int) $deal->product->id,
                    'name' => $deal->product->display_name ?: $deal->product->name,
                    'tier' => $deal->product->tier,
                ] : null,
                'client' => $deal->client ? [
                    'id' => (int) $deal->client->id,
                    'name' => $deal->client->name,
                ] : null,
            ] : null,
        ];
    }

    private function formatActivityLog(AuditLog $log, array $extras = []): array
    {
        $payload = [
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

        if ($log->relationLoaded('actor') && $log->actor) {
            $payload['actor'] = [
                'id' => (int) $log->actor->id,
                'name' => $log->actor->name,
            ];
        }

        return array_merge($payload, $extras);
    }

    private function activityLabel(AuditLog $log): string
    {
        return match ((string) $log->action) {
            'client_create' => 'Created client profile',
            'client_credential_reset' => 'Reset client credentials',
            'deal_activate' => (($log->after_state['deal_status'] ?? null) === 'active')
                ? 'Activated subscription'
                : 'Started subscription activation',
            'deal_renew' => (($log->after_state['new_status'] ?? null) === 'active')
                ? 'Renewed subscription'
                : 'Started renewal',
            'deal_free_trial' => 'Approved free trial',
            'deal_discount' => 'Applied discount',
            'payment_match_confirm' => 'Matched payment manually',
            'payment_match_auto' => 'Matched payment automatically',
            'payment_create_subscription' => 'Created subscription from payment',
            'payment_manual_approve' => 'Approved manual payment',
            'payment_manual_verify' => 'Verified manual payment',
            'payment_manual_reject' => 'Rejected manual payment',
            'payment_manual_close' => 'Closed payment',
            'payment_send_link' => 'Sent payment link',
            'payment_retry_stk' => 'Retried STK payment',
            'conversation_sms_sent' => 'Sent conversation SMS',
            'conversation_sms_failed' => 'Conversation SMS failed',
            'conversation_whatsapp_sent' => 'Sent conversation WhatsApp',
            'conversation_whatsapp_failed' => 'Conversation WhatsApp failed',
            'renewal_sms_sent' => 'Sent renewal SMS',
            'renewal_sms_failed' => 'Renewal SMS failed',
            'renewal_whatsapp_sent' => 'Sent renewal WhatsApp',
            'renewal_whatsapp_failed' => 'Renewal WhatsApp failed',
            'lead_status_update' => (($log->after_state['status'] ?? null) === 'contacted')
                ? 'Contacted lead'
                : 'Updated lead status',
            'lead_convert_to_client' => 'Converted lead to client',
            'support_chat_reply' => 'Replied in support chat',
            'client_credential_send' => 'Sent credentials',
            'client_login_as_client_link' => 'Generated client session link',
            default => ucwords(str_replace('_', ' ', (string) $log->action)),
        };
    }

    private function entityUrl(string $entityType, int $entityId): ?string
    {
        return match ($entityType) {
            'client' => '/clients/'.$entityId,
            'lead' => '/leads/'.$entityId,
            'deal' => '/deals/'.$entityId,
            'payment' => '/payments/'.$entityId,
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
            'target_currency' => $goal->target_currency,
            'target_display' => $this->formatGoalValue($goal->metric, (float) $goal->target, $goal->target_currency),
            'value_type' => $this->goalMetricValueType($goal->metric),
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
            'target_currency' => $goalOverride->target_currency,
            'target_display' => $this->formatGoalValue($goalOverride->metric, (float) $goalOverride->target, $goalOverride->target_currency),
            'value_type' => $this->goalMetricValueType($goalOverride->metric),
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
        $targetCurrency = $this->goalTargetCurrency($goal->metric, $goal->target_currency);
        $current = $this->goalCurrentValue($goal->metric, $range['start'], $range['end'], $viewer, $platformId, (int) $agent->id, $targetCurrency);
        $percentage = $goal->target > 0
            ? (int) min(100, round(($current / $goal->target) * 100))
            : 0;

        return [
            'user_id' => (int) $agent->id,
            'name' => $agent->name,
            'role' => $agent->role,
            'current' => $current,
            'target' => (int) $goal->target,
            'target_currency' => $targetCurrency,
            'current_display' => $this->formatGoalValue($goal->metric, $current, $targetCurrency),
            'target_display' => $this->formatGoalValue($goal->metric, (float) $goal->target, $targetCurrency),
            'percentage' => $percentage,
        ];
    }

    private function formatGoalOverrideProgressRow(AgentGoalOverride $goalOverride, User $agent, User $viewer): array
    {
        $range = $this->goalPeriodRange($goalOverride->period);
        $platformId = (int) $goalOverride->platform_id;
        $targetCurrency = $this->goalTargetCurrency($goalOverride->metric, $goalOverride->target_currency);
        $current = $this->goalCurrentValue($goalOverride->metric, $range['start'], $range['end'], $viewer, $platformId, (int) $agent->id, $targetCurrency);
        $percentage = $goalOverride->target > 0
            ? (int) min(100, round(($current / $goalOverride->target) * 100))
            : 0;

        return [
            'user_id' => (int) $agent->id,
            'name' => $agent->name,
            'role' => $agent->role,
            'current' => $current,
            'target' => (int) $goalOverride->target,
            'target_currency' => $targetCurrency,
            'current_display' => $this->formatGoalValue($goalOverride->metric, $current, $targetCurrency),
            'target_display' => $this->formatGoalValue($goalOverride->metric, (float) $goalOverride->target, $targetCurrency),
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

    private function goalCurrentValue(
        string $metric,
        CarbonInterface $start,
        CarbonInterface $end,
        User $viewer,
        ?int $platformId,
        int $userId,
        ?string $targetCurrency
    ): float {
        if ($metric === 'revenue') {
            $currency = $targetCurrency ?: $this->reportingCurrencyService->resolveTargetCurrency();
            $revenue = $this->aggregateRevenueByUser([$userId], $start, $end, $viewer, $platformId, $currency);

            return (float) ($revenue[$userId]['normalized_revenue_total'] ?? 0);
        }

        $metrics = $this->aggregateActionMetricsForRange($start, $end, $viewer, $platformId, [$userId]);

        return (float) (($metrics[$userId][$metric] ?? 0));
    }

    private function formatSingleUserGoalProgress(AgentGoal $goal, User $user): array
    {
        $range = $this->goalPeriodRange($goal->period);
        $platformId = $goal->platform_id ? (int) $goal->platform_id : null;
        $targetCurrency = $this->goalTargetCurrency($goal->metric, $goal->target_currency);
        $current = $this->goalCurrentValue($goal->metric, $range['start'], $range['end'], $user, $platformId, (int) $user->id, $targetCurrency);
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
            'target_currency' => $targetCurrency,
            'target_display' => $this->formatGoalValue($goal->metric, (float) $goal->target, $targetCurrency),
            'current' => $current,
            'current_display' => $this->formatGoalValue($goal->metric, $current, $targetCurrency),
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
        $targetCurrency = $this->goalTargetCurrency($goalOverride->metric, $goalOverride->target_currency);
        $current = $this->goalCurrentValue($goalOverride->metric, $range['start'], $range['end'], $user, $platformId, (int) $user->id, $targetCurrency);
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
            'target_currency' => $targetCurrency,
            'target_display' => $this->formatGoalValue($goalOverride->metric, (float) $goalOverride->target, $targetCurrency),
            'current' => $current,
            'current_display' => $this->formatGoalValue($goalOverride->metric, $current, $targetCurrency),
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

                if (! empty($accessiblePlatforms)) {
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
            ->when(! empty($visibleAgentIds), fn ($query) => $query->whereIn('user_id', $visibleAgentIds))
            ->when($platformId !== null, fn ($query) => $query->where('platform_id', $platformId))
            ->when($platformId === null, function ($query) use ($viewer) {
                $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
            });
    }

    private function marketRevenueTargetsQuery(User $viewer, ?int $platformId, string $period)
    {
        return MarketRevenueTarget::query()
            ->where('period', $period)
            ->when($platformId !== null, fn ($query) => $query->where('platform_id', $platformId))
            ->when($platformId === null, function ($query) use ($viewer) {
                $this->marketAuthorizationService->applyPlatformScope($query, $viewer);
            });
    }

    private function formatMarketRevenueTarget(MarketRevenueTarget $target, Collection $overrides): array
    {
        $assigned = $overrides
            ->where('metric', 'revenue')
            ->where('platform_id', (int) $target->platform_id)
            ->where('period', $target->period)
            ->sum(fn (AgentGoalOverride $goal) => (float) $goal->target);
        $targetAmount = (float) $target->target;
        $gap = $targetAmount - (float) $assigned;
        $assignedPercentage = $targetAmount > 0
            ? (int) min(999, round(((float) $assigned / $targetAmount) * 100))
            : 0;

        return [
            'id' => (int) $target->id,
            'platform_id' => (int) $target->platform_id,
            'platform_name' => $target->platform?->name,
            'platform_country' => $target->platform?->country,
            'period' => $target->period,
            'target' => $targetAmount,
            'target_currency' => $target->target_currency,
            'target_display' => $this->formatGoalValue('revenue', $targetAmount, $target->target_currency),
            'assigned' => (float) $assigned,
            'assigned_display' => $this->formatGoalValue('revenue', (float) $assigned, $target->target_currency),
            'gap' => $gap,
            'gap_display' => $this->formatGoalValue('revenue', abs($gap), $target->target_currency),
            'assigned_percentage' => $assignedPercentage,
            'is_over_allocated' => $gap < 0,
            'set_by' => $target->setter ? [
                'id' => (int) $target->setter->id,
                'name' => $target->setter->name,
            ] : null,
        ];
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
        if (! $this->goalRoleScopeMatchesUser($goal->role_scope, $user)) {
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
        if (! in_array($role, self::AGENT_ROLES, true)) {
            return false;
        }

        return in_array($role, $this->allowedGoalRoleScopesForMetric($metric), true)
            || in_array(self::GOAL_ROLE_SCOPE_ALL, $this->allowedGoalRoleScopesForMetric($metric), true);
    }

    private function assertGoalMetricAllowedForRoleScope(string $metric, string $roleScope): void
    {
        if (! $this->goalMetricAllowedForRoleScope($metric, $roleScope)) {
            abort(422, 'This metric is not supported for the selected role scope.');
        }
    }

    private function assertGoalMetricAllowedForRole(string $metric, string $role): void
    {
        if (! $this->goalMetricAllowedForRole($metric, $role)) {
            abort(422, 'This metric is not supported for the selected user role.');
        }
    }

    private function assertGoalAssigneeAccessible(User $viewer, User $agent, int $platformId): void
    {
        $this->assertAgentVisibleToViewer($viewer, $agent);

        if (! $this->userHasPlatform($agent, $platformId)) {
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
            'discounts_given',
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
            'discounts_given' => 0,
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
            + ($row['discounts_given'] ?? 0)
        );
    }

    private function buildRevenuePayload(array $currencyBreakdown, ?int $platformId, ?array $normalized = null): array
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
            ->map(fn (array $row) => $row['currency'].' '.$this->formatMoney((float) $row['amount']))
            ->implode(' | ');

        $normalized ??= $this->reportingCurrencyService->normalizeBreakdown($currencyBreakdown);
        $normalizedFields = [
            'revenue_source' => 'collected_payments',
            'normalized_revenue_total' => $normalized['normalized_total'],
            'normalized_revenue_currency' => $normalized['normalized_currency'],
            'normalized_revenue_display' => $normalized['normalized_display'],
            'revenue_normalization_meta' => $normalized['normalization_meta'],
        ];

        if ($platformId !== null) {
            $single = $rows[0] ?? [
                'currency' => null,
                'amount' => '0.00',
            ];

            return array_merge([
                'revenue_total' => $single['amount'],
                'revenue_currency' => $single['currency'],
                'revenue_by_currency' => $rows,
                'revenue_display' => $single['currency']
                    ? ($single['currency'].' '.$this->formatMoney((float) $single['amount']))
                    : '--',
            ], $normalizedFields);
        }

        return array_merge([
            'revenue_total' => null,
            'revenue_currency' => null,
            'revenue_by_currency' => $rows,
            'revenue_display' => $display !== '' ? $display : '--',
        ], $normalizedFields);
    }

    private function emptyRevenuePayload(?int $platformId): array
    {
        return [
            'revenue_total' => $platformId !== null ? '0.00' : null,
            'revenue_currency' => null,
            'revenue_by_currency' => [],
            'revenue_display' => '--',
            'revenue_source' => 'collected_payments',
            'normalized_revenue_total' => 0.0,
            'normalized_revenue_currency' => $this->reportingCurrencyService->resolveTargetCurrency(),
            'normalized_revenue_display' => $this->reportingCurrencyService->normalizeBreakdown([])['normalized_display'],
            'revenue_normalization_meta' => $this->reportingCurrencyService->normalizeBreakdown([])['normalization_meta'],
        ];
    }

    private function leaderboardRevenueSortValue(array $row, string $currencyMode): ?float
    {
        if ($currencyMode === ReportingCurrencyService::MODE_FLAT && $row['normalized_revenue_total'] !== null) {
            return (float) $row['normalized_revenue_total'];
        }

        if ($row['revenue_total'] !== null) {
            return (float) $row['revenue_total'];
        }

        return null;
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
            'discounts_given' => 'Discounts Given',
            'revenue' => 'Revenue',
            'total_actions' => 'Total Actions',
            default => ucwords(str_replace('_', ' ', $metric)),
        };
    }

    private function goalMetricValueType(string $metric): string
    {
        return $metric === 'revenue' ? 'currency' : 'count';
    }

    private function goalTargetCurrency(string $metric, ?string $targetCurrency): ?string
    {
        if ($metric !== 'revenue') {
            return null;
        }

        return $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);
    }

    private function formatGoalValue(string $metric, float $value, ?string $targetCurrency): string
    {
        if ($metric === 'revenue') {
            return ($targetCurrency ?: $this->reportingCurrencyService->resolveTargetCurrency()).' '.$this->formatMoney($value);
        }

        return number_format((int) round($value));
    }

    private function visibleTeamRolesForViewer(User $viewer, string $roleFilter = self::ROLE_FILTER_ALL): array
    {
        $allowedRoles = $viewer->role === MarketAuthorizationService::ROLE_ADMIN
            ? self::ADMIN_VISIBLE_TEAM_ROLES
            : self::AGENT_ROLES;

        $roleFilter = $this->normalizeLeaderboardRoleFilter($roleFilter);
        if ($roleFilter === self::ROLE_FILTER_ALL) {
            return $allowedRoles;
        }

        return in_array($roleFilter, $allowedRoles, true) ? [$roleFilter] : [];
    }

    private function goalRoleScopeLabel(string $roleScope): string
    {
        return match ($roleScope) {
            self::GOAL_ROLE_SCOPE_SALES => 'Sales only',
            self::GOAL_ROLE_SCOPE_MARKETING => 'Marketing only',
            self::GOAL_ROLE_SCOPE_SUB_ADMIN => 'Sub-admins only',
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
            self::PERIOD_TODAY, self::PERIOD_WEEK, self::PERIOD_MONTH, self::PERIOD_30_DAYS => $period,
            default => self::PERIOD_WEEK,
        };
    }

    private function normalizeActivityEntityType(mixed $entityType): string
    {
        $value = strtolower(trim((string) $entityType));

        return match ($value) {
            'subscription' => 'deal',
            'client', 'lead', 'payment', 'deal', 'user', 'platform' => $value,
            default => '',
        };
    }

    private function normalizeLeaderboardRoleFilter(string $roleFilter): string
    {
        $roleFilter = strtolower(trim($roleFilter));

        return in_array($roleFilter, self::ROLE_FILTERS, true)
            ? $roleFilter
            : self::ROLE_FILTER_ALL;
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

        if (! in_array($metric, self::GOAL_METRICS, true)) {
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
            self::GOAL_ROLE_SCOPE_SUB_ADMIN,
            self::GOAL_ROLE_SCOPE_ALL => $roleScope,
            default => self::GOAL_ROLE_SCOPE_SALES,
        };
    }

    private function normalizeGoalTargetCurrency(string $metric, ?string $targetCurrency): ?string
    {
        if ($metric !== 'revenue') {
            return null;
        }

        return $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);
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
            self::PERIOD_30_DAYS => [
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy(),
            ],
            default => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy(),
            ],
        };
    }

    private function resolveReportingRange(string $period, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if ($from && $to) {
            $start = Carbon::instance($from)->startOfDay();
            $end = Carbon::instance($to)->addDay()->startOfDay();

            if ($start->gt($end)) {
                [$start, $end] = [$end->copy()->subDay()->startOfDay(), $start->copy()->addDay()->startOfDay()];
            }

            return [
                'start' => $start,
                'end' => $end,
            ];
        }

        $range = $this->resolveNamedPeriodRange($period);
        $range['end'] = Carbon::instance($range['end'])->addSecond();

        return $range;
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
