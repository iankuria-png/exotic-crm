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
use Illuminate\Database\Eloquent\Builder;

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

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this dashboard market.'
        );

        $platformIds = $selectedPlatformId
            ? [(int) $selectedPlatformId]
            : $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $search = trim((string) ($validated['search'] ?? ''));
        $oldestRecordAt = $this->resolveOldestDashboardRecordAt($platformIds);
        $defaultFrom = ($oldestRecordAt ? (clone $oldestRecordAt) : now()->startOfMonth())->startOfDay();
        $defaultTo = now()->endOfDay();

        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : (clone $defaultFrom);
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : (clone $defaultTo);
        $hasExplicitDateFilter = !empty($validated['from']) || !empty($validated['to']);
        $requestedFromDate = !empty($validated['from']) ? Carbon::parse($validated['from'])->toDateString() : null;
        $requestedToDate = !empty($validated['to']) ? Carbon::parse($validated['to'])->toDateString() : null;
        $isDefaultDateWindow = (empty($validated['from']) && empty($validated['to']))
            || (
                $requestedFromDate !== null
                && $requestedToDate !== null
                && $requestedFromDate === $defaultFrom->toDateString()
                && $requestedToDate === $defaultTo->toDateString()
            );

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
        $unmatchedPaymentsWindowQuery = Payment::whereNull('client_id')->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        $awaitingPaymentsQuery = Payment::whereIn('status', ['initiated', 'pending']);
        $failedPaymentsQuery = Payment::where('status', 'failed');
        $unmatchedQueueQuery = Payment::whereNull('client_id')->where('status', 'completed');
        $renewalRisk72hQuery = Deal::active()
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->copy()->addDays(3));
        $renewalPipeline14dQuery = Deal::active()
            ->where('expires_at', '>', now()->copy()->addDays(3))
            ->where('expires_at', '<=', now()->copy()->addDays(14));

        if (is_array($platformIds)) {
            $activeClientsQuery->whereIn('platform_id', $platformIds);
            $totalClientsQuery->whereIn('platform_id', $platformIds);
            $pendingLeadsQuery->whereIn('platform_id', $platformIds);
            $totalLeadsQuery->whereIn('platform_id', $platformIds);
            $activeDealsQuery->whereIn('platform_id', $platformIds);
            $expiringSoonQuery->whereIn('platform_id', $platformIds);
            $paymentsWindowQuery->whereIn('platform_id', $platformIds);
            $unmatchedPaymentsWindowQuery->whereIn('platform_id', $platformIds);
            $awaitingPaymentsQuery->whereIn('platform_id', $platformIds);
            $failedPaymentsQuery->whereIn('platform_id', $platformIds);
            $unmatchedQueueQuery->whereIn('platform_id', $platformIds);
            $renewalRisk72hQuery->whereIn('platform_id', $platformIds);
            $renewalPipeline14dQuery->whereIn('platform_id', $platformIds);
        }

        $windowSeconds = max(1, $to->diffInSeconds($from) + 1);
        $previousFrom = (clone $from)->subSeconds($windowSeconds);
        $previousTo = (clone $from)->subSecond();
        $previousRevenueQuery = Payment::where('status', 'completed')->whereBetween('created_at', [$previousFrom, $previousTo]);
        if (is_array($platformIds)) {
            $previousRevenueQuery->whereIn('platform_id', $platformIds);
        }

        $completedPaymentsWindow = (clone $paymentsWindowQuery)->count();
        $revenueWindow = (float) (clone $paymentsWindowQuery)->sum('amount');
        $revenuePreviousWindow = (float) (clone $previousRevenueQuery)->sum('amount');
        $averageTicket = $completedPaymentsWindow > 0 ? round($revenueWindow / $completedPaymentsWindow, 2) : 0.0;
        $revenueDeltaPercent = $revenuePreviousWindow > 0
            ? round((($revenueWindow - $revenuePreviousWindow) / $revenuePreviousWindow) * 100, 1)
            : null;

        $paymentRecoveryPending = (clone $awaitingPaymentsQuery)->count();
        $paymentRecoveryFailed = (clone $failedPaymentsQuery)->count();
        $paymentRecoveryUnmatched = (clone $unmatchedQueueQuery)->count();
        $unmatchedPaymentsWindow = (clone $unmatchedPaymentsWindowQuery)->count();
        $paymentRecoveryTotal = $paymentRecoveryPending + $paymentRecoveryFailed + $paymentRecoveryUnmatched;

        $renewalRisk72h = (clone $renewalRisk72hQuery)->count();
        $renewalPipeline14d = (clone $renewalPipeline14dQuery)->count();
        $renewalWorkload14d = $renewalRisk72h + $renewalPipeline14d;

        return response()->json([
            'filters' => [
                'platform_id' => $selectedPlatformId ? (int) $selectedPlatformId : null,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'search' => $search !== '' ? $search : null,
            ],
            'window' => [
                'default_from' => $defaultFrom->toDateString(),
                'default_to' => $defaultTo->toDateString(),
                'applied_from' => $from->toDateString(),
                'applied_to' => $to->toDateString(),
                'is_default' => $isDefaultDateWindow,
                'label' => $isDefaultDateWindow ? 'All-time default (oldest record to today)' : 'Custom range',
            ],
            'kpis' => [
                'active_clients' => $activeClientsQuery->count(),
                'total_clients' => $totalClientsQuery->count(),
                'pending_leads' => $pendingLeadsQuery->count(),
                'total_leads' => $totalLeadsQuery->count(),
                'active_deals' => $activeDealsQuery->count(),
                'expiring_soon' => $expiringSoonQuery->count(),
                'completed_payments_window' => $completedPaymentsWindow,
                'completed_payments_mtd' => $completedPaymentsWindow,
                'recent_payments' => $completedPaymentsWindow,
                'revenue_window' => $revenueWindow,
                'revenue_mtd' => $revenueWindow,
                'revenue_previous_window' => $revenuePreviousWindow,
                'revenue_delta_percent' => $revenueDeltaPercent,
                'average_ticket_window' => $averageTicket,
                'payment_recovery_queue_total' => $paymentRecoveryTotal,
                'payment_recovery_pending' => $paymentRecoveryPending,
                'payment_recovery_failed' => $paymentRecoveryFailed,
                'payment_recovery_unmatched' => $paymentRecoveryUnmatched,
                'unmatched_payments_window' => $unmatchedPaymentsWindow,
                'unmatched_payments' => $unmatchedPaymentsWindow,
                'renewal_risk_72h' => $renewalRisk72h,
                'renewal_pipeline_4_14d' => $renewalPipeline14d,
                'renewal_workload_14d' => $renewalWorkload14d,
            ],
            'expiring_deals' => $expiringDeals,
            'payment_review_queue' => $paymentReviewQueue,
            'recent_payments' => $paymentReviewQueue,
            'upcoming_follow_ups' => $upcomingFollowUps,
        ]);
    }

    public function products()
    {
        $validated = request()->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
        ]);

        $requestedPlatformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            request()->user(),
            $requestedPlatformId,
            'You do not have access to this market products catalog.'
        );

        return response()->json(
            Product::query()
                ->where('platform_id', $requestedPlatformId)
                ->where('is_active', true)
                ->orderByRaw('FIELD(UPPER(name), "BASIC", "PREMIUM", "VIP")')
                ->orderBy('name')
                ->get()
        );
    }

    private function resolveOldestDashboardRecordAt(?array $platformIds): ?Carbon
    {
        $oldestCandidates = [];

        $clientsQuery = Client::query();
        $leadsQuery = Lead::query();
        $dealsQuery = Deal::query();
        $paymentsQuery = Payment::query();
        $notesQuery = ClientNote::query();

        if (is_array($platformIds)) {
            $clientsQuery->whereIn('platform_id', $platformIds);
            $leadsQuery->whereIn('platform_id', $platformIds);
            $dealsQuery->whereIn('platform_id', $platformIds);
            $paymentsQuery->whereIn('platform_id', $platformIds);
            $notesQuery->whereHas('client', function (Builder $query) use ($platformIds) {
                $query->whereIn('platform_id', $platformIds);
            });
        }

        foreach ([
            $clientsQuery->min('created_at'),
            $leadsQuery->min('created_at'),
            $dealsQuery->min('created_at'),
            $paymentsQuery->min('created_at'),
            $notesQuery->min('created_at'),
        ] as $candidate) {
            if (!$candidate) {
                continue;
            }

            try {
                $oldestCandidates[] = Carbon::parse((string) $candidate);
            } catch (\Throwable $exception) {
                continue;
            }
        }

        if (empty($oldestCandidates)) {
            return null;
        }

        usort($oldestCandidates, function (Carbon $left, Carbon $right): int {
            if ($left->equalTo($right)) {
                return 0;
            }

            return $left->lt($right) ? -1 : 1;
        });
        return $oldestCandidates[0];
    }
}
