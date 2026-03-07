<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\RenewalRun;
use App\Services\MarketAuthorizationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this report market.'
        );

        $platformIds = $selectedPlatformId
            ? [(int) $selectedPlatformId]
            : $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $paymentsQuery = Payment::query()
            ->where('status', 'completed')
            ->excludingWalletTopups()
            ->whereBetween('created_at', [$from, $to]);
        $walletTopupsQuery = Payment::query()
            ->where('status', 'completed')
            ->walletTopups()
            ->whereBetween('created_at', [$from, $to]);

        $clientsQuery = Client::query();
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
            ->where('status', 'completed')
            ->excludingWalletTopups()
            ->where('created_at', '>=', now()->startOfMonth())
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->sum('amount');
        $walletTopupRevenueMtd = Payment::query()
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
        ]);
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
}
