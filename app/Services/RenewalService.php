<?php

namespace App\Services;

use App\Billing\Support\MarketBillingMethodPolicy;
use App\Billing\Support\WalletAutoRenewPolicy;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\RenewalCampaign;
use App\Models\RenewalRun;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\Messaging\DispatchResult;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use App\Support\CrmAuditAction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RenewalService
{
    /** @var array<int, bool> Per-platform short-cycle guard flag, cached per run. */
    private array $guardEnabledCache = [];

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly TemplateService $templateService,
        private readonly AuditService $auditService,
        private readonly MarketBillingMethodPolicy $marketBillingMethodPolicy,
        private readonly WalletAutoRenewPolicy $walletAutoRenewPolicy,
        private readonly WalletCheckoutService $walletCheckoutService,
        private readonly ClientSubscriptionActionResolver $clientSubscriptionActionResolver,
        private readonly MessagingDispatcher $messagingDispatcher,
        private readonly LifecycleSmsService $lifecycleSmsService,
        private readonly ClientProfileMetricsService $profileMetricsService
    ) {
    }

    /**
     * Renewal template variables: base client vars + freshness-gated profile
     * stats + (when the template embeds {{payment_link}} and the market allows
     * it) a tokenized checkout link on the client's current plan.
     */
    private function renewalTemplateVariables(Deal $deal, Template $template, array $extra = [], ?int $actorId = null): array
    {
        return array_merge(
            $this->templateService->buildClientVariables(
                $deal->client,
                $deal,
                array_merge($this->profileMetricsService->templateVariables($deal->client), $extra)
            ),
            $this->lifecycleSmsService->renewalLinkVariables($deal, $template, $actorId)
        );
    }

    public function buildOverview(array $filters = [], int $perPage = 50, ?User $viewer = null): array
    {
        $includeUntracked = (bool) ($filters['include_untracked'] ?? false);
        $query = Client::query()
            ->with(['platform', 'assignedAgent', 'activeDeal.product'])
            ->leftJoin('deals', function ($join) {
                // Join latest deal per client to support modern + legacy subscription views.
                $join->on('clients.id', '=', 'deals.client_id')
                    ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
            })
            ->leftJoin('products as deal_products', 'deal_products.id', '=', 'deals.product_id')
            ->select(
                'clients.*',
                'deals.id as deal_id',
                'deals.status as deal_status',
                'deals.expires_at as deal_expires_at',
                'deals.product_id as deal_product_id',
                'deal_products.name as deal_product_name',
                'deals.plan_type as deal_plan_type',
                'deals.duration as deal_duration',
                'deals.amount as deal_amount',
                'deals.currency as deal_currency',
                'deals.origin as deal_origin',
                'deals.cancellation_reason_code as deal_cancellation_reason_code',
                'deals.cancellation_notes as deal_cancellation_notes',
                'deals.cancelled_payment_id as deal_cancelled_payment_id'
            )
            ->where(function ($q) use ($includeUntracked) {
                $q->whereNotNull('deals.id')
                    ->orWhereNotNull('clients.escort_expire')
                    ->orWhereNotNull('clients.premium_expire')
                    ->orWhereNotNull('clients.featured_expire')
                    ->orWhere('clients.profile_status', 'private');

                if ($includeUntracked) {
                    $q->orWhere(function ($untracked) {
                        $untracked->whereNull('deals.id')
                            ->where('clients.profile_status', 'publish')
                            ->whereNull('clients.escort_expire')
                            ->whereNull('clients.premium_expire')
                            ->whereNull('clients.featured_expire');
                    });
                }
            });

        if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
            $query->whereIn('clients.platform_id', $filters['platform_ids']);
        } elseif (!empty($filters['platform_id'])) {
            $query->where('clients.platform_id', (int) $filters['platform_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('clients.name', 'like', "%{$search}%")
                    ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['high_risk'])) {
            $query->where('clients.is_high_risk', true);
        }

        if (!empty($filters['cancellation_reason_code'])) {
            $query->where('deals.cancellation_reason_code', (string) $filters['cancellation_reason_code']);
        }

        $bucket = (string) ($filters['bucket'] ?? 'all');
        $this->applyBucketFilter($query, $bucket);

        if (!empty($filters['status'])) {
            $this->applyStatusFilter($query, (string) $filters['status']);
        }

        /** @var \Illuminate\Pagination\LengthAwarePaginator $targets */
        $expiryDateExpr = $this->expiryDateExpr();
        $targets = $query
            ->orderByRaw("{$expiryDateExpr} DESC")
            ->paginate($perPage);

        $targetRows = $targets->getCollection();
        $dealIds = $targetRows->pluck('deal_id')->filter()->map(fn($id) => (int) $id)->values();
        $clientIds = $targetRows->pluck('id')->filter()->map(fn($id) => (int) $id)->values();

        $paidDealIds = Payment::query()
            ->reportableSuccessful()
            ->whereIn('deal_id', $dealIds)
            ->pluck('deal_id')
            ->map(fn($id) => (int) $id)
            ->flip();

        $telemetryByKey = $this->buildTelemetryMap($dealIds, $clientIds);
        $platformIdsForCatalog = $targetRows->pluck('platform_id')
            ->filter(fn($id) => !empty($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $activeProductCatalogByPlatform = Product::query()
            ->where('is_active', true)
            ->when(
                $platformIdsForCatalog->isNotEmpty(),
                fn(Builder $builder) => $builder->whereIn('platform_id', $platformIdsForCatalog->all())
            )
            ->get(['id', 'platform_id', 'name', 'monthly_price', 'biweekly_price', 'weekly_price', 'currency'])
            ->groupBy(fn(Product $product) => (int) $product->platform_id)
            ->map(fn(Collection $products) => $products->keyBy(fn(Product $product) => strtolower((string) $product->name)));

        $targets->setCollection(
            $targetRows->map(function (Client $client) use ($paidDealIds, $telemetryByKey, $activeProductCatalogByPlatform) {
                $expiryDate = $this->resolveExpiryDate(
                    $client->deal_expires_at,
                    $client->escort_expire,
                    $client->premium_expire,
                    $client->featured_expire
                );
                $daysLeft = $this->daysUntil($expiryDate);

                $hasWpStateConflict = !$client->deal_id
                    && !$expiryDate
                    && $client->profile_status === 'publish'
                    && ((bool) $client->needs_payment || (bool) $client->notactive);
                $isUntracked = !$client->deal_id
                    && !$expiryDate
                    && $client->profile_status === 'publish'
                    && !$hasWpStateConflict;
                $status = $client->deal_status ?: ($daysLeft !== null && $daysLeft < 0 ? 'expired' : 'active');
                $remindersPaused = $client->activeDeal ? $this->isReminderPaused($client->activeDeal) : false;

                $renewalBucket = $remindersPaused
                    ? 'paused'
                    : ($daysLeft !== null && $daysLeft < 0
                        ? $this->bucketForDaysExpired($daysLeft)
                        : $this->bucketForDays($daysLeft));

                if (!$expiryDate && $client->profile_status === 'private') {
                    $renewalBucket = 'lapsed';
                    $status = 'expired';
                }

                if ($isUntracked) {
                    $renewalBucket = 'untracked';
                    $status = 'untracked';
                }

                $originType = ($isUntracked || $hasWpStateConflict)
                    ? 'untracked'
                    : ($client->deal_id
                        ? ($client->deal_origin === 'mpesa_import' ? 'mpesa_import' : 'modern')
                        : 'legacy');
                $clientDeactivation = !$client->deal_id
                    ? $this->clientSubscriptionActionResolver->resolveNoDealDeactivation($client, [
                        'has_active_deal' => false,
                        'has_tracked_deal_history' => false,
                    ])
                    : [
                        'can_deactivate_without_deal' => false,
                        'deactivation_scope' => null,
                        'deactivation_label' => 'Deactivate',
                        'deactivation_disabled_reason' => null,
                    ];
                $paymentStatus = $client->deal_id && $paidDealIds->has((int) $client->deal_id) ? 'verified' : 'unlinked';
                $telemetryKey = $client->deal_id ? 'deal_' . $client->deal_id : 'client_' . $client->id;
                $telemetry = $telemetryByKey->get($telemetryKey, [
                    'reminders_sent_count' => 0,
                    'reminders_failed_count' => 0,
                    'last_renewal_reminder_at' => null,
                    'wallet_auto_renew_state' => null,
                ]);

                $record = $client->toArray();
                if (is_array(data_get($record, 'platform'))) {
                    data_set(
                        $record,
                        'platform.billing_method_policy',
                        $this->marketBillingMethodPolicy->forPlatform($client->platform)
                    );
                }
                $inferredPlanType = null;
                $inferredProductName = null;
                $platformProductCatalog = $activeProductCatalogByPlatform->get((int) $client->platform_id, collect());

                if (!$client->deal_id) {
                    if ((bool) $client->featured) {
                        $inferredPlanType = 'vip';
                        $inferredProductName = 'VIP';
                    } elseif ((bool) $client->premium) {
                        $inferredPlanType = 'premium';
                        $inferredProductName = 'Premium';
                    } else {
                        $inferredPlanType = 'basic';
                        $inferredProductName = 'Basic';
                    }
                }

                $legacyEstimate = !$client->deal_id
                    ? $this->estimateLegacySubscription(
                        $inferredPlanType,
                        $platformProductCatalog,
                        (string) ($client->platform?->currency_code ?: 'KES')
                    )
                    : [
                        'amount' => null,
                        'duration' => null,
                        'currency' => null,
                    ];

                return array_merge($record, [
                    'id' => $client->deal_id ? (int) $client->deal_id : ('virtual_' . $client->id),
                    'client_id' => $client->id,
                    'client' => $record,
                    'is_virtual' => !$client->deal_id,
                    'is_untracked' => $isUntracked,
                    'has_wp_state_conflict' => $hasWpStateConflict,
                    'wp_profile_state_label' => $hasWpStateConflict ? 'WP state conflict' : null,
                    'wp_profile_state_detail' => $hasWpStateConflict
                        ? ((bool) $client->needs_payment
                            ? 'WordPress says this profile is public but also marked payment required.'
                            : 'WordPress says this profile is public but also awaiting admin activation.')
                        : null,
                    'can_deactivate_without_deal' => (bool) ($clientDeactivation['can_deactivate_without_deal'] ?? false),
                    'deactivation_scope' => $client->deal_id && in_array($status, ['active', 'expired'], true)
                        ? 'deal'
                        : ($clientDeactivation['deactivation_scope'] ?? null),
                    'deactivation_label' => $client->deal_id && in_array($status, ['active', 'expired'], true)
                        ? 'Deactivate'
                        : ($clientDeactivation['deactivation_label'] ?? null),
                    'deactivation_disabled_reason' => $clientDeactivation['deactivation_disabled_reason'] ?? null,
                    'origin_type' => $originType,
                    'payment_status' => $paymentStatus,
                    'plan_type' => $client->deal_plan_type ?? $inferredPlanType,
                    'duration' => $isUntracked ? null : ($client->deal_duration ?? $legacyEstimate['duration']),
                    'amount' => $isUntracked ? null : ($client->deal_amount ?? $legacyEstimate['amount']),
                    'currency' => $isUntracked
                        ? (string) ($client->platform?->currency_code ?: ($legacyEstimate['currency'] ?? 'KES'))
                        : ($client->deal_currency ?? $legacyEstimate['currency']),
                    'product' => $client->deal_id ? [
                        'id' => $client->deal_product_id,
                        'name' => $client->deal_product_name ?: $client->activeDeal?->product?->name,
                    ] : null,
                    'product_id' => $client->deal_product_id,
                    'inferred_plan_type' => $inferredPlanType,
                    'inferred_product_name' => $inferredProductName,
                    'amount_is_estimate' => !$isUntracked && !$client->deal_id && $client->deal_amount === null && $legacyEstimate['amount'] !== null,
                    'duration_is_estimate' => !$isUntracked && !$client->deal_id && $client->deal_duration === null && !empty($legacyEstimate['duration']),
                    'expires_at' => $expiryDate ? $expiryDate->toDateTimeString() : null,
                    'status' => $status,
                    'days_left' => $daysLeft,
                    'renewal_bucket' => $renewalBucket,
                    'reminders_sent_count' => (int) $telemetry['reminders_sent_count'],
                    'reminders_failed_count' => (int) $telemetry['reminders_failed_count'],
                    'last_renewal_reminder_at' => $telemetry['last_renewal_reminder_at'],
                    'wallet_auto_renew_state' => $telemetry['wallet_auto_renew_state'],
                    'reminders_paused' => $remindersPaused,
                    'renewal_paused_until' => $client->activeDeal ? optional($client->activeDeal->renewal_paused_until)->toDateTimeString() : null,
                    'renewal_pause_reason' => $client->activeDeal ? $client->activeDeal->renewal_pause_reason : null,
                    'cancellation_reason_code' => $client->deal_cancellation_reason_code,
                    'cancellation_notes' => $client->deal_cancellation_notes,
                    'cancelled_payment_id' => $client->deal_cancelled_payment_id,
                ]);
            })
        );

        $summaryBase = Client::query()
            ->leftJoin('deals', function ($join) {
                $join->on('clients.id', '=', 'deals.client_id')
                    ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
            })
            ->where(function ($q) use ($includeUntracked) {
                $q->whereNotNull('deals.id')
                    ->orWhereNotNull('clients.escort_expire')
                    ->orWhereNotNull('clients.premium_expire')
                    ->orWhereNotNull('clients.featured_expire')
                    ->orWhere('clients.profile_status', 'private');

                if ($includeUntracked) {
                    $q->orWhere(function ($untracked) {
                        $untracked->whereNull('deals.id')
                            ->where('clients.profile_status', 'publish')
                            ->whereNull('clients.escort_expire')
                            ->whereNull('clients.premium_expire')
                            ->whereNull('clients.featured_expire');
                    });
                }
            });

        if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
            $summaryBase->whereIn('clients.platform_id', $filters['platform_ids']);
        } elseif (!empty($filters['platform_id'])) {
            $summaryBase->where('clients.platform_id', (int) $filters['platform_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $summaryBase->where(function ($q) use ($search) {
                $q->where('clients.name', 'like', "%{$search}%")
                    ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['high_risk'])) {
            $summaryBase->where('clients.is_high_risk', true);
        }

        if (!empty($filters['cancellation_reason_code'])) {
            $summaryBase->where('deals.cancellation_reason_code', (string) $filters['cancellation_reason_code']);
        }

        $inScopeBase = clone $summaryBase;
        $this->applyBucketFilter($inScopeBase, 'all');

        $inScopeTotal = $inScopeBase
            ->select('clients.id')
            ->distinct()
            ->count('clients.id');

        $nowTs = now()->timestamp;
        $dateExpr = $this->expiryDateExpr();
        $summaryRow = (clone $summaryBase)
            ->selectRaw(
                "SUM(CASE WHEN (deals.status = 'active' OR (deals.id IS NULL AND {$dateExpr} >= ?)) THEN 1 ELSE 0 END) as active_deals,
                 SUM(CASE WHEN (deals.status = 'active' AND deals.id IS NOT NULL) THEN 1 ELSE 0 END) as modern_active_count,
                 SUM(CASE WHEN {$dateExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as risk,
                 SUM(CASE WHEN {$dateExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as pending,
                 SUM(CASE WHEN deals.renewal_reminders_paused = 1 THEN 1 ELSE 0 END) as paused_reminders,
                 SUM(CASE WHEN {$dateExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as expired_deals,
                 SUM(CASE WHEN deals.id IS NULL AND {$dateExpr} IS NULL AND clients.profile_status = 'publish' AND COALESCE(clients.needs_payment, 0) = 0 AND COALESCE(clients.notactive, 0) = 0 THEN 1 ELSE 0 END) as untracked_active,
                 SUM(CASE WHEN ({$dateExpr} < ? OR (deals.id IS NULL AND clients.profile_status = 'private' AND clients.escort_expire IS NULL AND clients.premium_expire IS NULL AND clients.featured_expire IS NULL)) THEN 1 ELSE 0 END) as lapsed_deals,
                 SUM(CASE WHEN deals.status IN ('pending','awaiting_payment','paid','active') THEN COALESCE(deals.amount, 0) ELSE 0 END) as pipeline_value,
                 SUM(CASE WHEN deals.status = 'active' AND deals.payment_id IS NOT NULL THEN COALESCE(deals.amount, 0) ELSE 0 END) as verified_revenue",
                [
                    $nowTs,
                    $nowTs,
                    $nowTs + (3 * 86400),
                    $nowTs + (4 * 86400),
                    $nowTs + (14 * 86400),
                    $nowTs - (14 * 86400),
                    $nowTs - 1,
                    $nowTs - (14 * 86400),
                ]
            )
            ->first();
        $summaryRow = $summaryRow ?: (object) [];

        $summary = [
            'in_scope_total' => (int) $inScopeTotal,
            'active_deals' => (int) ($summaryRow->active_deals ?? 0),
            'modern_active_count' => (int) ($summaryRow->modern_active_count ?? 0),
            'risk' => (int) ($summaryRow->risk ?? 0),
            'pending' => (int) ($summaryRow->pending ?? 0),
            'renewed_this_month' => (int) Deal::query()
                ->whereNotNull('activated_at')
                ->where('activated_at', '>=', now()->startOfMonth())
                ->when(
                    !empty($filters['platform_ids']) && is_array($filters['platform_ids']),
                    fn($q) => $q->whereIn('platform_id', $filters['platform_ids'])
                )
                ->when(
                    empty($filters['platform_ids']) && !empty($filters['platform_id']),
                    fn($q) => $q->where('platform_id', (int) $filters['platform_id'])
                )
                ->count(),
            'untracked_active' => (int) ($summaryRow->untracked_active ?? 0),
            'paused_reminders' => (int) ($summaryRow->paused_reminders ?? 0),
            'expired_deals' => (int) ($summaryRow->expired_deals ?? 0),
            'lapsed_deals' => (int) ($summaryRow->lapsed_deals ?? 0),
            'pipeline_value' => (float) ($summaryRow->pipeline_value ?? 0),
            'verified_revenue' => (float) ($summaryRow->verified_revenue ?? 0),
        ];

        $campaigns = RenewalCampaign::query()
            ->with('template:id,title,channel,status')
            ->orderBy('trigger_days')
            ->get();

        $recentRuns = RenewalRun::query()
            ->with(['campaign.template:id,title', 'runner:id,name'])
            ->when(
                $viewer && $viewer->role !== MarketAuthorizationService::ROLE_ADMIN,
                fn(Builder $builder) => $builder->where('run_by', $viewer->id)
            )
            ->orderByDesc('run_at')
            ->limit(10)
            ->get();

        return [
            'summary' => $summary,
            'targets' => $targets,
            'campaigns' => $campaigns,
            'recent_runs' => $recentRuns,
        ];
    }

    public function buildSummary(array $filters = []): array
    {
        $includeUntracked = (bool) ($filters['include_untracked'] ?? false);
        $summaryBase = Client::query()
            ->leftJoin('deals', function ($join) {
                $join->on('clients.id', '=', 'deals.client_id')
                    ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
            })
            ->where(function ($q) use ($includeUntracked) {
                $q->whereNotNull('deals.id')
                    ->orWhereNotNull('clients.escort_expire')
                    ->orWhereNotNull('clients.premium_expire')
                    ->orWhereNotNull('clients.featured_expire')
                    ->orWhere('clients.profile_status', 'private');

                if ($includeUntracked) {
                    $q->orWhere(function ($untracked) {
                        $untracked->whereNull('deals.id')
                            ->where('clients.profile_status', 'publish')
                            ->whereNull('clients.escort_expire')
                            ->whereNull('clients.premium_expire')
                            ->whereNull('clients.featured_expire');
                    });
                }
            });

        if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
            $summaryBase->whereIn('clients.platform_id', $filters['platform_ids']);
        } elseif (!empty($filters['platform_id'])) {
            $summaryBase->where('clients.platform_id', (int) $filters['platform_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $summaryBase->where(function ($q) use ($search) {
                $q->where('clients.name', 'like', "%{$search}%")
                    ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['high_risk'])) {
            $summaryBase->where('clients.is_high_risk', true);
        }

        if (!empty($filters['cancellation_reason_code'])) {
            $summaryBase->where('deals.cancellation_reason_code', (string) $filters['cancellation_reason_code']);
        }

        $inScopeBase = clone $summaryBase;
        $this->applyBucketFilter($inScopeBase, 'all');

        $inScopeTotal = $inScopeBase
            ->select('clients.id')
            ->distinct()
            ->count('clients.id');

        $nowTs = now()->timestamp;
        $dateExpr = $this->expiryDateExpr();
        $summaryRow = (clone $summaryBase)
            ->selectRaw(
                "SUM(CASE WHEN (deals.status = 'active' OR (deals.id IS NULL AND {$dateExpr} >= ?)) THEN 1 ELSE 0 END) as active_deals,
                 SUM(CASE WHEN (deals.status = 'active' AND deals.id IS NOT NULL) THEN 1 ELSE 0 END) as modern_active_count,
                 SUM(CASE WHEN {$dateExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as risk,
                 SUM(CASE WHEN {$dateExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as pending,
                 SUM(CASE WHEN deals.renewal_reminders_paused = 1 THEN 1 ELSE 0 END) as paused_reminders,
                 SUM(CASE WHEN {$dateExpr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as expired_deals,
                 SUM(CASE WHEN deals.id IS NULL AND {$dateExpr} IS NULL AND clients.profile_status = 'publish' AND COALESCE(clients.needs_payment, 0) = 0 AND COALESCE(clients.notactive, 0) = 0 THEN 1 ELSE 0 END) as untracked_active,
                 SUM(CASE WHEN ({$dateExpr} < ? OR (deals.id IS NULL AND clients.profile_status = 'private' AND clients.escort_expire IS NULL AND clients.premium_expire IS NULL AND clients.featured_expire IS NULL)) THEN 1 ELSE 0 END) as lapsed_deals,
                 SUM(CASE WHEN deals.status IN ('pending','awaiting_payment','paid','active') THEN COALESCE(deals.amount, 0) ELSE 0 END) as pipeline_value,
                 SUM(CASE WHEN deals.status = 'active' AND deals.payment_id IS NOT NULL THEN COALESCE(deals.amount, 0) ELSE 0 END) as verified_revenue",
                [
                    $nowTs,
                    $nowTs,
                    $nowTs + (3 * 86400),
                    $nowTs + (4 * 86400),
                    $nowTs + (14 * 86400),
                    $nowTs - (14 * 86400),
                    $nowTs - 1,
                    $nowTs - (14 * 86400),
                ]
            )
            ->first();

        $summaryRow = $summaryRow ?: (object) [];

        return [
            'in_scope_total' => (int) $inScopeTotal,
            'active_deals' => (int) ($summaryRow->active_deals ?? 0),
            'modern_active_count' => (int) ($summaryRow->modern_active_count ?? 0),
            'risk' => (int) ($summaryRow->risk ?? 0),
            'pending' => (int) ($summaryRow->pending ?? 0),
            'untracked_active' => (int) ($summaryRow->untracked_active ?? 0),
            'paused_reminders' => (int) ($summaryRow->paused_reminders ?? 0),
            'expired_deals' => (int) ($summaryRow->expired_deals ?? 0),
            'lapsed_deals' => (int) ($summaryRow->lapsed_deals ?? 0),
            'pipeline_value' => (float) ($summaryRow->pipeline_value ?? 0),
            'verified_revenue' => (float) ($summaryRow->verified_revenue ?? 0),
        ];
    }

    /**
     * Configuration payload for the per-market reminder cadence editor: the market's
     * own campaigns (if any), the global default set it would otherwise inherit,
     * the short-cycle guard flag, and the renewal templates available to pick from.
     */
    public function cadenceConfig(?int $platformId): array
    {
        $marketCampaigns = $platformId !== null
            ? RenewalCampaign::query()
                ->with('template:id,title,channel,status,platform_id')
                ->where('platform_id', $platformId)
                ->orderBy('trigger_days')
                ->get()
            : collect();

        $globalCampaigns = RenewalCampaign::query()
            ->with('template:id,title,channel,status,platform_id')
            ->whereNull('platform_id')
            ->orderBy('trigger_days')
            ->get();

        $hasOverride = $platformId !== null && $marketCampaigns->isNotEmpty();

        $templates = Template::query()
            ->where('category', 'renewal')
            ->where('status', 'active')
            ->when(
                $platformId !== null,
                fn(Builder $q) => $q->where(
                    fn(Builder $qq) => $qq->whereNull('platform_id')->orWhere('platform_id', $platformId)
                ),
                fn(Builder $q) => $q->whereNull('platform_id')
            )
            ->orderByDesc('id')
            ->get(['id', 'title', 'channel', 'platform_id']);

        $guardEnabled = true;
        if ($platformId !== null) {
            $flag = Platform::query()->whereKey($platformId)->value('renewal_reminder_guard_enabled');
            $guardEnabled = $flag === null ? true : (bool) $flag;
        }

        return [
            'platform_id' => $platformId,
            'has_market_override' => $hasOverride,
            'effective_source' => $hasOverride ? 'market' : 'global',
            'guard_enabled' => $guardEnabled,
            'market_campaigns' => $marketCampaigns->values(),
            'global_campaigns' => $globalCampaigns->values(),
            'effective_campaigns' => ($hasOverride ? $marketCampaigns : $globalCampaigns)->values(),
            'templates' => $templates,
        ];
    }

    private function estimateLegacySubscription(?string $planType, Collection $activeProductCatalog, string $fallbackCurrency = 'KES'): array
    {
        if (empty($planType)) {
            return [
                'amount' => null,
                'duration' => null,
                'currency' => $fallbackCurrency,
            ];
        }

        /** @var Product|null $product */
        $product = $activeProductCatalog->get(strtolower($planType));

        if (!$product) {
            return [
                'amount' => null,
                'duration' => 'monthly',
                'currency' => $fallbackCurrency,
            ];
        }

        return [
            'amount' => $product->monthly_price !== null ? (float) $product->monthly_price : null,
            'duration' => 'monthly',
            'currency' => $product->currency ?: $fallbackCurrency,
        ];
    }

    public function bulkRemind(array $selection, bool $selectAll = false, array $filters = [], ?int $templateId = null, ?int $actorId = null, bool $dryRun = false, string $channel = 'sms'): array
    {
        $channel = $this->normalizeMessageChannel($channel);
        $targets = [];

        if ($selectAll) {
            // Rebuild query with filters to get ALL matching targets
            $query = Client::query()
                ->leftJoin('deals', function ($join) {
                    $join->on('clients.id', '=', 'deals.client_id')
                        ->whereIn('deals.status', ['active', 'expired'])
                        ->whereRaw('deals.id = (SELECT id FROM deals d2 WHERE d2.client_id = clients.id ORDER BY d2.created_at DESC LIMIT 1)');
                })
                ->select(
                    'clients.id as client_id',
                    'deals.id as deal_id',
                    'deals.expires_at as deal_expires_at',
                    'clients.escort_expire',
                    'clients.premium_expire',
                    'clients.featured_expire'
                )
                ->where(function ($q) {
                    $q->whereNotNull('deals.id')
                        ->orWhereNotNull('clients.escort_expire')
                        ->orWhereNotNull('clients.premium_expire')
                        ->orWhereNotNull('clients.featured_expire')
                        ->orWhere('clients.profile_status', 'private');
                });

            if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
                $query->whereIn('clients.platform_id', $filters['platform_ids']);
            } elseif (!empty($filters['platform_id'])) {
                $query->where('clients.platform_id', (int) $filters['platform_id']);
            }

            if (!empty($filters['search'])) {
                $search = trim((string) $filters['search']);
                $query->where(function ($q) use ($search) {
                    $q->where('clients.name', 'like', "%{$search}%")
                        ->orWhere('clients.phone_normalized', 'like', "%{$search}%");
                });
            }

            if (!empty($filters['high_risk'])) {
                $query->where('clients.is_high_risk', true);
            }

            if (!empty($filters['cancellation_reason_code'])) {
                $query->where('deals.cancellation_reason_code', (string) $filters['cancellation_reason_code']);
            }

            if (!empty($filters['bucket'])) {
                $this->applyBucketFilter($query, (string) $filters['bucket']);
            } else {
                $this->applyBucketFilter($query, 'all');
            }

            if (!empty($filters['status'])) {
                $this->applyStatusFilter($query, (string) $filters['status']);
            }

            $targets = $query->get()->map(function ($row) {
                $expiryDate = $this->resolveExpiryDate(
                    $row->deal_expires_at,
                    $row->escort_expire,
                    $row->premium_expire,
                    $row->featured_expire
                );
                return [
                    'deal_id' => $row->deal_id,
                    'client_id' => $row->client_id,
                    'is_virtual' => !$row->deal_id,
                    'expires_at' => $expiryDate ? $expiryDate->toDateTimeString() : null,
                ];
            })->toArray();
        } else {
            $targets = $selection;
        }

        $sent = 0;
        $failed = 0;
        $errors = [];
        $preview = [];

        foreach ($targets as $target) {
            try {
                if (!empty($target['deal_id'])) {
                    $deal = Deal::query()->with('client.platform')->findOrFail((int) $target['deal_id']);
                } else {
                    $client = Client::query()->with('platform')->findOrFail((int) $target['client_id']);
                    $deal = new Deal();
                    $deal->client_id = $client->id;
                    $deal->platform_id = $client->platform_id;
                    $deal->client = $client;
                    $deal->expires_at = $target['expires_at']
                        ?? $this->resolveExpiryDate(null, $client->escort_expire, $client->premium_expire, $client->featured_expire);
                }

                if ($dryRun) {
                    $paused = $this->isReminderPaused($deal);
                    $template = $templateId
                        ? Template::query()->where('id', $templateId)->where('channel', $channel)->first()
                        : $this->resolveDefaultRenewalTemplate($deal, $channel);
                    $hasClient = $deal->client !== null;
                    $canSend = $hasClient && $template !== null && !$paused;
                    $skipReason = $paused ? 'paused' : (!$hasClient ? 'no_client' : ($template === null ? 'no_template' : null));

                    $preview[] = [
                        'deal_id' => $deal->id ?: null,
                        'client_id' => $deal->client_id,
                        'client_name' => $deal->client->name ?? 'Unknown',
                        'phone' => $deal->client->phone_normalized ?? null,
                        'can_send' => $canSend,
                        'skip_reason' => $skipReason,
                    ];
                    continue;
                }

                $res = $this->sendManualReminder($deal, $templateId, $actorId, $channel);
                if (!empty($res['success'])) {
                    $sent++;
                } else {
                    $failed++;
                    $errors[] = [
                        'deal_id' => $target['deal_id'] ?? null,
                        'client_id' => $target['client_id'] ?? $deal->client_id ?? null,
                        'reason' => $res['reason'] ?? 'Unknown failure',
                    ];
                }
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'deal_id' => $target['deal_id'] ?? null,
                    'client_id' => $target['client_id'] ?? null,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        if ($dryRun) {
            $sendable = count(array_filter($preview, fn($p) => $p['can_send']));
            return [
                'dry_run' => true,
                'total' => count($preview),
                'sendable' => $sendable,
                'skipped' => count($preview) - $sendable,
                'preview' => array_slice($preview, 0, 50),
            ];
        }

        return [
            'total' => count($targets),
            'success' => $sent,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 25),
        ];
    }

    public function runCampaigns($campaignIds = null, ?int $actorId = null, ?array $platformIds = null, array $options = []): array
    {
        if (is_int($campaignIds)) {
            $campaignIds = [$campaignIds];
        } elseif (!is_array($campaignIds)) {
            $campaignIds = null;
        }

        $channel = trim(strtolower((string) ($options['channel'] ?? '')));

        // Resolve which campaigns run against which markets. Each market runs its own
        // cadence when it has campaigns of its own, otherwise the global default set.
        $runPlan = $this->buildCampaignRunPlan($campaignIds, $platformIds, $channel, $options);

        if (empty($runPlan)) {
            return [
                'campaigns' => [],
                'totals' => ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0],
                'dry_run' => (bool) ($options['dry_run'] ?? false),
            ];
        }

        $results = [];
        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0];

        foreach ($runPlan as $planItem) {
            $result = $this->runSingleCampaign($planItem['campaign'], $actorId, $planItem['platform_ids'], $options);
            $results[] = $result;

            $totals['sent'] += $result['sent_count'];
            $totals['failed'] += $result['failed_count'];
            $totals['skipped'] += $result['skipped_count'];
            $totals['targeted'] += $result['total_targeted'];
        }

        return [
            'campaigns' => $results,
            'totals' => $totals,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ];
    }

    /**
     * Build the list of (campaign, platform scope) pairs to execute.
     *
     * - When specific campaign IDs or a manual target list are supplied, those exact
     *   campaigns run against the requested market scope (operator-driven path).
     * - Otherwise this is an automated full run: each market executes its own
     *   campaigns when it has any, and every other market falls back to the global
     *   default campaigns (platform_id = NULL). Global campaigns run once against the
     *   grouped set of fallback markets to keep the query count low.
     *
     * @return array<int, array{campaign: RenewalCampaign, platform_ids: ?array<int, int>}>
     */
    private function buildCampaignRunPlan(?array $campaignIds, ?array $platformIds, string $channel, array $options): array
    {
        $applyChannel = fn(Builder $builder) => $builder->when(
            $channel !== '',
            fn(Builder $q) => $q->whereHas('template', fn(Builder $t) => $t->where('channel', $channel))
        );

        // Operator-driven path: explicit campaign selection or a manual target list.
        if ((is_array($campaignIds) && !empty($campaignIds)) || !empty($options['targets'])) {
            $campaigns = $applyChannel(
                RenewalCampaign::query()
                    ->with('template')
                    ->where('enabled', true)
                    ->when(
                        is_array($campaignIds) && !empty($campaignIds),
                        fn(Builder $builder) => $builder->whereIn('id', $campaignIds)
                    )
            )
                ->orderBy('trigger_days')
                ->get();

            return $campaigns->map(fn(RenewalCampaign $campaign) => [
                'campaign' => $campaign,
                'platform_ids' => $platformIds,
            ])->all();
        }

        $allCampaigns = $applyChannel(
            RenewalCampaign::query()
                ->with('template')
                ->where('enabled', true)
        )
            ->orderBy('trigger_days')
            ->get();

        $globalCampaigns = $allCampaigns->whereNull('platform_id')->values();
        $marketCampaigns = $allCampaigns->whereNotNull('platform_id')->groupBy('platform_id');

        // No market has its own cadence yet -> preserve the exact legacy behaviour of
        // running the global set across the requested scope in a single pass.
        if ($marketCampaigns->isEmpty()) {
            return $globalCampaigns->map(fn(RenewalCampaign $campaign) => [
                'campaign' => $campaign,
                'platform_ids' => $platformIds,
            ])->all();
        }

        $scopePlatformIds = is_array($platformIds)
            ? array_values(array_unique(array_map('intval', $platformIds)))
            : Platform::query()->pluck('id')->map(fn($id) => (int) $id)->all();

        $plan = [];
        $marketsWithOwn = [];

        foreach ($marketCampaigns as $platformId => $campaigns) {
            $platformId = (int) $platformId;
            if (!in_array($platformId, $scopePlatformIds, true)) {
                continue;
            }
            $marketsWithOwn[] = $platformId;
            foreach ($campaigns as $campaign) {
                $plan[] = ['campaign' => $campaign, 'platform_ids' => [$platformId]];
            }
        }

        $marketsUsingGlobal = array_values(array_diff($scopePlatformIds, $marketsWithOwn));
        if (!empty($marketsUsingGlobal) && $globalCampaigns->isNotEmpty()) {
            foreach ($globalCampaigns as $campaign) {
                $plan[] = ['campaign' => $campaign, 'platform_ids' => $marketsUsingGlobal];
            }
        }

        return $plan;
    }

    public function runAutomatedRenewals(?int $actorId = null, ?array $platformIds = null, array $options = []): array
    {
        $deals = !empty($options['targets']) && is_array($options['targets'])
            ? $this->targetWalletAutoRenewDealsFromTargets(collect($options['targets']), $platformIds)
            : $this->targetDealsForWalletAutoRenew($platformIds);

        if ($deals->isEmpty()) {
            return [
                'total_targeted' => 0,
                'attempted_count' => 0,
                'succeeded_count' => 0,
                'failed_count' => 0,
                'fallback_count' => 0,
                'escalated_count' => 0,
                'skipped_count' => 0,
                'results' => [],
            ];
        }

        $results = [];
        $totals = [
            'total_targeted' => $deals->count(),
            'attempted_count' => 0,
            'succeeded_count' => 0,
            'failed_count' => 0,
            'fallback_count' => 0,
            'escalated_count' => 0,
            'skipped_count' => 0,
        ];

        foreach ($deals as $deal) {
            $outcome = $this->handleWalletAutoRenewDeal($deal, $actorId);
            $results[] = $outcome;

            if (!empty($outcome['attempted_charge'])) {
                $totals['attempted_count']++;
            }

            $bucket = match ($outcome['status'] ?? 'skipped') {
                'succeeded' => 'succeeded_count',
                'failed' => 'failed_count',
                'fallback_sent' => 'fallback_count',
                'escalated' => 'escalated_count',
                default => 'skipped_count',
            };

            $totals[$bucket]++;
        }

        return array_merge($totals, [
            'results' => $results,
        ]);
    }

    public function sendManualReminder(Deal $deal, ?int $templateId = null, ?int $actorId = null, string $channel = 'sms'): array
    {
        $deal->loadMissing(['client.platform', 'product']);
        $channel = $this->normalizeMessageChannel($channel);
        $channelLabel = $this->channelLabel($channel);

        if ($this->isReminderPaused($deal)) {
            $resumeOn = $deal->renewal_paused_until ? Carbon::parse($deal->renewal_paused_until)->toDateTimeString() : 'manual resume';

            return [
                'success' => false,
                'status' => 'paused',
                'reason' => 'Renewal reminders are paused for this subscription until ' . $resumeOn . '.',
            ];
        }

        if (!$deal->client) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'Deal does not have a linked client.',
            ];
        }

        $template = $templateId
            ? Template::query()->where('id', $templateId)->where('channel', $channel)->first()
            : $this->resolveDefaultRenewalTemplate($deal, $channel);

        if (!$template) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => "No {$channelLabel} template available for this reminder.",
            ];
        }

        $variables = $this->renewalTemplateVariables($deal, $template, [
            'trigger_days' => $this->suggestTriggerDays($deal),
        ], $actorId);

        $rendered = $this->templateService->renderTemplate($template, $variables);
        $rendered['body'] = rtrim((string) $rendered['body']);
        if (!empty($rendered['missing'])) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'Template rendering missing variables: ' . implode(', ', $rendered['missing']),
                'missing' => $rendered['missing'],
            ];
        }

        $delivery = $this->dispatchRenewalMessage($deal, $rendered['body'], $channel, [
            'phone_prefix' => optional($deal->client->platform)->phone_prefix ?? '254',
            'template_id' => $template->id,
            'deal_id' => $deal->id,
            'actor_id' => $actorId,
            'mode' => 'manual',
        ]);

        $eventType = $this->renewalEventType($channel, (bool) $delivery['success']);
        $notePrefix = $delivery['success'] ? "[Renewal {$channelLabel}]" : "[Renewal {$channelLabel} Failed]";

        DB::transaction(function () use ($deal, $template, $delivery, $eventType, $notePrefix, $rendered, $actorId, $channel) {
            ClientNote::create([
                'client_id' => $deal->client_id,
                'author_id' => $this->resolveActorId($actorId),
                'note_type' => 'system',
                'content' => sprintf(
                    '%s Template #%d: %s',
                    $notePrefix,
                    $template->id,
                    $rendered['body']
                ),
                'follow_up_at' => null,
                'created_at' => now(),
            ]);

            TimelineEvent::create([
                'platform_id' => $deal->platform_id,
                'entity_type' => $deal->id ? 'deal' : 'client',
                'entity_id' => $deal->id ?: $deal->client_id,
                'event_type' => $eventType,
                'actor_id' => $actorId,
                'content' => [
                    'template_id' => $template->id,
                    'channel' => $channel,
                    'delivery_status' => $delivery['status'],
                    'provider_response' => $delivery['provider_response'] ?? null,
                    'whatsapp_message_id' => $delivery['whatsapp_message_id'] ?? null,
                    'manual' => true,
                ],
                'created_at' => now(),
            ]);

            $this->auditService->record([
                'platform_id' => $deal->platform_id,
                'actor_id' => $actorId,
                'action' => $eventType,
                'entity_type' => $deal->id ? 'deal' : 'client',
                'entity_id' => $deal->id ?: $deal->client_id,
                'before_state' => null,
                'after_state' => [
                    'template_id' => $template->id,
                    'channel' => $channel,
                    'delivery_status' => $delivery['status'],
                    'whatsapp_message_id' => $delivery['whatsapp_message_id'] ?? null,
                ],
                'reason' => 'Manual renewal reminder',
            ]);
        });

        return array_merge([
            'success' => (bool) $delivery['success'],
            'status' => $delivery['status'],
            'template_id' => $template->id,
            'message' => $rendered['body'],
        ], $delivery);
    }

    public function pauseReminders(Deal $deal, string $reason, ?int $actorId = null, ?string $pauseUntil = null): array
    {
        $pauseUntilDate = null;
        if ($pauseUntil) {
            $pauseUntilDate = Carbon::parse($pauseUntil)->endOfDay();
        }

        $beforeState = [
            'renewal_reminders_paused' => (bool) $deal->renewal_reminders_paused,
            'renewal_paused_until' => optional($deal->renewal_paused_until)->toDateTimeString(),
            'renewal_pause_reason' => $deal->renewal_pause_reason,
        ];

        $deal->update([
            'renewal_reminders_paused' => true,
            'renewal_paused_until' => $pauseUntilDate,
            'renewal_pause_reason' => $reason,
        ]);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_reminders_paused',
            'actor_id' => $actorId,
            'content' => [
                'reason' => $reason,
                'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
            ],
            'created_at' => now(),
        ]);

        $this->auditService->record([
            'platform_id' => $deal->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::RENEWAL_PAUSE,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'before_state' => $beforeState,
            'after_state' => [
                'renewal_reminders_paused' => true,
                'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
                'renewal_pause_reason' => $reason,
            ],
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'status' => 'paused',
            'deal_id' => $deal->id,
            'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
        ];
    }

    public function resumeReminders(Deal $deal, string $reason, ?int $actorId = null): array
    {
        $beforeState = [
            'renewal_reminders_paused' => (bool) $deal->renewal_reminders_paused,
            'renewal_paused_until' => optional($deal->renewal_paused_until)->toDateTimeString(),
            'renewal_pause_reason' => $deal->renewal_pause_reason,
        ];

        $deal->update([
            'renewal_reminders_paused' => false,
            'renewal_paused_until' => null,
            'renewal_pause_reason' => null,
        ]);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_reminders_resumed',
            'actor_id' => $actorId,
            'content' => [
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->record([
            'platform_id' => $deal->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::RENEWAL_RESUME,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'before_state' => $beforeState,
            'after_state' => [
                'renewal_reminders_paused' => false,
                'renewal_paused_until' => null,
                'renewal_pause_reason' => null,
            ],
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'status' => 'active',
            'deal_id' => $deal->id,
        ];
    }

    private function runSingleCampaign(RenewalCampaign $campaign, ?int $actorId, ?array $platformIds = null, array $options = []): array
    {
        $campaign->loadMissing('template');
        $channel = $this->normalizeMessageChannel((string) ($campaign->template?->channel ?: $campaign->channel ?: 'sms'));
        $channelLabel = $this->channelLabel($channel);

        $deals = !empty($options['targets']) && is_array($options['targets'])
            ? $this->targetDealsFromOverviewTargets(collect($options['targets']), $platformIds)
            : $this->targetDealsForCampaign($campaign, $platformIds);

        $dryRun = (bool) ($options['dry_run'] ?? false);
        if ($dryRun) {
            $preview = $deals->map(function ($deal) use ($campaign) {
                $client = $deal->client ?? null;
                $expiresAt = $deal->expires_at;
                if ($expiresAt instanceof Carbon) {
                    $expiresAt = $expiresAt->toDateTimeString();
                } elseif (is_numeric($expiresAt)) {
                    $expiresAt = Carbon::createFromTimestamp((int) $expiresAt)->toDateTimeString();
                } elseif ($expiresAt) {
                    $expiresAt = Carbon::parse($expiresAt)->toDateTimeString();
                } else {
                    $expiresAt = null;
                }

                $suppressed = $this->shouldSuppressForShortCycle($deal, $campaign);

                return [
                    'deal_id' => $deal->id ?: null,
                    'client_id' => $deal->client_id ?: ($client?->id),
                    'client_name' => $client?->name,
                    'phone' => $client?->phone_normalized,
                    'platform_id' => $deal->platform_id ?: ($client?->platform_id),
                    'is_virtual' => empty($deal->id),
                    'expires_at' => $expiresAt,
                    'cycle_days' => $this->cycleLengthDays($deal),
                    'suppressed' => $suppressed,
                    'suppressed_reason' => $suppressed ? 'short_cycle_guard' : null,
                ];
            })->values();

            $suppressedCount = $preview->where('suppressed', true)->count();

            return [
                'campaign_id' => $campaign->id,
                'trigger_days' => $campaign->trigger_days,
                'run_id' => null,
                'total_targeted' => $deals->count(),
                'sent_count' => 0,
                'failed_count' => 0,
                'skipped_count' => $suppressedCount,
                'suppressed_count' => $suppressedCount,
                'status' => 'dry_run',
                'targets_preview' => $preview->take(100)->all(),
            ];
        }

        $runnerId = $this->resolveActorId($actorId);
        $runCurrencies = $deals->map(function ($deal) {
            $currency = strtoupper(trim((string) ($deal->currency ?? $deal->deal_currency ?? '')));
            return $currency !== '' ? $currency : null;
        })->filter()->unique()->values();

        $run = RenewalRun::create([
            'campaign_id' => $campaign->id,
            'run_at' => now(),
            'total_targeted' => $deals->count(),
            'sent_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'run_by' => $runnerId,
            'status' => 'completed',
            'currency' => $runCurrencies->count() === 1 ? $runCurrencies->first() : null,
            'created_at' => now(),
        ]);

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($deals as $deal) {
            $entityType = $deal->id ? 'deal' : 'client';
            $entityId = (int) ($deal->id ?: $deal->client_id);
            if ($entityId <= 0) {
                $failed++;
                continue;
            }

            if ($this->alreadyAttemptedToday($entityType, $entityId, $campaign->id)) {
                $skipped++;
                continue;
            }

            // Short-cycle guard: never fire a pre-expiry reminder whose lead time is
            // longer than the client's own subscription (e.g. a -7d reminder on a
            // 7-day plan). Suppressed targets are counted as skipped, not sent.
            if ($this->shouldSuppressForShortCycle($deal, $campaign)) {
                $skipped++;
                continue;
            }

            $deal->loadMissing(['client.platform', 'product']);
            if (!$deal->client || !$campaign->template) {
                $failed++;
                $this->writeRenewalTimeline($deal, $campaign, $run, false, 'Missing client or template', $channel);
                continue;
            }

            $variables = $this->renewalTemplateVariables($deal, $campaign->template, [
                'trigger_days' => $campaign->trigger_days,
            ], $runnerId);
            $rendered = $this->templateService->renderTemplate($campaign->template, $variables);
            // A market that can't carry a link renders {{payment_link}} as '' —
            // trim so the copy doesn't end on a dangling space.
            $rendered['body'] = rtrim((string) $rendered['body']);

            if (!empty($rendered['missing'])) {
                $failed++;
                $this->writeRenewalTimeline(
                    $deal,
                    $campaign,
                    $run,
                    false,
                    'Missing variables: ' . implode(', ', $rendered['missing']),
                    $channel
                );
                continue;
            }

            $delivery = $this->dispatchRenewalMessage($deal, $rendered['body'], $channel, [
                'phone_prefix' => optional($deal->client->platform)->phone_prefix ?? '254',
                'campaign_id' => $campaign->id,
                'run_id' => $run->id,
                'template_id' => $campaign->template_id,
                'actor_id' => $runnerId,
            ]);

            if ($delivery['success']) {
                $sent++;
            } elseif (($delivery['status'] ?? null) === 'suppressed') {
                $skipped++;
            } else {
                $failed++;
            }

            DB::transaction(function () use ($deal, $campaign, $run, $rendered, $delivery, $runnerId, $channel, $channelLabel) {
                $notePrefix = $delivery['success'] ? '[RC' . $campaign->id . "] Renewal {$channelLabel}" : '[RC' . $campaign->id . "] Renewal {$channelLabel} Failed";

                ClientNote::create([
                    'client_id' => $deal->client_id,
                    'author_id' => $runnerId,
                    'note_type' => 'system',
                    'content' => sprintf('%s: %s', $notePrefix, $rendered['body']),
                    'follow_up_at' => null,
                    'created_at' => now(),
                ]);

                $this->writeRenewalTimeline(
                    $deal,
                    $campaign,
                    $run,
                    (bool) $delivery['success'],
                    $delivery['provider_response'] ?? null,
                    $channel
                );

                $this->auditService->record([
                    'platform_id' => $deal->platform_id,
                    'actor_id' => $runnerId,
                    'action' => $this->renewalEventType($channel, (bool) $delivery['success']),
                    'entity_type' => $deal->id ? 'deal' : 'client',
                    'entity_id' => $deal->id ?: $deal->client_id,
                    'after_state' => [
                        'campaign_id' => $campaign->id,
                        'run_id' => $run->id,
                        'channel' => $channel,
                        'delivery_status' => $delivery['status'],
                        'whatsapp_message_id' => $delivery['whatsapp_message_id'] ?? null,
                    ],
                    'reason' => 'Automated renewal campaign',
                ]);
            });
        }

        $status = 'completed';
        if ($failed > 0 && $sent === 0) {
            $status = 'failed';
        } elseif ($failed > 0 || $skipped > 0) {
            $status = 'partial';
        }

        $run->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
        ]);

        return [
            'campaign_id' => $campaign->id,
            'trigger_days' => $campaign->trigger_days,
            'run_id' => $run->id,
            'total_targeted' => $run->total_targeted,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
        ];
    }

    private function targetDealsFromOverviewTargets(Collection $targets, ?array $platformIds = null): Collection
    {
        if ($targets->isEmpty()) {
            return collect();
        }

        $normalizedTargets = $targets
            ->filter(fn($row) => is_array($row))
            ->reject(fn($row) => (!empty($row['is_untracked'])) || (($row['status'] ?? null) === 'untracked'))
            ->values();

        $dealIds = $normalizedTargets
            ->filter(fn($row) => empty($row['is_virtual']) && !empty($row['id']) && is_numeric($row['id']))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values();
        $clientIds = $normalizedTargets
            ->filter(fn($row) => !empty($row['is_virtual']) && !empty($row['client_id']))
            ->pluck('client_id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values();

        $dealsById = $dealIds->isEmpty()
            ? collect()
            : Deal::query()
                ->with(['client.platform', 'product'])
                ->when(
                    is_array($platformIds),
                    fn(Builder $builder) => $builder->whereIn('platform_id', $platformIds)
                )
                ->whereIn('id', $dealIds->all())
                ->get()
                ->keyBy('id');

        $clientsById = $clientIds->isEmpty()
            ? collect()
            : Client::query()
                ->with('platform')
                ->when(
                    is_array($platformIds),
                    fn(Builder $builder) => $builder->whereIn('platform_id', $platformIds)
                )
                ->whereIn('id', $clientIds->all())
                ->get()
                ->keyBy('id');

        return $normalizedTargets->map(function (array $row) use ($dealsById, $clientsById) {
            if (!empty($row['is_virtual'])) {
                $clientId = (int) ($row['client_id'] ?? 0);
                $client = $clientsById->get($clientId);
                if (!$client) {
                    return null;
                }

                $virtualDeal = new Deal();
                $virtualDeal->id = null;
                $virtualDeal->client_id = (int) $client->id;
                $virtualDeal->platform_id = (int) $client->platform_id;
                $virtualDeal->client = $client;
                $virtualDeal->product = null;
                $virtualDeal->expires_at = $row['expires_at']
                    ?? $this->resolveExpiryDate(null, $client->escort_expire, $client->premium_expire, $client->featured_expire);
                return $virtualDeal;
            }

            $dealId = (int) ($row['id'] ?? 0);
            return $dealId > 0 ? $dealsById->get($dealId) : null;
        })->filter()->values();
    }

    private function targetDealsForCampaign(RenewalCampaign $campaign, ?array $platformIds = null): Collection
    {
        $targetDate = now()->startOfDay()->addDays($campaign->trigger_days * -1);

        // 1. Target real Deals
        $deals = Deal::query()
            ->whereIn('status', ['active', 'expired'])
            ->whereDate('expires_at', $targetDate->toDateString())
            ->where(function (Builder $builder) {
                $builder->where('renewal_reminders_paused', false)
                    ->orWhere(function (Builder $pausedBuilder) {
                        $pausedBuilder->where('renewal_reminders_paused', true)
                            ->whereNotNull('renewal_paused_until')
                            ->where('renewal_paused_until', '<', now());
                    });
            })
            // Client-level outreach pause suppresses renewals too.
            ->whereDoesntHave('client', fn (Builder $builder) => $builder
                ->whereNotNull('reminders_paused_until')
                ->where('reminders_paused_until', '>', now()))
            ->when(
                is_array($platformIds),
                fn(Builder $builder) => $builder->whereIn('platform_id', $platformIds)
            )
            ->when($campaign->product_id, fn(Builder $builder) => $builder->where('product_id', $campaign->product_id))
            ->with(['client.platform', 'product'])
            ->get();

        // 2. Target virtual renewals from legacy expiry signals when no active/expired deal exists.
        // Note: Filters on product_id are ignored for virtual renewals as they have no linked product.
        $virtuals = Client::query()
            ->whereBetween(DB::raw('COALESCE(clients.escort_expire, clients.premium_expire, clients.featured_expire)'), [
                $targetDate->copy()->startOfDay()->timestamp,
                $targetDate->copy()->endOfDay()->timestamp,
            ])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('deals')
                    ->whereColumn('deals.client_id', 'clients.id')
                    ->whereIn('deals.status', ['active', 'expired']);
            })
            ->where(function (Builder $builder) {
                $builder->whereNull('reminders_paused_until')
                    ->orWhere('reminders_paused_until', '<', now());
            })
            ->when(
                is_array($platformIds),
                fn(Builder $builder) => $builder->whereIn('platform_id', $platformIds)
            )
            ->with(['platform'])
            ->get()
            ->map(function ($client) {
                $virtualDeal = new Deal();
                $virtualDeal->id = null;
                $virtualDeal->client_id = (int) $client->id;
                $virtualDeal->platform_id = (int) $client->platform_id;
                $virtualDeal->setRelation('client', $client);
                $virtualDeal->setRelation('product', null);
                $virtualDeal->expires_at = $this->resolveExpiryDate(null, $client->escort_expire, $client->premium_expire, $client->featured_expire);
                return $virtualDeal;
            });

        return $deals->concat($virtuals);
    }

    private function buildTelemetryMap(Collection $dealIds, Collection $clientIds): Collection
    {
        if ($dealIds->isEmpty() && $clientIds->isEmpty()) {
            return collect();
        }

        $rows = TimelineEvent::query()
            ->whereIn('event_type', ['renewal_sms_sent', 'renewal_sms_failed', 'renewal_whatsapp_sent', 'renewal_whatsapp_failed'])
            ->where(function ($query) use ($dealIds, $clientIds) {
                if ($dealIds->isNotEmpty()) {
                    $query->where(function ($dealScope) use ($dealIds) {
                        $dealScope->where('entity_type', 'deal')
                            ->whereIn('entity_id', $dealIds);
                    });
                }

                if ($clientIds->isNotEmpty()) {
                    $query->orWhere(function ($clientScope) use ($clientIds) {
                        $clientScope->where('entity_type', 'client')
                            ->whereIn('entity_id', $clientIds);
                    });
                }
            })
            ->get(['id', 'entity_type', 'entity_id', 'event_type', 'content', 'created_at']);

        $walletRows = TimelineEvent::query()
            ->whereIn('event_type', [
                'wallet_auto_renew_attempted',
                'wallet_auto_renew_succeeded',
                'wallet_auto_renew_failed',
                'wallet_auto_renew_fallback_sent',
                'wallet_auto_renew_escalated',
            ])
            ->where(function ($query) use ($dealIds, $clientIds) {
                if ($dealIds->isNotEmpty()) {
                    $query->where(function ($dealScope) use ($dealIds) {
                        $dealScope->where('entity_type', 'deal')
                            ->whereIn('entity_id', $dealIds);
                    });
                }

                if ($clientIds->isNotEmpty()) {
                    $query->orWhere(function ($clientScope) use ($clientIds) {
                        $clientScope->where('entity_type', 'client')
                            ->whereIn('entity_id', $clientIds);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'entity_type', 'entity_id', 'event_type', 'content', 'created_at']);

        $groupedRows = $rows->groupBy(fn ($row) => $row->entity_type . '_' . $row->entity_id);
        $walletStates = $walletRows
            ->groupBy(fn ($row) => $row->entity_type . '_' . $row->entity_id)
            ->map(fn (Collection $events) => $this->serializeWalletAutoRenewState($events->first()));

        return $groupedRows
            ->mapWithKeys(function (Collection $events, string $key) use ($walletStates) {
                $lastReminder = $events
                    ->sortByDesc(fn ($event) => optional($event->created_at)->getTimestamp() ?? 0)
                    ->first();

                return [
                    $key => [
                        'reminders_sent_count' => $events->whereIn('event_type', ['renewal_sms_sent', 'renewal_whatsapp_sent'])->count(),
                        'reminders_failed_count' => $events->whereIn('event_type', ['renewal_sms_failed', 'renewal_whatsapp_failed'])->count(),
                        'last_renewal_reminder_at' => optional($lastReminder?->created_at)->toDateTimeString(),
                        'wallet_auto_renew_state' => $walletStates->get($key),
                    ],
                ];
            })
            ->union(
                $walletStates
                    ->reject(fn ($state, $key) => $groupedRows->has($key))
                    ->mapWithKeys(fn ($state, $key) => [
                        $key => [
                            'reminders_sent_count' => 0,
                            'reminders_failed_count' => 0,
                            'last_renewal_reminder_at' => null,
                            'wallet_auto_renew_state' => $state,
                        ],
                    ])
            );
    }

    private function resolveExpiryDate($dealExpiry, $clientExpiry, $premiumExpiry = null, $featuredExpiry = null): ?Carbon
    {
        if ($dealExpiry) {
            return $dealExpiry instanceof Carbon ? $dealExpiry : Carbon::parse($dealExpiry);
        }

        $candidates = [
            $this->toUnixTimestamp($clientExpiry),
            $this->toUnixTimestamp($premiumExpiry),
            $this->toUnixTimestamp($featuredExpiry),
        ];
        $candidates = array_values(array_filter($candidates, static fn($value) => $value !== null));

        $ts = !empty($candidates) ? max($candidates) : null;
        if ($ts === null) {
            return null;
        }

        return Carbon::createFromTimestamp($ts);
    }

    private function toUnixTimestamp($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $parsed = strtotime((string) $value);

        return $parsed === false ? null : (int) $parsed;
    }

    private function applyBucketFilter(Builder|\Illuminate\Database\Query\Builder $query, string $bucket): void
    {
        $nowTs = now()->timestamp;
        $dateExpr = $this->expiryDateExpr();
        $bucket = trim(strtolower($bucket));

        if ($bucket === '' || $bucket === 'all') {
            // Exclude long-lapsed records by default, while keeping pending modern subscriptions.
            $query->where(function ($builder) use ($dateExpr, $nowTs) {
                $builder->where(function ($dateScoped) use ($dateExpr, $nowTs) {
                    $dateScoped->where(DB::raw($dateExpr), '>=', $nowTs - (14 * 86400))
                        ->orWhereRaw("{$dateExpr} IS NULL");
                })->where(function ($lapsedGuard) {
                    $lapsedGuard->whereNotNull('deals.id')
                        ->orWhere('clients.profile_status', '!=', 'private')
                        ->orWhereNotNull('clients.escort_expire')
                        ->orWhereNotNull('clients.premium_expire')
                        ->orWhereNotNull('clients.featured_expire');
                });
            });
            return;
        }

        if ($bucket === 'active') {
            $query->where(DB::raw($dateExpr), '>=', $nowTs);
            return;
        }

        if ($bucket === 'paused') {
            $query->where('deals.renewal_reminders_paused', true)
                ->where(function (Builder $builder) {
                    $builder->whereNull('deals.renewal_paused_until')
                        ->orWhere('deals.renewal_paused_until', '>=', now());
                });
            return;
        }

        if ($bucket === 'untracked') {
            $query->whereNull('deals.id')
                ->where('clients.profile_status', 'publish')
                ->where(function ($builder) {
                    $builder->whereNull('clients.needs_payment')->orWhere('clients.needs_payment', false);
                })
                ->where(function ($builder) {
                    $builder->whereNull('clients.notactive')->orWhere('clients.notactive', false);
                })
                ->whereRaw("{$dateExpr} IS NULL");
            return;
        }

        if ($bucket === 'risk') {
            $query->whereBetween(DB::raw($dateExpr), [$nowTs, $nowTs + (3 * 86400)]);
            return;
        }

        if ($bucket === 'pending') {
            $query->whereBetween(DB::raw($dateExpr), [$nowTs + (4 * 86400), $nowTs + (14 * 86400)]);
            return;
        }

        if ($bucket === 'workload') {
            $query->whereBetween(DB::raw($dateExpr), [$nowTs, $nowTs + (14 * 86400)]);
            return;
        }

        if ($bucket === 'stable') {
            $query->where(DB::raw($dateExpr), '>', $nowTs + (14 * 86400));
            return;
        }

        if ($bucket === 'expired') {
            $query->whereBetween(DB::raw($dateExpr), [$nowTs - (14 * 86400), $nowTs - 1]);
            return;
        }

        if ($bucket === 'lapsed') {
            $query->where(function ($q) use ($nowTs, $dateExpr) {
                $q->where(DB::raw($dateExpr), '<', $nowTs - (14 * 86400))
                    ->orWhere(function ($sq) {
                        $sq->whereNull('deals.id')
                            ->where('clients.profile_status', 'private')
                            ->whereNull('clients.escort_expire')
                            ->whereNull('clients.premium_expire')
                            ->whereNull('clients.featured_expire');
                    });
            });
            return;
        }

        if ($bucket === 'mpesa_history') {
            $query->where('deals.origin', 'mpesa_import');
        }
    }

    private function applyStatusFilter(Builder|\Illuminate\Database\Query\Builder $query, string $status): void
    {
        $status = trim(strtolower($status));
        if ($status === '') {
            return;
        }

        $nowTs = now()->timestamp;
        $dateExpr = $this->expiryDateExpr();

        if ($status === 'active') {
            $query->where(function ($builder) use ($dateExpr, $nowTs) {
                $builder->where('deals.status', 'active')
                    ->orWhere(function ($virtual) use ($dateExpr, $nowTs) {
                        $virtual->whereNull('deals.id')
                            ->where(DB::raw($dateExpr), '>=', $nowTs);
                    });
            });
            return;
        }

        if ($status === 'expired') {
            $query->where(function ($builder) use ($dateExpr, $nowTs) {
                $builder->where('deals.status', 'expired')
                    ->orWhere(function ($virtual) use ($dateExpr, $nowTs) {
                        $virtual->whereNull('deals.id')
                            ->where(DB::raw($dateExpr), '<', $nowTs);
                    });
            });
            return;
        }

        if ($status === 'untracked') {
            $query->whereNull('deals.id')
                ->where('clients.profile_status', 'publish')
                ->where(function ($builder) {
                    $builder->whereNull('clients.needs_payment')->orWhere('clients.needs_payment', false);
                })
                ->where(function ($builder) {
                    $builder->whereNull('clients.notactive')->orWhere('clients.notactive', false);
                })
                ->whereRaw("{$dateExpr} IS NULL");
            return;
        }

        $query->where('deals.status', $status);
    }

    private function expiryDateExpr(): string
    {
        $driver = DB::connection()->getDriverName();

        $dealExpiryExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%s', deals.expires_at) AS INTEGER)",
            'pgsql' => 'EXTRACT(EPOCH FROM deals.expires_at)',
            default => 'UNIX_TIMESTAMP(deals.expires_at)',
        };

        return "COALESCE({$dealExpiryExpr}, clients.escort_expire, clients.premium_expire, clients.featured_expire)";
    }

    private function alreadyAttemptedToday(string $entityType, int $entityId, int $campaignId): bool
    {
        return TimelineEvent::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('event_type', ['renewal_sms_sent', 'renewal_sms_failed', 'renewal_whatsapp_sent', 'renewal_whatsapp_failed'])
            ->whereDate('created_at', now()->toDateString())
            ->where('content', 'like', '%"campaign_id":' . $campaignId . '%')
            ->exists();
    }

    private function dispatchRenewalMessage(Deal $deal, string $body, string $channel, array $context): array
    {
        $deal->loadMissing(['client.platform']);
        $channel = $this->normalizeMessageChannel($channel);

        if ($channel === 'sms') {
            return $this->notificationService->sendSmsToClient($deal->client, $body, $context);
        }

        $recipient = MessageRecipient::fromClient($deal->client)
            ->withDealId($deal->id ? (int) $deal->id : null);

        $dispatch = $this->messagingDispatcher->dispatch($recipient, $body, 'whatsapp', array_merge($context, [
            'message_type' => 'renewal',
            'suppress_gateway_timeline' => true,
            'idempotency_key' => 'renewal-' . ($context['campaign_id'] ?? 'manual') . '-' . ($deal->id ?: $deal->client_id) . '-' . sha1($body . '|' . ($context['run_id'] ?? '') . '|' . microtime(true)),
        ]));

        return $this->serializeDispatchResult($dispatch);
    }

    private function serializeDispatchResult(DispatchResult $dispatch): array
    {
        return [
            'success' => $dispatch->success,
            'status' => $dispatch->status,
            'provider' => $dispatch->channel,
            'provider_response' => $dispatch->smsResult['provider_response']
                ?? $dispatch->errorMessage
                ?? ($dispatch->success ? 'Message accepted by provider.' : 'Message could not be sent.'),
            'whatsapp_message_id' => $dispatch->whatsAppMessage?->id,
            'fallback_attempted' => $dispatch->fallbackAttempted,
            'error_code' => $dispatch->errorCode,
        ];
    }

    private function normalizeMessageChannel(string $channel): string
    {
        return strtolower(trim($channel)) === 'whatsapp' ? 'whatsapp' : 'sms';
    }

    private function channelLabel(string $channel): string
    {
        return $this->normalizeMessageChannel($channel) === 'whatsapp' ? 'WhatsApp' : 'SMS';
    }

    private function renewalEventType(string $channel, bool $success): string
    {
        if ($this->normalizeMessageChannel($channel) === 'whatsapp') {
            return $success ? CrmAuditAction::RENEWAL_WHATSAPP_SENT : CrmAuditAction::RENEWAL_WHATSAPP_FAILED;
        }

        return $success ? CrmAuditAction::RENEWAL_SMS_SENT : CrmAuditAction::RENEWAL_SMS_FAILED;
    }

    private function writeRenewalTimeline($deal, RenewalCampaign $campaign, RenewalRun $run, bool $success, ?string $response, string $channel = 'sms'): void
    {
        $channel = $this->normalizeMessageChannel($channel);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => $deal->id ? 'deal' : 'client',
            'entity_id' => $deal->id ?: $deal->client_id,
            'event_type' => $this->renewalEventType($channel, $success),
            'actor_id' => null,
            'content' => [
                'campaign_id' => $campaign->id,
                'run_id' => $run->id,
                'template_id' => $campaign->template_id,
                'channel' => $channel,
                'response' => $response,
            ],
            'created_at' => now(),
        ]);
    }

    private function resolveDefaultRenewalTemplate(Deal $deal, string $channel = 'sms'): ?Template
    {
        $channel = $this->normalizeMessageChannel($channel);
        $trigger = $this->suggestTriggerDays($deal);

        $campaign = RenewalCampaign::query()
            ->where('enabled', true)
            ->where('trigger_days', $trigger)
            ->whereHas('template', fn (Builder $templateQuery) => $templateQuery->where('channel', $channel))
            ->with('template')
            ->first();

        if ($campaign?->template && $campaign->template->channel === $channel) {
            return $campaign->template;
        }

        return Template::query()
            ->where('category', 'renewal')
            ->where('channel', $channel)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Short-cycle guard: suppress a pre-expiry reminder whose lead time is at least
     * as long as the client's own subscription. A campaign with trigger_days = -7
     * fires "7 days before expiry"; on a 7-day plan that lands on the purchase day,
     * which reads as premature spam. Expiry-day (0) and post-expiry (positive) offsets
     * are never suppressed. When the cycle length can't be determined (legacy/virtual
     * renewals) the guard fails open so genuine reminders still go out.
     */
    private function shouldSuppressForShortCycle(Deal $deal, RenewalCampaign $campaign): bool
    {
        $triggerDays = (int) $campaign->trigger_days;
        $leadDaysBeforeExpiry = $triggerDays < 0 ? -$triggerDays : 0;

        if ($leadDaysBeforeExpiry <= 0) {
            return false;
        }

        $platformId = $deal->platform_id !== null ? (int) $deal->platform_id : null;
        if (!$this->guardEnabledForPlatform($platformId)) {
            return false;
        }

        $cycleDays = $this->cycleLengthDays($deal);
        if ($cycleDays === null || $cycleDays <= 0) {
            return false;
        }

        return $leadDaysBeforeExpiry >= $cycleDays;
    }

    private function guardEnabledForPlatform(?int $platformId): bool
    {
        if ($platformId === null) {
            return true;
        }

        if (!array_key_exists($platformId, $this->guardEnabledCache)) {
            $flag = Platform::query()->whereKey($platformId)->value('renewal_reminder_guard_enabled');
            // Default ON when the market row or column value is absent.
            $this->guardEnabledCache[$platformId] = $flag === null ? true : (bool) $flag;
        }

        return $this->guardEnabledCache[$platformId];
    }

    /**
     * Best-effort subscription length in days: prefer the explicit duration_days,
     * then a known duration keyword, then the activated_at → expires_at span.
     */
    private function cycleLengthDays(Deal $deal): ?int
    {
        if (is_numeric($deal->duration_days) && (int) $deal->duration_days > 0) {
            return (int) $deal->duration_days;
        }

        $duration = strtolower(trim((string) ($deal->duration ?? '')));
        $keywordMap = [
            'daily' => 1,
            'weekly' => 7,
            'biweekly' => 14,
            'bi-weekly' => 14,
            'fortnightly' => 14,
            'monthly' => 30,
            'quarterly' => 90,
            'yearly' => 365,
            'annual' => 365,
            'annually' => 365,
        ];
        if ($duration !== '' && isset($keywordMap[$duration])) {
            return $keywordMap[$duration];
        }

        if ($deal->activated_at && $deal->expires_at) {
            $start = $deal->activated_at instanceof Carbon ? $deal->activated_at : Carbon::parse($deal->activated_at);
            $end = $deal->expires_at instanceof Carbon ? $deal->expires_at : Carbon::parse($deal->expires_at);
            $span = $start->diffInDays($end, false);
            if ($span > 0) {
                return (int) $span;
            }
        }

        return null;
    }

    private function suggestTriggerDays(Deal $deal): int
    {
        $daysLeft = $this->daysUntil($deal->expires_at);

        if ($daysLeft === null) {
            return 0;
        }

        if ($daysLeft >= 7) {
            return -7;
        }

        if ($daysLeft >= 3) {
            return -3;
        }

        if ($daysLeft >= 0) {
            return 0;
        }

        return 3;
    }

    private function daysUntil($dateValue): ?int
    {
        if (!$dateValue) {
            return null;
        }

        if ($dateValue instanceof Carbon) {
            $date = $dateValue;
        } elseif (is_numeric($dateValue)) {
            $date = Carbon::createFromTimestamp((int) $dateValue);
        } else {
            $date = Carbon::parse($dateValue);
        }

        return now()->diffInDays($date, false);
    }

    private function bucketForDays(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return 'unknown';
        }

        if ($daysLeft < 0) {
            return 'expired';
        }

        if ($daysLeft <= 3) {
            return 'risk';
        }

        if ($daysLeft <= 14) {
            return 'pending';
        }

        return 'stable';
    }

    private function bucketForDaysExpired(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return 'unknown';
        }

        if ($daysLeft < -14) {
            return 'lapsed';
        }

        return 'expired';
    }

    private function isReminderPaused(Deal $deal): bool
    {
        if (!(bool) $deal->renewal_reminders_paused) {
            return false;
        }

        if (!$deal->renewal_paused_until) {
            return true;
        }

        return Carbon::parse($deal->renewal_paused_until)->isFuture();
    }

    private function resolveActorId(?int $actorId): int
    {
        if ($actorId) {
            return $actorId;
        }

        $userId = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->value('id');

        if ($userId) {
            return (int) $userId;
        }

        $fallback = User::query()->orderBy('id')->value('id');
        if ($fallback) {
            return (int) $fallback;
        }

        throw new \RuntimeException('No users available to set renewal run owner.');
    }

    private function targetDealsForWalletAutoRenew(?array $platformIds = null): Collection
    {
        $start = now()->copy()->subDays(3)->startOfDay();
        $end = now()->copy()->endOfDay();

        return Deal::query()
            ->where('status', 'active')
            ->whereBetween('expires_at', [$start, $end])
            ->where(function (Builder $builder) {
                $builder->where('renewal_reminders_paused', false)
                    ->orWhere(function (Builder $pausedBuilder) {
                        $pausedBuilder->where('renewal_reminders_paused', true)
                            ->whereNotNull('renewal_paused_until')
                            ->where('renewal_paused_until', '<', now());
                    });
            })
            ->when(
                is_array($platformIds),
                fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds)
            )
            ->with(['client.platform', 'product'])
            ->orderBy('expires_at')
            ->get();
    }

    private function targetWalletAutoRenewDealsFromTargets(Collection $targets, ?array $platformIds = null): Collection
    {
        $windowStart = now()->copy()->subDays(3)->startOfDay();
        $windowEnd = now()->copy()->endOfDay();

        return $this->targetDealsFromOverviewTargets($targets, $platformIds)
            ->filter(fn ($deal) => $deal instanceof Deal && $deal->id)
            ->filter(fn (Deal $deal) => (string) $deal->status === 'active')
            ->filter(function (Deal $deal) use ($windowStart, $windowEnd) {
                if (!$deal->expires_at) {
                    return false;
                }

                $expiry = $deal->expires_at instanceof Carbon
                    ? $deal->expires_at
                    : Carbon::parse($deal->expires_at);

                return $expiry->betweenIncluded($windowStart, $windowEnd);
            })
            ->values();
    }

    private function handleWalletAutoRenewDeal(Deal $deal, ?int $actorId = null): array
    {
        $deal->loadMissing(['client.platform', 'product']);
        $cycleExpiresAt = $deal->expires_at instanceof Carbon
            ? $deal->expires_at->copy()
            : ($deal->expires_at ? Carbon::parse($deal->expires_at) : null);

        if (!$cycleExpiresAt) {
            return [
                'deal_id' => (int) $deal->id,
                'client_id' => (int) ($deal->client_id ?? 0),
                'status' => 'escalated',
                'reason_code' => 'missing_expiry',
                'reason' => 'Subscription expiry is missing.',
            ];
        }

        if ($this->walletAutoRenewAlreadyHandled($deal, $cycleExpiresAt)) {
            return [
                'deal_id' => (int) $deal->id,
                'client_id' => (int) ($deal->client_id ?? 0),
                'status' => 'skipped',
                'reason_code' => 'already_handled',
                'reason' => 'Wallet auto-renew already handled this renewal cycle.',
            ];
        }

        $decision = $this->walletAutoRenewPolicy->forDeal($deal);
        $result = [
            'deal_id' => (int) $deal->id,
            'client_id' => (int) ($deal->client_id ?? 0),
            'status' => 'skipped',
            'attempted_charge' => false,
            'reason_code' => (string) ($decision['reason_code'] ?? 'skipped'),
            'reason' => (string) ($decision['reason'] ?? 'Wallet auto-renew was skipped.'),
            'fallback_method' => $decision['fallback_method'] ?? null,
            'policy' => $decision,
        ];

        if (($decision['action'] ?? 'skip') === 'skip') {
            return $result;
        }

        if (($decision['action'] ?? null) === 'escalate') {
            $this->recordWalletAutoRenewEvent($deal, 'wallet_auto_renew_escalated', $actorId, [
                'reason_code' => $decision['reason_code'] ?? null,
                'reason' => $decision['reason'] ?? null,
                'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
                'fallback_method' => $decision['fallback_method'] ?? null,
            ]);

            return array_merge($result, [
                'status' => 'escalated',
            ]);
        }

        if (($decision['action'] ?? null) === 'send_fallback') {
            $this->recordWalletAutoRenewEvent($deal, 'wallet_auto_renew_failed', $actorId, [
                'reason_code' => $decision['reason_code'] ?? null,
                'reason' => $decision['reason'] ?? null,
                'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
                'fallback_method' => $decision['fallback_method'] ?? null,
            ]);

            return $this->sendWalletAutoRenewFallback($deal, $decision, $cycleExpiresAt, $actorId);
        }

        $idempotencyKey = $this->walletAutoRenewIdempotencyKey($deal, $cycleExpiresAt);
        $this->recordWalletAutoRenewEvent($deal, 'wallet_auto_renew_attempted', $actorId, [
            'reason_code' => $decision['reason_code'] ?? null,
            'reason' => $decision['reason'] ?? null,
            'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
            'fallback_method' => $decision['fallback_method'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'pricing' => $decision['pricing'] ?? null,
        ]);

        try {
            $checkout = $this->walletCheckoutService->autoRenewDealFromWallet($deal, $idempotencyKey, [
                'origin' => 'wallet_auto_subscribe',
                'environment' => 'production',
                'actor_id' => $actorId,
            ]);

            $freshDeal = $checkout['deal'] ?? $deal->fresh(['client.platform', 'product']);
            $payment = $checkout['payment'] ?? null;

            $this->recordWalletAutoRenewEvent($freshDeal instanceof Deal ? $freshDeal : $deal, 'wallet_auto_renew_succeeded', $actorId, [
                'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
                'previous_expires_at' => $checkout['previous_expires_at'] ?? $cycleExpiresAt->toDateTimeString(),
                'new_expires_at' => optional($freshDeal?->expires_at)->toDateTimeString(),
                'payment_id' => $payment?->id,
                'payment_reference' => $payment?->transaction_reference,
                'amount' => $payment?->amount,
                'currency' => $payment?->currency,
                'replayed' => (bool) ($checkout['replayed'] ?? false),
            ]);

            $this->auditService->record([
                'platform_id' => (int) $deal->platform_id,
                'actor_id' => $this->resolveActorId($actorId),
                'action' => 'deal_auto_renew',
                'entity_type' => 'deal',
                'entity_id' => (int) $deal->id,
                'before_state' => [
                    'expires_at' => $cycleExpiresAt->toDateTimeString(),
                ],
                'after_state' => [
                    'expires_at' => optional($freshDeal?->expires_at)->toDateTimeString(),
                    'payment_id' => $payment?->id,
                    'replayed' => (bool) ($checkout['replayed'] ?? false),
                ],
                'reason' => 'Wallet auto-renew completed successfully.',
            ]);

            return array_merge($result, [
                'status' => 'succeeded',
                'attempted_charge' => true,
                'payment_id' => $payment?->id,
                'new_expires_at' => optional($freshDeal?->expires_at)->toDateTimeString(),
                'replayed' => (bool) ($checkout['replayed'] ?? false),
            ]);
        } catch (\Throwable $exception) {
            $this->recordWalletAutoRenewEvent($deal, 'wallet_auto_renew_failed', $actorId, [
                'reason_code' => 'wallet_execution_failed',
                'reason' => $exception->getMessage(),
                'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
                'fallback_method' => $decision['fallback_method'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            if (!empty($decision['fallback_method'])) {
                return $this->sendWalletAutoRenewFallback(
                    $deal,
                    array_merge($decision, [
                        'reason_code' => 'wallet_execution_failed',
                        'reason' => $exception->getMessage(),
                    ]),
                    $cycleExpiresAt,
                    $actorId
                );
            }

            $this->recordWalletAutoRenewEvent($deal, 'wallet_auto_renew_escalated', $actorId, [
                'reason_code' => 'wallet_execution_failed',
                'reason' => $exception->getMessage(),
                'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
            ]);

            return array_merge($result, [
                'status' => 'escalated',
                'attempted_charge' => true,
                'reason_code' => 'wallet_execution_failed',
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function sendWalletAutoRenewFallback(Deal $deal, array $decision, Carbon $cycleExpiresAt, ?int $actorId = null): array
    {
        $fallbackResult = $this->sendManualReminder($deal, null, $actorId);
        $fallbackSent = (bool) ($fallbackResult['success'] ?? false);

        if ($fallbackSent) {
            $this->recordWalletAutoRenewEvent($deal, 'wallet_auto_renew_fallback_sent', $actorId, [
                'reason_code' => $decision['reason_code'] ?? null,
                'reason' => $decision['reason'] ?? null,
                'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
                'fallback_method' => $decision['fallback_method'] ?? null,
                'template_id' => $fallbackResult['template_id'] ?? null,
                'delivery_status' => $fallbackResult['status'] ?? null,
            ]);

            return [
                'deal_id' => (int) $deal->id,
                'client_id' => (int) ($deal->client_id ?? 0),
                'status' => 'fallback_sent',
                'reason_code' => (string) ($decision['reason_code'] ?? 'fallback_sent'),
                'reason' => (string) ($decision['reason'] ?? 'Fallback renewal handling was sent.'),
                'fallback_method' => $decision['fallback_method'] ?? null,
            ];
        }

        $this->recordWalletAutoRenewEvent($deal, 'wallet_auto_renew_escalated', $actorId, [
            'reason_code' => 'fallback_delivery_failed',
            'reason' => $fallbackResult['reason'] ?? 'Fallback reminder failed to send.',
            'cycle_expires_at' => $cycleExpiresAt->toDateTimeString(),
            'fallback_method' => $decision['fallback_method'] ?? null,
        ]);

        return [
            'deal_id' => (int) $deal->id,
            'client_id' => (int) ($deal->client_id ?? 0),
            'status' => 'escalated',
            'reason_code' => 'fallback_delivery_failed',
            'reason' => (string) ($fallbackResult['reason'] ?? 'Fallback reminder failed to send.'),
            'fallback_method' => $decision['fallback_method'] ?? null,
        ];
    }

    private function walletAutoRenewAlreadyHandled(Deal $deal, Carbon $cycleExpiresAt): bool
    {
        return TimelineEvent::query()
            ->where('entity_type', 'deal')
            ->where('entity_id', (int) $deal->id)
            ->whereIn('event_type', [
                'wallet_auto_renew_succeeded',
                'wallet_auto_renew_fallback_sent',
                'wallet_auto_renew_escalated',
            ])
            ->get(['content'])
            ->contains(function (TimelineEvent $event) use ($cycleExpiresAt) {
                return (string) data_get($event->content, 'cycle_expires_at') === $cycleExpiresAt->toDateTimeString();
            });
    }

    private function walletAutoRenewIdempotencyKey(Deal $deal, Carbon $cycleExpiresAt): string
    {
        return 'wallet-auto-renew:' . $deal->id . ':' . $cycleExpiresAt->format('YmdHis');
    }

    private function recordWalletAutoRenewEvent(Deal $deal, string $eventType, ?int $actorId = null, array $content = []): void
    {
        TimelineEvent::create([
            'platform_id' => (int) $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => (int) $deal->id,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'content' => $content,
            'created_at' => now(),
        ]);
    }

    private function serializeWalletAutoRenewState(?TimelineEvent $event): ?array
    {
        if (!$event) {
            return null;
        }

        $status = match ((string) $event->event_type) {
            'wallet_auto_renew_attempted' => 'attempted',
            'wallet_auto_renew_succeeded' => 'succeeded',
            'wallet_auto_renew_failed' => 'failed',
            'wallet_auto_renew_fallback_sent' => 'fallback_sent',
            'wallet_auto_renew_escalated' => 'escalated',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        return [
            'status' => $status,
            'event_type' => (string) $event->event_type,
            'at' => optional($event->created_at)->toDateTimeString(),
            'reason' => data_get($event->content, 'reason'),
            'reason_code' => data_get($event->content, 'reason_code'),
            'payment_id' => data_get($event->content, 'payment_id'),
            'fallback_method' => data_get($event->content, 'fallback_method'),
            'cycle_expires_at' => data_get($event->content, 'cycle_expires_at'),
            'new_expires_at' => data_get($event->content, 'new_expires_at'),
        ];
    }
}
