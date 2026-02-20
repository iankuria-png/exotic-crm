<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ClientNote;
use App\Services\MarketAuthorizationService;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function summary(Request $request)
    {
        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $expiringDealsQuery = Deal::expiringSoon(7)
            ->with(['client', 'product'])
            ->orderBy('expires_at');
        if (is_array($platformIds)) {
            $expiringDealsQuery->whereIn('platform_id', $platformIds);
        }
        $expiringDeals = $expiringDealsQuery->limit(10)->get();

        $recentPaymentsQuery = Payment::where('status', 'completed')
            ->with(['platform', 'product'])
            ->orderBy('created_at', 'desc');
        if (is_array($platformIds)) {
            $recentPaymentsQuery->whereIn('platform_id', $platformIds);
        }
        $recentPayments = $recentPaymentsQuery->limit(10)->get();

        $upcomingFollowUpsQuery = ClientNote::withPendingFollowUp()
            ->with(['client', 'author'])
            ->orderBy('follow_up_at');
        if (is_array($platformIds)) {
            $upcomingFollowUpsQuery->whereHas('client', function ($query) use ($platformIds) {
                $query->whereIn('platform_id', $platformIds);
            });
        }
        $upcomingFollowUps = $upcomingFollowUpsQuery->limit(10)->get();

        $activeClientsQuery = Client::active();
        $totalClientsQuery = Client::query();
        $pendingLeadsQuery = Lead::new();
        $totalLeadsQuery = Lead::query();
        $activeDealsQuery = Deal::active();
        $expiringSoonQuery = Deal::expiringSoon(7);
        $paymentsMtdQuery = Payment::where('status', 'completed')->where('created_at', '>=', now()->startOfMonth());
        $unmatchedPaymentsQuery = Payment::whereNull('client_id')->where('status', 'completed');

        if (is_array($platformIds)) {
            $activeClientsQuery->whereIn('platform_id', $platformIds);
            $totalClientsQuery->whereIn('platform_id', $platformIds);
            $pendingLeadsQuery->whereIn('platform_id', $platformIds);
            $totalLeadsQuery->whereIn('platform_id', $platformIds);
            $activeDealsQuery->whereIn('platform_id', $platformIds);
            $expiringSoonQuery->whereIn('platform_id', $platformIds);
            $paymentsMtdQuery->whereIn('platform_id', $platformIds);
            $unmatchedPaymentsQuery->whereIn('platform_id', $platformIds);
        }

        return response()->json([
            'kpis' => [
                'active_clients' => $activeClientsQuery->count(),
                'total_clients' => $totalClientsQuery->count(),
                'pending_leads' => $pendingLeadsQuery->count(),
                'total_leads' => $totalLeadsQuery->count(),
                'active_deals' => $activeDealsQuery->count(),
                'expiring_soon' => $expiringSoonQuery->count(),
                'recent_payments' => (clone $paymentsMtdQuery)->count(),
                'revenue_mtd' => (float) (clone $paymentsMtdQuery)->sum('amount'),
                'unmatched_payments' => $unmatchedPaymentsQuery->count(),
            ],
            'expiring_deals' => $expiringDeals,
            'recent_payments' => $recentPayments,
            'upcoming_follow_ups' => $upcomingFollowUps,
        ]);
    }

    public function products()
    {
        return response()->json(Product::where('is_active', true)->get());
    }
}
