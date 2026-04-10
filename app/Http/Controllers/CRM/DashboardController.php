<?php

namespace App\Http\Controllers\CRM;

use App\Helpers\CurrencyBreakdown;
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
use App\Services\ClientRetentionInsightService;
use App\Services\MarketAuthorizationService;
use App\Services\RenewalService;
use App\Services\SupportBoardService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
        private readonly ClientRetentionInsightService $clientRetentionInsightService
    ) {
    }

    public function summary(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:120',
            'country_period' => 'nullable|in:week,month',
            'sales_view' => 'nullable|boolean',
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

        // Per-currency breakdowns for all revenue KPIs.  scalar_amount is null when
        // multiple currencies are present so the frontend cannot display a wrong total.
        $revenueWindowBreakdown    = CurrencyBreakdown::fromPaymentQuery(clone $paymentsWindowQuery);
        $revenuePreviousBreakdown  = CurrencyBreakdown::fromPaymentQuery(clone $previousRevenueQuery);
        $walletTopupBreakdown      = CurrencyBreakdown::fromPaymentQuery(clone $walletTopupsWindowQuery);

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
        $renewalRisk72h = (int) ($renewalSummary['risk'] ?? 0);
        $renewalPipeline14d = (int) ($renewalSummary['pending'] ?? 0);
        $renewalWorkload14d = $renewalRisk72h + $renewalPipeline14d;

        $countryPeriod = $validated['country_period'] ?? 'week';
        $countryRevenue = $this->buildCountryRevenue($platformIds, $countryPeriod, $from, $to);

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
        $newUsers = $salesView ? $this->buildNewUsersKpi(is_array($platformIds) ? $platformIds : null) : null;
        $topPackages = $salesView ? $this->buildTopPackages(is_array($platformIds) ? $platformIds : null, $from, $to) : [];
        $missedChatsCount = $salesView ? $this->resolveMissedChatsCount(is_array($platformIds) ? $platformIds : null) : null;

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
                'revenue_window_breakdown' => $revenueWindowBreakdown['breakdown'],
                'revenue_mtd' => $revenueWindow,
                'revenue_mtd_breakdown' => $revenueWindowBreakdown['breakdown'],
                'wallet_topups_window' => $walletTopupsWindow,
                'wallet_topup_revenue_window' => $walletTopupRevenueWindow,
                'wallet_topup_revenue_window_breakdown' => $walletTopupBreakdown['breakdown'],
                'revenue_previous_window' => $revenuePreviousWindow,
                'revenue_previous_window_breakdown' => $revenuePreviousBreakdown['breakdown'],
                'revenue_delta_percent' => $revenueDeltaPercent,
                'average_ticket_window' => $averageTicket,
                'revenue_is_mixed' => $isMixed,
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
            'country_revenue' => $countryRevenue,
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
        $clientCounts = Client::query()
            ->selectRaw('platform_id, COUNT(*) as total')
            ->whereIn('platform_id', $platforms->pluck('id')->all())
            ->groupBy('platform_id')
            ->pluck('total', 'platform_id');

        $markets = $platforms->map(function (Platform $platform) use ($clientCounts) {
            $lastResult = is_array($platform->sync_last_result) ? $platform->sync_last_result : [];
            $lastDelta = is_array(data_get($lastResult, 'clients')) ? data_get($lastResult, 'clients') : [];
            $profilesTotal = $this->extractSyncedProfilesTotal($lastResult);
            $lastSyncedAt = optional($platform->sync_last_synced_at)->toDateTimeString();
            $needsSync = $platform->sync_last_status === 'error'
                || !$platform->sync_last_synced_at
                || $platform->sync_last_synced_at->lt(now()->subHours(12));

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

    private function buildCountryRevenue(?array $platformIds, string $period, Carbon $rangeFrom, Carbon $rangeTo): array
    {
        $windowDays = $period === 'month' ? 30 : 7;
        $currentTo = $rangeTo->copy();
        $periodFrom = $rangeTo->copy()->subDays($windowDays - 1)->startOfDay();
        $currentFrom = $rangeFrom->copy()->greaterThan($periodFrom)
            ? $rangeFrom->copy()
            : $periodFrom;

        $previousTo = $currentFrom->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subDays($windowDays - 1)->startOfDay();

        $platforms = Platform::query();
        if (is_array($platformIds)) {
            $platforms->whereIn('id', $platformIds);
        } else {
            $platforms->where('is_active', true);
        }
        $platforms = $platforms->get();

        $result = [];
        foreach ($platforms as $platform) {
            $currentRevenueQuery = Payment::where('platform_id', $platform->id)
                ->reportableSuccessful()
                ->excludingWalletTopups()
                ->whereBetween('created_at', [$currentFrom, $currentTo]);

            $previousRevenueQuery = Payment::where('platform_id', $platform->id)
                ->reportableSuccessful()
                ->excludingWalletTopups()
                ->whereBetween('created_at', [$previousFrom, $previousTo]);

            $currentRevenueBreakdown = CurrencyBreakdown::fromPaymentQuery(clone $currentRevenueQuery, $platform->currency_code ?: 'KES');
            $previousRevenueBreakdown = CurrencyBreakdown::fromPaymentQuery(clone $previousRevenueQuery, $platform->currency_code ?: 'KES');
            $currentRevenue = $currentRevenueBreakdown['scalar_amount'];
            $previousRevenue = $previousRevenueBreakdown['scalar_amount'];
            $trend = $this->calculateComparableCountryTrend($currentRevenueBreakdown, $previousRevenueBreakdown);

            $result[] = [
                'platform_id' => $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
                'currency' => $platform->currency_code,
                'current_revenue_breakdown' => $currentRevenueBreakdown['breakdown'],
                'current_revenue' => $currentRevenue,
                'previous_revenue_breakdown' => $previousRevenueBreakdown['breakdown'],
                'previous_revenue' => $previousRevenue,
                'trend' => $trend,
            ];
        }

        usort($result, fn($a, $b) => array_sum($b['current_revenue_breakdown']) <=> array_sum($a['current_revenue_breakdown']));

        return $result;
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
}
