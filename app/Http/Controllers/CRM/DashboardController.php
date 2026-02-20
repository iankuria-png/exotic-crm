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
use Carbon\Carbon;

class DashboardController extends Controller
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
            'search' => 'nullable|string|max:120',
        ]);

        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfMonth();
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();
        $search = trim((string) ($validated['search'] ?? ''));
        $hasExplicitDateFilter = !empty($validated['from']) || !empty($validated['to']);

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this dashboard market.'
        );

        $platformIds = $selectedPlatformId
            ? [(int) $selectedPlatformId]
            : $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $expiringDealsQuery = Deal::expiringSoon(7)
            ->with(['client', 'product'])
            ->orderBy('expires_at');
        if (is_array($platformIds)) {
            $expiringDealsQuery->whereIn('platform_id', $platformIds);
        }
        if ($search !== '') {
            $expiringDealsQuery->whereHas('client', function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%");
            });
        }
        $expiringDeals = $expiringDealsQuery->limit(10)->get();

        $paymentReviewQueueQuery = Payment::where('status', 'completed')
            ->whereNull('client_id')
            ->with(['platform', 'product'])
            ->orderBy('created_at', 'desc');
        if (is_array($platformIds)) {
            $paymentReviewQueueQuery->whereIn('platform_id', $platformIds);
        }
        if ($search !== '') {
            $paymentReviewQueueQuery->where(function ($query) use ($search) {
                $query->where('phone', 'like', "%{$search}%")
                    ->orWhere('transaction_reference', 'like', "%{$search}%");
            });
        }
        if ($hasExplicitDateFilter) {
            $paymentReviewQueueQuery->whereBetween('created_at', [$from, $to]);
        }
        $paymentReviewQueue = $paymentReviewQueueQuery->limit(10)->get();

        $upcomingFollowUpsQuery = ClientNote::withPendingFollowUp()
            ->with(['client', 'author'])
            ->orderBy('follow_up_at');
        if (is_array($platformIds)) {
            $upcomingFollowUpsQuery->whereHas('client', function ($query) use ($platformIds) {
                $query->whereIn('platform_id', $platformIds);
            });
        }
        if ($hasExplicitDateFilter) {
            $upcomingFollowUpsQuery->whereBetween('follow_up_at', [$from, $to]);
        }
        if ($search !== '') {
            $upcomingFollowUpsQuery->where(function ($query) use ($search) {
                $query->where('content', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone_normalized', 'like', "%{$search}%");
                    });
            });
        }
        $upcomingFollowUps = $upcomingFollowUpsQuery->limit(10)->get();

        $activeClientsQuery = Client::active();
        $totalClientsQuery = Client::query();
        $pendingLeadsQuery = Lead::new();
        $totalLeadsQuery = Lead::query();
        $activeDealsQuery = Deal::active();
        $expiringSoonQuery = Deal::expiringSoon(7);
        $paymentsWindowQuery = Payment::where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        $unmatchedPaymentsQuery = Payment::whereNull('client_id')->where('status', 'completed')->whereBetween('created_at', [$from, $to]);

        if (is_array($platformIds)) {
            $activeClientsQuery->whereIn('platform_id', $platformIds);
            $totalClientsQuery->whereIn('platform_id', $platformIds);
            $pendingLeadsQuery->whereIn('platform_id', $platformIds);
            $totalLeadsQuery->whereIn('platform_id', $platformIds);
            $activeDealsQuery->whereIn('platform_id', $platformIds);
            $expiringSoonQuery->whereIn('platform_id', $platformIds);
            $paymentsWindowQuery->whereIn('platform_id', $platformIds);
            $unmatchedPaymentsQuery->whereIn('platform_id', $platformIds);
        }

        return response()->json([
            'filters' => [
                'platform_id' => $selectedPlatformId ? (int) $selectedPlatformId : null,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'search' => $search !== '' ? $search : null,
            ],
            'kpis' => [
                'active_clients' => $activeClientsQuery->count(),
                'total_clients' => $totalClientsQuery->count(),
                'pending_leads' => $pendingLeadsQuery->count(),
                'total_leads' => $totalLeadsQuery->count(),
                'active_deals' => $activeDealsQuery->count(),
                'expiring_soon' => $expiringSoonQuery->count(),
                'completed_payments_window' => (clone $paymentsWindowQuery)->count(),
                'completed_payments_mtd' => (clone $paymentsWindowQuery)->count(),
                'recent_payments' => (clone $paymentsWindowQuery)->count(),
                'revenue_mtd' => (float) (clone $paymentsWindowQuery)->sum('amount'),
                'unmatched_payments' => $unmatchedPaymentsQuery->count(),
            ],
            'expiring_deals' => $expiringDeals,
            'payment_review_queue' => $paymentReviewQueue,
            'recent_payments' => $paymentReviewQueue,
            'upcoming_follow_ups' => $upcomingFollowUps,
        ]);
    }

    public function products()
    {
        return response()->json(Product::where('is_active', true)->get());
    }
}
