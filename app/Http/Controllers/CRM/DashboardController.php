<?php

namespace App\Http\Controllers\CRM;

use App\Helpers\CurrencyBreakdown;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\AgentGoal;
use App\Models\Lead;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ClientNote;
use App\Models\RenewalCampaign;
use App\Models\TimelineEvent;
use App\Services\ClientRetentionInsightService;
use App\Services\ClientSyncRunService;
use App\Services\MarketAuthorizationService;
use App\Services\RenewalService;
use App\Services\ReportingCurrencyService;
use App\Services\SupportBoardService;
use App\Services\WpSyncService;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Throwable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    private const SALES_NEW_USER_SOURCE_GROUPS = [
        'crm_created' => ['crm_manual', 'crm_provisioned'],
        'wp_organic' => ['fast_signup', 'full_registration'],
    ];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly RenewalService $renewalService,
        private readonly ClientRetentionInsightService $clientRetentionInsightService,
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly ClientSyncRunService $clientSyncRunService
    ) {
    }

    public function summary(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:120',
            'country_period' => 'nullable|in:week,month,custom',
            'sales_view' => 'nullable|boolean',
            'currency_mode' => 'nullable|in:native,flat',
            'reporting_currency' => 'nullable|string|min:3|max:8',
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
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($validated['reporting_currency'] ?? null);
        $currencyMode = $this->reportingCurrencyService->resolveMode(
            $validated['currency_mode'] ?? null,
            $selectedPlatformId === null
        );
        $shouldNormalizeRevenue = $currencyMode === ReportingCurrencyService::MODE_FLAT;
        $dashboardTimingStartedAt = hrtime(true);
        $dashboardTimings = [];

        try {
            $oldestRecordAt = $this->resolveOldestDashboardRecordAt($platformIds);
            $this->recordDashboardTimingCheckpoint(
                'resolve_oldest_dashboard_record_at',
                $dashboardTimingStartedAt,
                $dashboardTimings,
                $selectedPlatformId,
                $platformIds,
                $validated,
                $currencyMode,
                $targetCurrency
            );

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

            $reviewBaselineCutoff = $this->resolveBaselineCutoff();
            $salesView = $request->boolean('sales_view') || $request->user()?->role === MarketAuthorizationService::ROLE_SALES;
            $paymentReviewQueueQuery = Payment::query()
                ->reportableSuccessful()
                ->whereNull('client_id')
                ->with(['platform', 'product'])
                ->orderBy('created_at', 'desc');
            if ($reviewBaselineCutoff) {
                $paymentReviewQueueQuery->where('created_at', '>=', $reviewBaselineCutoff);
            }
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
                ->reportableSuccessful()
                ->excludingWalletTopups()
                ->whereBetween('created_at', [$from, $to]);
            $walletTopupsWindowQuery = Payment::query()
                ->reportableSuccessful()
                ->walletTopups()
                ->whereBetween('created_at', [$from, $to]);
            $unmatchedPaymentsWindowQuery = Payment::query()
                ->reportableSuccessful()
                ->whereNull('client_id')
                ->whereBetween('created_at', [$from, $to]);
            $baselineCutoff = $this->resolveBaselineCutoff();
            $awaitingPaymentsQuery = Payment::query()->businessVisible()->whereIn('status', ['initiated', 'pending']);
            $failedPaymentsQuery = Payment::query()->businessVisible()->where('status', 'failed');
            $unmatchedQueueQuery = Payment::query()
                ->reportableSuccessful()
                ->whereNull('client_id')
                ->whereIn('status', Payment::SUCCESSFUL_STATUSES);
            if ($baselineCutoff) {
                $awaitingPaymentsQuery->where('created_at', '>=', $baselineCutoff);
                $failedPaymentsQuery->where('created_at', '>=', $baselineCutoff);
                $unmatchedQueueQuery->where('created_at', '>=', $baselineCutoff);
            }
            if (is_array($platformIds)) {
                $activeClientsQuery->whereIn('platform_id', $platformIds);
                $totalClientsQuery->whereIn('platform_id', $platformIds);
                $pendingLeadsQuery->whereIn('platform_id', $platformIds);
                $totalLeadsQuery->whereIn('platform_id', $platformIds);
                $activeDealsQuery->whereIn('platform_id', $platformIds);
                $expiringSoonQuery->whereIn('platform_id', $platformIds);
                $paymentsWindowQuery->whereIn('platform_id', $platformIds);
                $walletTopupsWindowQuery->whereIn('platform_id', $platformIds);
                $unmatchedPaymentsWindowQuery->whereIn('platform_id', $platformIds);
                $awaitingPaymentsQuery->whereIn('platform_id', $platformIds);
                $failedPaymentsQuery->whereIn('platform_id', $platformIds);
                $unmatchedQueueQuery->whereIn('platform_id', $platformIds);
            }

            $windowSeconds = max(1, $to->diffInSeconds($from) + 1);
            $previousFrom = (clone $from)->subSeconds($windowSeconds);
            $previousTo = (clone $from)->subSecond();
            $previousRevenueQuery = Payment::query()
                ->reportableSuccessful()
                ->excludingWalletTopups()
                ->whereBetween('created_at', [$previousFrom, $previousTo]);
            if (is_array($platformIds)) {
                $previousRevenueQuery->whereIn('platform_id', $platformIds);
            }

            $completedPaymentsWindow = (clone $paymentsWindowQuery)->count();

            // Per-currency breakdowns for all revenue KPIs. scalar_amount is null when
            // multiple currencies are present so the frontend cannot display a wrong total.
            $revenueWindowBreakdown    = CurrencyBreakdown::fromPaymentQuery(clone $paymentsWindowQuery);
            $revenuePreviousBreakdown  = CurrencyBreakdown::fromPaymentQuery(clone $previousRevenueQuery);
            $walletTopupBreakdown      = CurrencyBreakdown::fromPaymentQuery(clone $walletTopupsWindowQuery);
            $revenueWindowNormalized   = $this->normalizePaymentQueryForMode(clone $paymentsWindowQuery, $targetCurrency, $shouldNormalizeRevenue);
            $revenuePreviousNormalized = $this->normalizePaymentQueryForMode(clone $previousRevenueQuery, $targetCurrency, $shouldNormalizeRevenue);
            $walletTopupNormalized     = $this->normalizePaymentQueryForMode(clone $walletTopupsWindowQuery, $targetCurrency, $shouldNormalizeRevenue);
            $this->recordDashboardTimingCheckpoint(
                'normalize_revenue_windows',
                $dashboardTimingStartedAt,
                $dashboardTimings,
                $selectedPlatformId,
                $platformIds,
                $validated,
                $currencyMode,
                $targetCurrency,
                $from,
                $to
            );

            $revenueWindow         = $revenueWindowBreakdown['scalar_amount'];
            $revenuePreviousWindow = $revenuePreviousBreakdown['scalar_amount'];
            $walletTopupRevenueWindow = $walletTopupBreakdown['scalar_amount'];

            $isMixed = $revenueWindowBreakdown['currency_count'] > 1;

            $walletTopupsWindow = (clone $walletTopupsWindowQuery)->count();

            // Average ticket and delta only make sense for a single-currency scope.
            $averageTicket = (!$isMixed && $completedPaymentsWindow > 0 && $revenueWindow !== null)
                ? round($revenueWindow / $completedPaymentsWindow, 2)
                : null;

            $prevRevScalar = $revenuePreviousBreakdown['scalar_amount'];
            $revenueDeltaPercent = (!$isMixed && $revenuePreviousBreakdown['currency_count'] <= 1 && $prevRevScalar !== null && $prevRevScalar > 0 && $revenueWindow !== null)
                ? round((($revenueWindow - $prevRevScalar) / $prevRevScalar) * 100, 1)
                : null;

            $paymentRecoveryPending = (clone $awaitingPaymentsQuery)->count();
            $paymentRecoveryFailed = (clone $failedPaymentsQuery)->count();
            $paymentRecoveryUnmatched = (clone $unmatchedQueueQuery)->count();
            $unmatchedPaymentsWindow = (clone $unmatchedPaymentsWindowQuery)->count();
            $paymentRecoveryTotal = $paymentRecoveryPending + $paymentRecoveryFailed + $paymentRecoveryUnmatched;

            $renewalSummary = $this->renewalService->buildSummary([
                'platform_ids' => is_array($platformIds) ? $platformIds : null,
                'platform_id' => !is_array($platformIds) && $selectedPlatformId ? (int) $selectedPlatformId : null,
                'search' => $search,
                'include_untracked' => true,
            ]);
            $this->recordDashboardTimingCheckpoint(
                'renewal_summary',
                $dashboardTimingStartedAt,
                $dashboardTimings,
                $selectedPlatformId,
                $platformIds,
                $validated,
                $currencyMode,
                $targetCurrency,
                $from,
                $to
            );

            $renewalRisk72h = (int) ($renewalSummary['risk'] ?? 0);
            $renewalPipeline14d = (int) ($renewalSummary['pending'] ?? 0);
            $renewalWorkload14d = $renewalRisk72h + $renewalPipeline14d;

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
            $retentionSummary = $this->clientRetentionInsightService->buildDashboardSummary(
                is_array($platformIds) ? $platformIds : null
            );
            $this->recordDashboardTimingCheckpoint(
                'retention_summary',
                $dashboardTimingStartedAt,
                $dashboardTimings,
                $selectedPlatformId,
                $platformIds,
                $validated,
                $currencyMode,
                $targetCurrency,
                $from,
                $to
            );

            $newUsers = $salesView ? $this->buildNewUsersKpi(is_array($platformIds) ? $platformIds : null) : null;
            $topPackages = $salesView ? $this->buildTopPackages(is_array($platformIds) ? $platformIds : null, $from, $to) : [];
            $missedChatsCount = $salesView ? $this->resolveMissedChatsCount(is_array($platformIds) ? $platformIds : null) : null;

            $response = response()->json([
            'filters' => [
                'platform_id' => $selectedPlatformId ? (int) $selectedPlatformId : null,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'search' => $search !== '' ? $search : null,
                'country_period' => $validated['country_period'] ?? 'week',
                'currency_mode' => $currencyMode,
                'reporting_currency' => $targetCurrency,
            ],
            'window' => [
                'default_from' => $defaultFrom->toDateString(),
                'default_to' => $defaultTo->toDateString(),
                'all_time_from' => $defaultFrom->toDateString(),
                'all_time_to' => $defaultTo->toDateString(),
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
                'revenue_window_breakdown' => $revenueWindowBreakdown['breakdown'],
                'revenue_window_normalized' => $revenueWindowNormalized['normalized_total'],
                'revenue_window_normalized_display' => $revenueWindowNormalized['normalized_display'],
                'revenue_window_normalization_meta' => $revenueWindowNormalized['normalization_meta'],
                'revenue_mtd' => $revenueWindow,
                'revenue_mtd_breakdown' => $revenueWindowBreakdown['breakdown'],
                'revenue_mtd_normalized' => $revenueWindowNormalized['normalized_total'],
                'revenue_mtd_normalization_meta' => $revenueWindowNormalized['normalization_meta'],
                'wallet_topups_window' => $walletTopupsWindow,
                'wallet_topup_revenue_window' => $walletTopupRevenueWindow,
                'wallet_topup_revenue_window_breakdown' => $walletTopupBreakdown['breakdown'],
                'wallet_topup_revenue_window_normalized' => $walletTopupNormalized['normalized_total'],
                'wallet_topup_revenue_window_normalization_meta' => $walletTopupNormalized['normalization_meta'],
                'revenue_previous_window' => $revenuePreviousWindow,
                'revenue_previous_window_breakdown' => $revenuePreviousBreakdown['breakdown'],
                'revenue_previous_window_normalized' => $revenuePreviousNormalized['normalized_total'],
                'revenue_previous_window_normalization_meta' => $revenuePreviousNormalized['normalization_meta'],
                'revenue_delta_percent' => $revenueDeltaPercent,
                'average_ticket_window' => $averageTicket,
                'revenue_is_mixed' => $isMixed,
                'normalized_currency' => $targetCurrency,
                'currency_mode' => $currencyMode,
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
                'new_users' => $newUsers,
                'missed_chats_count' => $missedChatsCount,
            ],
            'expiring_deals' => $expiringDeals,
            'payment_review_queue' => $paymentReviewQueue,
            'recent_payments' => $paymentReviewQueue,
            'upcoming_follow_ups' => $upcomingFollowUps,
            'country_revenue' => [],
            'top_packages' => $topPackages,
            'active_campaigns_count' => $activeCampaignsCount,
            'recent_activity' => $recentActivity,
            'retention_summary' => $retentionSummary,
            'comms_stats' => [
                'sent_count' => $commsSentCount,
                'delivered_count' => $commsDeliveredCount,
                'failed_count' => $commsFailedCount,
            ],
            ]);

            $this->recordDashboardTimingCheckpoint(
                'completed',
                $dashboardTimingStartedAt,
                $dashboardTimings,
                $selectedPlatformId,
                $platformIds,
                $validated,
                $currencyMode,
                $targetCurrency,
                $from,
                $to
            );

            return $response;
        } catch (Throwable $exception) {
            $this->recordDashboardTimingFailure(
                $dashboardTimingStartedAt,
                $dashboardTimings,
                $selectedPlatformId,
                $platformIds,
                $validated,
                $currencyMode,
                $targetCurrency,
                $exception
            );

            throw $exception;
        }
    }

    public function myMarkets(Request $request)
    {
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $query = Platform::query()
            ->orderBy('name');

        if (is_array($allowedPlatformIds)) {
            if (empty($allowedPlatformIds)) {
                return response()->json([]);
            }

            $query->whereIn('id', $allowedPlatformIds);
        }

        $platforms = $query->get();
        $clientSyncRuns = $this->clientSyncRunService->latestRunsForPlatforms(
            $platforms->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $clientCounts = Client::query()
            ->selectRaw('platform_id, COUNT(*) as total')
            ->whereIn('platform_id', $platforms->pluck('id')->all())
            ->groupBy('platform_id')
            ->pluck('total', 'platform_id');

        $markets = $platforms->map(function (Platform $platform) use ($clientCounts, $clientSyncRuns) {
            $lastResult = is_array($platform->sync_last_result) ? $platform->sync_last_result : [];
            $lastDelta = is_array(data_get($lastResult, 'clients')) ? data_get($lastResult, 'clients') : [];
            $profilesTotal = $this->extractSyncedProfilesTotal($lastResult);
            $lastSyncedAt = optional($platform->sync_last_synced_at)->toDateTimeString();
            $needsSync = $platform->sync_last_status === 'error'
                || !$platform->sync_last_synced_at
                || $platform->sync_last_synced_at->lt(now()->subHours(12));
            $latestRun = $clientSyncRuns->get((int) $platform->id);

            return [
                'id' => (int) $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
                'country_code' => $this->countryCodeForCountry($platform->country),
                'currency' => $platform->currency_code,
                'is_active' => (bool) $platform->is_active,
                'sync_last_synced_at' => $lastSyncedAt,
                'sync_last_status' => $platform->sync_last_status,
                'sync_last_error' => $platform->sync_last_error,
                'sync_last_result' => $lastResult,
                'profiles_total' => (int) ($clientCounts[(int) $platform->id] ?? $profilesTotal ?? 0),
                'last_delta' => [
                    'created' => (int) ($lastDelta['created'] ?? 0),
                    'updated' => (int) ($lastDelta['updated'] ?? 0),
                    'skipped' => (int) ($lastDelta['skipped'] ?? 0),
                    'total' => (int) ($lastDelta['total'] ?? 0),
                ],
                'client_sync' => [
                    'latest_run' => $this->clientSyncRunService->serializeRun($latestRun),
                    'protocol' => $platform->client_sync_protocol,
                    'capability_status' => $platform->client_sync_capability_status,
                    'legacy_correctness_risk' => ($platform->client_sync_protocol ?? null) === 'v1'
                        || ($platform->client_sync_capability_status ?? null) === 'legacy_not_found',
                ],
                'needs_sync' => $needsSync,
            ];
        })->values();

        return response()->json($markets);
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

    public function countryRevenue(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'country_period' => 'nullable|in:week,month',
            'currency_mode' => 'nullable|in:native,flat',
            'reporting_currency' => 'nullable|string|min:3|max:8',
        ]);

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this dashboard market.'
        );

        $platformIds = $selectedPlatformId
            ? [(int) $selectedPlatformId]
            : $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($validated['reporting_currency'] ?? null);
        $currencyMode = $this->reportingCurrencyService->resolveMode(
            $validated['currency_mode'] ?? null,
            $selectedPlatformId === null
        );
        $shouldNormalizeRevenue = $currencyMode === ReportingCurrencyService::MODE_FLAT;

        $oldestRecordAt = $this->resolveOldestDashboardRecordAt($platformIds);
        $defaultFrom = ($oldestRecordAt ? (clone $oldestRecordAt) : now()->startOfMonth())->startOfDay();
        $defaultTo = now()->endOfDay();

        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : (clone $defaultFrom);
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : (clone $defaultTo);

        return response()->json(
            $this->buildCountryRevenue(
                $platformIds,
                $from,
                $to,
                $targetCurrency,
                $shouldNormalizeRevenue,
                (string) ($validated['country_period'] ?? 'month')
            )
        );
    }

    public function countryPerformance(Request $request, Platform $platform)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'currency_mode' => 'nullable|in:native,flat',
            'reporting_currency' => 'nullable|string|min:3|max:8',
        ]);

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this dashboard market.'
        );

        $platformIds = [(int) $platform->id];
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($validated['reporting_currency'] ?? null);
        $currencyMode = $this->reportingCurrencyService->resolveMode(
            $validated['currency_mode'] ?? null,
            false
        );
        $shouldNormalizeRevenue = $currencyMode === ReportingCurrencyService::MODE_FLAT;

        $oldestRecordAt = $this->resolveOldestDashboardRecordAt($platformIds);
        $defaultFrom = ($oldestRecordAt ? (clone $oldestRecordAt) : now()->startOfMonth())->startOfDay();
        $defaultTo = now()->endOfDay();

        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : (clone $defaultFrom);
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : (clone $defaultTo);

        [$previousFrom, $previousTo] = $this->resolvePreviousMatchingWindow($from, $to);

        $currentRevenueRows = $this->aggregateCountryRevenueRows($platformIds, $from, $to)->values();
        $previousRevenueRows = $this->aggregateCountryRevenueRows($platformIds, $previousFrom, $previousTo)->values();

        $currentRevenueBreakdown = $this->buildCurrencyBreakdownFromRows($currentRevenueRows);
        $previousRevenueBreakdown = $this->buildCurrencyBreakdownFromRows($previousRevenueRows);
        $currentRevenueNormalized = $this->normalizeCountryRevenueRowsForMode($currentRevenueRows, $targetCurrency, $shouldNormalizeRevenue);
        $previousRevenueNormalized = $this->normalizeCountryRevenueRowsForMode($previousRevenueRows, $targetCurrency, $shouldNormalizeRevenue);
        $trendPoints = $this->buildCountryPerformanceTrend(
            $currentRevenueRows,
            $from,
            $to,
            $targetCurrency,
            $shouldNormalizeRevenue
        );
        $insights = $this->buildCountryPerformanceInsights($trendPoints);
        $engagement = $this->fetchCountryEngagementSummary($platform, $from, $to);

        return response()->json([
            'market' => [
                'platform_id' => (int) $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
                'currency' => $platform->currency_code,
            ],
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $previousFrom->toDateString(),
                'previous_to' => $previousTo->toDateString(),
            ],
            'summary' => [
                'current_revenue_breakdown' => $currentRevenueBreakdown['breakdown'],
                'current_revenue' => $currentRevenueBreakdown['scalar_amount'],
                'current_revenue_normalized' => $currentRevenueNormalized['normalized_total'],
                'current_revenue_normalized_display' => $currentRevenueNormalized['normalized_display'],
                'current_revenue_normalization_meta' => $currentRevenueNormalized['normalization_meta'],
                'previous_revenue_breakdown' => $previousRevenueBreakdown['breakdown'],
                'previous_revenue' => $previousRevenueBreakdown['scalar_amount'],
                'previous_revenue_normalized' => $previousRevenueNormalized['normalized_total'],
                'previous_revenue_normalization_meta' => $previousRevenueNormalized['normalization_meta'],
                'trend' => $this->calculateComparableCountryTrend($currentRevenueBreakdown, $previousRevenueBreakdown),
                'normalized_trend' => $this->calculateNormalizedCountryTrend(
                    $currentRevenueNormalized,
                    $previousRevenueNormalized,
                    $shouldNormalizeRevenue
                ),
                'payments_count' => (int) collect($currentRevenueRows)->sum(fn ($row) => (int) ($row->payments_count ?? 0)),
                'previous_payments_count' => (int) collect($previousRevenueRows)->sum(fn ($row) => (int) ($row->payments_count ?? 0)),
                'last_payment_at' => collect($currentRevenueRows)->pluck('event_date')->filter()->max(),
            ],
            'trend' => [
                'bucket' => $this->resolveTrendBucketType($from, $to),
                'points' => $trendPoints,
            ],
            'insights' => $insights,
            'user_summary' => [
                'active_users' => Client::query()
                    ->active()
                    ->where('platform_id', $platform->id)
                    ->count(),
                'engagement' => $engagement['engagement'],
            ],
            'contact_mix' => $engagement['contact_mix'],
            'availability' => $engagement['availability'],
        ]);
    }

    private function buildCountryRevenue(?array $platformIds, Carbon $rangeFrom, Carbon $rangeTo, string $targetCurrency, bool $shouldNormalizeRevenue, string $goalPeriod = 'month'): array
    {
        [$previousFrom, $previousTo] = $this->resolvePreviousMatchingWindow($rangeFrom, $rangeTo);
        $revenueTargets = $this->marketRevenueTargets($platformIds, $goalPeriod === 'week' ? 'weekly' : 'monthly', $targetCurrency);

        $platforms = Platform::query();
        if (is_array($platformIds)) {
            $platforms->whereIn('id', $platformIds);
        }
        $platforms = $platforms
            ->orderBy('country')
            ->orderBy('name')
            ->get();

        $currentRevenueRows = $this->aggregateCountryRevenueRows($platformIds, $rangeFrom, $rangeTo);
        $previousRevenueRows = $this->aggregateCountryRevenueRows($platformIds, $previousFrom, $previousTo);
        $currentRowsByPlatform = $currentRevenueRows->groupBy(fn ($row) => (int) $row->platform_id);
        $previousRowsByPlatform = $previousRevenueRows->groupBy(fn ($row) => (int) $row->platform_id);

        $result = [];
        foreach ($platforms as $platform) {
            $platformCurrentRows = $currentRowsByPlatform->get((int) $platform->id, collect())->values();
            $platformPreviousRows = $previousRowsByPlatform->get((int) $platform->id, collect())->values();

            $currentRevenueBreakdown = $this->buildCurrencyBreakdownFromRows($platformCurrentRows);
            $previousRevenueBreakdown = $this->buildCurrencyBreakdownFromRows($platformPreviousRows);
            $currentRevenueNormalized = $this->normalizeCountryRevenueRowsForMode($platformCurrentRows, $targetCurrency, $shouldNormalizeRevenue);
            $previousRevenueNormalized = $this->normalizeCountryRevenueRowsForMode($platformPreviousRows, $targetCurrency, $shouldNormalizeRevenue);
            $currentRevenue = $currentRevenueBreakdown['scalar_amount'];
            $previousRevenue = $previousRevenueBreakdown['scalar_amount'];
            $target = $revenueTargets[(int) $platform->id] ?? null;
            $targetAmount = $target ? (float) $target['target'] : null;
            $progressValue = (float) ($currentRevenueNormalized['normalized_total'] ?? 0);
            $trend = $this->calculateComparableCountryTrend($currentRevenueBreakdown, $previousRevenueBreakdown);
            $normalizedTrend = null;
            if (
                $shouldNormalizeRevenue
                &&
                !($currentRevenueNormalized['normalization_meta']['partial'] ?? true)
                && !($previousRevenueNormalized['normalization_meta']['partial'] ?? true)
                && (float) ($previousRevenueNormalized['normalized_total'] ?? 0) > 0
            ) {
                $normalizedTrend = round(
                    (((float) $currentRevenueNormalized['normalized_total'] - (float) $previousRevenueNormalized['normalized_total']) / (float) $previousRevenueNormalized['normalized_total']) * 100,
                    1
                );
            }

            $result[] = [
                'platform_id' => $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
                'currency' => $platform->currency_code,
                'current_revenue_breakdown' => $currentRevenueBreakdown['breakdown'],
                'current_revenue' => $currentRevenue,
                'current_revenue_normalized' => $currentRevenueNormalized['normalized_total'],
                'current_revenue_normalized_display' => $currentRevenueNormalized['normalized_display'],
                'current_revenue_normalization_meta' => $currentRevenueNormalized['normalization_meta'],
                'previous_revenue_breakdown' => $previousRevenueBreakdown['breakdown'],
                'previous_revenue' => $previousRevenue,
                'previous_revenue_normalized' => $previousRevenueNormalized['normalized_total'],
                'previous_revenue_normalization_meta' => $previousRevenueNormalized['normalization_meta'],
                'trend' => $trend,
                'normalized_trend' => $normalizedTrend,
                'target' => $target ? [
                    'period' => $target['period'],
                    'target' => $targetAmount,
                    'target_currency' => $target['target_currency'],
                    'target_display' => $target['target_currency'] . ' ' . number_format($targetAmount, 2),
                    'current' => $progressValue,
                    'current_display' => $target['target_currency'] . ' ' . number_format($progressValue, 2),
                    'percentage' => $targetAmount && $targetAmount > 0 ? (int) min(100, round(($progressValue / $targetAmount) * 100)) : 0,
                ] : null,
            ];
        }

        usort($result, function ($a, $b) {
            $left = $a['current_revenue_normalized'] ?? null;
            $right = $b['current_revenue_normalized'] ?? null;

            if ($left !== null && $right !== null) {
                return $right <=> $left;
            }

            return array_sum($b['current_revenue_breakdown']) <=> array_sum($a['current_revenue_breakdown']);
        });

        return $result;
    }

    private function marketRevenueTargets(?array $platformIds, string $period, string $targetCurrency): array
    {
        $targets = AgentGoal::query()
            ->where('metric', 'revenue')
            ->where('period', $period)
            ->whereNotNull('platform_id')
            ->where(function ($query) use ($targetCurrency) {
                $query->whereNull('target_currency')
                    ->orWhere('target_currency', strtoupper($targetCurrency));
            })
            ->when(is_array($platformIds), function ($query) use ($platformIds) {
                if (empty($platformIds)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query->whereIn('platform_id', $platformIds);
            })
            ->get(['platform_id', 'target', 'target_currency', 'period']);

        return $targets
            ->groupBy(fn (AgentGoal $goal) => (int) $goal->platform_id)
            ->map(fn ($goals) => [
                'target' => (float) $goals->sum(fn (AgentGoal $goal) => (int) $goal->target),
                'target_currency' => strtoupper((string) ($goals->first()->target_currency ?: $targetCurrency)),
                'period' => $period,
            ])
            ->all();
    }

    private function aggregateCountryRevenueRows(?array $platformIds, Carbon $from, Carbon $to)
    {
        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite'
            ? "date(COALESCE(payments.completed_at, payments.created_at))"
            : "DATE(COALESCE(payments.completed_at, payments.created_at))";
        $currencyExpression = "COALESCE(payments.currency, platforms.currency_code, 'KES')";

        $query = Payment::query()
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereBetween('payments.created_at', [$from, $to])
            ->select(DB::raw("{$dateExpression} as event_date"))
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(payments.id) as payments_count')
            ->groupByRaw($dateExpression)
            ->groupBy('payments.platform_id')
            ->groupBy('platforms.country')
            ->groupBy('platforms.name')
            ->groupByRaw($currencyExpression);

        if (is_array($platformIds)) {
            $query->whereIn('payments.platform_id', $platformIds);
        }

        return $query->get();
    }

    private function buildCurrencyBreakdownFromRows($rows): array
    {
        $breakdown = collect($rows)
            ->groupBy(fn ($row) => (string) $row->currency)
            ->map(fn ($group) => round((float) $group->sum(fn ($row) => (float) $row->amount), 2))
            ->all();

        ksort($breakdown);
        $count = count($breakdown);

        return [
            'breakdown' => $breakdown,
            'currency_count' => $count,
            'scalar_amount' => $count === 1 ? array_values($breakdown)[0] : null,
        ];
    }

    private function normalizeCountryRevenueRowsForMode($rows, string $targetCurrency, bool $shouldNormalizeRevenue): array
    {
        if ($shouldNormalizeRevenue) {
            return $this->reportingCurrencyService->normalizeEventRows($rows, $targetCurrency, false);
        }

        return $this->emptyNormalizationPayload($targetCurrency);
    }

    private function resolvePreviousMatchingWindow(Carbon $from, Carbon $to): array
    {
        $windowSeconds = max(1, $to->diffInSeconds($from) + 1);
        $previousFrom = $from->copy()->subSeconds($windowSeconds);
        $previousTo = $from->copy()->subSecond();

        return [$previousFrom, $previousTo];
    }

    private function calculateNormalizedCountryTrend(array $currentNormalized, array $previousNormalized, bool $shouldNormalizeRevenue): ?float
    {
        if (
            !$shouldNormalizeRevenue
            || ($currentNormalized['normalized_total'] ?? null) === null
            || ($previousNormalized['normalized_total'] ?? null) === null
            || ($currentNormalized['normalization_meta']['partial'] ?? true)
            || ($previousNormalized['normalization_meta']['partial'] ?? true)
        ) {
            return null;
        }

        $previousTotal = (float) ($previousNormalized['normalized_total'] ?? 0);
        if ($previousTotal <= 0) {
            return null;
        }

        return round(
            (((float) $currentNormalized['normalized_total'] - $previousTotal) / $previousTotal) * 100,
            1
        );
    }

    private function resolveTrendBucketType(Carbon $from, Carbon $to): string
    {
        return $from->diffInDays($to) + 1 > 31 ? 'week' : 'day';
    }

    private function buildCountryPerformanceTrend($rows, Carbon $from, Carbon $to, string $targetCurrency, bool $shouldNormalizeRevenue): array
    {
        $bucketType = $this->resolveTrendBucketType($from, $to);
        $groupedRows = collect($rows)->groupBy(function ($row) use ($bucketType) {
            $eventDate = Carbon::parse((string) $row->event_date);

            return $bucketType === 'week'
                ? $eventDate->copy()->startOfWeek()->toDateString()
                : $eventDate->toDateString();
        });

        $period = $bucketType === 'week'
            ? CarbonPeriod::create($from->copy()->startOfWeek(), CarbonInterval::week(), $to->copy()->startOfWeek())
            : CarbonPeriod::create($from->copy()->startOfDay(), CarbonInterval::day(), $to->copy()->startOfDay());

        $points = [];
        foreach ($period as $pointDate) {
            $bucketStart = $bucketType === 'week'
                ? $pointDate->copy()->startOfWeek()
                : $pointDate->copy()->startOfDay();
            $bucketEnd = $bucketType === 'week'
                ? $bucketStart->copy()->endOfWeek()->min($to->copy())
                : $bucketStart->copy()->endOfDay();
            $bucketKey = $bucketStart->toDateString();
            $bucketRows = $groupedRows->get($bucketKey, collect())->values();
            $breakdown = $this->buildCurrencyBreakdownFromRows($bucketRows);
            $normalized = $this->normalizeCountryRevenueRowsForMode($bucketRows, $targetCurrency, $shouldNormalizeRevenue);

            $points[] = [
                'bucket_key' => $bucketKey,
                'bucket_start' => $bucketStart->toDateString(),
                'bucket_end' => $bucketEnd->toDateString(),
                'label' => $bucketType === 'week'
                    ? sprintf('%s - %s', $bucketStart->format('j M'), $bucketEnd->format('j M'))
                    : $bucketStart->format('j M'),
                'revenue_breakdown' => $breakdown['breakdown'],
                'revenue' => $breakdown['scalar_amount'],
                'normalized_total' => $normalized['normalized_total'],
                'normalized_display' => $normalized['normalized_display'],
                'normalization_meta' => $normalized['normalization_meta'],
                'payments_count' => (int) $bucketRows->sum(fn ($row) => (int) ($row->payments_count ?? 0)),
            ];
        }

        return $points;
    }

    private function buildCountryPerformanceInsights(array $trendPoints): array
    {
        if (empty($trendPoints)) {
            return [
                'strongest_period' => null,
                'weakest_period' => null,
                'momentum' => null,
                'recent_movement' => null,
            ];
        }

        $comparablePoints = collect($trendPoints)->map(function (array $point) {
            $comparisonValue = $point['normalized_total'] ?? $point['revenue'] ?? array_sum($point['revenue_breakdown'] ?? []);
            $point['comparison_value'] = round((float) $comparisonValue, 2);

            return $point;
        });

        $strongest = $comparablePoints->sortByDesc('comparison_value')->first();
        $weakest = $comparablePoints->sortBy('comparison_value')->first();
        $recentWindow = $comparablePoints->take(-3)->values();
        $previousWindow = $comparablePoints->slice(max(0, $comparablePoints->count() - 6), 3)->values();

        $recentTotal = (float) $recentWindow->sum('comparison_value');
        $previousTotal = (float) $previousWindow->sum('comparison_value');
        $recentDelta = $previousTotal > 0
            ? round((($recentTotal - $previousTotal) / $previousTotal) * 100, 1)
            : ($recentTotal > 0 ? 100.0 : null);

        $lastPoint = $comparablePoints->last();
        $priorPoint = $comparablePoints->count() > 1 ? $comparablePoints->slice(-2, 1)->first() : null;
        $momentumDelta = $priorPoint && (float) $priorPoint['comparison_value'] > 0
            ? round(((float) $lastPoint['comparison_value'] - (float) $priorPoint['comparison_value']) / (float) $priorPoint['comparison_value'] * 100, 1)
            : null;

        return [
            'strongest_period' => $strongest ? $this->mapInsightPoint($strongest) : null,
            'weakest_period' => $weakest ? $this->mapInsightPoint($weakest) : null,
            'momentum' => [
                'direction' => $momentumDelta === null ? 'flat' : ($momentumDelta > 0 ? 'up' : ($momentumDelta < 0 ? 'down' : 'flat')),
                'delta_percent' => $momentumDelta,
                'label' => $momentumDelta === null ? 'No recent movement yet' : ($momentumDelta > 0 ? 'Building upward' : ($momentumDelta < 0 ? 'Cooling off' : 'Holding steady')),
            ],
            'recent_movement' => [
                'direction' => $recentDelta === null ? 'flat' : ($recentDelta > 0 ? 'up' : ($recentDelta < 0 ? 'down' : 'flat')),
                'delta_percent' => $recentDelta,
                'label' => $recentDelta === null ? 'No prior comparison window' : ($recentDelta > 0 ? 'Recent revenue is up' : ($recentDelta < 0 ? 'Recent revenue is down' : 'Recent revenue is flat')),
            ],
        ];
    }

    private function mapInsightPoint(array $point): array
    {
        return [
            'label' => $point['label'],
            'bucket_start' => $point['bucket_start'],
            'bucket_end' => $point['bucket_end'],
            'revenue_breakdown' => $point['revenue_breakdown'],
            'revenue' => $point['revenue'],
            'normalized_total' => $point['normalized_total'],
            'normalized_display' => $point['normalized_display'],
            'payments_count' => $point['payments_count'],
        ];
    }

    private function fetchCountryEngagementSummary(Platform $platform, Carbon $from, Carbon $to): array
    {
        try {
            $payload = WpSyncService::forPlatform((int) $platform->id)->getAnalyticsRankings([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'per_page' => 1,
                'sort_by' => 'engagement_score',
                'order' => 'desc',
            ]);
        } catch (\Throwable $exception) {
            return [
                'engagement' => [
                    'available' => false,
                    'message' => 'Profile engagement analytics are currently unavailable.',
                ],
                'contact_mix' => [],
                'availability' => [
                    'engagement' => false,
                    'contact_mix' => false,
                ],
            ];
        }

        $platformTotals = is_array($payload['platform_totals'] ?? null) ? $payload['platform_totals'] : [];
        $marketAverages = is_array($payload['market_averages'] ?? null) ? $payload['market_averages'] : [];
        $contactRate = $this->extractAnalyticsMetricValue($platformTotals['contact_rate_percent'] ?? null);
        $marketContactRate = (float) ($marketAverages['contact_rate_percent'] ?? 0);
        $health = 'steady';
        $healthLabel = 'Steady with market';
        if ($contactRate > $marketContactRate + 0.5) {
            $health = 'above_market';
            $healthLabel = 'Above market baseline';
        } elseif ($contactRate < $marketContactRate - 0.5) {
            $health = 'below_market';
            $healthLabel = 'Below market baseline';
        }

        return [
            'engagement' => [
                'available' => true,
                'views' => (int) $this->extractAnalyticsMetricTotal($platformTotals['profile_view'] ?? null),
                'views_delta_percent' => $this->extractAnalyticsMetricDelta($platformTotals['profile_view'] ?? null),
                'contacts' => (int) $this->extractAnalyticsMetricTotal($platformTotals['contact_actions'] ?? null),
                'contacts_delta_percent' => $this->extractAnalyticsMetricDelta($platformTotals['contact_actions'] ?? null),
                'contact_rate_percent' => $contactRate,
                'contact_rate_delta_pp' => $this->extractAnalyticsContactRateDelta($payload, $platformTotals),
                'market_contact_rate_percent' => $marketContactRate,
                'health' => $health,
                'health_label' => $healthLabel,
            ],
            'contact_mix' => $this->normalizeAnalyticsContactMix($payload['platform_contact_mix'] ?? []),
            'availability' => [
                'engagement' => true,
                'contact_mix' => true,
            ],
        ];
    }

    private function extractAnalyticsMetricTotal($metric): float
    {
        if (is_array($metric)) {
            return (float) ($metric['total'] ?? 0);
        }

        return (float) $metric;
    }

    private function extractAnalyticsMetricDelta($metric): ?float
    {
        if (!is_array($metric)) {
            return null;
        }

        foreach (['delta_percent', 'delta_total_percent', 'delta'] as $key) {
            if (array_key_exists($key, $metric) && $metric[$key] !== null) {
                return round((float) $metric[$key], 1);
            }
        }

        return null;
    }

    private function extractAnalyticsMetricValue($metric): float
    {
        if (is_array($metric)) {
            return round((float) ($metric['value'] ?? 0), 1);
        }

        return round((float) $metric, 1);
    }

    private function extractAnalyticsContactRateDelta(array $payload, array $platformTotals): ?float
    {
        $contactRate = $platformTotals['contact_rate_percent'] ?? null;
        if (is_array($contactRate) && array_key_exists('delta_pp', $contactRate)) {
            return round((float) $contactRate['delta_pp'], 1);
        }

        if (array_key_exists('delta_contact_rate_pp', $platformTotals)) {
            return round((float) $platformTotals['delta_contact_rate_pp'], 1);
        }

        if (array_key_exists('delta_contact_rate_pp', $payload)) {
            return round((float) $payload['delta_contact_rate_pp'], 1);
        }

        return null;
    }

    private function normalizeAnalyticsContactMix($mix): array
    {
        if (!is_array($mix)) {
            return [];
        }

        $rows = array_is_list($mix)
            ? $mix
            : array_map(function ($value, $key) {
                if (is_array($value)) {
                    $value['event_type'] = $value['event_type'] ?? $key;
                }

                return $value;
            }, $mix, array_keys($mix));

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) {
                return [
                    'key' => (string) ($row['event_type'] ?? 'unknown'),
                    'label' => (string) ($row['label'] ?? Str::headline((string) ($row['event_type'] ?? 'unknown'))),
                    'total' => (int) ($row['total'] ?? 0),
                    'percent' => round((float) ($row['share_percent'] ?? $row['percent'] ?? 0), 1),
                ];
            })
            ->values()
            ->all();
    }

    private function normalizePaymentQueryForMode(Builder $query, string $targetCurrency, bool $shouldNormalizeRevenue): array
    {
        if ($shouldNormalizeRevenue) {
            return $this->reportingCurrencyService->normalizePaymentQuery($query, $targetCurrency, false);
        }

        return $this->emptyNormalizationPayload($targetCurrency);
    }

    private function emptyNormalizationPayload(string $targetCurrency): array
    {
        $payload = $this->reportingCurrencyService->normalizeBreakdown([], null, $targetCurrency);
        $payload['normalized_total'] = null;
        $payload['normalized_display'] = null;
        $payload['normalization_meta']['as_of'] = null;

        return $payload;
    }

    private function buildNewUsersKpi(?array $platformIds): array
    {
        $windowStart = now()->subDays(6)->startOfDay();
        $windowEnd = now()->endOfDay();

        $query = Client::query()
            ->selectRaw('signup_source, COUNT(*) as total')
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->groupBy('signup_source');

        if (is_array($platformIds)) {
            if (empty($platformIds)) {
                return [
                    'crm_created' => 0,
                    'wp_organic' => 0,
                    'total' => 0,
                ];
            }

            $query->whereIn('platform_id', $platformIds);
        }

        $countsBySource = $query->pluck('total', 'signup_source')
            ->map(fn ($value) => (int) $value);

        $crmCreated = collect(self::SALES_NEW_USER_SOURCE_GROUPS['crm_created'])
            ->sum(fn (string $source) => (int) ($countsBySource[$source] ?? 0));
        $wpOrganic = collect(self::SALES_NEW_USER_SOURCE_GROUPS['wp_organic'])
            ->sum(fn (string $source) => (int) ($countsBySource[$source] ?? 0));

        return [
            'crm_created' => $crmCreated,
            'wp_organic' => $wpOrganic,
            'total' => $crmCreated + $wpOrganic,
        ];
    }

    private function buildTopPackages(?array $platformIds, Carbon $from, Carbon $to): array
    {
        $packageExpression = "COALESCE(deal_products.name, payment_products.name, deals.plan_type, 'Unknown package')";

        $query = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereBetween('payments.created_at', [$from, $to])
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('products as payment_products', 'payment_products.id', '=', 'payments.product_id')
            ->leftJoin('products as deal_products', 'deal_products.id', '=', 'deals.product_id')
            ->selectRaw("{$packageExpression} as package_name, COUNT(*) as activation_count")
            ->groupBy(DB::raw($packageExpression))
            ->orderByDesc('activation_count')
            ->limit(5);

        if (is_array($platformIds)) {
            if (empty($platformIds)) {
                return [];
            }

            $query->whereIn('payments.platform_id', $platformIds);
        }

        return $query->get()
            ->map(fn ($row) => [
                'package_name' => (string) ($row->package_name ?: 'Unknown package'),
                'activation_count' => (int) ($row->activation_count ?? 0),
            ])
            ->values()
            ->all();
    }

    private function resolveMissedChatsCount(?array $platformIds): ?int
    {
        $platformQuery = Platform::query()
            ->whereNotNull('support_board_api_url')
            ->whereNotNull('support_board_token')
            ->orderBy('id');

        if (is_array($platformIds)) {
            if (empty($platformIds)) {
                return 0;
            }

            $platformQuery->whereIn('id', $platformIds);
        }

        $platforms = $platformQuery->get();
        if ($platforms->isEmpty()) {
            return null;
        }

        $count = 0;
        $hasSuccessfulFetch = false;

        foreach ($platforms as $platform) {
            $service = new SupportBoardService($platform);
            if (!$service->isConfigured()) {
                continue;
            }

            try {
                $page = 1;
                while ($page <= 50) {
                    $batch = $service->getAllConversations($page);
                    if (empty($batch)) {
                        break;
                    }

                    $count += collect($batch)
                        ->filter(fn (array $conversation) => (int) ($conversation['status_code'] ?? 0) !== 4)
                        ->count();

                    $hasSuccessfulFetch = true;
                    $page++;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $hasSuccessfulFetch ? $count : null;
    }

    private function extractSyncedProfilesTotal(array $result): ?int
    {
        $candidates = [
            data_get($result, 'clients.total'),
            data_get($result, 'total'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            return (int) $candidate;
        }

        return null;
    }

    private function countryCodeForCountry(?string $country): ?string
    {
        $normalized = Str::lower(trim((string) $country));

        return match ($normalized) {
            'kenya' => 'KE',
            'tanzania' => 'TZ',
            'uganda' => 'UG',
            'nigeria' => 'NG',
            'south africa' => 'ZA',
            'ghana' => 'GH',
            'ethiopia' => 'ET',
            'rwanda' => 'RW',
            default => $normalized !== '' ? strtoupper(Str::substr($normalized, 0, 2)) : null,
        };
    }

    /**
     * Compare two country revenue windows only when both windows are effectively
     * single-currency scopes in the same resolved currency.
     */
    private function calculateComparableCountryTrend(array $currentBreakdown, array $previousBreakdown): ?float
    {
        if (($currentBreakdown['currency_count'] ?? 0) > 1 || ($previousBreakdown['currency_count'] ?? 0) > 1) {
            return null;
        }

        $currentCurrency = array_key_first($currentBreakdown['breakdown'] ?? []);
        $previousCurrency = array_key_first($previousBreakdown['breakdown'] ?? []);

        if ($currentCurrency !== null && $previousCurrency !== null && $currentCurrency !== $previousCurrency) {
            return null;
        }

        $resolvedCurrency = $currentCurrency ?? $previousCurrency;
        if ($resolvedCurrency === null) {
            return null;
        }

        $currentAmount = (float) ($currentBreakdown['breakdown'][$resolvedCurrency] ?? 0);
        $previousAmount = (float) ($previousBreakdown['breakdown'][$resolvedCurrency] ?? 0);

        if ($previousAmount > 0) {
            return round((($currentAmount - $previousAmount) / $previousAmount) * 100, 1);
        }

        return $currentAmount > 0 ? 100.0 : null;
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
        // Respect data baseline cutoff when mode is 'fresh_start'
        $baselineCutoff = $this->resolveBaselineCutoff();
        if ($baselineCutoff) {
            return $baselineCutoff;
        }

        $oldestCandidates = [];

        $clientsQuery = Client::query();
        $leadsQuery = Lead::query();
        $dealsQuery = Deal::query();
        $paymentsQuery = Payment::query()->businessVisible();
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

    /**
     * Return the baseline cutoff as a Carbon date if mode is 'fresh_start', else null.
     */
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

    private function recordDashboardTimingCheckpoint(
        string $section,
        int $startedAt,
        array &$timings,
        ?int $selectedPlatformId,
        ?array $platformIds,
        array $validated,
        string $currencyMode,
        string $targetCurrency,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): void {
        $elapsedMs = round((hrtime(true) - $startedAt) / 1_000_000, 1);
        $timings[$section] = $elapsedMs;

        Log::info('CRM dashboard timing checkpoint', array_merge(
            $this->dashboardTimingContext($selectedPlatformId, $platformIds, $validated, $currencyMode, $targetCurrency, $from, $to),
            [
                'section' => $section,
                'elapsed_ms' => $elapsedMs,
                'timings_ms' => $timings,
            ]
        ));
    }

    private function recordDashboardTimingFailure(
        int $startedAt,
        array $timings,
        ?int $selectedPlatformId,
        ?array $platformIds,
        array $validated,
        string $currencyMode,
        string $targetCurrency,
        Throwable $exception,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): void {
        Log::error('CRM dashboard timing failure', array_merge(
            $this->dashboardTimingContext($selectedPlatformId, $platformIds, $validated, $currencyMode, $targetCurrency, $from, $to),
            [
                'elapsed_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
                'timings_ms' => $timings,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]
        ));
    }

    private function dashboardTimingContext(
        ?int $selectedPlatformId,
        ?array $platformIds,
        array $validated,
        string $currencyMode,
        string $targetCurrency,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        return [
            'platform_id' => $selectedPlatformId ? (int) $selectedPlatformId : null,
            'platform_scope' => $selectedPlatformId ? 'single_market' : 'all_accessible_markets',
            'platform_count' => is_array($platformIds) ? count($platformIds) : null,
            'country_period' => $validated['country_period'] ?? 'week',
            'currency_mode' => $currencyMode,
            'reporting_currency' => $targetCurrency,
            'from' => $from?->toDateString() ?? ($validated['from'] ?? null),
            'to' => $to?->toDateString() ?? ($validated['to'] ?? null),
        ];
    }
}
