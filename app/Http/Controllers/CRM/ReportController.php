<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Deal;
use App\Models\IntegrationSetting;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\RenewalRun;
use App\Services\MarketAuthorizationService;
use App\Services\WpSyncService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function summary(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|exists:platforms,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->subMonths(5)->startOfMonth();
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();

        $baselineCutoff = $this->resolveBaselineCutoff();
        if ($baselineCutoff && $baselineCutoff->gt($from)) {
            $from = $baselineCutoff;
        }

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this report market.'
        );

        $platformIds = $selectedPlatformId
            ? [(int) $selectedPlatformId]
            : $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $paymentsQuery = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
            ->excludingWalletTopups()
            ->whereBetween('created_at', [$from, $to]);
        $walletTopupsQuery = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
            ->walletTopups()
            ->whereBetween('created_at', [$from, $to]);

        $clientsQuery = Client::query()->where('created_at', '>=', $from);
        $leadsQuery = Lead::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('archived_at');
        $dealsQuery = Deal::query()->whereBetween('created_at', [$from, $to]);

        if (is_array($platformIds)) {
            $paymentsQuery->whereIn('platform_id', $platformIds);
            $clientsQuery->whereIn('platform_id', $platformIds);
            $leadsQuery->whereIn('platform_id', $platformIds);
            $dealsQuery->whereIn('platform_id', $platformIds);
        }

        $funnelStageLabels = [
            'new' => 'New',
            'contacted' => 'Contacted',
            'qualified' => 'Qualified',
            'converted' => 'Converted',
            'lost' => 'Lost',
        ];

        $leadFunnel = [];
        foreach (array_keys($funnelStageLabels) as $stage) {
            $leadFunnel[$stage] = (clone $leadsQuery)->where('status', $stage)->count();
        }

        $totalLeads = array_sum($leadFunnel);
        $conversionRate = $totalLeads > 0 ? round(($leadFunnel['converted'] / $totalLeads) * 100) : 0;
        $leadFunnelStages = [];
        $previousStageCount = null;

        foreach ($funnelStageLabels as $stageKey => $label) {
            $count = (int) ($leadFunnel[$stageKey] ?? 0);
            $conversionFromPrevious = null;
            $dropoffFromPrevious = null;

            if ($previousStageCount !== null && $previousStageCount > 0) {
                $conversionFromPrevious = round(($count / $previousStageCount) * 100, 1);
                $dropoffFromPrevious = max(0, round(100 - $conversionFromPrevious, 1));
            }

            $leadFunnelStages[] = [
                'key' => $stageKey,
                'label' => $label,
                'count' => $count,
                'share_of_total' => $totalLeads > 0 ? round(($count / $totalLeads) * 100, 1) : 0,
                'conversion_from_previous' => $conversionFromPrevious,
                'dropoff_from_previous' => $dropoffFromPrevious,
            ];

            $previousStageCount = $count;
        }

        $activeDeals = Deal::query()
            ->where('status', 'active')
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->count();

        $expiringSoon = Deal::query()
            ->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->count();

        $renewalRate = ($activeDeals + $expiringSoon) > 0
            ? round(($activeDeals / ($activeDeals + $expiringSoon)) * 100)
            : 0;

        $revenueMtd = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
            ->excludingWalletTopups()
            ->where('created_at', '>=', now()->startOfMonth())
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->sum('amount');
        $walletTopupRevenueMtd = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
            ->walletTopups()
            ->where('created_at', '>=', now()->startOfMonth())
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->sum('amount');

        $kpis = [
            'total_revenue' => (float) (clone $paymentsQuery)->sum('amount'),
            'revenue_mtd' => (float) $revenueMtd,
            'wallet_topups_count' => (int) (clone $walletTopupsQuery)->count(),
            'wallet_topup_revenue' => (float) (clone $walletTopupsQuery)->sum('amount'),
            'wallet_topup_revenue_mtd' => (float) $walletTopupRevenueMtd,
            'active_clients' => (int) (clone $clientsQuery)->where('profile_status', 'publish')->count(),
            'total_clients' => (int) (clone $clientsQuery)->count(),
            'conversion_rate' => $conversionRate,
            'renewal_rate' => $renewalRate,
            'active_deals' => $activeDeals,
            'expiring_soon' => $expiringSoon,
        ];

        $monthKeyExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $revenueTrendRows = Payment::query()
            ->liveOnly()
            ->selectRaw("{$monthKeyExpression} as month_key")
            ->selectRaw('SUM(amount) as total_revenue')
            ->where('status', 'completed')
            ->excludingWalletTopups()
            ->whereBetween('created_at', [$from, $to])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->groupBy('month_key')
            ->orderByDesc('month_key')
            ->limit(6)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($row) => [
                'month_key' => $row->month_key,
                'label' => $this->formatMonthLabel($row->month_key),
                'value' => (float) $row->total_revenue,
            ]);

        $leadSources = Lead::query()
            ->selectRaw('source, COUNT(*) as total')
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->groupBy('source')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'source' => $row->source ?: 'unknown',
                'value' => (int) $row->total,
            ]);

        $packageRevenue = Deal::query()
            ->leftJoin('products', 'products.id', '=', 'deals.product_id')
            ->selectRaw('COALESCE(products.name, deals.plan_type) as package_name')
            ->selectRaw('SUM(deals.amount) as total_revenue')
            ->whereBetween('deals.created_at', [$from, $to])
            ->whereNotIn('deals.status', ['pending', 'cancelled'])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('deals.platform_id', $platformIds))
            ->groupBy(DB::raw('COALESCE(products.name, deals.plan_type)'))
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'label' => $row->package_name ?: 'Unknown',
                'value' => (float) $row->total_revenue,
            ]);

        $ownerPerformance = Deal::query()
            ->leftJoin('users', 'users.id', '=', 'deals.assigned_to')
            ->selectRaw("COALESCE(users.name, 'Unassigned') as owner_name")
            ->selectRaw('COUNT(deals.id) as deals_count')
            ->selectRaw('SUM(deals.amount) as total_revenue')
            ->selectRaw("SUM(CASE WHEN deals.status = 'active' THEN 1 ELSE 0 END) as active_subscriptions")
            ->selectRaw("SUM(CASE WHEN deals.status IN ('pending', 'awaiting_payment', 'paid') THEN 1 ELSE 0 END) as pre_activation_subscriptions")
            ->selectRaw("SUM(CASE WHEN deals.status = 'expired' THEN 1 ELSE 0 END) as expired_subscriptions")
            ->whereBetween('deals.created_at', [$from, $to])
            ->whereNotIn('deals.status', ['cancelled'])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('deals.platform_id', $platformIds))
            ->groupBy(DB::raw("COALESCE(users.name, 'Unassigned')"))
            ->orderByDesc('deals_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'owner' => $row->owner_name,
                'deals' => (int) $row->deals_count,
                'revenue' => (float) $row->total_revenue,
                'active_subscriptions' => (int) $row->active_subscriptions,
                'pre_activation_subscriptions' => (int) $row->pre_activation_subscriptions,
                'expired_subscriptions' => (int) $row->expired_subscriptions,
                'avg_revenue_per_subscription' => (int) $row->deals_count > 0
                    ? round(((float) $row->total_revenue) / (int) $row->deals_count, 2)
                    : 0,
            ]);

        $ownerPerformanceTotals = [
            'subscriptions' => (int) $ownerPerformance->sum('deals'),
            'revenue' => (float) $ownerPerformance->sum('revenue'),
            'active_subscriptions' => (int) $ownerPerformance->sum('active_subscriptions'),
        ];

        $topOwner = $ownerPerformance->first();

        $renewalRuns = RenewalRun::query()
            ->whereBetween('run_at', [$from, $to])
            ->get();

        $renewalHealth = [
            'run_count' => $renewalRuns->count(),
            'sent' => (int) $renewalRuns->sum('sent_count'),
            'failed' => (int) $renewalRuns->sum('failed_count'),
            'skipped' => (int) $renewalRuns->sum('skipped_count'),
            'active_deals' => $activeDeals,
            'expiring_soon' => $expiringSoon,
            'expired_last_30_days' => Deal::query()
                ->where('expires_at', '<', now())
                ->where('expires_at', '>=', now()->subDays(30))
                ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
                ->count(),
        ];

        return response()->json([
            'filters' => [
                'platform_id' => $selectedPlatformId ? (int) $selectedPlatformId : null,
            ],
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'kpis' => $kpis,
            'lead_funnel' => $leadFunnel,
            'lead_funnel_stages' => $leadFunnelStages,
            'lead_funnel_totals' => [
                'total' => $totalLeads,
                'workable' => (int) ($leadFunnel['new'] + $leadFunnel['contacted'] + $leadFunnel['qualified']),
                'converted' => (int) $leadFunnel['converted'],
                'lost' => (int) $leadFunnel['lost'],
            ],
            'revenue_trend' => $revenueTrendRows,
            'lead_sources' => $leadSources,
            'package_revenue' => $packageRevenue,
            'owner_performance' => $ownerPerformance,
            'owner_performance_totals' => $ownerPerformanceTotals,
            'owner_performance_top_owner' => $topOwner
                ? [
                    'owner' => $topOwner['owner'],
                    'deals' => $topOwner['deals'],
                    'revenue' => $topOwner['revenue'],
                    'active_subscriptions' => $topOwner['active_subscriptions'],
                ]
                : null,
            'renewal_health' => $renewalHealth,
            'baseline_cutoff' => $baselineCutoff?->toDateString(),
        ]);
    }

    public function profileEngagement(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|exists:platforms,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'compare_from' => 'nullable|date',
            'compare_to' => 'nullable|date|after_or_equal:compare_from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|in:engagement_score,profile_view,contact_rate,contact_total',
            'order' => 'nullable|in:asc,desc',
            'status' => 'nullable|in:publish,private,draft,pending',
        ]);

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this report market.'
        );

        if (!$selectedPlatformId) {
            $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

            if (is_array($accessiblePlatformIds) && count($accessiblePlatformIds) === 1) {
                $selectedPlatformId = (int) $accessiblePlatformIds[0];
            }
        }

        if (!$selectedPlatformId) {
            return response()->json([
                'message' => 'Select a market to view profile engagement analytics.',
            ], 422);
        }

        $params = array_filter([
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'compare_from' => $validated['compare_from'] ?? null,
            'compare_to' => $validated['compare_to'] ?? null,
            'page' => $validated['page'] ?? null,
            'per_page' => $validated['per_page'] ?? null,
            'sort_by' => $validated['sort_by'] ?? null,
            'order' => $validated['order'] ?? null,
            'status' => $validated['status'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $payload = WpSyncService::forPlatform((int) $selectedPlatformId)
                ->getAnalyticsRankings($params);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to fetch profile engagement analytics.',
                'error' => $exception->getMessage(),
            ], 502);
        }

        $payload['filters'] = array_merge(
            is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
            ['platform_id' => (int) $selectedPlatformId]
        );
        $payload['profiles'] = $this->enrichAnalyticsProfiles(
            (int) $selectedPlatformId,
            is_array($payload['profiles'] ?? null) ? $payload['profiles'] : []
        );

        return response()->json($payload);
    }

    private function resolveBaselineCutoff(): ?Carbon
    {
        try {
            $value = IntegrationSetting::query()
                ->where('key', 'data_baseline_mode')
                ->value('value');
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($value)) {
            return null;
        }
        $mode = $value['mode'] ?? 'fresh_start';
        $cutoffDate = $value['cutoff_date'] ?? null;
        if ($mode !== 'fresh_start' || !$cutoffDate) {
            return null;
        }
        try {
            return Carbon::parse($cutoffDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatMonthLabel(?string $monthKey): string
    {
        if (empty($monthKey)) {
            return 'Unknown month';
        }

        try {
            return Carbon::createFromFormat('Y-m', $monthKey)->format('M Y');
        } catch (\Throwable) {
            return $monthKey;
        }
    }

    private function enrichAnalyticsProfiles(int $platformId, array $profiles): array
    {
        if (empty($profiles)) {
            return [];
        }

        $postIds = collect($profiles)
            ->pluck('post_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($postIds->isEmpty()) {
            return $profiles;
        }

        $clients = Client::query()
            ->where('platform_id', $platformId)
            ->whereIn('wp_post_id', $postIds)
            ->with([
                'assignedAgent:id,name',
                'activeDeal:id,client_id,status,plan_type,product_id',
                'activeDeal.product:id,name,display_name,tier',
            ])
            ->get()
            ->keyBy(fn (Client $client) => (int) $client->wp_post_id);

        return array_map(function (array $profile) use ($clients) {
            $postId = (int) ($profile['post_id'] ?? 0);
            /** @var Client|null $client */
            $client = $clients->get($postId);

            $profile['crm_client_id'] = $client ? (int) $client->id : null;
            $profile['assigned_agent'] = $client && $client->assignedAgent
                ? [
                    'id' => (int) $client->assignedAgent->id,
                    'name' => $client->assignedAgent->name,
                ]
                : null;
            $profile['assigned_agent_name'] = $client?->assignedAgent?->name;
            $profile['subscription_tier'] = $client ? $this->resolveAnalyticsPlanLabel($client) : null;
            $profile['subscription_status'] = $client?->activeDeal?->status ?? $client?->profile_status;
            $profile['crm_profile_status'] = $client?->profile_status;
            $profile['wp_profile_url'] = $client?->wp_profile_url;

            return $profile;
        }, $profiles);
    }

    private function resolveAnalyticsPlanLabel(Client $client): string
    {
        $product = $client->activeDeal?->product;

        if ($product?->display_name) {
            return (string) $product->display_name;
        }

        if ($product?->name) {
            return (string) $product->name;
        }

        if ($client->activeDeal?->plan_type) {
            return Str::title((string) $client->activeDeal->plan_type);
        }

        return (string) $client->plan_label;
    }
}
