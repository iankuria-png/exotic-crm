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
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->subMonths(5)->startOfMonth();
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();

        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $paymentsQuery = Payment::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to]);

        $clientsQuery = Client::query();
        $leadsQuery = Lead::query();
        $dealsQuery = Deal::query()->whereBetween('created_at', [$from, $to]);

        if (is_array($platformIds)) {
            $paymentsQuery->whereIn('platform_id', $platformIds);
            $clientsQuery->whereIn('platform_id', $platformIds);
            $leadsQuery->whereIn('platform_id', $platformIds);
            $dealsQuery->whereIn('platform_id', $platformIds);
        }

        $leadFunnel = [
            'new' => (clone $leadsQuery)->where('status', 'new')->count(),
            'contacted' => (clone $leadsQuery)->where('status', 'contacted')->count(),
            'qualified' => (clone $leadsQuery)->where('status', 'qualified')->count(),
            'converted' => (clone $leadsQuery)->where('status', 'converted')->count(),
            'lost' => (clone $leadsQuery)->where('status', 'lost')->count(),
        ];

        $totalLeads = array_sum($leadFunnel);
        $conversionRate = $totalLeads > 0 ? round(($leadFunnel['converted'] / $totalLeads) * 100) : 0;

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
            ->where('created_at', '>=', now()->startOfMonth())
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->sum('amount');

        $kpis = [
            'total_revenue' => (float) (clone $paymentsQuery)->sum('amount'),
            'revenue_mtd' => (float) $revenueMtd,
            'active_clients' => (int) (clone $clientsQuery)->where('profile_status', 'publish')->count(),
            'total_clients' => (int) (clone $clientsQuery)->count(),
            'conversion_rate' => $conversionRate,
            'renewal_rate' => $renewalRate,
            'active_deals' => $activeDeals,
            'expiring_soon' => $expiringSoon,
        ];

        $revenueTrendRows = Payment::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key")
            ->selectRaw("DATE_FORMAT(created_at, '%b %Y') as month_label")
            ->selectRaw('SUM(amount) as total_revenue')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when(is_array($platformIds), fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds))
            ->groupBy('month_key', 'month_label')
            ->orderByDesc('month_key')
            ->limit(6)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($row) => [
                'month_key' => $row->month_key,
                'label' => $row->month_label,
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
            ]);

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
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'kpis' => $kpis,
            'lead_funnel' => $leadFunnel,
            'revenue_trend' => $revenueTrendRows,
            'lead_sources' => $leadSources,
            'package_revenue' => $packageRevenue,
            'owner_performance' => $ownerPerformance,
            'renewal_health' => $renewalHealth,
        ]);
    }
}

