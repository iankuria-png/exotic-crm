<?php

namespace App\Http\Controllers\CRM;

use App\Helpers\CurrencyBreakdown;
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

        $paymentsQuery = $this->successfulCollectedPaymentsQuery($from, $to, $platformIds);
        $walletTopupsQuery = Payment::query()
            ->reportableSuccessful()
            ->walletTopups()
            ->whereBetween('created_at', [$from, $to]);

        $clientsQuery = Client::query()->where('created_at', '>=', $from);
        $leadsQuery = Lead::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('archived_at');
        $dealsQuery = Deal::query()->whereBetween('created_at', [$from, $to]);

        if (is_array($platformIds)) {
            $walletTopupsQuery->whereIn('platform_id', $platformIds);
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

        $revenueMtdQuery = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->where('created_at', '>=', now()->startOfMonth())
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds));
        $walletTopupMtdQuery = Payment::query()
            ->reportableSuccessful()
            ->walletTopups()
            ->where('created_at', '>=', now()->startOfMonth())
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds));

        // Per-currency breakdowns for all KPI revenue fields.
        $totalRevenueBreakdown   = CurrencyBreakdown::fromPaymentQuery(clone $paymentsQuery);
        $revenueMtdBreakdown     = CurrencyBreakdown::fromPaymentQuery(clone $revenueMtdQuery);
        $walletTopupBreakdown    = CurrencyBreakdown::fromPaymentQuery(clone $walletTopupsQuery);
        $walletTopupMtdBreakdown = CurrencyBreakdown::fromPaymentQuery(clone $walletTopupMtdQuery);

        $kpis = [
            'total_revenue' => $totalRevenueBreakdown['scalar_amount'],
            'total_revenue_breakdown' => $totalRevenueBreakdown['breakdown'],
            'revenue_mtd' => $revenueMtdBreakdown['scalar_amount'],
            'revenue_mtd_breakdown' => $revenueMtdBreakdown['breakdown'],
            'wallet_topups_count' => (int) (clone $walletTopupsQuery)->count(),
            'wallet_topup_revenue' => $walletTopupBreakdown['scalar_amount'],
            'wallet_topup_revenue_breakdown' => $walletTopupBreakdown['breakdown'],
            'wallet_topup_revenue_mtd' => $walletTopupMtdBreakdown['scalar_amount'],
            'wallet_topup_revenue_mtd_breakdown' => $walletTopupMtdBreakdown['breakdown'],
            'active_clients' => (int) (clone $clientsQuery)->active()->count(),
            'total_clients' => (int) (clone $clientsQuery)->count(),
            'conversion_rate' => $conversionRate,
            'renewal_rate' => $renewalRate,
            'active_deals' => $activeDeals,
            'expiring_soon' => $expiringSoon,
        ];

        $monthKeyExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
        $paymentCurrencyExpression = $this->paymentCurrencyExpression();
        $packageNameExpression = $this->paymentPackageExpression();
        $ownerNameExpression = $this->paymentOwnerExpression();

        $revenueTrendRows = Payment::query()
            ->reportableSuccessful()
            ->selectRaw("{$monthKeyExpression} as month_key")
            ->selectRaw('SUM(amount) as total_revenue')
            ->excludingWalletTopups()
            ->whereBetween('created_at', [$from, $to])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->groupBy('month_key')
            ->orderByDesc('month_key')
            ->limit(6)
            ->get()
            ->reverse()
            ->values();

        // Second pass: group by (month_key, currency) to build per-month breakdowns.
        $trendCurrencyRows = Payment::query()
            ->reportableSuccessful()
            ->selectRaw("{$monthKeyExpression} as month_key")
            ->selectRaw("{$paymentCurrencyExpression} as currency")
            ->selectRaw('SUM(amount) as total')
            ->excludingWalletTopups()
            ->whereBetween('created_at', [$from, $to])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->groupBy('month_key')
            ->groupByRaw($paymentCurrencyExpression)
            ->get();

        $breakdownByMonth = [];
        foreach ($trendCurrencyRows as $row) {
            $breakdownByMonth[$row->month_key][$row->currency] = (float) $row->total;
        }
        foreach ($breakdownByMonth as &$currencyMap) {
            ksort($currencyMap);
        }
        unset($currencyMap);

        $revenueTrendRows = $revenueTrendRows->map(fn ($row) => [
            'month_key'         => $row->month_key,
            'label'             => $this->formatMonthLabel($row->month_key),
            'value'             => count($breakdownByMonth[$row->month_key] ?? []) === 1
                                       ? array_values($breakdownByMonth[$row->month_key])[0]
                                       : null,
            'revenue_breakdown' => $breakdownByMonth[$row->month_key] ?? [],
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

        $packageRevenue = (clone $paymentsQuery)
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('products as payment_products', 'payment_products.id', '=', 'payments.product_id')
            ->leftJoin('products as deal_products', 'deal_products.id', '=', 'deals.product_id')
            ->selectRaw("{$packageNameExpression} as package_name")
            ->selectRaw("{$paymentCurrencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as total')
            ->groupByRaw("{$packageNameExpression}, {$paymentCurrencyExpression}")
            ->get()
            ->groupBy(fn ($row) => $row->package_name ?: 'Unknown package')
            ->map(function ($rows, $label) {
                $breakdown = [];
                foreach ($rows as $row) {
                    $breakdown[$row->currency] = ($breakdown[$row->currency] ?? 0.0) + (float) $row->total;
                }
                ksort($breakdown);

                return [
                    'label' => $label,
                    'value' => count($breakdown) === 1 ? array_values($breakdown)[0] : null,
                    'revenue_breakdown' => $breakdown,
                    'sort_value' => array_sum($breakdown),
                ];
            })
            ->sortByDesc('sort_value')
            ->take(10)
            ->map(fn ($row) => [
                'label' => $row['label'],
                'value' => $row['value'],
                'revenue_breakdown' => $row['revenue_breakdown'],
            ])
            ->values();

        $ownerPerformanceAll = (clone $paymentsQuery)
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('users', 'users.id', '=', 'deals.assigned_to')
            ->selectRaw("{$ownerNameExpression} as owner_name")
            ->selectRaw("{$paymentCurrencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as total')
            ->selectRaw('COUNT(payments.id) as payments_count')
            ->groupByRaw("{$ownerNameExpression}, {$paymentCurrencyExpression}")
            ->get()
            ->groupBy(fn ($row) => $row->owner_name ?: 'Unassigned')
            ->map(function ($rows, $owner) {
                $breakdown = [];
                $paymentCount = 0;
                foreach ($rows as $row) {
                    $breakdown[$row->currency] = ($breakdown[$row->currency] ?? 0.0) + (float) $row->total;
                    $paymentCount += (int) $row->payments_count;
                }
                ksort($breakdown);

                $avgBreakdown = [];
                foreach ($breakdown as $currency => $amount) {
                    $avgBreakdown[$currency] = $paymentCount > 0 ? round($amount / $paymentCount, 2) : 0.0;
                }

                $scalarAverage = count($breakdown) === 1 && $paymentCount > 0
                    ? round(array_values($breakdown)[0] / $paymentCount, 2)
                    : null;

                return [
                    'owner' => $owner,
                    'payments_count' => $paymentCount,
                    'deals' => $paymentCount,
                    'revenue' => count($breakdown) === 1 ? array_values($breakdown)[0] : null,
                    'revenue_breakdown' => $breakdown,
                    'avg_revenue_per_payment' => $scalarAverage,
                    'avg_revenue_per_payment_breakdown' => $avgBreakdown,
                    'avg_revenue_per_subscription' => $scalarAverage,
                    'avg_revenue_breakdown' => $avgBreakdown,
                    'active_subscriptions' => 0,
                    'sort_value' => array_sum($breakdown),
                ];
            })
            ->sortByDesc('sort_value')
            ->values();

        $ownerPerformanceTotals = [
            'subscriptions' => (int) $ownerPerformanceAll->sum('payments_count'),
            'payments_count' => (int) $ownerPerformanceAll->sum('payments_count'),
            'revenue' => count($kpis['total_revenue_breakdown']) === 1 ? array_values($kpis['total_revenue_breakdown'])[0] : null,
            'revenue_breakdown' => $kpis['total_revenue_breakdown'],
            'active_subscriptions' => 0,
        ];

        $topOwner = $ownerPerformanceAll->first();
        $ownerPerformance = $ownerPerformanceAll->take(10)->values();

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
                    'payments_count' => $topOwner['payments_count'],
                    'revenue' => $topOwner['revenue'],
                    'revenue_breakdown' => $topOwner['revenue_breakdown'],
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

    private function successfulCollectedPaymentsQuery(Carbon $from, Carbon $to, ?array $platformIds): Builder
    {
        return Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereBetween('payments.created_at', [$from, $to])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('payments.platform_id', $platformIds));
    }

    private function paymentCurrencyExpression(): string
    {
        return "COALESCE(payments.currency, (SELECT currency_code FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1), 'KES')";
    }

    private function paymentPackageExpression(): string
    {
        return "COALESCE(deal_products.name, payment_products.name, deals.plan_type, 'Unknown package')";
    }

    private function paymentOwnerExpression(): string
    {
        return "COALESCE(users.name, 'Unassigned')";
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
