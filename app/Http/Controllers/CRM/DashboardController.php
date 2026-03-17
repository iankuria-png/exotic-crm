<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ClientNote;
use App\Models\RenewalCampaign;
use App\Models\TimelineEvent;
use App\Services\MarketAuthorizationService;
use App\Services\RenewalService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly RenewalService $renewalService
    ) {
    }

    public function summary(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:120',
            'country_period' => 'nullable|in:week,month',
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

        $expiringDeals = $this->buildExpiringSubscriptionsWidget($platformIds, $search);

        $paymentReviewQueueQuery = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
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
        $paymentsWindowQuery = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
            ->excludingWalletTopups()
            ->whereBetween('created_at', [$from, $to]);
        $walletTopupsWindowQuery = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
            ->walletTopups()
            ->whereBetween('created_at', [$from, $to]);
        $unmatchedPaymentsWindowQuery = Payment::query()->liveOnly()->whereNull('client_id')->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        $awaitingPaymentsQuery = Payment::query()->liveOnly()->whereIn('status', ['initiated', 'pending']);
        $failedPaymentsQuery = Payment::query()->liveOnly()->where('status', 'failed');
        $unmatchedQueueQuery = Payment::query()->liveOnly()->whereNull('client_id')->where('status', 'completed');
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
        $previousRevenueQuery = Payment::query()
            ->liveOnly()
            ->where('status', 'completed')
            ->excludingWalletTopups()
            ->whereBetween('created_at', [$previousFrom, $previousTo]);
        if (is_array($platformIds)) {
            $previousRevenueQuery->whereIn('platform_id', $platformIds);
        }

        $completedPaymentsWindow = (clone $paymentsWindowQuery)->count();
        $revenueWindow = (float) (clone $paymentsWindowQuery)->sum('amount');
        $revenuePreviousWindow = (float) (clone $previousRevenueQuery)->sum('amount');
        $walletTopupsWindow = (clone $walletTopupsWindowQuery)->count();
        $walletTopupRevenueWindow = (float) (clone $walletTopupsWindowQuery)->sum('amount');
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

        $countryPeriod = $validated['country_period'] ?? 'week';
        $countryRevenue = $this->buildCountryRevenue($platformIds, $countryPeriod);

        $activeCampaignsQuery = RenewalCampaign::enabled();
        if (is_array($platformIds)) {
            $activeCampaignsQuery->whereHas('product', function ($query) use ($platformIds) {
                $query->whereIn('platform_id', $platformIds);
            });
        }
        $activeCampaignsCount = $activeCampaignsQuery->count();

        $recentActivityQuery = TimelineEvent::orderBy('created_at', 'desc');
        if (is_array($platformIds)) {
            $recentActivityQuery->whereIn('platform_id', $platformIds);
        }
        $recentActivity = $recentActivityQuery->limit(5)->get(['id', 'entity_type', 'event_type', 'created_at']);

        $upcomingFollowUpsCountQuery = ClientNote::withPendingFollowUp();
        if (is_array($platformIds)) {
            $upcomingFollowUpsCountQuery->whereHas('client', function ($query) use ($platformIds) {
                $query->whereIn('platform_id', $platformIds);
            });
        }
        $upcomingFollowUpsCount = $upcomingFollowUpsCountQuery->count();

        $commsStatsQuery = TimelineEvent::whereIn('event_type', ['sms_sent', 'sms_delivered', 'sms_failed', 'whatsapp_sent', 'whatsapp_delivered', 'whatsapp_failed'])
            ->where('created_at', '>=', now()->subDays(30));
        if (is_array($platformIds)) {
            $commsStatsQuery->whereIn('platform_id', $platformIds);
        }
        $commsEvents = $commsStatsQuery->get(['event_type']);
        $commsSentCount = $commsEvents->whereIn('event_type', ['sms_sent', 'whatsapp_sent'])->count();
        $commsDeliveredCount = $commsEvents->whereIn('event_type', ['sms_delivered', 'whatsapp_delivered'])->count();
        $commsFailedCount = $commsEvents->whereIn('event_type', ['sms_failed', 'whatsapp_failed'])->count();

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
                'wallet_topups_window' => $walletTopupsWindow,
                'wallet_topup_revenue_window' => $walletTopupRevenueWindow,
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
                'upcoming_follow_ups_count' => $upcomingFollowUpsCount,
            ],
            'expiring_deals' => $expiringDeals,
            'payment_review_queue' => $paymentReviewQueue,
            'recent_payments' => $paymentReviewQueue,
            'upcoming_follow_ups' => $upcomingFollowUps,
            'country_revenue' => $countryRevenue,
            'active_campaigns_count' => $activeCampaignsCount,
            'recent_activity' => $recentActivity,
            'comms_stats' => [
                'sent_count' => $commsSentCount,
                'delivered_count' => $commsDeliveredCount,
                'failed_count' => $commsFailedCount,
            ],
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

        $products = Product::query()
            ->where('platform_id', $requestedPlatformId)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->with(['activePrices'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($products);
    }

    private function buildCountryRevenue(?array $platformIds, string $period): array
    {
        $now = now();
        if ($period === 'month') {
            $currentFrom = $now->copy()->startOfMonth();
            $currentTo = $now->copy()->endOfDay();
            $previousFrom = $now->copy()->subMonth()->startOfMonth();
            $previousTo = $now->copy()->subMonth()->endOfMonth();
        } else {
            $currentFrom = $now->copy()->startOfWeek();
            $currentTo = $now->copy()->endOfDay();
            $previousFrom = $now->copy()->subWeek()->startOfWeek();
            $previousTo = $now->copy()->subWeek()->endOfWeek();
        }

        $platforms = Platform::where('is_active', true);
        if (is_array($platformIds)) {
            $platforms->whereIn('id', $platformIds);
        }
        $platforms = $platforms->get();

        $result = [];
        foreach ($platforms as $platform) {
            $currentRevenue = (float) Payment::where('platform_id', $platform->id)
                ->liveOnly()
                ->where('status', 'completed')
                ->excludingWalletTopups()
                ->whereBetween('created_at', [$currentFrom, $currentTo])
                ->sum('amount');

            $previousRevenue = (float) Payment::where('platform_id', $platform->id)
                ->liveOnly()
                ->where('status', 'completed')
                ->excludingWalletTopups()
                ->whereBetween('created_at', [$previousFrom, $previousTo])
                ->sum('amount');

            $trend = $previousRevenue > 0
                ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
                : ($currentRevenue > 0 ? 100.0 : null);

            $result[] = [
                'platform_id' => $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
                'currency' => $platform->currency_code,
                'current_revenue' => $currentRevenue,
                'previous_revenue' => $previousRevenue,
                'trend' => $trend,
            ];
        }

        usort($result, fn($a, $b) => $b['current_revenue'] <=> $a['current_revenue']);

        return $result;
    }

    /**
     * Build the expiring subscriptions list for the dashboard widget.
     * Uses the same COALESCE expiry logic as the Subscriptions page to include
     * both modern CRM deals and legacy WP-based subscriptions.
     */
    private function buildExpiringSubscriptionsWidget(?array $platformIds, string $search = ''): array
    {
        $nowTs = now()->timestamp;
        $driver = DB::connection()->getDriverName();
        $dealExpiryExpr = $driver === 'sqlite'
            ? "CAST(strftime('%s', deals.expires_at) AS INTEGER)"
            : 'UNIX_TIMESTAMP(deals.expires_at)';
        $expiryExpr = "COALESCE({$dealExpiryExpr}, clients.escort_expire, clients.premium_expire, clients.featured_expire)";

        $query = Client::query()
            ->leftJoin('deals', function ($join) {
                $join->on('clients.id', '=', 'deals.client_id')
                    ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
            })
            ->leftJoin('products as deal_products', 'deal_products.id', '=', 'deals.product_id')
            ->select(
                'clients.id as client_id',
                'clients.name as client_name',
                'clients.platform_id',
                'clients.premium',
                'clients.premium_expire',
                'clients.featured',
                'clients.featured_expire',
                'deals.id as deal_id',
                'deals.plan_type',
                'deal_products.name as product_name',
                DB::raw("{$expiryExpr} as expiry_ts")
            )
            ->where(function ($q) use ($expiryExpr, $nowTs) {
                $q->where('deals.status', 'active')
                    ->orWhere(function ($legacy) use ($expiryExpr, $nowTs) {
                        $legacy->whereNull('deals.id')
                            ->whereRaw("{$expiryExpr} >= ?", [$nowTs]);
                    });
            })
            ->whereBetween(DB::raw($expiryExpr), [$nowTs, $nowTs + (14 * 86400)])
            ->orderByRaw("{$expiryExpr} ASC");

        if (is_array($platformIds)) {
            $query->whereIn('clients.platform_id', $platformIds);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('clients.name', 'like', "%{$search}%")
                    ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
            });
        }

        return $query->limit(10)->get()->map(function ($row) {
            $expiresAt = $row->expiry_ts ? Carbon::createFromTimestamp((int) $row->expiry_ts) : null;
            $planType = $row->plan_type;

            if (!$row->deal_id) {
                if ($row->getAttribute('featured') || $row->getAttribute('featured_expire')) {
                    $planType = 'vip';
                } elseif ($row->getAttribute('premium') || $row->getAttribute('premium_expire')) {
                    $planType = 'premium';
                } else {
                    $planType = 'basic';
                }
            }

            return [
                'id' => $row->deal_id ?: ('virtual_' . $row->client_id),
                'client_id' => $row->client_id,
                'client' => [
                    'id' => $row->client_id,
                    'name' => $row->client_name,
                ],
                'product' => $row->product_name ? ['name' => $row->product_name] : null,
                'plan_type' => $planType,
                'expires_at' => $expiresAt ? $expiresAt->toDateTimeString() : null,
                'is_virtual' => !$row->deal_id,
            ];
        })->all();
    }

    private function resolveOldestDashboardRecordAt(?array $platformIds): ?Carbon
    {
        $oldestCandidates = [];

        $clientsQuery = Client::query();
        $leadsQuery = Lead::query();
        $dealsQuery = Deal::query();
        $paymentsQuery = Payment::query()->liveOnly();
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
