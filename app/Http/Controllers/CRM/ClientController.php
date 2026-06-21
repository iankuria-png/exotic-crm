<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\ClientCredentialDispatch;
use App\Models\ClientNote;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\TimelineEvent;
use App\Models\Platform;
use App\Models\User;
use App\Exceptions\ClientCaseClosureException;
use App\Services\AuditService;
use App\Services\AutoPush\AutoPushBoostService;
use App\Services\ChurnAggregatorService;
use App\Services\ClientCaseClosureService;
use App\Services\ClientChurnStamper;
use App\Services\ClientDeletionService;
use App\Services\ClientFunnelService;
use App\Services\ClientLifetimeValueService;
use App\Services\ClientOutreachService;
use App\Services\ClientSegmentService;
use App\Services\ClientSubscriptionActionResolver;
use App\Services\ClientSubscriptionDeactivationService;
use App\Services\ClientWpLinkRepairService;
use App\Services\ClientRetentionInsightService;
use App\Services\DealPaymentService;
use App\Services\LeadAssignmentService;
use App\Services\MarketAuthorizationService;
use App\Services\CredentialDeliveryService;
use App\Services\ClientSyncService;
use App\Services\ExpiredSubscriptionReconciler;
use App\Services\NotificationService;
use App\Services\PaymentLinkService;
use App\Services\PaymentMatchingService;
use App\Services\ClientProfileUrlSearchService;
use App\Services\ClientProfileImageService;
use App\Services\SupportBoardService;
use App\Services\WalletSettingsService;
use App\Services\WpDirectProvisioningService;
use App\Services\WpSyncService;
use App\Support\CityNormalizer;
use App\Support\CrmAuditAction;
use App\Support\CrmClientChurnReason;
use App\Support\CrmClientCloseReason;
use App\Support\DealDeactivationReason;
use App\Support\DeactivationRequest;
use App\Support\PhoneNormalizer;
use App\Support\WpProfileFieldCatalog;
use App\Support\WpProfileFieldValidator;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Client\RequestException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClientController extends Controller
{
    private const PROFILE_MEDIA_ALLOWED_EXTENSIONS = 'jpg,jpeg,png,webp,mp4';
    private const PROFILE_MEDIA_IMAGE_MAX_KB = 5120;
    private const PROFILE_MEDIA_VIDEO_MAX_KB = 51200;
    private const PROFILE_MEDIA_MAX_IMAGES = 20;
    private const PROFILE_MEDIA_MAX_VIDEOS = 5;

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly LeadAssignmentService $leadAssignmentService,
        private readonly AuditService $auditService,
        private readonly CredentialDeliveryService $credentialDeliveryService,
        private readonly ClientWpLinkRepairService $clientWpLinkRepairService,
        private readonly ClientRetentionInsightService $clientRetentionInsightService,
        private readonly ClientDeletionService $clientDeletionService,
        private readonly DealPaymentService $dealPaymentService,
        private readonly ClientSubscriptionDeactivationService $clientSubscriptionDeactivationService,
        private readonly ClientSubscriptionActionResolver $clientSubscriptionActionResolver,
        private readonly NotificationService $notificationService,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly WalletSettingsService $walletSettingsService,
        private readonly ClientProfileUrlSearchService $clientProfileUrlSearchService,
        private readonly ClientProfileImageService $clientProfileImageService,
        private readonly ClientCaseClosureService $clientCaseClosureService,
        private readonly ClientSegmentService $clientSegmentService,
        private readonly ExpiredSubscriptionReconciler $expiredSubscriptionReconciler,
        private readonly ChurnAggregatorService $churnAggregatorService,
        private readonly ClientChurnStamper $clientChurnStamper,
        private readonly ClientLifetimeValueService $clientLifetimeValueService,
        private readonly AutoPushBoostService $autoPushBoostService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'segment' => 'nullable|string|in:' . implode(',', ClientSegmentService::keys()),
            'city_key' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|in:25,50,100,150',
        ]);

        $requestedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this client market.'
        );

        $query = Client::with([
            'platform',
            'assignedAgent',
            'creator:id,name,email,role',
            'retentionInsight:client_id,score,band,primary_tag,computed_at',
            'activeDeal.product:id,name,display_name,slug,tier',
        ]);
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());
        $searchResolution = null;

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $resolvedClientSearch = $this->clientProfileUrlSearchService->resolveClientSearch(
                $search,
                $request->user(),
                $requestedPlatformId
            );

            if (is_array($resolvedClientSearch)) {
                $searchResolution = $resolvedClientSearch['resolution'] ?? null;
                $resolvedClientIds = array_values(array_filter(
                    array_map('intval', (array) ($resolvedClientSearch['client_ids'] ?? [])),
                    fn (int $id) => $id > 0
                ));
                $fallbackTerms = array_values(array_filter(array_map(
                    static fn ($term) => trim((string) $term),
                    (array) ($resolvedClientSearch['fallback_terms'] ?? [])
                )));

                if ($resolvedClientIds !== []) {
                    $query->whereIn('id', $resolvedClientIds);
                } elseif ($fallbackTerms !== []) {
                    $this->applyClientTextSearch($query, $fallbackTerms);
                } else {
                    $query->whereRaw('1 = 0');
                }
            } else {
                $this->applyClientTextSearch($query, [$search]);
            }
        }

        if ($request->filled('status')) {
            if ((string) $request->status === 'expired_public') {
                // Stuck profiles: still publicly active but past their WP expiry.
                // Raw "escort_expire < now" is intentionally inclusive for a review
                // queue; the precise per-market cutoff still gates actual deactivation.
                $query->active()
                    ->whereNotNull('escort_expire')
                    ->where('escort_expire', '>', 0)
                    ->where('escort_expire', '<', now()->timestamp);
            } else {
                $query->where('profile_status', $request->status);
            }
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        $view = strtolower((string) $request->input('view', 'all'));
        if ($view === 'closed') {
            $query->closed()->with('closedBy:id,name,email');
        } else {
            $query->notClosed();
        }

        if ($request->boolean('high_risk')) {
            $query->highRisk();
        }

        if ($request->filled('plan')) {
            $this->applyCanonicalPlanFilter(
                $query,
                Client::normalizePlanFilterKey((string) $request->input('plan'))
            );
        }

        if ($request->filled('signup_source')) {
            if ((string) $request->signup_source === 'field') {
                $query->where(function ($sourceQuery) {
                    $sourceQuery->where('signup_source', 'field')
                        ->orWhereHas('creator', fn ($creatorQuery) => $creatorQuery->where('role', MarketAuthorizationService::ROLE_FIELD_SALES));
                });
            } else {
                $query->where('signup_source', $request->signup_source);
            }
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', (int) $request->input('created_by'));
        }

        if ($request->filled('verified')) {
            $query->where('verified', $request->boolean('verified'));
        }

        if (in_array((string) $request->input('has_chat'), ['0', '1'], true)) {
            if ((string) $request->input('has_chat') === '1') {
                $query->whereNotNull('sb_user_id');
            } else {
                $query->whereNull('sb_user_id');
            }
        }

        if ($request->filled('online_within')) {
            $minutes = (int) $request->online_within;
            if ($minutes > 0) {
                $cutoff = now()->subMinutes($minutes)->timestamp;
                $query->where('last_online_at', '>=', $cutoff);
            }
        }

        if ($request->filled('retention_band')) {
            $retentionBand = trim((string) $request->retention_band);
            $query->whereHas('retentionInsight', function ($builder) use ($retentionBand) {
                if ($retentionBand === 'watch') {
                    $builder->whereIn('band', ClientRetentionInsightService::WATCH_BANDS);
                    return;
                }

                $bands = array_values(array_filter(array_map('trim', explode(',', $retentionBand))));
                if (count($bands) > 1) {
                    $builder->whereIn('band', $bands);
                    return;
                }

                $builder->where('band', $retentionBand);
            });
        }

        if ($request->filled('behavior_tag')) {
            $query->whereHas('retentionInsight', function ($builder) use ($request) {
                $builder->where('primary_tag', (string) $request->behavior_tag);
            });
        }

        if ($request->filled('city')) {
            $query->where('city', (string) $request->city);
        }

        if ($request->filled('city_key')) {
            $cityKey = (string) $request->input('city_key');
            $matchingCities = (clone $query)
                ->whereNotNull('city')
                ->distinct()
                ->pluck('city')
                ->filter(fn ($city) => CityNormalizer::canonicalKey($city) === $cityKey)
                ->values();

            $query->whereIn('city', $matchingCities->all() ?: ["\0__none__"]);
        }

        if ($request->filled('created_from')) {
            $query->where('created_at', '>=', $this->parseClientDateBoundary((string) $request->input('created_from')));
        }

        if ($request->filled('created_to')) {
            $query->where('created_at', '<=', $this->parseClientDateBoundary((string) $request->input('created_to'), endOfDay: true));
        }

        $statsQuery = clone $query;
        $segmentCounts = $this->clientSegmentService->segmentCounts(clone $statsQuery);

        $segment = trim((string) ($validated['segment'] ?? ''));
        if ($segment !== '') {
            $this->clientSegmentService->applySegment($query, $segment);
        }

        $premiumStatsQuery = clone $statsQuery;
        $newUsersStatsQuery = clone $statsQuery;
        $this->applyCanonicalPlanFilter($premiumStatsQuery, 'premium');

        // Closed-case stats: identical platform scope to $query but reset the closed/notClosed filter so
        // both views surface the same counts on the closed tab cards regardless of which view is active.
        $closedStatsBase = Client::query();
        $this->marketAuthorizationService->applyPlatformScope($closedStatsBase, $request->user());
        if ($request->filled('platform_id')) {
            $closedStatsBase->where('platform_id', $request->platform_id);
        }

        if (!$request->filled('created_from') && !$request->filled('created_to')) {
            $newUsersStatsQuery->where('created_at', '>=', now()->subDays(6)->startOfDay());
        }

        // Stuck-profile count is independent of the current status filter so the
        // "Expired (still public)" card always reflects the true backlog.
        $expiredPublicBase = Client::query();
        $this->marketAuthorizationService->applyPlatformScope($expiredPublicBase, $request->user());
        if ($request->filled('platform_id')) {
            $expiredPublicBase->where('platform_id', $request->platform_id);
        }
        $expiredPublicBase->notClosed()->active()
            ->whereNotNull('escort_expire')
            ->where('escort_expire', '>', 0)
            ->where('escort_expire', '<', now()->timestamp);

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'active' => (clone $statsQuery)->active()->count(),
            'premium' => $premiumStatsQuery->count(),
            'verified' => (clone $statsQuery)->where('verified', true)->count(),
            'high_risk' => (clone $statsQuery)->where('is_high_risk', true)->count(),
            'inactive' => (clone $statsQuery)->where('profile_status', 'private')->count(),
            'expired_public' => $expiredPublicBase->count(),
            'with_chat' => (clone $statsQuery)->whereNotNull('sb_user_id')->count(),
            'online_now' => (clone $statsQuery)->where('last_online_at', '>=', now()->subMinutes(15)->timestamp)->count(),
            'new_users' => $newUsersStatsQuery->count(),
            'retention_watch' => (clone $statsQuery)->whereHas('retentionInsight', function ($builder) {
                $builder->whereIn('band', ClientRetentionInsightService::WATCH_BANDS);
            })->count(),
            'segments' => $segmentCounts,
            'closed_recent' => (clone $closedStatsBase)->closed()->where('closed_at', '>=', now()->subDays(30))->count(),
            'closed_recent_7d' => (clone $closedStatsBase)->closed()->where('closed_at', '>=', now()->subDays(7))->count(),
            'purging_soon' => (clone $closedStatsBase)->closed()->whereNotNull('purge_after')->where('purge_after', '<=', now()->addDays(7))->count(),
        ];

        $this->applyClientSort(
            $query,
            (string) $request->input('sort_by', 'updated_at'),
            (string) $request->input('sort_direction', 'desc')
        );

        $clients = $query->paginate((int) ($validated['per_page'] ?? 25));
        $clients->getCollection()->each(fn (Client $client) => $this->decorateExpiryState($client));
        $this->decorateLifetimeValue($clients->getCollection());

        $payload = $clients->toArray();
        $payload['stats'] = $stats;
        $payload['search_resolution'] = $searchResolution;

        return response()->json($payload);
    }

    private function applyClientTextSearch($query, array $terms): void
    {
        $normalizedTerms = array_values(array_unique(array_filter(array_map(
            static fn ($term) => trim((string) $term),
            $terms
        ))));

        if ($normalizedTerms === []) {
            return;
        }

        $query->where(function ($builder) use ($normalizedTerms) {
            foreach ($normalizedTerms as $term) {
                $builder->orWhere(function ($nested) use ($term) {
                    $nested->where('name', 'like', "%{$term}%")
                        ->orWhere('phone_normalized', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");

                    if (ctype_digit($term)) {
                        $numeric = (int) $term;
                        $nested->orWhere('id', $numeric)
                            ->orWhere('wp_post_id', $numeric)
                            ->orWhere('wp_user_id', $numeric);
                    }
                });
            }
        });
    }

    private function applyCanonicalPlanFilter($query, string $planKey): void
    {
        if ($planKey === '') {
            return;
        }

        $query->where(function ($builder) use ($planKey) {
            $builder->whereHas('deals', function ($dealQuery) use ($planKey) {
                $dealQuery->where('status', 'active')
                    ->where(function ($matchQuery) use ($planKey) {
                        $matchQuery->whereHas('product', function ($productQuery) use ($planKey) {
                            $productQuery->where('tier', $planKey)
                                ->orWhere('slug', $planKey);
                        })
                            ->orWhere(function ($fallbackDealQuery) use ($planKey) {
                                $fallbackDealQuery->where('plan_type', $planKey)
                                    ->where(function ($productFallbackQuery) {
                                        $productFallbackQuery->whereNull('product_id')
                                            ->orWhereDoesntHave('product');
                                    });
                            });
                    });
            });

            if (!in_array($planKey, ['premium', 'featured', 'basic'], true)) {
                return;
            }

            $builder->orWhere(function ($legacyQuery) use ($planKey) {
                $legacyQuery->whereDoesntHave('deals', function ($dealQuery) {
                    $dealQuery->where('status', 'active');
                });

                if ($planKey === 'premium') {
                    $legacyQuery->where('premium', true);
                    return;
                }

                if ($planKey === 'featured') {
                    $legacyQuery->where('featured', true);
                    return;
                }

                $legacyQuery->where('premium', false)->where('featured', false);
            });
        });
    }

    private function parseClientDateBoundary(string $date, bool $endOfDay = false): Carbon
    {
        $boundary = Carbon::createFromFormat('Y-m-d', trim($date), config('app.timezone'));

        return $endOfDay ? $boundary->endOfDay() : $boundary->startOfDay();
    }

    private function applyClientSort($query, string $sortBy, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        switch ($sortBy) {
            case 'name':
                $query->orderBy('name', $direction)
                    ->orderBy('updated_at', 'desc')
                    ->orderBy('id', 'desc');
                break;

            case 'created_at':
                $query->orderBy('created_at', $direction)
                    ->orderBy('id', $direction);
                break;

            case 'updated_at':
            default:
                $query->orderBy('updated_at', $direction)
                    ->orderBy('id', $direction);
                break;
        }
    }

    public function store(Request $request)
    {
        $this->guardStorePayloadKeys($request);

        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'name' => 'required|string|max:255',
            'phone_normalized' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:100',
            'profile_status' => 'nullable|in:publish,private,draft,pending',
            'assigned_to' => 'nullable|exists:users,id',
            'wp_user_id' => 'nullable|integer|min:1',
            'onboarding_mode' => 'nullable|in:manual,wp_provision',
            'wp_username' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'wp_password' => 'nullable|string|min:8|max:100',
            'birthday' => 'nullable|date_format:Y-m-d',
            'height' => 'nullable|string|max:50',
            'weight' => 'nullable|string|max:50',
            'bio' => 'nullable|string|max:5000',
            'provision_request_id' => 'nullable|string|max:64',
            'signup_source' => 'nullable|in:crm_manual,crm_provisioned,field',
            'reason' => 'nullable|string|max:500',
        ]);

        $onboardingMode = (string) ($validated['onboarding_mode'] ?? 'manual');
        if (($request->user()?->role ?? null) === MarketAuthorizationService::ROLE_FIELD_SALES) {
            $validated['signup_source'] = 'field';
            $onboardingMode = 'wp_provision';
            $validated['onboarding_mode'] = 'wp_provision';
        }

        if (($validated['signup_source'] ?? null) === 'field') {
            if (($request->user()?->role ?? null) !== MarketAuthorizationService::ROLE_FIELD_SALES) {
                return response()->json([
                    'message' => 'Only field sales users can create field-sourced clients.',
                ], 403);
            }

            if ($onboardingMode !== 'wp_provision') {
                return response()->json([
                    'message' => 'Field sales clients must be provisioned in WordPress.',
                ], 422);
            }
        }
        if (
            $onboardingMode === 'wp_provision'
            && empty($validated['email'])
            && empty($validated['phone_normalized'])
        ) {
            return response()->json([
                'message' => 'Email or phone is required when provisioning a WordPress profile.',
            ], 422);
        }

        if ($onboardingMode === 'wp_provision') {
            $platform = Platform::query()->findOrFail((int) $validated['platform_id']);
            $validated = array_merge(
                $validated,
                $this->prepareProvisioningProfilePayload($request, $platform)
            );
        }

        try {
            if ($onboardingMode === 'wp_provision') {
                $client = $this->createProvisionedClient(
                    $request,
                    $validated,
                    $validated['reason'] ?? 'WordPress-provisioned client create from CRM'
                );
            } else {
                $client = $this->createManualClient(
                    $request,
                    $validated,
                    $validated['reason'] ?? 'Manual client create from CRM'
                );
            }
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (ConflictHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (\Throwable $exception) {
            Log::error('Client create failed', [
                'onboarding_mode' => $onboardingMode,
                'platform_id' => $validated['platform_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Client creation failed: ' . $exception->getMessage(),
            ], 500);
        }

        return response()->json($client, 201);
    }

    public function uploadCsv(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'has_header' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this client market.'
        );

        $rows = $this->parseCsvRows(
            $validated['file']->getRealPath(),
            (bool) ($validated['has_header'] ?? true)
        );

        if (count($rows) === 0) {
            return response()->json([
                'message' => 'CSV file has no data rows.',
            ], 422);
        }

        if (count($rows) > 500) {
            return response()->json([
                'message' => 'CSV upload limit is 500 rows per upload.',
            ], 422);
        }

        $totals = [
            'rows' => count($rows),
            'created' => 0,
            'failed' => 0,
        ];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            $payload = [
                'platform_id' => $platformId,
                'name' => trim((string) ($row['name'] ?? $row['client_name'] ?? '')),
                'phone_normalized' => $row['phone_normalized'] ?? $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'city' => $row['city'] ?? null,
                'profile_status' => $row['profile_status'] ?? $row['status'] ?? 'private',
                'assigned_to' => isset($row['assigned_to']) && trim((string) $row['assigned_to']) !== '' ? (int) $row['assigned_to'] : null,
                'wp_user_id' => isset($row['wp_user_id']) && trim((string) $row['wp_user_id']) !== '' ? (int) $row['wp_user_id'] : null,
            ];

            try {
                $this->createManualClient(
                    $request,
                    $payload,
                    ($validated['reason'] ?? 'CSV client upload from CRM') . " (row {$rowNumber})"
                );
                $totals['created'] += 1;
            } catch (\Throwable $exception) {
                $totals['failed'] += 1;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'totals' => $totals,
            'errors' => $errors,
        ]);
    }

    public function show(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((bool) $client->kyc_required && !$client->kycSubject) {
            app(\App\Services\Kyc\KycSubjectService::class)->resolveOrCreateForClient($client);
            $client->refresh();
        }

        $client->load([
            'platform',
            'assignedAgent',
            'creator:id,name,email,role',
            'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
            'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'activeDeal.product',
            'kycSubject.sites',
        ]);
        $this->hydrateBillingPlatformState($client);
        $this->appendSubscriptionActionMetadata($client);
        $client->setAttribute('whatsapp_inbound_count', \App\Models\WhatsAppMessage::query()
            ->where('client_id', $client->id)
            ->where('direction', 'inbound')
            ->count());
        $client->setAttribute('whatsapp_conversation_enabled', \App\Models\WhatsAppRoutingRule::query()
            ->where('market_id', $client->platform_id)
            ->where('message_type', 'conversation')
            ->where('enabled', true)
            ->whereHas('primaryProfile', fn ($profile) => $profile
                ->where('active', true)
                ->where('kill_switch_enabled', false))
            ->exists());
        $this->decorateExpiryState($client);

        return response()->json($client);
    }

    /**
     * Attach the derived `expiry_state` ('expired_public' when the profile is still
     * publicly active but past its timezone-aware expiry cutoff, else null) so the
     * frontend badge needs no market-timezone logic.
     */
    private function decorateExpiryState(Client $client): void
    {
        $client->setAttribute(
            'expiry_state',
            $this->expiredSubscriptionReconciler->isStuck($client) ? 'expired_public' : null
        );
    }

    private function decorateLifetimeValue($clients): void
    {
        $ids = collect($clients)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $values = $this->clientLifetimeValueService->forClientIds($ids);

        collect($clients)->each(function (Client $client) use ($values): void {
            $entry = $values[(int) $client->id] ?? null;

            $client->setAttribute('lifetime_value_currency', 'USD');

            if ($entry === null) {
                $client->setAttribute('lifetime_value_usd', 0.0);
                $client->setAttribute('lifetime_value_partial', false);
                $client->setAttribute('lifetime_payment_count', 0);
                $client->setAttribute('lifetime_last_payment_at', null);
                $client->setAttribute('lifetime_source_breakdown', []);

                return;
            }

            $client->setAttribute('lifetime_value_usd', $entry['value_usd']);
            $client->setAttribute('lifetime_value_partial', $entry['partial']);
            $client->setAttribute('lifetime_payment_count', $entry['payment_count']);
            $client->setAttribute('lifetime_last_payment_at', $entry['last_payment_at']);
            $client->setAttribute('lifetime_source_breakdown', $entry['source_breakdown']);
        });
    }

    public function quickReplies(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        return response()->json(
            app(ClientOutreachService::class)->quickRepliesFor($client, $request->user())
        );
    }

    /**
     * Manually force-expire a profile that is past its WP expiry but still
     * publicly active. Uses the same reconciler as the daily cron. Rejected
     * (422) for profiles that are not actually expired — generic deactivation
     * is handled by deactivateSubscription().
     */
    public function expireNow(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);
        $client->loadMissing('platform');

        if (!$this->expiredSubscriptionReconciler->isStuck($client)) {
            return response()->json([
                'message' => 'This profile is not past its expiry, so it cannot be force-expired. Use Deactivate subscription instead.',
            ], 422);
        }

        $before = [
            'profile_status' => $client->profile_status,
            'escort_expire' => $client->escort_expire,
        ];

        try {
            $result = $this->expiredSubscriptionReconciler->reconcileClient($client, (int) $request->user()->id, false);
        } catch (\Throwable $e) {
            Log::error('Manual expire-now failed', [
                'client_id' => $client->id,
                'wp_post_id' => $client->wp_post_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to deactivate the profile in WordPress. Please retry.',
            ], 502);
        }

        $fresh = $client->fresh(['platform']);
        $this->decorateExpiryState($fresh);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_SUBSCRIPTION_DEACTIVATE,
            'client',
            (int) $client->id,
            $before,
            [
                'profile_status' => $fresh->profile_status,
                'escort_expire' => $fresh->escort_expire,
                'deactivation_scope' => 'expired_subscription_reconcile',
                'deals_expired' => $result['deals_expired'] ?? 0,
            ],
            'Force-expired stuck profile (past WP expiry)'
        );

        return response()->json([
            'message' => 'Profile expired and set to private.',
            'client' => $fresh,
            'result' => $result,
        ]);
    }

    /**
     * Bulk force-expire selected clients. Only clients that are genuinely past
     * their expiry but still public are deactivated (the reconciler's isStuck
     * guard); ineligible or unauthorized clients are reported, not acted on.
     */
    public function bulkExpire(Request $request)
    {
        $validated = $request->validate([
            'client_ids' => 'required|array|min:1|max:100',
            'client_ids.*' => 'integer|exists:clients,id',
        ]);

        $clients = Client::with('platform')->whereIn('id', $validated['client_ids'])->get();

        $authorized = collect();
        $unauthorizedIds = [];
        foreach ($clients as $client) {
            try {
                $this->authorizeClientAccess($request, $client);
                $authorized->push($client);
            } catch (\Throwable $exception) {
                $unauthorizedIds[] = (int) $client->id;
            }
        }

        $actorId = (int) optional($request->user())->id;
        $outcome = $this->expiredSubscriptionReconciler->reconcileMany($authorized, $actorId ?: null);

        // Audit each deactivated profile against its own market.
        $byId = $authorized->keyBy('id');
        foreach ($outcome['results'] as $row) {
            if (($row['action'] ?? null) !== 'deactivated') {
                continue;
            }
            $client = $byId->get($row['client_id']);
            if (!$client) {
                continue;
            }
            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_SUBSCRIPTION_DEACTIVATE,
                'client',
                (int) $client->id,
                null,
                [
                    'profile_status' => 'private',
                    'deactivation_scope' => 'expired_subscription_reconcile_bulk',
                ],
                'Bulk force-expired stuck profile (past WP expiry)'
            );
        }

        foreach ($unauthorizedIds as $unauthorizedId) {
            $outcome['results'][] = [
                'client_id' => $unauthorizedId,
                'action' => 'failed',
                'error' => 'forbidden',
            ];
            $outcome['summary']['total']++;
            $outcome['summary']['failed']++;
        }

        return response()->json($outcome);
    }

    private function hydrateBillingPlatformState(Client $client): void
    {
        if (!$client->platform) {
            return;
        }

        $client->platform->setAttribute(
            'billing_method_policy',
            $this->dealPaymentService->marketBillingMethodPolicy($client->platform)
        );
        $client->platform->setAttribute(
            'payment_link_providers',
            $this->walletSettingsService->currentPaymentLinkProviders($client->platform)
        );
    }

    private function appendSubscriptionActionMetadata(Client $client): void
    {
        $subscriptionAction = $this->clientSubscriptionActionResolver->resolveNoDealDeactivation($client, [
            'has_tracked_deal_history' => $client->relationLoaded('deals')
                ? $client->deals->isNotEmpty()
                : false,
        ]);

        foreach ($subscriptionAction as $key => $value) {
            $client->setAttribute($key, $value);
        }
    }

    public function deletePreview(Request $request, Client $client)
    {
        $this->marketAuthorizationService->ensureManager($request->user());
        $this->authorizeClientAccess($request, $client);

        return response()->json(
            $this->clientDeletionService->previewDeletion($client)
        );
    }

    public function sendPaymentLink(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'mode' => 'required|in:quick_subscribe,existing_deal',
            'product_id' => 'required_if:mode,quick_subscribe|nullable|integer|min:1',
            'product_price_id' => 'required_if:mode,quick_subscribe|nullable|integer|min:1',
            'deal_id' => 'required_if:mode,existing_deal|nullable|integer|min:1',
            'payment_link_provider' => 'nullable|string|max:120',
            'reason' => 'nullable|string|max:500',
        ]);

        $client->loadMissing('platform');

        $paymentLinkSelection = $this->dealPaymentService->resolvePaymentLinkProvider(
            $client,
            $validated['payment_link_provider'] ?? null,
            $request->user()
        );
        $paymentLinkProvider = $paymentLinkSelection['provider'] ?? null;

        if ($validated['mode'] === 'quick_subscribe') {
            $result = DB::transaction(function () use ($request, $client, $validated, $paymentLinkProvider, $paymentLinkSelection) {
                $deal = $this->dealPaymentService->createPendingDealFromCatalog(
                    $client,
                    (int) $validated['product_id'],
                    isset($validated['product_price_id']) ? (int) $validated['product_price_id'] : null,
                    null,
                    (int) $request->user()->id,
                    null
                );

                return $this->dealPaymentService->startLinkPaymentForDeal(
                    $deal,
                    $client,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection,
                    true
                );
            });

            return response()->json(
                $this->paymentLinkResponsePayload($result['deal'], $result['payment'], $result),
                202
            );
        }

        $deal = Deal::query()
            ->where('client_id', (int) $client->id)
            ->whereKey((int) $validated['deal_id'])
            ->first();

        if (!$deal) {
            return response()->json([
                'message' => 'Selected deal does not belong to this client.',
            ], 422);
        }

        if (!in_array((string) $deal->status, ['pending', 'awaiting_payment'], true)) {
            return response()->json([
                'message' => 'Only pending or awaiting-payment deals can receive payment links.',
            ], 422);
        }

        $payment = $deal->payment;
        if ($deal->status === 'pending' && !$payment) {
            $result = DB::transaction(function () use ($request, $deal, $client, $paymentLinkProvider, $paymentLinkSelection) {
                return $this->dealPaymentService->startLinkPaymentForDeal(
                    $deal,
                    $client,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection,
                    true
                );
            });

            return response()->json(
                $this->paymentLinkResponsePayload($result['deal'], $result['payment'], $result),
                202
            );
        }

        if (!$payment) {
            $result = DB::transaction(function () use ($request, $deal, $client, $paymentLinkProvider, $paymentLinkSelection) {
                return $this->dealPaymentService->startLinkPaymentForDeal(
                    $deal,
                    $client,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection,
                    true
                );
            });

            return response()->json(
                $this->paymentLinkResponsePayload($result['deal'], $result['payment'], $result),
                202
            );
        }

        $paymentStatus = (string) $payment->status;
        if (in_array($paymentStatus, Payment::SUCCESSFUL_STATUSES, true)) {
            return response()->json([
                'message' => 'Payment already completed for this deal.',
            ], 422);
        }

        if (in_array($paymentStatus, Payment::RESENDABLE_LINK_STATUSES, true)) {
            $sendResult = $this->paymentLinkService->sendLink($payment, [
                'request' => $request,
                'channel' => 'sms',
                'phone' => $payment->phone ?: $client->phone_normalized,
                'provider' => $paymentLinkProvider,
                'requested_provider' => $paymentLinkSelection['requested_provider'] ?? null,
                'provider_override_requested' => (bool) ($paymentLinkSelection['override_requested'] ?? false),
                'provider_override_applied' => (bool) ($paymentLinkSelection['override_applied'] ?? false),
                'provider_override_denied' => (bool) ($paymentLinkSelection['override_denied'] ?? false),
                'provider_override_actor_role' => $paymentLinkSelection['actor_role'] ?? null,
                'reason' => (string) ($validated['reason'] ?? 'Resend payment link from client profile'),
                'notification_purpose' => 'deal_activation_payment_link',
                'notification_context' => [
                    'deal_id' => $deal->id,
                ],
                'success_message' => 'Payment link sent by SMS. Subscription will activate after payment confirmation.',
                'disabled_message' => 'Payment link prepared (SMS disabled). Subscription will activate after payment confirmation.',
            ]);

            if (!($sendResult['success'] ?? false) && empty($sendResult['payment_url'])) {
                return response()->json([
                    'message' => $sendResult['message'] ?? 'Payment link SMS could not be sent.',
                ], (int) ($sendResult['http_status'] ?? 500));
            }

            return response()->json(
                $this->paymentLinkResponsePayload($deal, $payment, [
                    'message' => $sendResult['message'] ?? 'Payment link prepared.',
                    'payment_url' => $sendResult['payment_url'] ?? null,
                    'sms_result' => $sendResult['notification_result'] ?? null,
                    'phone' => $sendResult['phone'] ?? ($payment->phone ?: $client->phone_normalized),
                ]),
                202
            );
        }

        if (in_array($paymentStatus, Payment::REPLACEMENT_REQUIRED_STATUSES, true)) {
            $result = DB::transaction(function () use ($request, $deal, $client, $paymentLinkProvider, $paymentLinkSelection) {
                return $this->dealPaymentService->startLinkPaymentForDeal(
                    $deal,
                    $client,
                    $request,
                    $paymentLinkProvider,
                    $paymentLinkSelection,
                    true
                );
            });

            return response()->json(
                $this->paymentLinkResponsePayload($result['deal'], $result['payment'], $result),
                202
            );
        }

        return response()->json([
            'message' => 'This deal cannot receive a new payment link in its current payment state.',
        ], 422);
    }

    public function destroy(Request $request, Client $client)
    {
        $this->marketAuthorizationService->ensureManager($request->user());
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'confirm' => 'required|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        if (trim((string) $validated['confirm']) !== trim((string) $client->name)) {
            return response()->json([
                'message' => 'Confirmation text must match the client name exactly.',
            ], 422);
        }

        $result = $this->clientDeletionService->deleteClient(
            $client,
            (int) $request->user()->id,
            (string) ($validated['reason'] ?? 'Client deleted from CRM')
        );

        return response()->json($result);
    }

    public function bulkDeletePreview(Request $request)
    {
        $this->marketAuthorizationService->ensureManager($request->user());

        $validated = $request->validate([
            'client_ids' => 'nullable|array|max:500',
            'client_ids.*' => 'integer|min:1',
            'filters' => 'nullable|array',
            'filters.platform_id' => 'nullable|integer|min:1',
            'filters.inactive_days' => 'nullable|integer|min:1',
            'filters.has_no_chat' => 'nullable|boolean',
            'filters.has_no_subscription_or_payment' => 'nullable|boolean',
        ]);

        $clientIds = collect($validated['client_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $filters = $validated['filters'] ?? [];

        if (empty($clientIds) && empty($filters)) {
            return response()->json([
                'message' => 'Provide selected client IDs or smart-delete filters to preview.',
            ], 422);
        }

        if (!empty($filters['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $filters['platform_id'],
                'You do not have access to this client market.'
            );
        }

        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        return response()->json(
            $this->clientDeletionService->bulkPreview($filters, $clientIds, $platformIds)
        );
    }

    public function bulkDelete(Request $request)
    {
        $this->marketAuthorizationService->ensureManager($request->user());

        $validated = $request->validate([
            'client_ids' => 'required|array|max:500',
            'client_ids.*' => 'integer|min:1',
            'confirm' => 'required|string',
            'reason' => 'nullable|string|max:500',
        ]);

        if (trim((string) $validated['confirm']) !== 'DELETE') {
            return response()->json([
                'message' => 'Type DELETE to confirm the bulk deletion.',
            ], 422);
        }

        $clientIds = collect($validated['client_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        $accessibleQuery = Client::query()->whereIn('id', $clientIds);
        if (is_array($platformIds)) {
            if (empty($platformIds)) {
                $accessibleQuery->whereRaw('1 = 0');
            } else {
                $accessibleQuery->whereIn('platform_id', $platformIds);
            }
        }

        $accessibleCount = $accessibleQuery->count();

        if ($accessibleCount !== count($clientIds)) {
            return response()->json([
                'message' => 'One or more selected clients are not accessible for deletion.',
            ], 403);
        }

        return response()->json(
            $this->clientDeletionService->bulkDelete(
                $clientIds,
                (int) $request->user()->id,
                (string) ($validated['reason'] ?? 'Bulk client deletion from CRM')
            )
        );
    }

    /**
     * Profile completeness — separate endpoint so it doesn't block show().
     */
    public function profileCompleteness(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        return response()->json($this->computeProfileCompleteness($client));
    }

    public function retentionInsight(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $insight = $this->clientRetentionInsightService->getOrRefreshForClient($client);

        return response()->json(
            $this->clientRetentionInsightService->buildClientPayload($insight)
        );
    }

    public function retentionHistory(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $rows = DB::table('client_retention_insight_history')
            ->where('client_id', (int) $client->id)
            ->orderBy('recorded_date')
            ->limit(90)
            ->get(['score', 'band', 'recorded_date']);

        return response()->json([
            'history' => $rows->map(fn ($row) => [
                'date' => $row->recorded_date,
                'score' => (int) $row->score,
                'health_score' => 100 - (int) $row->score,
                'band' => $row->band,
            ])->values()->all(),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'city' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone_normalized' => 'nullable|string|max:20',
        ]);

        $beforeState = [
            'assigned_to' => $client->assigned_to,
            'city' => $client->city,
            'email' => $client->email,
            'phone_normalized' => $client->phone_normalized,
        ];
        $hadSupportBoardLink = !empty($client->sb_user_id);

        if (array_key_exists('assigned_to', $validated) && $validated['assigned_to']) {
            $assignee = User::query()->find((int) $validated['assigned_to']);
            if (!$assignee || !$assignee->isActive() || !$this->marketAuthorizationService->userCanAccessPlatform($assignee, (int) $client->platform_id)) {
                return response()->json([
                    'message' => 'Assigned owner is not eligible for this market.',
                ], 422);
            }
        }

        $client->update($validated);

        if (
            $hadSupportBoardLink
            && ($client->wasChanged('phone_normalized') || $client->wasChanged('email'))
        ) {
            SupportBoardService::clearResolveCache($client);
            $client->forceFill([
                'sb_user_id' => null,
                'sb_matched_by' => null,
            ])->saveQuietly();
        }

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'client_updated',
            'actor_id' => $request->user()->id,
            'content' => [
                'before' => $beforeState,
                'after' => [
                    'assigned_to' => $client->assigned_to,
                    'city' => $client->city,
                    'email' => $client->email,
                    'phone_normalized' => $client->phone_normalized,
                ],
            ],
            'created_at' => now(),
        ]);

        $client->load(['platform', 'assignedAgent']);

        return response()->json($client);
    }

    public function timeline(Client $client, Request $request)
    {
        $this->authorizeClientAccess($request, $client);

        $events = TimelineEvent::forEntity('client', $client->id)
            ->with('actor')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        return response()->json($events);
    }

    public function storeNote(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'note_type' => 'required|in:call,email,sms,whatsapp,internal,system,support_chat',
            'content' => 'required|string|max:5000',
            'follow_up_at' => 'nullable|date|after:now',
        ]);

        $note = ClientNote::create([
            'client_id' => $client->id,
            'author_id' => $request->user()->id,
            'note_type' => $validated['note_type'],
            'content' => $validated['content'],
            'follow_up_at' => $validated['follow_up_at'] ?? null,
            'created_at' => now(),
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'note_added',
            'actor_id' => $request->user()->id,
            'content' => [
                'note_id' => $note->id,
                'note_type' => $note->note_type,
                'has_follow_up' => $note->follow_up_at !== null,
            ],
            'created_at' => now(),
        ]);

        $note->load('author');
        return response()->json($note, 201);
    }

    public function syncOne(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client record is CRM-only and cannot be synced from WordPress yet.',
            ], 422);
        }

        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $syncService = new \App\Services\ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);
            $client->refresh();
            $this->refreshClientDisplayImageCache($client, verifyReachable: false);
            $client->refresh();
            $client->load([
                'platform',
                'assignedAgent',
                'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
                'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'activeDeal.product',
                'kycSubject.sites',
            ]);
            $this->hydrateBillingPlatformState($client);
            $this->appendSubscriptionActionMetadata($client);

            return response()->json($client);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─── Verified Status ────────────────────────────────────────────────────────

    public function updateVerifiedStatus(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        $validated = $request->validate([
            'verified' => 'required|boolean',
            'source' => 'nullable|string',
            'reason' => 'nullable|string|max:2000',
        ]);
        $verified  = (bool) $validated['verified'];

        if ($verified) {
            $source = (string) ($validated['source'] ?? '');
            $reason = trim((string) ($validated['reason'] ?? ''));

            if (($request->user()->role ?? '') !== 'admin' || $source !== 'manual_crm_emergency' || $reason === '') {
                return response()->json([
                    'message' => 'Setting verified=true requires the admin-only manual_crm_emergency path with an explicit reason.',
                ], 422);
            }
        }

        $before = [
            'verified' => (bool) $client->verified,
            'verified_source' => $client->verified_source,
        ];

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            // Push to WP via the existing generic update endpoint.
            // Sends '1'/'0' so the WP theme treats it as a truthy meta value.
            $wpSync->updateClientProfile((int) $client->wp_post_id, [
                'verified' => $verified ? '1' : '0',
            ]);
        } catch (RequestException $e) {
            $status  = $e->response?->status() ?? 502;
            $payload = $e->response?->json();
            if ($status >= 400 && $status < 500) {
                return response()->json($payload ?? ['message' => 'WordPress rejected the request.'], $status);
            }
            return response()->json(['message' => 'WordPress update failed: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'WordPress update failed: ' . $e->getMessage()], 502);
        }

        if ($verified) {
            $subject = app(\App\Services\Kyc\KycSubjectService::class)->resolveOrCreateForClient($client);
            app(\App\Services\Kyc\KycSubjectService::class)->markApprovedFromSource(
                $subject,
                'manual_crm_emergency',
                $request->user(),
                trim((string) $validated['reason'])
            );
        } else {
            $client->forceFill([
                'verified' => false,
                'verified_source' => null,
                'verified_source_at' => null,
                'verified_source_actor_id' => null,
                'verified_source_reason' => null,
            ])->save();

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_VERIFIED_STATUS_UPDATE,
                'client',
                (int) $client->id,
                $before,
                ['verified' => false, 'verified_source' => null],
                'Manual verified badge removal'
            );
        }

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
        (new ClientSyncService($platform))->syncOne((int) $client->wp_post_id);
        $client->refresh();

        $client->load([
            'platform',
            'assignedAgent',
            'deals'      => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'notes'      => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
            'payments'   => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'activeDeal.product',
            'kycSubject.sites',
        ]);
        $this->hydrateBillingPlatformState($client);
        $this->appendSubscriptionActionMetadata($client);

        return response()->json($client);
    }

    // ─── New Badge ──────────────────────────────────────────────────────────────

    public function updateNewBadge(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        $validated = $request->validate([
            'mode' => 'nullable|string|in:auto,force_on,force_off',
            'force_new' => 'nullable|boolean',
        ]);

        $mode = $validated['mode'] ?? null;
        if ($mode === null && array_key_exists('force_new', $validated)) {
            $mode = (bool) $validated['force_new'] ? 'force_on' : 'auto';
        }

        if ($mode === null) {
            return response()->json([
                'message' => 'A NEW badge mode is required.',
            ], 422);
        }

        $forceNew = $mode === 'force_on';
        $before = [
            'force_new' => (bool) $client->force_new,
            'new_badge_mode' => in_array((string) $client->new_badge_mode, ['auto', 'force_on', 'force_off'], true)
                ? (string) $client->new_badge_mode
                : ((bool) $client->force_new ? 'force_on' : 'auto'),
        ];

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $wpSync->updateClientProfile((int) $client->wp_post_id, [
                'new_badge_mode' => $mode,
                'force_new' => $forceNew ? '1' : '',
            ]);
        } catch (RequestException $e) {
            $status  = $e->response?->status() ?? 502;
            $payload = $e->response?->json();
            if ($status >= 400 && $status < 500) {
                return response()->json($payload ?? ['message' => 'WordPress rejected the request.'], $status);
            }
            return response()->json(['message' => 'WordPress update failed: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'WordPress update failed: ' . $e->getMessage()], 502);
        }

        $client->update([
            'force_new' => $forceNew,
            'new_badge_mode' => $mode,
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_NEW_BADGE_UPDATE,
            'client',
            (int) $client->id,
            $before,
            [
                'force_new' => $forceNew,
                'new_badge_mode' => $mode,
            ]
        );

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
        (new ClientSyncService($platform))->syncOne((int) $client->wp_post_id);
        $client->refresh();

        $client->load([
            'platform',
            'assignedAgent',
            'deals'    => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'notes'    => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
            'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'activeDeal.product',
        ]);
        $this->hydrateBillingPlatformState($client);
        $this->appendSubscriptionActionMetadata($client);

        return response()->json($client);
    }

    // ─── Auto-push boost ─────────────────────────────────────────────────────────

    /**
     * Boost a client for auto-push: force-prioritise them in the next auto-push
     * run(s) for their market for a bounded window (default 48h).
     */
    public function boost(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'hours' => 'nullable|integer|min:1|max:168',
        ]);

        $hours = (int) ($validated['hours'] ?? 48);
        $before = [
            'boosted_until' => $client->boosted_until?->toIso8601String(),
        ];

        $client->update([
            'boosted_until' => now()->addHours($hours),
            'boosted_at' => now(),
            'boosted_by' => (int) $request->user()->id,
        ]);

        try {
            $boostDispatch = $this->autoPushBoostService->dispatchNow($client->fresh('platform'), (int) $request->user()->id);
        } catch (\Throwable $exception) {
            Log::warning('auto_push.boost_dispatch_failed', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'error' => $exception->getMessage(),
            ]);
            $boostDispatch = [
                'status' => 'failed',
                'campaign_id' => null,
                'campaign_item_id' => null,
                'reshuffled_items' => 0,
                'message' => 'Boost was saved, but the immediate push could not be queued.',
            ];
        }

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_BOOST_SET,
            'client',
            (int) $client->id,
            $before,
            [
                'boosted_until' => $client->boosted_until?->toIso8601String(),
                'hours' => $hours,
            ]
        );

        TimelineEvent::query()->create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'client_boosted',
            'actor_id' => (int) $request->user()->id,
            'content' => [
                'hours' => $hours,
                'boosted_until' => $client->boosted_until?->toIso8601String(),
                'boost_dispatch' => $boostDispatch,
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => sprintf('Boosted for %d hours.', $hours),
            'is_boosted' => true,
            'boosted_until' => $client->boosted_until?->toIso8601String(),
            'boost_remaining_hours' => $client->boost_remaining_hours,
            'boost_dispatch' => $boostDispatch,
        ]);
    }

    /**
     * Clear an active boost.
     */
    public function unboost(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $before = [
            'boosted_until' => $client->boosted_until?->toIso8601String(),
        ];

        $client->update([
            'boosted_until' => null,
            'boosted_at' => null,
            'boosted_by' => null,
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_BOOST_CLEAR,
            'client',
            (int) $client->id,
            $before,
            ['boosted_until' => null]
        );

        return response()->json([
            'message' => 'Boost removed.',
            'is_boosted' => false,
            'boosted_until' => null,
            'boost_remaining_hours' => null,
        ]);
    }

    // ─── Tours ──────────────────────────────────────────────────────────────────

    public function tours(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json(['tours' => []]);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $data   = $wpSync->getTours((int) $client->wp_post_id);
            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch tours: ' . $e->getMessage()], 502);
        }
    }

    public function addTour(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json(['message' => 'This client is not linked to a WordPress profile.'], 422);
        }

        $validated = $request->validate([
            'city'  => 'required|string|max:255',
            'start' => 'required|date_format:Y-m-d',
            'end'   => 'required|date_format:Y-m-d|after_or_equal:start',
            'phone' => 'required|string|max:50',
        ]);

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $tour   = $wpSync->addTour((int) $client->wp_post_id, $validated);
        } catch (RequestException $e) {
            $status  = $e->response?->status() ?? 502;
            $payload = $e->response?->json();
            if ($status >= 400 && $status < 500) {
                return response()->json($payload ?? ['message' => 'WordPress rejected the tour.'], $status);
            }
            return response()->json(['message' => 'Failed to create tour: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to create tour: ' . $e->getMessage()], 502);
        }

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_TOUR_ADD,
            'client',
            (int) $client->id,
            [],
            ['tour' => $validated]
        );

        return response()->json($tour, 201);
    }

    public function deleteTour(Request $request, Client $client, int $tourId)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json(['message' => 'This client is not linked to a WordPress profile.'], 422);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $wpSync->deleteTour((int) $client->wp_post_id, $tourId);
        } catch (RequestException $e) {
            $status  = $e->response?->status() ?? 502;
            $payload = $e->response?->json();
            if ($status >= 400 && $status < 500) {
                return response()->json($payload ?? ['message' => 'WordPress rejected the request.'], $status);
            }
            return response()->json(['message' => 'Failed to delete tour: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to delete tour: ' . $e->getMessage()], 502);
        }

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_TOUR_DELETE,
            'client',
            (int) $client->id,
            ['tour_id' => $tourId],
            []
        );

        return response()->json(['message' => 'Tour deleted.']);
    }

    public function bulkRefreshDisplayImages(Request $request)
    {
        $validated = $request->validate([
            'client_ids' => 'required|array|max:200',
            'client_ids.*' => 'integer|min:1',
        ]);

        $clientIds = collect($validated['client_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($clientIds === []) {
            return response()->json([
                'message' => 'Select at least one client to refresh thumbnails.',
            ], 422);
        }

        $accessibleQuery = Client::query()->whereIn('id', $clientIds);
        $this->marketAuthorizationService->applyPlatformScope($accessibleQuery, $request->user());
        $accessibleCount = (clone $accessibleQuery)->count();

        if ($accessibleCount !== count($clientIds)) {
            return response()->json([
                'message' => 'One or more selected clients are not accessible for thumbnail refresh.',
            ], 403);
        }

        $clients = $accessibleQuery
            ->with('platform')
            ->orderBy('id')
            ->get();

        $refreshed = 0;
        $cleared = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        foreach ($clients as $client) {
            if ((int) $client->wp_post_id <= 0) {
                $skipped++;
                $results[] = [
                    'client_id' => (int) $client->id,
                    'status' => 'skipped',
                    'message' => 'Client is not linked to WordPress.',
                ];
                continue;
            }

            try {
                $selection = $this->clientProfileImageService->refreshClient($client, verifyReachable: false);

                if ($selection) {
                    $refreshed++;
                    $results[] = [
                        'client_id' => (int) $client->id,
                        'status' => 'refreshed',
                        'display_image_url' => $selection['url'],
                        'display_image_source' => $selection['source'],
                    ];
                } else {
                    $cleared++;
                    $results[] = [
                        'client_id' => (int) $client->id,
                        'status' => 'cleared',
                        'message' => 'No usable WordPress image was found.',
                    ];
                }
            } catch (\Throwable $exception) {
                $failed++;
                $results[] = [
                    'client_id' => (int) $client->id,
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Client thumbnails refresh completed.',
            'processed_count' => count($clientIds),
            'refreshed_count' => $refreshed,
            'cleared_count' => $cleared,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'results' => $results,
        ], $failed > 0 ? 207 : 200);
    }

    public function deactivateSubscription(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'reason_code' => 'nullable|string|in:' . implode(',', $this->deactivationReasonValues()),
            'reason_notes' => 'nullable|string|max:500',
            'notify_client' => 'nullable|boolean',
        ]);

        if (
            trim((string) ($validated['reason_code'] ?? '')) === ''
            && trim((string) ($validated['reason'] ?? '')) === ''
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reason_code' => 'A structured reason code or legacy reason is required.',
            ]);
        }

        $beforeState = [
            'profile_status' => $client->profile_status,
            'needs_payment' => (bool) $client->needs_payment,
            'notactive' => (bool) $client->notactive,
            'premium' => (bool) $client->premium,
            'featured' => (bool) $client->featured,
            'escort_expire' => $client->escort_expire,
            'is_high_risk' => (bool) $client->is_high_risk,
            'risk_reason_code' => $client->risk_reason_code,
        ];

        $deactivationRequest = $this->buildClientDeactivationRequest($validated);

        DB::beginTransaction();
        try {
            $client = $this->clientSubscriptionDeactivationService->deactivate(
                $client,
                $deactivationRequest,
                optional($request->user())->id
            );

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_SUBSCRIPTION_DEACTIVATE,
                'client',
                (int) $client->id,
                $beforeState,
                [
                    'profile_status' => $client->profile_status,
                    'needs_payment' => (bool) $client->needs_payment,
                    'notactive' => (bool) $client->notactive,
                    'premium' => (bool) $client->premium,
                    'featured' => (bool) $client->featured,
                    'escort_expire' => $client->escort_expire,
                    'is_high_risk' => (bool) $client->is_high_risk,
                    'risk_reason_code' => $client->risk_reason_code,
                    'deactivation_scope' => 'client_wp_subscription',
                ],
                $deactivationRequest->auditReason()
            );

            DB::commit();

            if ($request->boolean('notify_client')) {
                $message = $this->resolveClientSubscriptionDeactivationMessage($client);
                if ($message !== null) {
                    $this->notificationService->sendSmsToClient($client, $message, [
                        'purpose' => 'client_subscription_deactivate_notice',
                    ]);
                }
            }

            $client->load([
                'platform',
                'assignedAgent',
                'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
                'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'activeDeal.product',
            ]);
            $this->hydrateBillingPlatformState($client);
            $this->appendSubscriptionActionMetadata($client);

            return response()->json([
                'message' => 'WordPress-only subscription deactivated.',
                'client' => $client,
            ]);
        } catch (InvalidArgumentException $exception) {
            DB::rollBack();

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Subscription deactivation failed: ' . $exception->getMessage(),
            ], 500);
        }
    }

    public function wpProfile(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $profile = $wpSync->getClientProfile((int) $client->wp_post_id);

            return response()->json([
                'wp_profile' => $profile,
            ]);
        } catch (RequestException $exception) {
            return $this->handleWpReadRequestException($exception, $client, 'Failed to fetch WordPress profile.');
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to fetch WordPress profile.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function profileAnalytics(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $analytics = $wpSync->getAnalytics(
                (int) $client->wp_post_id,
                $validated['from'] ?? null,
                $validated['to'] ?? null
            );

            return response()->json($analytics);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to fetch WordPress profile analytics.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function updateWpProfile(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        $validated = $request->validate([
            'fields' => 'required|array|min:1',
            'force' => 'nullable|boolean',
            'reason' => 'required|string|max:500',
        ]);
        $requestedFields = $validated['fields'];
        $blockedFields = [
            'premium',
            'premium_expire',
            'featured',
            'featured_expire',
            'escort_expire',
            'profile_status',
            'needs_payment',
            'notactive',
        ];

        $attemptedBlocked = array_values(array_intersect(array_keys($requestedFields), $blockedFields));
        if (!empty($attemptedBlocked)) {
            return response()->json([
                'message' => 'Subscription and activation fields are not editable from profile management.',
                'blocked_fields' => $attemptedBlocked,
            ], 422);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $currentProfile = $wpSync->getClientProfile((int) $client->wp_post_id);
            $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
            $fields = $this->prepareWpProfileFields($platform, $requestedFields, $currentProfile);

            $wpModifiedAt = $this->extractWpModifiedAt($currentProfile);
            $crmLastSyncedAt = $client->last_synced_at ? Carbon::parse($client->last_synced_at) : null;
            $force = (bool) ($validated['force'] ?? false);

            if (!$force && $wpModifiedAt && $crmLastSyncedAt && $wpModifiedAt->gt($crmLastSyncedAt)) {
                return response()->json([
                    'message' => 'WordPress profile was updated after CRM last sync. Review and confirm to overwrite.',
                    'conflict' => [
                        'wp_modified_at' => $wpModifiedAt->toIso8601String(),
                        'crm_last_synced_at' => $crmLastSyncedAt->toIso8601String(),
                        'diff' => $this->buildProfileDiff($fields, $currentProfile),
                    ],
                ], 409);
            }

            $beforeState = [
                'fields' => $this->snapshotWpFieldValues($fields, $currentProfile),
            ];

            $updatedProfile = $wpSync->updateClientProfile((int) $client->wp_post_id, $fields);

            $syncService = new \App\Services\ClientSyncService($platform);
            $syncService->syncOne((int) $client->wp_post_id);
            $client->refresh();
            $this->refreshClientDisplayImageCache($client, verifyReachable: false);
            $client->refresh();

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_PROFILE_EDIT,
                'client',
                (int) $client->id,
                $beforeState,
                [
                    'fields' => $fields,
                    'wp_profile_updated' => true,
                ],
                (string) $validated['reason']
            );

            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'client_profile_updated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'fields' => array_keys($fields),
                ],
                'created_at' => now(),
            ]);

            return response()->json([
                'client' => $client->load([
                    'platform',
                    'assignedAgent',
                    'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                    'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
                    'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                    'activeDeal.product',
                ]),
                'wp_profile' => $updatedProfile,
            ]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $message = collect($errors)
                ->flatten()
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->first() ?: 'WordPress profile update validation failed.';

            return response()->json([
                'message' => $message,
                'errors' => $errors,
            ], 422);
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? 502;
            $payload = $exception->response?->json();
            if (!is_array($payload)) {
                $payload = [
                    'message' => $exception->response?->body() ?: 'WordPress profile update failed.',
                ];
            }

            if ($status >= 400 && $status < 500) {
                return response()->json($payload, $status);
            }

            return response()->json([
                'message' => 'Failed to update WordPress profile.',
                'error' => $payload['message'] ?? $exception->getMessage(),
            ], 502);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to update WordPress profile.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function media(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            return response()->json($wpSync->getClientMedia((int) $client->wp_post_id));
        } catch (RequestException $exception) {
            return $this->handleWpReadRequestException($exception, $client, 'Failed to fetch client media.');
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to fetch client media.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function repairWpLink(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        try {
            $result = $this->clientWpLinkRepairService->repair($client);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RequestException $exception) {
            $payload = $exception->response?->json();
            $error = is_array($payload)
                ? ($payload['message'] ?? $exception->getMessage())
                : ($exception->response?->body() ?: $exception->getMessage());

            return response()->json([
                'message' => 'WordPress link repair could not be synchronized.',
                'error' => $error,
            ], 502);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'WordPress link repair failed.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        if (($result['status'] ?? '') !== 'repaired') {
            return response()->json([
                'message' => $result['message'] ?? 'WordPress link repair could not be completed.',
                'repair' => [
                    'status' => $result['status'] ?? 'unknown',
                    'candidate_post_ids' => $result['candidate_post_ids'] ?? [],
                    'conflict_client_id' => $result['conflict_client_id'] ?? null,
                    'profile_post_type' => $result['profile_post_type'] ?? null,
                ],
            ], 422);
        }

        /** @var Client $repairedClient */
        $repairedClient = $result['client'];
        $this->refreshClientDisplayImageCache($repairedClient, verifyReachable: false);
        $repairedClient->refresh();

        $this->auditService->fromRequest(
            $request,
            (int) $repairedClient->platform_id,
            CrmAuditAction::CLIENT_PROFILE_EDIT,
            'client',
            (int) $repairedClient->id,
            [
                'wp_post_id' => $result['previous_wp_post_id'] ?? null,
            ],
            [
                'wp_post_id' => $result['wp_post_id'] ?? null,
                'repair' => [
                    'status' => $result['status'],
                    'candidate_post_ids' => $result['candidate_post_ids'] ?? [],
                    'profile_post_type' => $result['profile_post_type'] ?? null,
                ],
            ],
            'Repaired stale WordPress profile link from CRM'
        );

        return response()->json([
            'message' => $result['message'],
            'client' => $repairedClient,
            'repair' => [
                'status' => $result['status'],
                'wp_post_id' => $result['wp_post_id'] ?? null,
                'previous_wp_post_id' => $result['previous_wp_post_id'] ?? null,
                'candidate_post_ids' => $result['candidate_post_ids'] ?? [],
                'profile_post_type' => $result['profile_post_type'] ?? null,
            ],
        ]);
    }

    public function uploadMedia(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        $validated = $request->validate([
            'file' => [
                'sometimes',
                'file',
                'mimes:' . self::PROFILE_MEDIA_ALLOWED_EXTENSIONS,
            ],
            'files' => ['sometimes', 'array', 'min:1'],
            'files.*' => ['file', 'mimes:' . self::PROFILE_MEDIA_ALLOWED_EXTENSIONS],
            'set_main' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ], [
            'file.mimes' => 'The file must be a JPEG, PNG, WEBP image, or MP4 video.',
            'files.*.mimes' => 'Each file must be a JPEG, PNG, WEBP image, or MP4 video.',
        ]);

        $uploadedFiles = $this->resolveProfileMediaUploadFiles($request);
        if ($uploadedFiles === []) {
            return response()->json([
                'message' => 'At least one file upload is required.',
            ], 422);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $setMain = (bool) ($validated['set_main'] ?? false);
            $this->validateProfileMediaBatch($uploadedFiles, $setMain);

            $existingMedia = $wpSync->getClientMedia((int) $client->wp_post_id);
            $this->ensureProfileMediaCapacity($existingMedia, $uploadedFiles);

            $results = [];
            foreach ($uploadedFiles as $index => $file) {
                $results[] = $wpSync->uploadClientMedia(
                    (int) $client->wp_post_id,
                    $file,
                    $setMain && count($uploadedFiles) === 1 && $index === 0 && !$this->isProfileMediaVideoUpload($file)
                );
            }

            try {
                $refreshedMedia = $wpSync->getClientMedia((int) $client->wp_post_id);
                $this->refreshClientDisplayImageCache($client, verifyReachable: false, mediaPayload: $refreshedMedia);
                $client->refresh();
            } catch (\Throwable $refreshException) {
                Log::warning('Failed to refresh client media cache after upload.', [
                    'client_id' => $client->id,
                    'platform_id' => $client->platform_id,
                    'wp_post_id' => $client->wp_post_id,
                    'error' => $refreshException->getMessage(),
                ]);
            }

            $uploadedAttachments = collect($results)
                ->map(fn (array $result): array => (array) ($result['attachment'] ?? []))
                ->values()
                ->all();

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_PROFILE_EDIT,
                'client',
                (int) $client->id,
                null,
                [
                    'media_upload' => [
                        'upload_count' => count($uploadedAttachments),
                        'attachments' => $uploadedAttachments,
                        'set_main' => $setMain && count($uploadedFiles) === 1,
                    ],
                ],
                $validated['reason'] ?? 'Uploaded profile media from CRM'
            );

            $response = [
                'success' => true,
                'uploaded_count' => count($uploadedAttachments),
                'attachments' => $uploadedAttachments,
                'message' => count($uploadedAttachments) === 1
                    ? 'Media uploaded successfully.'
                    : sprintf('%d images uploaded successfully.', count($uploadedAttachments)),
            ];

            if (count($uploadedAttachments) === 1) {
                $response['attachment'] = $uploadedAttachments[0];
            }

            return response()->json($response);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to upload media.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function deleteMedia(Request $request, Client $client, int $attachmentId)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $result = $wpSync->deleteClientMedia((int) $client->wp_post_id, $attachmentId);

            $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
            (new \App\Services\ClientSyncService($platform))->syncOne((int) $client->wp_post_id);
            $client->refresh();
            $this->refreshClientDisplayImageCache($client, verifyReachable: false);

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_PROFILE_EDIT,
                'client',
                (int) $client->id,
                null,
                [
                    'media_delete' => [
                        'attachment_id' => $attachmentId,
                    ],
                ],
                $validated['reason'] ?? 'Deleted profile media from CRM'
            );

            return response()->json($result);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to delete media.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function setMainMedia(Request $request, Client $client, int $attachmentId)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client is not linked to a WordPress profile.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $result = $wpSync->setClientMainImage((int) $client->wp_post_id, $attachmentId);

            $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
            (new \App\Services\ClientSyncService($platform))->syncOne((int) $client->wp_post_id);
            $client->refresh();
            $this->refreshClientDisplayImageCache($client, verifyReachable: false);

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_PROFILE_EDIT,
                'client',
                (int) $client->id,
                null,
                [
                    'media_set_main' => [
                        'attachment_id' => $attachmentId,
                    ],
                ],
                $validated['reason'] ?? 'Set main profile image from CRM'
            );

            return response()->json($result);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to set main image.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    private function refreshClientDisplayImageCache(Client $client, bool $verifyReachable, ?array $mediaPayload = null): void
    {
        try {
            $this->clientProfileImageService->refreshClient($client, $mediaPayload, verifyReachable: $verifyReachable);
        } catch (\Throwable $exception) {
            Log::warning('Failed to refresh client display image cache.', [
                'client_id' => $client->id,
                'platform_id' => $client->platform_id,
                'wp_post_id' => $client->wp_post_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function health(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $duplicates = collect();
        if ($client->phone_normalized) {
            $duplicates = Client::query()
                ->where('platform_id', (int) $client->platform_id)
                ->where('phone_normalized', $client->phone_normalized)
                ->where('id', '!=', (int) $client->id)
                ->orderBy('id')
                ->get([
                    'id',
                    'name',
                    'wp_post_id',
                    'profile_status',
                    'duplicate_of',
                    'phone_normalized',
                    'created_at',
                ]);
        }

        $duplicateIds = $duplicates->pluck('id')->map(fn($id) => (int) $id)->all();
        $activeDealsByClient = empty($duplicateIds)
            ? collect()
            : Deal::query()
                ->whereIn('client_id', $duplicateIds)
                ->where('status', 'active')
                ->selectRaw('client_id, COUNT(*) as active_count')
                ->groupBy('client_id')
                ->pluck('active_count', 'client_id');
        $lastPaymentsByClient = empty($duplicateIds)
            ? collect()
            : Payment::query()
                ->whereIn('client_id', $duplicateIds)
                ->selectRaw('client_id, MAX(created_at) as last_payment_at')
                ->groupBy('client_id')
                ->pluck('last_payment_at', 'client_id');

        $leads = collect();
        if ($client->phone_normalized) {
            $leads = Lead::query()
                ->where('platform_id', (int) $client->platform_id)
                ->where('phone_normalized', $client->phone_normalized)
                ->orderByDesc('created_at')
                ->limit(30)
                ->get([
                    'id',
                    'name',
                    'status',
                    'converted_client_id',
                    'archived_at',
                    'created_at',
                ]);
        }

        return response()->json([
            'summary' => [
                'phone_normalized' => $client->phone_normalized,
                'duplicate_count' => $duplicates->count(),
                'lead_matches' => $leads->count(),
            ],
            'duplicates' => $duplicates->map(function (Client $duplicate) use ($activeDealsByClient, $lastPaymentsByClient) {
                return [
                    'id' => (int) $duplicate->id,
                    'name' => $duplicate->name,
                    'wp_post_id' => $duplicate->wp_post_id,
                    'profile_status' => $duplicate->profile_status,
                    'duplicate_of' => $duplicate->duplicate_of,
                    'phone_normalized' => $duplicate->phone_normalized,
                    'active_deals_count' => (int) ($activeDealsByClient->get($duplicate->id) ?? 0),
                    'last_payment_at' => $lastPaymentsByClient->get($duplicate->id),
                    'created_at' => optional($duplicate->created_at)->toDateTimeString(),
                ];
            })->values(),
            'lead_matches' => $leads->map(function (Lead $lead) {
                return [
                    'id' => (int) $lead->id,
                    'name' => $lead->name,
                    'status' => $lead->status,
                    'converted_client_id' => $lead->converted_client_id,
                    'archived_at' => optional($lead->archived_at)->toDateTimeString(),
                    'created_at' => optional($lead->created_at)->toDateTimeString(),
                ];
            })->values(),
        ]);
    }

    public function resolveHealth(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'action' => 'required|in:keep_primary,merge_into_primary,archive_duplicate,update_phone',
            'duplicate_ids' => 'nullable|array',
            'duplicate_ids.*' => 'integer|exists:clients,id',
            'duplicate_id' => 'nullable|integer|exists:clients,id',
            'new_phone_normalized' => 'nullable|string|max:20',
            'reason' => 'required|string|max:500',
        ]);

        $action = (string) $validated['action'];
        $beforeState = null;
        $afterState = null;
        $result = [
            'deals_reassigned' => 0,
            'payments_reassigned' => 0,
            'leads_relinked' => 0,
            'notes_copied' => 0,
            'duplicates_updated' => 0,
            'auto_matched_payments' => 0,
        ];

        if ($action === 'update_phone') {
            $duplicateId = (int) ($validated['duplicate_id'] ?? 0);
            $client->loadMissing('platform');
            $platformPhonePrefix = (string) ($client->platform?->phone_prefix ?: '254');
            $normalizedPhone = PhoneNormalizer::normalize(
                $validated['new_phone_normalized'] ?? null,
                $platformPhonePrefix
            );
            if ($duplicateId <= 0 || !$normalizedPhone) {
                return response()->json([
                    'message' => 'duplicate_id and new_phone_normalized are required for update_phone.',
                ], 422);
            }

            $duplicate = Client::query()
                ->where('id', $duplicateId)
                ->where('platform_id', (int) $client->platform_id)
                ->where('id', '!=', (int) $client->id)
                ->first();

            if (!$duplicate) {
                return response()->json([
                    'message' => 'Duplicate client not found in this market.',
                ], 422);
            }

            $beforeState = [
                'duplicate_id' => (int) $duplicate->id,
                'phone_normalized' => $duplicate->phone_normalized,
                'duplicate_of' => $duplicate->duplicate_of,
            ];

            DB::transaction(function () use (
                $duplicate,
                $normalizedPhone,
                $client,
                $platformPhonePrefix,
                &$result
            ) {
                $duplicate->update([
                    'phone_normalized' => $normalizedPhone,
                    'duplicate_of' => null,
                ]);

                $matcher = app(PaymentMatchingService::class);
                $candidatePayments = Payment::query()
                    ->where('platform_id', (int) $client->platform_id)
                    ->whereNull('client_id')
                    ->where(function ($query) use ($normalizedPhone) {
                        $query->where('phone', $normalizedPhone)
                            ->orWhere('phone', 'like', '%' . $normalizedPhone . '%');
                    })
                    ->get();

                foreach ($candidatePayments as $payment) {
                    $matchResult = $matcher->matchPayment($payment, $platformPhonePrefix);
                    if (!empty($matchResult['matched'])) {
                        $result['auto_matched_payments'] += 1;
                    }
                }
            });

            $duplicate->refresh();
            $afterState = [
                'duplicate_id' => (int) $duplicate->id,
                'phone_normalized' => $duplicate->phone_normalized,
                'duplicate_of' => $duplicate->duplicate_of,
                'auto_matched_payments' => $result['auto_matched_payments'],
            ];
        } else {
            $duplicateIds = collect($validated['duplicate_ids'] ?? [])
                ->map(fn($id) => (int) $id)
                ->filter(fn($id) => $id > 0)
                ->unique()
                ->values();

            if ($duplicateIds->isEmpty()) {
                return response()->json([
                    'message' => 'Select at least one duplicate profile to resolve.',
                ], 422);
            }

            $duplicates = Client::query()
                ->whereIn('id', $duplicateIds->all())
                ->where('platform_id', (int) $client->platform_id)
                ->where('id', '!=', (int) $client->id)
                ->get();

            if ($duplicates->count() !== $duplicateIds->count()) {
                return response()->json([
                    'message' => 'One or more selected duplicates are invalid for this market.',
                ], 422);
            }

            $beforeState = [
                'action' => $action,
                'duplicates' => $duplicates->map(function (Client $duplicate) {
                    return [
                        'id' => (int) $duplicate->id,
                        'profile_status' => $duplicate->profile_status,
                        'duplicate_of' => $duplicate->duplicate_of,
                    ];
                })->values()->all(),
            ];

            DB::transaction(function () use (
                $action,
                $duplicates,
                $client,
                $request,
                &$result
            ) {
                foreach ($duplicates as $duplicate) {
                    if ($action === 'archive_duplicate') {
                        $duplicate->update([
                            'profile_status' => 'private',
                            'duplicate_of' => (int) $client->id,
                        ]);
                        $result['duplicates_updated'] += 1;
                        continue;
                    }

                    $duplicate->update([
                        'profile_status' => 'private',
                        'duplicate_of' => (int) $client->id,
                    ]);
                    $result['duplicates_updated'] += 1;

                    $result['deals_reassigned'] += Deal::query()
                        ->where('client_id', (int) $duplicate->id)
                        ->update(['client_id' => (int) $client->id]);

                    $result['payments_reassigned'] += Payment::query()
                        ->where('client_id', (int) $duplicate->id)
                        ->update(['client_id' => (int) $client->id]);

                    if ($action === 'merge_into_primary') {
                        $result['leads_relinked'] += Lead::query()
                            ->where('converted_client_id', (int) $duplicate->id)
                            ->update(['converted_client_id' => (int) $client->id]);

                        $notes = ClientNote::query()
                            ->where('client_id', (int) $duplicate->id)
                            ->orderBy('id')
                            ->get();

                        foreach ($notes as $note) {
                            ClientNote::create([
                                'client_id' => (int) $client->id,
                                'author_id' => (int) $request->user()->id,
                                'note_type' => 'system',
                                'content' => '[Merged from Client #' . $duplicate->id . '] ' . (string) $note->content,
                                'follow_up_at' => null,
                                'created_at' => now(),
                            ]);
                            $result['notes_copied'] += 1;
                        }
                    }
                }
            });

            $afterState = [
                'action' => $action,
                'result' => $result,
            ];
        }

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'client_health_resolved',
            'actor_id' => $request->user()->id,
            'content' => [
                'action' => $action,
                'result' => $result,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_HEALTH_RESOLVE,
            'client',
            (int) $client->id,
            $beforeState,
            $afterState,
            (string) $validated['reason']
        );

        return response()->json([
            'message' => 'Client health resolution applied.',
            'result' => $result,
        ]);
    }

    public function credentialDispatches(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $dispatches = $client->credentialDispatches()
            ->with('actor')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($dispatches);
    }

    public function credentialAccessContext(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        try {
            $context = $this->credentialDeliveryService->accessContext($client);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Credential access context lookup failed', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Credential access context could not be loaded.',
            ], 500);
        }

        return response()->json($context);
    }

    public function resetCredentials(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'temporary_password' => 'nullable|string|min:8|max:100',
            'reason' => 'required|string|max:500',
            'source' => 'nullable|string|max:100',
        ]);

        try {
            $result = $this->credentialDeliveryService->resetCredentials($client, $validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Credential reset failed', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Credential reset failed. Please retry.',
            ], 500);
        }

        $accessContext = (array) ($result['access_context'] ?? []);
        $password = (string) data_get($result, 'revealed.password', '');

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'client_credentials_reset',
            'actor_id' => (int) $request->user()->id,
            'content' => [
                'wp_user_id' => (int) ($client->wp_user_id ?? 0),
                'wp_username' => $accessContext['wp_username'] ?? null,
                'login_url' => $accessContext['login_url'] ?? null,
                'profile_url' => $accessContext['profile_url'] ?? null,
                'password_revealed' => $password !== '',
                'password_length' => $password !== '' ? strlen($password) : null,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_CREDENTIAL_RESET,
            'client',
            (int) $client->id,
            null,
            [
                'wp_user_id' => (int) ($client->wp_user_id ?? 0),
                'wp_username' => $accessContext['wp_username'] ?? null,
                'password_revealed' => $password !== '',
                'password_length' => $password !== '' ? strlen($password) : null,
            ],
            (string) $validated['reason']
        );

        return response()->json([
            'access_context' => $accessContext,
            'revealed' => [
                'password' => $password,
            ],
        ]);
    }

    public function loginAsClient(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'target' => 'nullable|in:edit_profile,change_password,profile,home',
            'reason' => 'required|string|max:500',
            'source' => 'nullable|string|max:100',
        ]);
        $isFieldDepositFlow = ($request->user()?->role ?? null) === MarketAuthorizationService::ROLE_FIELD_SALES
            || ($validated['source'] ?? null) === 'field_sales.deposit_flow';

        try {
            $result = $this->credentialDeliveryService->createClientSessionLink($client, [
                'target' => $validated['target'] ?? 'edit_profile',
                'reason' => $validated['reason'],
                'issued_by' => trim((string) ($request->user()?->email ?: $request->user()?->name ?: ('user#' . (int) $request->user()?->id))),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RequestException $exception) {
            Log::error('Client session link request failed', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'status' => $exception->response?->status(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Client session link could not be generated. Please retry.',
            ], 502);
        } catch (\Throwable $exception) {
            Log::error('Client session link generation failed', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Client session link could not be generated. Please retry.',
            ], 500);
        }

        $expiresAt = $result['expires_at'] ?? null;
        $target = (string) ($result['target'] ?? ($validated['target'] ?? 'edit_profile'));

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'client_login_as_client_link_generated',
            'actor_id' => (int) $request->user()->id,
            'content' => [
                'wp_post_id' => (int) ($client->wp_post_id ?? 0),
                'target' => $target,
                'expires_at' => $expiresAt,
                'session_link_generated' => true,
                'source' => $validated['source'] ?? null,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_LOGIN_AS_CLIENT_LINK,
            'client',
            (int) $client->id,
            null,
            [
                'wp_post_id' => (int) ($client->wp_post_id ?? 0),
                'target' => $target,
                'expires_at' => $expiresAt,
                'session_link_generated' => true,
                'source' => $validated['source'] ?? null,
                'field_sales_deposit_flow' => $isFieldDepositFlow,
                'request_ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ],
            (string) $validated['reason']
        );

        if ($isFieldDepositFlow) {
            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::FIELD_SALES_CLIENT_LOGIN_AS_CLIENT,
                'client',
                (int) $client->id,
                null,
                [
                    'wp_post_id' => (int) ($client->wp_post_id ?? 0),
                    'target' => $target,
                    'expires_at' => $expiresAt,
                    'source' => 'field_sales.deposit_flow',
                    'request_ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ],
                (string) $validated['reason']
            );
        }

        return response()->json([
            'url' => $result['url'],
            'expires_at' => $expiresAt,
            'target' => $target,
        ]);
    }

    public function sendCredentials(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'method' => 'required|in:setup_link,temporary_password',
            'channel' => 'required|in:email,sms,whatsapp,both,sms_whatsapp',
            'timing' => 'required|in:send_now,manual_send_later',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:30',
            'temporary_password' => 'nullable|string|min:8|max:100',
            'idempotency_key' => 'nullable|string|max:120',
            'reason' => 'required|string|max:500',
            'source' => 'nullable|string|max:100',
        ]);

        try {
            $dispatch = $this->credentialDeliveryService->createDispatch(
                $client,
                $validated,
                (int) $request->user()->id
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Credential dispatch create failed', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Credential dispatch failed. Please retry.',
            ], 500);
        }

        $dispatch->load('actor');
        $eventType = match ($dispatch->status) {
            'deferred' => 'client_credentials_deferred',
            'sent' => 'client_credentials_sent',
            'partial' => 'client_credentials_partially_sent',
            default => 'client_credentials_failed',
        };

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => $eventType,
            'actor_id' => (int) $request->user()->id,
            'content' => [
                'dispatch_id' => (int) $dispatch->id,
                'method' => $dispatch->method,
                'channel' => $dispatch->channel,
                'timing' => $dispatch->timing,
                'status' => $dispatch->status,
                'recipient_email' => $dispatch->recipient_email,
                'recipient_phone' => $dispatch->recipient_phone,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_CREDENTIAL_SEND,
            'client',
            (int) $client->id,
            null,
            [
                'dispatch_id' => (int) $dispatch->id,
                'method' => $dispatch->method,
                'channel' => $dispatch->channel,
                'timing' => $dispatch->timing,
                'status' => $dispatch->status,
            ],
            (string) $validated['reason']
        );

        return response()->json([
            'dispatch' => $dispatch,
            'recommendation' => $this->buildCredentialDispatchRecommendation($dispatch),
        ], 201);
    }

    public function retryCredentialDispatch(Request $request, Client $client, ClientCredentialDispatch $dispatch)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $dispatch->client_id !== (int) $client->id) {
            return response()->json([
                'message' => 'Dispatch record does not belong to this client.',
            ], 404);
        }

        $validated = $request->validate([
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:30',
            'temporary_password' => 'nullable|string|min:8|max:100',
            'idempotency_key' => 'nullable|string|max:120',
            'force' => 'nullable|boolean',
            'reason' => 'required|string|max:500',
        ]);

        if ((string) $dispatch->status === 'sent' && !((bool) ($validated['force'] ?? false))) {
            return response()->json([
                'message' => 'Credentials were already delivered. Set force=true to resend intentionally.',
            ], 409);
        }

        try {
            $dispatch = $this->credentialDeliveryService->retryDispatch(
                $client,
                $dispatch,
                (int) $request->user()->id,
                $validated
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Credential dispatch retry failed', [
                'client_id' => (int) $client->id,
                'dispatch_id' => (int) $dispatch->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Credential retry failed. Please retry again.',
            ], 500);
        }

        $dispatch->load('actor');
        $eventType = match ($dispatch->status) {
            'sent' => 'client_credentials_sent',
            'partial' => 'client_credentials_partially_sent',
            default => 'client_credentials_failed',
        };

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => $eventType,
            'actor_id' => (int) $request->user()->id,
            'content' => [
                'dispatch_id' => (int) $dispatch->id,
                'retry' => true,
                'method' => $dispatch->method,
                'channel' => $dispatch->channel,
                'status' => $dispatch->status,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_CREDENTIAL_RETRY,
            'client',
            (int) $client->id,
            null,
            [
                'dispatch_id' => (int) $dispatch->id,
                'status' => $dispatch->status,
                'method' => $dispatch->method,
                'channel' => $dispatch->channel,
            ],
            (string) $validated['reason']
        );

        return response()->json([
            'dispatch' => $dispatch,
            'recommendation' => $this->buildCredentialDispatchRecommendation($dispatch),
        ]);
    }

    private function authorizeClientAccess(Request $request, Client $client): void
    {
        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $client->platform_id)) {
            abort(403, 'You do not have access to this client market.');
        }
    }

    private function paymentLinkResponsePayload(Deal $deal, Payment $payment, array $result): array
    {
        return [
            'message' => $result['message'] ?? 'Payment link prepared.',
            'deal' => $deal->fresh(['product', 'platform', 'client', 'payment']),
            'payment' => $payment->fresh(['platform', 'product', 'client']),
            'payment_url' => $result['payment_url'] ?? data_get($payment->raw_payload, 'payment_url'),
            'sms_result' => $result['sms_result'] ?? $result['notification_result'] ?? null,
            'phone' => $result['phone'] ?? $payment->phone,
        ];
    }

    private function createProvisionedClient(Request $request, array $payload, string $reason): Client
    {
        $platformId = (int) ($payload['platform_id'] ?? 0);
        if ($platformId <= 0) {
            throw new \InvalidArgumentException('platform_id is required.');
        }

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this client market.'
        );

        $platform = Platform::query()->findOrFail($platformId);
        if (!$this->platformHasWpDatabaseCredentials($platform)) {
            throw new \InvalidArgumentException('WordPress database credentials are incomplete for this market.');
        }
        $phonePrefix = (string) ($platform->phone_prefix ?: '254');

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name is required.');
        }

        $profileStatus = strtolower(trim((string) ($payload['profile_status'] ?? 'private')));
        if (!in_array($profileStatus, ['publish', 'private', 'draft', 'pending'], true)) {
            $profileStatus = 'private';
        }

        $assignedTo = $this->resolveAssignedOwner($platformId, $payload, $name);
        $normalizedPhone = PhoneNormalizer::normalize($payload['phone_normalized'] ?? null, $phonePrefix) ?? '';
        $duplicatePhoneMatches = $this->duplicatePhoneMatches($platformId, $normalizedPhone);
        $signupSource = $this->resolveSignupSource($request, $payload, 'crm_provisioned');

        $provisioningResult = (new WpDirectProvisioningService($platform))->provisionEscort([
            'name' => $name,
            'email' => !empty($payload['email']) ? trim((string) $payload['email']) : '',
            'phone' => $normalizedPhone,
            'whatsapp' => $normalizedPhone,
            'city' => !empty($payload['city']) ? trim((string) $payload['city']) : '',
            'post_status' => $profileStatus,
            'username' => !empty($payload['wp_username']) ? trim((string) $payload['wp_username']) : '',
            'password' => !empty($payload['wp_password']) ? (string) $payload['wp_password'] : '',
            'signup_source' => $signupSource,
            'provision_request_id' => !empty($payload['provision_request_id'])
                ? trim((string) $payload['provision_request_id'])
                : (string) \Illuminate\Support\Str::uuid(),
            ...$this->extractProvisioningFields($payload),
        ]);

        $wpPostId = (int) ($provisioningResult['wp_post_id'] ?? 0);
        $wpUserId = (int) ($provisioningResult['wp_user_id'] ?? 0);
        if ($wpPostId <= 0 || $wpUserId <= 0) {
            throw new \RuntimeException('WordPress provisioning did not return valid profile IDs.');
        }

        $profileFinalizeStatus = $this->finalizeProvisionedWpProfile($platform, $wpPostId, $payload);

        $client = Client::updateOrCreate(
            [
                'platform_id' => $platformId,
                'wp_post_id' => $wpPostId,
            ],
            [
                'wp_user_id' => $wpUserId,
                'client_type' => 'escort',
                'name' => $name,
                'phone_normalized' => $normalizedPhone !== '' ? $normalizedPhone : null,
                'email' => !empty($payload['email']) ? trim((string) $payload['email']) : null,
                'city' => !empty($payload['city']) ? trim((string) $payload['city']) : null,
                'region' => null,
                'profile_status' => (string) ($provisioningResult['wp_post_status'] ?? $profileStatus),
                'assigned_to' => $assignedTo,
                'created_by' => (int) $request->user()->id,
                'signup_source' => $signupSource,
                'premium' => false,
                'featured' => false,
                'verified' => false,
                'last_synced_at' => now(),
            ]
        );

        $syncStatus = 'skipped';
        try {
            $syncedClient = (new ClientSyncService($platform))->syncOne($wpPostId);
            if ($assignedTo && (int) ($syncedClient->assigned_to ?? 0) !== $assignedTo) {
                $syncedClient->assigned_to = $assignedTo;
            }
            $syncedClient->created_by = $syncedClient->created_by ?: (int) $request->user()->id;
            $syncedClient->signup_source = $signupSource;
            $syncedClient->save();
            $client = $syncedClient;
            $syncStatus = 'success';
        } catch (\Throwable $exception) {
            $syncStatus = 'failed';
            Log::warning('Provisioned client created but syncOne failed', [
                'platform_id' => $platformId,
                'wp_post_id' => $wpPostId,
                'error' => $exception->getMessage(),
            ]);
        }

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'client_created',
            'actor_id' => $request->user()->id,
            'content' => [
                'source' => 'wp_provisioned',
                'signup_source' => $signupSource,
                'assigned_to' => $client->assigned_to,
                'profile_status' => $client->profile_status,
                'wp_post_id' => $client->wp_post_id,
                'wp_user_id' => $client->wp_user_id,
                'linked_existing_user' => (bool) ($provisioningResult['linked_existing_user'] ?? false),
                'placeholder_email_used' => (bool) ($provisioningResult['placeholder_email_used'] ?? false),
                'profile_finalize_status' => $profileFinalizeStatus,
                'sync_status' => $syncStatus,
                'duplicate_phone_matches' => $duplicatePhoneMatches,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_CREATE,
            'client',
            (int) $client->id,
            null,
            [
                'source' => 'wp_provisioned',
                'signup_source' => $signupSource,
                'name' => $client->name,
                'phone_normalized' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'profile_status' => $client->profile_status,
                'assigned_to' => $client->assigned_to,
                'wp_post_id' => $client->wp_post_id,
                'wp_user_id' => $client->wp_user_id,
                'linked_existing_user' => (bool) ($provisioningResult['linked_existing_user'] ?? false),
                'placeholder_email_used' => (bool) ($provisioningResult['placeholder_email_used'] ?? false),
                'sync_status' => $syncStatus,
                'duplicate_phone_matches' => $duplicatePhoneMatches,
            ],
            $reason
        );

        $client->load(['platform', 'assignedAgent', 'creator:id,name,email,role']);
        $client->setAttribute('duplicate_phone_matches', $duplicatePhoneMatches);

        return $client;
    }

    private function finalizeProvisionedWpProfile(Platform $platform, int $wpPostId, array $payload): string
    {
        return 'owned_by_direct_writer';
    }

    private function createManualClient(Request $request, array $payload, string $reason): Client
    {
        $platformId = (int) ($payload['platform_id'] ?? 0);
        if ($platformId <= 0) {
            throw new \InvalidArgumentException('platform_id is required.');
        }

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this client market.'
        );

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name is required.');
        }

        $profileStatus = strtolower(trim((string) ($payload['profile_status'] ?? 'private')));
        if (!in_array($profileStatus, ['publish', 'private', 'draft', 'pending'], true)) {
            $profileStatus = 'private';
        }

        $assignedTo = $this->resolveAssignedOwner($platformId, $payload, $name);
        $phonePrefix = (string) (Platform::query()->whereKey($platformId)->value('phone_prefix') ?: '254');
        $normalizedPhone = PhoneNormalizer::normalize($payload['phone_normalized'] ?? null, $phonePrefix);
        $duplicatePhoneMatches = $this->duplicatePhoneMatches($platformId, $normalizedPhone);
        $signupSource = $this->resolveSignupSource($request, $payload, 'crm_manual');

        $manualWpPostId = $this->nextManualWpPostId($platformId);

        $client = Client::create([
            'platform_id' => $platformId,
            'wp_post_id' => $manualWpPostId,
            'wp_user_id' => !empty($payload['wp_user_id']) ? (int) $payload['wp_user_id'] : null,
            'client_type' => 'escort',
            'name' => $name,
            'phone_normalized' => $normalizedPhone,
            'email' => !empty($payload['email']) ? trim((string) $payload['email']) : null,
            'city' => !empty($payload['city']) ? trim((string) $payload['city']) : null,
            'profile_status' => $profileStatus,
            'assigned_to' => $assignedTo,
            'created_by' => (int) $request->user()->id,
            'signup_source' => $signupSource,
            'premium' => false,
            'featured' => false,
            'verified' => false,
            'last_synced_at' => null,
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'client_created',
            'actor_id' => $request->user()->id,
            'content' => [
                'source' => 'manual',
                'signup_source' => $signupSource,
                'assigned_to' => $client->assigned_to,
                'profile_status' => $client->profile_status,
                'duplicate_phone_matches' => $duplicatePhoneMatches,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_CREATE,
            'client',
            (int) $client->id,
            null,
            [
                'name' => $client->name,
                'phone_normalized' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'profile_status' => $client->profile_status,
                'assigned_to' => $client->assigned_to,
                'wp_post_id' => $client->wp_post_id,
                'signup_source' => $signupSource,
                'duplicate_phone_matches' => $duplicatePhoneMatches,
            ],
            $reason
        );

        $client->load(['platform', 'assignedAgent', 'creator:id,name,email,role']);
        $client->setAttribute('duplicate_phone_matches', $duplicatePhoneMatches);

        return $client;
    }

    private function resolveSignupSource(Request $request, array $payload, string $default): string
    {
        $source = strtolower(trim((string) ($payload['signup_source'] ?? '')));

        if ($source === '') {
            return $default;
        }

        if ($source === 'field') {
            if (($request->user()?->role ?? null) !== MarketAuthorizationService::ROLE_FIELD_SALES) {
                throw new \InvalidArgumentException('Only field sales users can create field-sourced clients.');
            }

            return 'field';
        }

        return in_array($source, ['crm_manual', 'crm_provisioned'], true) ? $source : $default;
    }

    private function duplicatePhoneMatches(int $platformId, ?string $normalizedPhone, ?int $excludeClientId = null): array
    {
        $phone = trim((string) $normalizedPhone);
        if ($phone === '') {
            return [];
        }

        return Client::query()
            ->where('platform_id', $platformId)
            ->where('phone_normalized', $phone)
            ->when($excludeClientId, fn ($query) => $query->where('id', '!=', $excludeClientId))
            ->latest('id')
            ->limit(10)
            ->get(['id', 'name', 'phone_normalized', 'profile_status', 'wp_post_id'])
            ->map(fn (Client $client) => [
                'id' => (int) $client->id,
                'name' => $client->name,
                'phone_normalized' => $client->phone_normalized,
                'profile_status' => $client->profile_status,
                'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            ])
            ->values()
            ->all();
    }

    private function nextManualWpPostId(int $platformId): int
    {
        $minId = Client::query()
            ->where('platform_id', $platformId)
            ->min('wp_post_id');

        if ($minId === null || (int) $minId >= 0) {
            return -1;
        }

        return ((int) $minId) - 1;
    }

    private function resolveAssignedOwner(int $platformId, array $payload, string $name): ?int
    {
        $assignedTo = !empty($payload['assigned_to']) ? (int) $payload['assigned_to'] : null;
        if ($assignedTo) {
            $assignee = User::query()->find($assignedTo);
            if (
                !$assignee ||
                !$assignee->isActive() ||
                !$this->marketAuthorizationService->userCanAccessPlatform($assignee, $platformId)
            ) {
                throw new \InvalidArgumentException('Assigned owner is not eligible for this market.');
            }

            return $assignedTo;
        }

        return $this->leadAssignmentService->assignOwnerId($platformId, [
            'phone_normalized' => $payload['phone_normalized'] ?? null,
            'email' => $payload['email'] ?? null,
            'name' => $name,
        ]);
    }

    private function platformHasWpDatabaseCredentials(Platform $platform): bool
    {
        return !empty($platform->db_host)
            && !empty($platform->db_name)
            && !empty($platform->db_user)
            && !empty($platform->db_pass);
    }

    private function platformHasWpApiCredentials(Platform $platform): bool
    {
        return !empty($platform->wp_api_url)
            && !empty($platform->wp_api_user)
            && !empty($platform->wp_api_password);
    }

    private function handleWpReadRequestException(RequestException $exception, Client $client, string $failureMessage)
    {
        $status = $exception->response?->status() ?? 502;
        $payload = $exception->response?->json();

        if (!is_array($payload)) {
            $payload = [
                'message' => $exception->response?->body() ?: $failureMessage,
            ];
        }

        if ($status === 404 && $this->isMissingWpClientPayload($payload)) {
            return response()->json([
                'message' => 'The linked WordPress profile could not be found for this client.',
                'error' => $payload['message'] ?? 'Client not found',
                'stale_link' => $this->buildStaleWpLinkPayload($client),
            ], 404);
        }

        if ($status >= 400 && $status < 500) {
            return response()->json($payload, $status);
        }

        return response()->json([
            'message' => $failureMessage,
            'error' => $payload['message'] ?? $exception->getMessage(),
        ], 502);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isMissingWpClientPayload(array $payload): bool
    {
        $code = strtolower(trim((string) ($payload['code'] ?? '')));
        $message = strtolower(trim((string) ($payload['message'] ?? '')));

        return ($code === 'not_found' && str_contains($message, 'client not found'))
            || $message === 'client not found';
    }

    private function buildStaleWpLinkPayload(Client $client): array
    {
        return [
            'client_id' => (int) $client->id,
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'wp_user_id' => (int) ($client->wp_user_id ?? 0),
            'repairable' => $this->clientWpLinkRepairService->canAttemptRepair($client),
        ];
    }

    private function buildCredentialDispatchRecommendation(ClientCredentialDispatch $dispatch): array
    {
        if ($dispatch->status === 'deferred') {
            return [
                'label' => 'Queued for manual send',
                'cta' => 'Open the client profile and send when contact details are confirmed.',
                'tone' => 'info',
            ];
        }

        if ($dispatch->status === 'sent') {
            return [
                'label' => 'Credentials sent',
                'cta' => 'Ask the client to confirm receipt and first login.',
                'tone' => 'success',
            ];
        }

        if ($dispatch->status === 'partial') {
            return [
                'label' => 'Partially delivered',
                'cta' => 'Retry the failed channel or switch to the available channel.',
                'tone' => 'warning',
            ];
        }

        return [
            'label' => 'Delivery failed',
            'cta' => 'Validate recipient details and retry with setup link first.',
            'tone' => 'danger',
        ];
    }

    private function parseCsvRows(string $path, bool $hasHeader): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to read uploaded CSV file.');
        }

        $rows = [];
        $header = [];
        $defaultColumns = ['name', 'phone', 'email', 'city', 'status', 'assigned_to', 'wp_user_id'];

        if ($hasHeader) {
            $headerRow = fgetcsv($handle);
            if (!is_array($headerRow) || empty($headerRow)) {
                fclose($handle);
                return [];
            }

            $header = array_map(function ($column) {
                $normalized = strtolower(trim((string) $column));
                $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
                return trim($normalized, '_');
            }, $headerRow);
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $columns = $hasHeader ? $header : array_slice($defaultColumns, 0, count($row));
            if (empty($columns)) {
                continue;
            }

            $normalizedRow = [];
            foreach ($columns as $index => $columnName) {
                if ($columnName === '') {
                    continue;
                }

                $normalizedRow[$columnName] = $row[$index] ?? null;
            }

            $rows[] = $normalizedRow;
        }

        fclose($handle);

        return $rows;
    }

    private function extractWpModifiedAt(array $profile): ?Carbon
    {
        $value = data_get($profile, 'post.modified_at');
        if (!$value) {
            $value = $profile['modified_at'] ?? null;
        }

        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function normalizeWpProfileFields(array $fields): array
    {
        $normalized = $fields;

        foreach (['gender', 'ethnicity', 'build'] as $field) {
            if (!array_key_exists($field, $normalized)) {
                continue;
            }

            $resolved = $this->normalizeWpProfileEnumCode($field, $normalized[$field]);
            $normalized[$field] = $resolved;
        }

        foreach (['services', 'availability'] as $field) {
            if (!array_key_exists($field, $normalized)) {
                continue;
            }

            $normalized[$field] = $this->normalizeWpProfileCodeList($field, $normalized[$field]);
        }

        if (array_key_exists('height', $normalized)) {
            $normalized['height'] = $this->normalizeWpProfileHeight($normalized['height']);
        }

        return $normalized;
    }

    private function guardStorePayloadKeys(Request $request): void
    {
        $allowed = array_flip(array_merge([
            'platform_id',
            'name',
            'phone_normalized',
            'email',
            'city',
            'profile_status',
            'assigned_to',
            'wp_user_id',
            'onboarding_mode',
            'wp_username',
            'wp_password',
            'birthday',
            'height',
            'weight',
            'bio',
            'provision_request_id',
            'signup_source',
            'reason',
        ], WpProfileFieldCatalog::editableFields()));

        $unknown = array_diff_key($request->all(), $allowed);

        if ($unknown === []) {
            return;
        }

        $messages = [];
        foreach (array_keys($unknown) as $key) {
            $messages[$key] = 'This field is not supported by the CRM create contract.';
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareProvisioningProfilePayload(Request $request, Platform $platform): array
    {
        $input = array_intersect_key(
            $request->all(),
            array_flip(array_merge(WpProfileFieldCatalog::editableFields(), ['bio']))
        );

        if ($input === []) {
            return [];
        }

        $fields = $this->normalizeWpProfileFields($input);
        if (array_key_exists('bio', $fields) && !array_key_exists('content', $fields)) {
            $fields['content'] = $fields['bio'];
        }

        $currencyCatalogIds = [];
        if (array_key_exists('currency', $fields) && $fields['currency'] !== null && $fields['currency'] !== '') {
            $currencyCatalogIds = $this->extractCurrencyIds($this->fetchWpProfileCurrencies($platform));
        }

        $validated = WpProfileFieldValidator::validate($fields, [
            'currency_catalog_ids' => $currencyCatalogIds,
        ]);

        if (!$this->profileFieldsIncludeLocation($validated)) {
            return $validated;
        }

        return $this->validateLocationHierarchy($validated, $this->fetchWpProfileLocations($platform));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractProvisioningFields(array $payload): array
    {
        $fields = [];
        foreach (array_merge(WpProfileFieldCatalog::editableFields(), ['bio', 'content']) as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $fields[$key] = $payload[$key];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $currentProfile
     * @return array<string, mixed>
     */
    private function prepareWpProfileFields(Platform $platform, array $fields, array $currentProfile): array
    {
        $normalized = $this->normalizeWpProfileFields($fields);
        $context = [
            'current_currency_id' => data_get($currentProfile, 'meta.currency'),
        ];

        if (array_key_exists('currency', $normalized)) {
            $currencies = $this->fetchWpProfileCurrencies($platform);
            $context['currency_catalog_ids'] = $this->extractCurrencyIds($currencies);
        }

        $validated = WpProfileFieldValidator::validate($normalized, [
            ...$context,
        ]);

        if (!$this->profileFieldsIncludeLocation($validated)) {
            return $validated;
        }

        return $this->validateLocationHierarchy($validated, $this->fetchWpProfileLocations($platform));
    }

    private function normalizeWpProfileEnumCode(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $maps = $this->wpProfileEnumMaps();
        $options = $maps[$field] ?? [];
        if (empty($options)) {
            return $raw;
        }

        if (array_key_exists($raw, $options)) {
            return $raw;
        }

        if (preg_match('/\d+/', $raw, $match)) {
            $code = (string) ((int) $match[0]);
            if ($code !== '0' && array_key_exists($code, $options)) {
                return $code;
            }
        }

        $needle = $this->normalizeWpEnumLookupToken($raw);
        foreach ($options as $code => $label) {
            $normalizedLabel = $this->normalizeWpEnumLookupToken((string) $label);
            if ($needle === $normalizedLabel) {
                return (string) $code;
            }

            $normalizedDisplay = $this->normalizeWpEnumLookupToken(sprintf('%s (%s)', $label, $code));
            if ($needle === $normalizedDisplay) {
                return (string) $code;
            }
        }

        return $raw;
    }

    private function normalizeWpProfileCodeList(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $tokens = [];
        if (is_array($value)) {
            $tokens = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw === '') {
                return null;
            }

            $tokens = explode(',', $raw);
        }

        $normalized = [];
        foreach ($tokens as $token) {
            $resolved = $this->normalizeWpProfileEnumCode($field, $token);
            if ($resolved === null || $resolved === '') {
                continue;
            }

            $resolvedRaw = trim((string) $resolved);
            if ($resolvedRaw === '') {
                continue;
            }

            // Keep numeric codes only to align with WordPress service storage.
            if (!preg_match('/^\d+$/', $resolvedRaw)) {
                continue;
            }

            $resolvedCode = (string) ((int) $resolvedRaw);
            if ($resolvedCode === '0') {
                continue;
            }

            if (!in_array($resolvedCode, $normalized, true)) {
                $normalized[] = $resolvedCode;
            }
        }

        return empty($normalized) ? null : $normalized;
    }

    private function normalizeWpProfileHeight(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $legacyCodeToCm = [
            '1' => '128',
            '2' => '134',
            '3' => '140',
            '4' => '146',
            '5' => '152',
            '6' => '155',
            '7' => '158',
            '8' => '162',
            '9' => '165',
            '10' => '168',
            '11' => '171',
            '12' => '174',
            '13' => '177',
            '14' => '180',
            '15' => '183',
            '16' => '189',
            '17' => '195',
            '18' => '201',
            '19' => '207',
            '20' => '213',
        ];

        return $legacyCodeToCm[$raw] ?? $raw;
    }

    private function normalizeWpEnumLookupToken(string $value): string
    {
        $lower = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $lower) ?? '';
        return trim($normalized);
    }

    private function wpProfileEnumMaps(): array
    {
        return WpProfileFieldCatalog::enumMaps();
    }

    /**
     * @return array{locations: array<int, array<string, mixed>>, currencies: array<int, array<string, mixed>>}
     */
    private function fetchWpProfileCatalogs(Platform $platform): array
    {
        return [
            'locations' => $this->fetchWpProfileLocations($platform),
            'currencies' => $this->fetchWpProfileCurrencies($platform),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchWpProfileLocations(Platform $platform): array
    {
        $locations = WpSyncService::forPlatform((int) $platform->id)->getLocations();

        return is_array($locations['locations'] ?? null) ? $locations['locations'] : $locations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchWpProfileCurrencies(Platform $platform): array
    {
        $currencies = WpSyncService::forPlatform((int) $platform->id)->getCurrencies();

        return is_array($currencies['currencies'] ?? null) ? $currencies['currencies'] : $currencies;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function profileFieldsIncludeLocation(array $fields): bool
    {
        return array_key_exists('region_id', $fields) || array_key_exists('city_id', $fields);
    }

    /**
     * @param  array<int, array<string, mixed>>  $currencies
     * @return array<int, int>
     */
    private function extractCurrencyIds(array $currencies): array
    {
        return array_values(array_filter(array_map(
            static fn (array $currency): int => (int) ($currency['id'] ?? 0),
            $currencies
        ), static fn (int $value): bool => $value > 0));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, array<string, mixed>>  $locations
     * @return array<string, mixed>
     */
    private function validateLocationHierarchy(array $fields, array $locations): array
    {
        if (!array_key_exists('region_id', $fields) && !array_key_exists('city_id', $fields)) {
            return $fields;
        }

        if (($fields['region_id'] ?? null) === null && ($fields['city_id'] ?? null) === null) {
            return $fields;
        }

        $regionsById = [];
        foreach ($locations as $region) {
            $regionId = (int) ($region['id'] ?? 0);
            if ($regionId <= 0) {
                continue;
            }

            $regionsById[$regionId] = $region;
        }

        $regionId = (int) ($fields['region_id'] ?? 0);
        $cityId = (int) ($fields['city_id'] ?? 0);
        $region = $regionsById[$regionId] ?? null;

        if (!$region) {
            throw ValidationException::withMessages([
                'region_id' => 'Selected region does not exist in the configured WordPress location catalog.',
            ]);
        }

        $cities = collect($region['cities'] ?? []);

        if ($cityId <= 0) {
            if ($cities->isEmpty()) {
                return $fields;
            }

            throw ValidationException::withMessages([
                'city_id' => 'Select a city within the selected region.',
            ]);
        }

        $city = $cities
            ->first(fn ($item): bool => (int) ($item['id'] ?? 0) === $cityId);

        if (!$city) {
            throw ValidationException::withMessages([
                'city_id' => 'That city is not in the selected region.',
            ]);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildClientDeactivationRequest(array $validated): DeactivationRequest
    {
        $legacyReason = trim((string) ($validated['reason'] ?? ''));
        $reasonCode = trim((string) ($validated['reason_code'] ?? ''));
        $reasonNotes = trim((string) ($validated['reason_notes'] ?? ''));

        if ($reasonCode === '') {
            $reasonCode = DealDeactivationReason::OTHER->value;
            $reasonNotes = $reasonNotes !== '' ? $reasonNotes : ($legacyReason !== '' ? $legacyReason : null);
        }

        return new DeactivationRequest(
            DealDeactivationReason::from($reasonCode),
            $reasonNotes !== '' ? $reasonNotes : null
        );
    }

    /**
     * @return list<string>
     */
    private function deactivationReasonValues(): array
    {
        return array_map(
            static fn (DealDeactivationReason $reason): string => $reason->value,
            DealDeactivationReason::cases()
        );
    }

    private function resolveClientSubscriptionDeactivationMessage(Client $client): ?string
    {
        $name = $client->name ?: 'there';

        return "Hi {$name}, your subscription has been deactivated. Contact support if this is unexpected.";
    }

    private function snapshotWpFieldValues(array $requestedFields, array $profile): array
    {
        $snapshot = [];
        $meta = is_array($profile['meta'] ?? null) ? $profile['meta'] : [];
        $taxonomies = is_array($profile['taxonomies'] ?? null) ? $profile['taxonomies'] : [];

        foreach (array_keys($requestedFields) as $field) {
            if ($field === 'name' || $field === 'post_title') {
                $snapshot[$field] = data_get($profile, 'post.title');
                continue;
            }

            if ($field === 'city') {
                $snapshot[$field] = data_get($taxonomies, 'city.name') ?? ($profile['city'] ?? null);
                continue;
            }

            $snapshot[$field] = $meta[$field] ?? ($profile[$field] ?? null);
        }

        return $snapshot;
    }

    private function buildProfileDiff(array $requestedFields, array $profile): array
    {
        $current = $this->snapshotWpFieldValues($requestedFields, $profile);
        $diff = [];
        foreach ($requestedFields as $field => $value) {
            $diff[$field] = [
                'crm_value' => $value,
                'wp_value' => $current[$field] ?? null,
            ];
        }

        return $diff;
    }

    /**
     * Compute profile completeness from CRM data + cached WP meta.
     */
    private function computeProfileCompleteness(Client $client): array
    {
        $fields = [];

        // Fields available from CRM clients table
        $fields[] = ['key' => 'name', 'label' => 'Name', 'filled' => (bool) $client->name];
        $fields[] = ['key' => 'phone', 'label' => 'Phone', 'filled' => (bool) $client->phone_normalized];
        $fields[] = ['key' => 'email', 'label' => 'Email', 'filled' => (bool) $client->email];
        $fields[] = ['key' => 'city', 'label' => 'City', 'filled' => (bool) $client->city];
        $fields[] = ['key' => 'photo', 'label' => 'At least 1 photo', 'filled' => (bool) ($client->display_image_url ?: $client->main_image_url)];

        // Fields from WP meta — fetch with short cache to avoid hitting WP API on every page load
        $wpMeta = $this->getCachedWpMeta($client);

        $fields[] = ['key' => 'gender', 'label' => 'Gender', 'filled' => !empty($wpMeta['gender'])];
        $fields[] = ['key' => 'ethnicity', 'label' => 'Ethnicity', 'filled' => !empty($wpMeta['ethnicity'])];
        $fields[] = ['key' => 'height', 'label' => 'Height', 'filled' => !empty($wpMeta['height'])];
        $fields[] = ['key' => 'build', 'label' => 'Build', 'filled' => !empty($wpMeta['build'] ?? $wpMeta['body_type'] ?? null)];
        $fields[] = ['key' => 'services', 'label' => 'Services', 'filled' => !empty($wpMeta['services'])];
        $fields[] = ['key' => 'bio', 'label' => 'Bio / About', 'filled' => !empty($wpMeta['bio'] ?? $wpMeta['_post_content'] ?? null)];
        $fields[] = ['key' => 'rates', 'label' => 'Rates', 'filled' => !empty($wpMeta['incall'] ?? $wpMeta['rate_incall'] ?? $wpMeta['outcall'] ?? $wpMeta['rate_outcall'] ?? $wpMeta['rate1h_incall'] ?? null)];

        $filledCount = count(array_filter($fields, fn($f) => $f['filled']));
        $totalCount = count($fields);
        $missing = array_values(array_map(
            fn($f) => $f['label'],
            array_filter($fields, fn($f) => !$f['filled'])
        ));

        return [
            'score' => $totalCount > 0 ? (int) round(($filledCount / $totalCount) * 100) : 0,
            'filled' => $filledCount,
            'total' => $totalCount,
            'missing' => $missing,
        ];
    }

    /**
     * Get WP profile meta with a 10-minute cache to avoid repeated API calls.
     */
    private function getCachedWpMeta(Client $client): array
    {
        if ((int) ($client->wp_post_id ?? 0) <= 0) {
            return [];
        }

        $cacheKey = "client_wp_meta_{$client->platform_id}_{$client->wp_post_id}";

        return Cache::remember($cacheKey, 600, function () use ($client) {
            try {
                $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
                $profile = $wpSync->getClientProfile((int) $client->wp_post_id);

                $meta = $profile['meta'] ?? [];
                // Include post content for bio completeness check
                if (!empty($profile['post']['content'])) {
                    $meta['_post_content'] = $profile['post']['content'];
                }

                return $meta;
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch WP meta for completeness', [
                    'client_id' => $client->id,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    private function validateProfileMediaUpload($file, bool $setMain, \Closure $fail): void
    {
        if (!$file instanceof \Illuminate\Http\UploadedFile) {
            return;
        }

        $isVideo = $this->isProfileMediaVideoUpload($file);
        if ($isVideo && strtolower((string) $file->getClientOriginalExtension()) !== 'mp4') {
            $fail('The file must be a JPEG, PNG, WEBP image, or MP4 video.');
            return;
        }

        $maxKb = $isVideo ? self::PROFILE_MEDIA_VIDEO_MAX_KB : self::PROFILE_MEDIA_IMAGE_MAX_KB;
        $sizeKb = (int) ceil(((int) ($file->getSize() ?? 0)) / 1024);

        if ($sizeKb > $maxKb) {
            $fail($isVideo ? 'MP4 videos must not exceed 50MB.' : 'Images must not exceed 5MB.');
        }

        if ($isVideo && $setMain) {
            $fail('Videos cannot be set as the main profile image.');
        }
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function resolveProfileMediaUploadFiles(Request $request): array
    {
        $files = $request->file('files', []);
        if (!is_array($files)) {
            $files = [];
        }

        $resolved = collect($files)
            ->filter(fn ($file): bool => $file instanceof UploadedFile)
            ->values();

        if ($resolved->isNotEmpty()) {
            return $resolved->all();
        }

        $singleFile = $request->file('file');

        return $singleFile instanceof UploadedFile ? [$singleFile] : [];
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    private function validateProfileMediaBatch(array $files, bool $setMain): void
    {
        $hasMultiple = count($files) > 1;
        $videoCount = 0;

        foreach ($files as $file) {
            $this->validateProfileMediaUpload($file, $setMain && !$hasMultiple, function (string $message): void {
                throw new InvalidArgumentException($message);
            });

            if ($this->isProfileMediaVideoUpload($file)) {
                $videoCount++;
            }
        }

        if ($hasMultiple && $videoCount > 0) {
            throw new InvalidArgumentException('You can upload multiple files at once only when all selected files are images.');
        }

        if ($hasMultiple && $setMain) {
            throw new InvalidArgumentException('Set main image is only available for single-image uploads.');
        }
    }

    /**
     * @param array<string, mixed> $existingMedia
     * @param array<int, UploadedFile> $files
     */
    private function ensureProfileMediaCapacity(array $existingMedia, array $files): void
    {
        $counts = $this->countCurrentProfileMedia($existingMedia);
        $incomingImages = 0;
        $incomingVideos = 0;

        foreach ($files as $file) {
            if ($this->isProfileMediaVideoUpload($file)) {
                $incomingVideos++;
            } else {
                $incomingImages++;
            }
        }

        if (($counts['images'] + $incomingImages) > self::PROFILE_MEDIA_MAX_IMAGES) {
            throw new InvalidArgumentException(sprintf(
                'This upload would exceed the profile image limit of %d.',
                self::PROFILE_MEDIA_MAX_IMAGES
            ));
        }

        if (($counts['videos'] + $incomingVideos) > self::PROFILE_MEDIA_MAX_VIDEOS) {
            throw new InvalidArgumentException(sprintf(
                'This upload would exceed the profile video limit of %d.',
                self::PROFILE_MEDIA_MAX_VIDEOS
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{images:int,videos:int}
     */
    private function countCurrentProfileMedia(array $payload): array
    {
        $rows = data_get($payload, 'data');
        if (!is_array($rows)) {
            $rows = array_is_list($payload) ? $payload : [];
        }

        $counts = ['images' => 0, 'videos' => 0];

        foreach ($rows as $media) {
            $mimeType = strtolower(trim((string) data_get($media, 'mime_type', '')));
            $url = strtolower(trim((string) data_get($media, 'url', '')));

            if (str_starts_with($mimeType, 'image/') || preg_match('/\.(jpe?g|png|webp)(?:$|[?#])/', $url)) {
                $counts['images']++;
                continue;
            }

            if (str_starts_with($mimeType, 'video/') || preg_match('/\.(mp4|m4v|mov|webm|ogg)(?:$|[?#])/', $url)) {
                $counts['videos']++;
            }
        }

        return $counts;
    }

    public function cities(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = Client::query()->whereNotNull('city')->where('city', '!=', '');

        if ($request->filled('platform_id')) {
            $query->where('platform_id', (int) $request->platform_id);
        }

        $cities = $query->distinct()->orderBy('city')->pluck('city');

        return response()->json(['cities' => $cities]);
    }

    public function platformLocations(Request $request, Platform $platform): \Illuminate\Http\JsonResponse
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this client market.'
        );

        $locations = WpSyncService::forPlatform((int) $platform->id)->getLocations();

        return response()->json([
            'locations' => $locations['locations'] ?? $locations,
        ]);
    }

    public function platformCurrencies(Request $request, Platform $platform): \Illuminate\Http\JsonResponse
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this client market.'
        );

        $currencies = WpSyncService::forPlatform((int) $platform->id)->getCurrencies();

        return response()->json([
            'currencies' => $currencies['currencies'] ?? $currencies,
            'default_currency_id' => $platform->wp_currency_id,
        ]);
    }

    private function isProfileMediaVideoUpload(UploadedFile $file): bool
    {
        $mimeType = strtolower((string) $file->getMimeType());
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return str_starts_with($mimeType, 'video/') || $extension === 'mp4';
    }

    public function closeReasons()
    {
        return response()->json([
            'reasons' => CrmClientCloseReason::options(),
            'soft_close_days' => ClientCaseClosureService::SOFT_CLOSE_DAYS,
        ]);
    }

    public function closedReasonsSummary(Request $request)
    {
        $requestedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this client market.'
        );

        $rangeDays = (int) $request->input('range_days', 30);
        if (!in_array($rangeDays, [7, 30, 90], true)) {
            $rangeDays = 30;
        }

        $platformScope = function ($query) use ($request, $requestedPlatformId) {
            $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

            if ($requestedPlatformId !== null) {
                $query->where('platform_id', $requestedPlatformId);
            }
        };

        $now = now();
        $rangeStart = $now->copy()->subDays($rangeDays);
        $previousStart = $rangeStart->copy()->subDays($rangeDays);

        $baseQuery = Client::query()
            ->tap($platformScope)
            ->closed()
            ->where('closed_at', '>=', $rangeStart);

        $previousQuery = Client::query()
            ->tap($platformScope)
            ->closed()
            ->where('closed_at', '>=', $previousStart)
            ->where('closed_at', '<', $rangeStart);

        $total = (clone $baseQuery)->count();
        $previousTotal = (clone $previousQuery)->count();
        $withNotes = (clone $baseQuery)
            ->whereNotNull('close_reason_note')
            ->where('close_reason_note', '!=', '')
            ->count();
        $unknownCount = (clone $baseQuery)
            ->where(function ($query) {
                $query->whereNull('close_reason_code')
                    ->orWhere('close_reason_code', '');
            })
            ->count();

        $reasonCounts = (clone $baseQuery)
            ->select('close_reason_code', DB::raw('COUNT(*) as total'))
            ->whereNotNull('close_reason_code')
            ->where('close_reason_code', '!=', '')
            ->groupBy('close_reason_code')
            ->pluck('total', 'close_reason_code')
            ->map(fn ($count) => (int) $count)
            ->all();

        $previousReasonCounts = (clone $previousQuery)
            ->select('close_reason_code', DB::raw('COUNT(*) as total'))
            ->whereNotNull('close_reason_code')
            ->where('close_reason_code', '!=', '')
            ->groupBy('close_reason_code')
            ->pluck('total', 'close_reason_code')
            ->map(fn ($count) => (int) $count)
            ->all();

        $knownReasons = array_map(function (array $option) use ($reasonCounts, $previousReasonCounts, $total) {
            $code = (string) $option['code'];
            $count = (int) ($reasonCounts[$code] ?? 0);
            $previousCount = (int) ($previousReasonCounts[$code] ?? 0);

            return [
                'code' => $code,
                'label' => (string) $option['label'],
                'count' => $count,
                'share' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                'previous_count' => $previousCount,
                'delta' => $count - $previousCount,
            ];
        }, CrmClientCloseReason::options());

        $extraReasonRows = collect($reasonCounts)
            ->reject(fn ($_count, string $code) => CrmClientCloseReason::isValid($code))
            ->map(fn (int $count, string $code) => [
                'code' => $code,
                'label' => $code,
                'count' => $count,
                'share' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                'previous_count' => (int) ($previousReasonCounts[$code] ?? 0),
                'delta' => $count - (int) ($previousReasonCounts[$code] ?? 0),
            ])
            ->values()
            ->all();

        $reasons = collect([...$knownReasons, ...$extraReasonRows])
            ->sortByDesc('count')
            ->values()
            ->all();

        if ($unknownCount > 0) {
            $reasons[] = [
                'code' => 'unknown',
                'label' => 'No Reason Captured',
                'count' => $unknownCount,
                'share' => $total > 0 ? round(($unknownCount / $total) * 100, 1) : 0.0,
                'previous_count' => 0,
                'delta' => $unknownCount,
            ];
        }

        $topReason = collect($reasons)->first(fn (array $reason) => (int) $reason['count'] > 0);

        $recentNotes = (clone $baseQuery)
            ->with(['platform:id,name', 'closedBy:id,name,email'])
            ->whereNotNull('close_reason_note')
            ->where('close_reason_note', '!=', '')
            ->orderBy('closed_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn (Client $client) => [
                'id' => (int) $client->id,
                'name' => $client->name,
                'phone' => $client->phone_normalized,
                'reason_code' => $client->close_reason_code,
                'reason_label' => CrmClientCloseReason::label((string) $client->close_reason_code),
                'reason_note' => $client->close_reason_note,
                'closed_at' => optional($client->closed_at)?->toIso8601String(),
                'platform' => $client->platform ? ['id' => (int) $client->platform->id, 'name' => $client->platform->name] : null,
                'closed_by' => $client->closedBy ? [
                    'id' => (int) $client->closedBy->id,
                    'name' => $client->closedBy->name,
                    'email' => $client->closedBy->email,
                ] : null,
            ])
            ->values();

        return response()->json([
            'range' => [
                'days' => $rangeDays,
                'label' => "Last {$rangeDays} days",
                'start' => $rangeStart->toIso8601String(),
                'end' => $now->toIso8601String(),
            ],
            'totals' => [
                'closed' => $total,
                'previous_closed' => $previousTotal,
                'delta' => $total - $previousTotal,
                'with_notes' => $withNotes,
                'without_reason' => $unknownCount,
            ],
            'top_reason' => $topReason,
            'reasons' => $reasons,
            'recent_notes' => $recentNotes,
        ]);
    }

    public function closeCase(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'reason_code' => 'required|string|in:' . implode(',', CrmClientCloseReason::ALL),
            'reason_note' => 'nullable|string|max:1000',
        ]);

        if (CrmClientCloseReason::requiresNote($validated['reason_code'])
            && trim((string) ($validated['reason_note'] ?? '')) === '') {
            return response()->json([
                'message' => 'A note is required when the reason is "Other".',
                'error_code' => 'note_required',
            ], 422);
        }

        try {
            $result = $this->clientCaseClosureService->close(
                $client,
                $validated['reason_code'],
                $validated['reason_note'] ?? null,
                $request->user(),
                $request,
            );
        } catch (ClientCaseClosureException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode(),
                'context' => $exception->context(),
            ], $exception->httpStatus());
        }

        return response()->json([
            'message' => 'Case closed.',
            'client' => $result['client'],
            'cascaded_payments_count' => $result['cascaded_payments_count'],
            'cascaded_payment_ids' => $result['cascaded_payment_ids'],
            'purge_after' => $result['purge_after'],
        ]);
    }

    public function reopen(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $client = $this->clientCaseClosureService->reopen(
                $client,
                $validated['note'] ?? null,
                $request->user(),
                $request,
            );
        } catch (ClientCaseClosureException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode(),
                'context' => $exception->context(),
            ], $exception->httpStatus());
        }

        return response()->json([
            'message' => 'Case reopened.',
            'client' => $client,
        ]);
    }

    public function bulkClose(Request $request)
    {
        $validated = $request->validate([
            'client_ids' => 'required|array|min:1|max:200',
            'client_ids.*' => 'integer|exists:clients,id',
            'reason_code' => 'required|string|in:' . implode(',', CrmClientCloseReason::ALL),
            'reason_note' => 'nullable|string|max:1000',
        ]);

        if (CrmClientCloseReason::requiresNote($validated['reason_code'])
            && trim((string) ($validated['reason_note'] ?? '')) === '') {
            return response()->json([
                'message' => 'A note is required when the reason is "Other".',
                'error_code' => 'note_required',
            ], 422);
        }

        // Authorize each client against the rep's platform scope. Disallowed ids
        // are reported in the per-row error list rather than aborting the batch.
        $clients = Client::whereIn('id', $validated['client_ids'])->get();
        $authorizedIds = [];
        $unauthorizedIds = [];
        foreach ($clients as $client) {
            try {
                $this->authorizeClientAccess($request, $client);
                $authorizedIds[] = (int) $client->id;
            } catch (\Throwable $exception) {
                $unauthorizedIds[] = (int) $client->id;
            }
        }

        $outcome = $this->clientCaseClosureService->bulkClose(
            $authorizedIds,
            $validated['reason_code'],
            $validated['reason_note'] ?? null,
            $request->user(),
            $request,
        );

        foreach ($unauthorizedIds as $unauthorizedId) {
            $outcome['results'][] = [
                'client_id' => $unauthorizedId,
                'success' => false,
                'error_code' => 'forbidden',
                'error_message' => 'You do not have access to this client.',
            ];
            $outcome['summary']['total'] = ($outcome['summary']['total'] ?? 0) + 1;
            $outcome['summary']['errors'] = ($outcome['summary']['errors'] ?? 0) + 1;
        }

        return response()->json($outcome);
    }

    public function markContacted(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'channel' => 'nullable|string|in:whatsapp,sms,phone,email,other',
            'note' => 'nullable|string|max:500',
        ]);

        $channel = $validated['channel'] ?? 'other';
        $note = isset($validated['note']) ? trim((string) $validated['note']) : null;
        if ($note === '') {
            $note = null;
        }

        $now = now();
        $isFirstContact = $client->first_contact_at === null;

        Client::withoutRetentionRefresh(function () use ($client, $isFirstContact, $now): void {
            $payload = ['last_contact_at' => $now];
            if ($isFirstContact) {
                $payload['first_contact_at'] = $now;
            }
            $client->forceFill($payload)->save();
        });

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'client_contacted',
            'actor_id' => optional($request->user())->id,
            'content' => [
                'channel' => $channel,
                'note' => $note,
                'first_contact' => $isFirstContact,
            ],
            'created_at' => $now,
        ]);

        return response()->json([
            'message' => $isFirstContact ? 'Marked as first contact.' : 'Contact activity refreshed.',
            'client' => $client->fresh(),
        ]);
    }

    public function conversionQueue(Request $request)
    {
        $requestedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible($request);

        $platformScope = function ($query) use ($request, $requestedPlatformId) {
            $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

            if ($requestedPlatformId !== null) {
                $query->where('platform_id', $requestedPlatformId);
            }
        };

        // Resolve range window. Accepts either `range_hours` (preset chips) or
        // `from`/`to` ISO dates (custom range). Defaults to last 48h to match
        // the customer-service intake cadence.
        [$rangeStart, $rangeEnd, $rangeLabel] = $this->resolveConversionQueueRange($request);
        $limits = [
            'new_signups' => $this->resolveConversionQueueLimit($request, 'new_signups_limit'),
            'failed_payments' => $this->resolveConversionQueueLimit($request, 'failed_payments_limit'),
            'stalled_contacted' => $this->resolveConversionQueueLimit($request, 'stalled_contacted_limit'),
        ];

        $newSignupsQuery = Client::query()
            ->with(['platform:id,name'])
            ->tap($platformScope)
            ->notClosed()
            ->whereNull('first_contact_at')
            ->whereDoesntHave('deals', function ($q) {
                $q->whereIn('status', ['active', 'paid', 'awaiting_payment']);
            })
            ->whereDoesntHave('payments', function ($q) {
                $q->whereIn('status', ['completed', 'activated']);
            })
            ->where('created_at', '>=', $rangeStart);
        if ($rangeEnd !== null) {
            $newSignupsQuery->where('created_at', '<=', $rangeEnd);
        }
        $newSignupsTotal = (clone $newSignupsQuery)->count();
        $newSignups = (clone $newSignupsQuery)
            ->orderBy('created_at', 'desc')
            ->limit($limits['new_signups'])
            ->get();

        $failedPaymentsQuery = Payment::query()
            ->with(['client:id,name,phone_normalized,city,platform_id,closed_at', 'client.platform:id,name', 'product:id,name,display_name'])
            ->where('status', 'failed')
            ->where('reconciliation_state', 'open')
            ->where('created_at', '>=', $rangeStart)
            ->whereHas('client', function ($q) use ($platformScope) {
                $q->whereNull('closed_at');
                $platformScope($q);
            });
        if ($rangeEnd !== null) {
            $failedPaymentsQuery->where('created_at', '<=', $rangeEnd);
        }
        $failedPaymentsTotal = (clone $failedPaymentsQuery)->count();
        $failedPayments = (clone $failedPaymentsQuery)
            ->orderBy('created_at', 'desc')
            ->limit($limits['failed_payments'])
            ->get();

        $stalledQuery = Client::query()
            ->with(['platform:id,name', 'assignedAgent:id,name'])
            ->tap($platformScope)
            ->notClosed()
            ->whereNotNull('first_contact_at')
            ->where('last_contact_at', '<', now()->subHours(72))
            ->whereDoesntHave('deals', function ($q) {
                $q->whereIn('status', ['active', 'paid']);
            })
            ->orderBy('last_contact_at', 'asc');
        $stalledTotal = (clone $stalledQuery)->count();
        $stalled = (clone $stalledQuery)
            ->limit($limits['stalled_contacted'])
            ->get();

        $now = now();

        return response()->json([
            'new_signups' => $newSignups->map(fn (Client $c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'phone' => $c->phone_normalized,
                'email' => $c->email,
                'city' => $c->city,
                'signup_source' => $c->signup_source,
                'created_at' => optional($c->created_at)?->toIso8601String(),
                'age_seconds' => $c->created_at ? max(0, $now->diffInSeconds($c->created_at)) : null,
                'sla_bucket' => $c->created_at ? $this->conversionSlaBucket($c->created_at) : 'red',
                'platform' => $c->platform ? ['id' => $c->platform->id, 'name' => $c->platform->name] : null,
                'sb_user_id' => $c->sb_user_id,
            ])->values(),
            'failed_payments' => $failedPayments->map(function (Payment $p) use ($now) {
                $product = $p->product?->display_name ?: $p->product?->name;
                return [
                    'id' => (int) $p->id,
                    'phone' => $p->phone,
                    'amount' => $p->amount,
                    'currency' => $p->currency ?? 'KES',
                    'product' => $product,
                    'product_id' => $p->product_id,
                    'reference' => $p->reference,
                    'failure_reason' => $p->failure_reason,
                    'created_at' => optional($p->created_at)?->toIso8601String(),
                    'age_seconds' => $p->created_at ? max(0, $now->diffInSeconds($p->created_at)) : null,
                    'sla_bucket' => $p->created_at ? $this->conversionSlaBucket($p->created_at) : 'red',
                    'client' => $p->client ? [
                        'id' => (int) $p->client->id,
                        'name' => $p->client->name,
                        'phone' => $p->client->phone_normalized,
                        'city' => $p->client->city,
                        'platform' => $p->client->platform ? ['id' => $p->client->platform->id, 'name' => $p->client->platform->name] : null,
                    ] : null,
                ];
            })->values(),
            'stalled_contacted' => $stalled->map(fn (Client $c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'phone' => $c->phone_normalized,
                'city' => $c->city,
                'first_contact_at' => optional($c->first_contact_at)?->toIso8601String(),
                'last_contact_at' => optional($c->last_contact_at)?->toIso8601String(),
                'age_seconds' => $c->last_contact_at ? max(0, $now->diffInSeconds($c->last_contact_at)) : null,
                'sla_bucket' => $c->last_contact_at ? $this->conversionSlaBucket($c->last_contact_at) : 'red',
                'platform' => $c->platform ? ['id' => $c->platform->id, 'name' => $c->platform->name] : null,
                'assigned_agent' => $c->assignedAgent ? ['id' => $c->assignedAgent->id, 'name' => $c->assignedAgent->name] : null,
            ])->values(),
            'counts' => [
                'new_signups' => $newSignupsTotal,
                'failed_payments' => $failedPaymentsTotal,
                'stalled_contacted' => $stalledTotal,
                'total' => $newSignupsTotal + $failedPaymentsTotal + $stalledTotal,
            ],
            'visible_counts' => [
                'new_signups' => $newSignups->count(),
                'failed_payments' => $failedPayments->count(),
                'stalled_contacted' => $stalled->count(),
                'total' => $newSignups->count() + $failedPayments->count() + $stalled->count(),
            ],
            'limits' => $limits,
            'has_more' => [
                'new_signups' => $newSignupsTotal > $newSignups->count(),
                'failed_payments' => $failedPaymentsTotal > $failedPayments->count(),
                'stalled_contacted' => $stalledTotal > $stalled->count(),
            ],
            'sla_thresholds' => [
                'green_max_minutes' => 5,
                'yellow_max_minutes' => 30,
                'orange_max_minutes' => 120,
            ],
            'range' => [
                'start' => $rangeStart->toIso8601String(),
                'end' => $rangeEnd?->toIso8601String(),
                'label' => $rangeLabel,
                'hours' => $rangeEnd === null ? (int) round(now()->diffInMinutes($rangeStart) / 60) : null,
                'applies_to' => ['new_signups', 'failed_payments'],
                'note' => 'Stalled contacted bucket uses its own >72h cutoff and is unaffected by this range.',
            ],
        ]);
    }

    /**
     * Resolve the conversion queue's filter window from request params.
     *
     * Accepts:
     *   - range_hours: integer preset (24, 48, 168, 720) — default 48
     *   - from / to: ISO date strings for a custom range (override range_hours)
     *
     * Returns [start Carbon, end Carbon|null, label string].
     */
    private function resolveConversionQueueRange(Request $request): array
    {
        $now = now();

        $from = trim((string) $request->input('from', ''));
        $to = trim((string) $request->input('to', ''));

        if ($from !== '' || $to !== '') {
            try {
                $start = $from !== '' ? Carbon::parse($from)->startOfDay() : $now->copy()->subDays(30)->startOfDay();
                $end = $to !== '' ? Carbon::parse($to)->endOfDay() : null;

                // Clamp absurd ranges to a max of 90 days.
                $cap = $now->copy()->subDays(90);
                if ($start->lessThan($cap)) {
                    $start = $cap;
                }

                $label = sprintf('Custom · %s%s', $start->format('M j'), $end ? ' → ' . $end->format('M j') : ' → now');
                return [$start, $end, $label];
            } catch (\Throwable) {
                // Fall through to the preset path on parse error.
            }
        }

        $rangeHours = (int) $request->input('range_hours', 48);
        $allowed = [24, 48, 168, 720]; // 1d, 2d, 7d, 30d
        if (!in_array($rangeHours, $allowed, true)) {
            $rangeHours = 48;
        }

        $start = $now->copy()->subHours($rangeHours);
        $label = match ($rangeHours) {
            24 => 'Last 24 hours',
            48 => 'Last 48 hours',
            168 => 'Last 7 days',
            720 => 'Last 30 days',
            default => sprintf('Last %d hours', $rangeHours),
        };

        return [$start, null, $label];
    }

    private function resolveConversionQueueLimit(Request $request, string $key): int
    {
        $limit = (int) $request->input($key, 50);

        if ($limit < 1) {
            return 50;
        }

        return min($limit, 500);
    }

    private function conversionSlaBucket(\DateTimeInterface $reference): string
    {
        $ageSeconds = max(0, now()->diffInSeconds($reference));

        if ($ageSeconds < 5 * 60) {
            return 'green';
        }
        if ($ageSeconds < 30 * 60) {
            return 'yellow';
        }
        if ($ageSeconds < 120 * 60) {
            return 'orange';
        }
        return 'red';
    }

    // ─── Churn Queue endpoints ────────────────────────────────────────────────

    public function churnSummary(Request $request)
    {
        [$from, $to] = $this->resolveChurnRange($request);

        // null means admin = all platforms; empty array means no access
        $accessibleIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        $platformIds = $accessibleIds ?? []; // empty = no restriction in aggregator

        // Narrow to a specific market if requested
        if ($request->filled('platform_id')) {
            $requested = (int) $request->input('platform_id');
            // Admins (null) can access any platform; others must have it in their list
            if ($accessibleIds === null || in_array($requested, $accessibleIds, true)) {
                $platformIds = [$requested];
            }
        }

        $summary = $this->churnAggregatorService->summary($from, $to, $platformIds);

        return response()->json($summary);
    }

    public function churned(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'plan' => 'nullable|string|in:basic,featured,premium,vip,vvip,unknown',
            'reason_code' => 'nullable|string|max:80',
            'source' => 'nullable|string|max:80',
            'signup_source' => 'nullable|string|in:fast_signup,full_registration,crm_manual,crm_provisioned,field,existing',
            'sort_by' => 'nullable|string|in:name,market,first_activated_at,churned_at,reason,last_plan,value',
            'sort_direction' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|in:10,25,50,100',
        ]);
        [$from, $to] = $this->resolveChurnRange($request);
        $latestPaidDealIdSql = $this->latestPaidDealIdSql();

        $query = Client::query()
            ->select('clients.*')
            ->with([
                'platform:id,name',
                'assignedAgent:id,name',
                'retentionInsight:client_id,band,primary_tag',
            ])
            ->selectRaw("({$latestPaidDealIdSql}) as last_paid_deal_id")
            ->whereNotNull('churned_at')
            ->whereBetween('churned_at', [$from->startOfDay(), $to->endOfDay()]);

        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if ($request->filled('platform_id')) {
            $query->where('platform_id', (int) $request->input('platform_id'));
        }

        if (! empty($validated['search'])) {
            $this->applyClientTextSearch($query, [$validated['search']]);
        }

        if (! empty($validated['reason_code'])) {
            $query->where('churn_reason_code', $validated['reason_code']);
        }

        if (! empty($validated['source'])) {
            $query->where('churn_source', $validated['source']);
        }

        if (! empty($validated['signup_source'])) {
            if ($validated['signup_source'] === 'existing') {
                $query->where(function ($sourceQuery) {
                    $sourceQuery->whereNull('signup_source')
                        ->orWhere('signup_source', '');
                });
            } else {
                $query->where('signup_source', $validated['signup_source']);
            }
        }

        if (! empty($validated['plan'])) {
            $this->applyChurnLastPlanFilter($query, $validated['plan'], $latestPaidDealIdSql);
        }

        $sortBy = $validated['sort_by'] ?? 'churned_at';
        $sortDirection = $validated['sort_direction'] ?? 'desc';
        $perPage = (int) ($validated['per_page'] ?? 25);

        if ($sortBy === 'value') {
            return response()->json($this->paginateChurnedByLifetimeValue(
                $request,
                $query,
                $latestPaidDealIdSql,
                $sortDirection,
                $perPage,
            ));
        }

        $this->applyChurnListSort(
            $query,
            $sortBy,
            $sortDirection,
            $latestPaidDealIdSql,
        );

        $clients = $query->paginate($perPage);
        $this->decorateChurnLastPlans($clients->getCollection());
        $this->decorateLifetimeValue($clients->getCollection());

        return response()->json($clients);
    }

    private function paginateChurnedByLifetimeValue(
        Request $request,
        $baseQuery,
        string $latestPaidDealIdSql,
        string $sortDirection,
        int $perPage,
    ): array {
        $valueSortCap = 5000;
        $matchingIds = (clone $baseQuery)
            ->reorder()
            ->limit($valueSortCap + 1)
            ->pluck('clients.id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($matchingIds->count() > $valueSortCap) {
            $fallbackQuery = clone $baseQuery;
            $this->applyChurnListSort($fallbackQuery, 'churned_at', 'desc', $latestPaidDealIdSql);
            $clients = $fallbackQuery->paginate($perPage);
            $this->decorateChurnLastPlans($clients->getCollection());
            $this->decorateLifetimeValue($clients->getCollection());

            $payload = $clients->toArray();
            $payload['meta'] = array_merge($payload['meta'] ?? [], [
                'value_ranking_unavailable' => true,
                'effective_sort_by' => 'churned_at',
                'message' => 'Value ranking is unavailable for this many rows. Narrow the filter to rank churned clients by lifetime value.',
            ]);

            return $payload;
        }

        $lifetimeValues = $this->clientLifetimeValueService->forClientIds($matchingIds);
        $sortedIds = $matchingIds->all();
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        usort($sortedIds, function (int $left, int $right) use ($lifetimeValues, $direction): int {
            $leftValue = $lifetimeValues[$left]['value_usd'] ?? null;
            $rightValue = $lifetimeValues[$right]['value_usd'] ?? null;
            $leftRank = $leftValue !== null && (float) $leftValue > 0 ? 0 : 1;
            $rightRank = $rightValue !== null && (float) $rightValue > 0 ? 0 : 1;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            if ($leftRank > 0) {
                return $left <=> $right;
            }

            $comparison = (float) $leftValue <=> (float) $rightValue;
            if ($direction === 'desc') {
                $comparison *= -1;
            }

            return $comparison !== 0 ? $comparison : $left <=> $right;
        });

        $page = max(1, (int) $request->input('page', 1));
        $total = count($sortedIds);
        $pageIds = array_slice($sortedIds, ($page - 1) * $perPage, $perPage);
        $order = array_flip($pageIds);

        $clients = Client::query()
            ->select('clients.*')
            ->with([
                'platform:id,name',
                'assignedAgent:id,name',
                'retentionInsight:client_id,band,primary_tag',
            ])
            ->selectRaw("({$latestPaidDealIdSql}) as last_paid_deal_id")
            ->whereIn('clients.id', $pageIds ?: [0])
            ->get()
            ->sortBy(fn (Client $client) => $order[(int) $client->id] ?? PHP_INT_MAX)
            ->values();

        $this->decorateChurnLastPlans($clients);
        $this->decorateLifetimeValue($clients);

        $paginator = new LengthAwarePaginator(
            $clients,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $payload = $paginator->toArray();
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'value_ranking_unavailable' => false,
            'effective_sort_by' => 'value',
        ]);

        return $payload;
    }

    private function latestPaidDealIdSql(): string
    {
        $paidStatuses = collect(ClientFunnelService::PAID_DEAL_STATUSES)
            ->map(fn (string $status) => DB::getPdo()->quote($status))
            ->implode(', ');

        return <<<SQL
            SELECT latest_paid_deal.id
            FROM deals AS latest_paid_deal
            WHERE latest_paid_deal.client_id = clients.id
              AND (
                latest_paid_deal.activated_at IS NOT NULL
                OR latest_paid_deal.status IN ({$paidStatuses})
              )
              AND COALESCE(latest_paid_deal.activated_at, latest_paid_deal.created_at) <= clients.churned_at
            ORDER BY COALESCE(latest_paid_deal.activated_at, latest_paid_deal.created_at) DESC, latest_paid_deal.id DESC
            LIMIT 1
            SQL;
    }

    private function applyChurnLastPlanFilter($query, string $plan, string $latestPaidDealIdSql): void
    {
        if ($plan === 'unknown') {
            $query->whereRaw("({$latestPaidDealIdSql}) IS NULL");

            return;
        }

        $query->whereExists(function ($dealQuery) use ($plan, $latestPaidDealIdSql) {
            $planKeySql = $this->churnPlanKeySql('selected_churn_deal', 'selected_churn_product');

            $dealQuery
                ->selectRaw('1')
                ->from('deals as selected_churn_deal')
                ->leftJoin('products as selected_churn_product', 'selected_churn_product.id', '=', 'selected_churn_deal.product_id')
                ->whereRaw("selected_churn_deal.id = ({$latestPaidDealIdSql})")
                ->whereRaw("{$planKeySql} = ?", [$plan]);
        });
    }

    private function applyChurnListSort($query, string $sortBy, string $direction, string $latestPaidDealIdSql): void
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $unknownPlanSortValue = $direction === 'asc' ? 'zzzz' : '';

        match ($sortBy) {
            'name' => $query->orderBy('clients.name', $direction),
            'market' => $query->orderBy(
                Platform::query()->select('name')->whereColumn('platforms.id', 'clients.platform_id')->limit(1),
                $direction,
            ),
            'first_activated_at' => $query->orderBy('clients.first_activated_at', $direction),
            'reason' => $query->orderBy('clients.churn_reason_code', $direction),
            'last_plan' => $query->orderByRaw(
                "COALESCE(NULLIF((
                    SELECT {$this->churnPlanKeySql('churn_deal', 'churn_product')}
                    FROM deals AS churn_deal
                    LEFT JOIN products AS churn_product ON churn_product.id = churn_deal.product_id
                    WHERE churn_deal.id = ({$latestPaidDealIdSql})
                    LIMIT 1
                ), 'unknown'), '{$unknownPlanSortValue}') {$direction}"
            ),
            default => $query->orderBy('clients.churned_at', $direction),
        };

        $query->orderBy('clients.id', 'desc');
    }

    private function churnPlanKeySql(string $dealAlias, string $productAlias): string
    {
        $productValues = [
            "{$productAlias}.tier",
            "COALESCE(NULLIF({$productAlias}.display_name, ''), {$productAlias}.name)",
            "{$productAlias}.slug",
        ];
        $cases = [];

        foreach ($productValues as $value) {
            $normalized = "TRIM(LOWER(REPLACE(REPLACE(COALESCE({$value}, ''), '_', ' '), '-', ' ')))";
            $cases[] = "WHEN {$normalized} LIKE '%vvip%' THEN 'vvip'";
            $cases[] = "WHEN {$normalized} = 'vip' THEN 'vip'";
            $cases[] = "WHEN {$normalized} LIKE '%premium%' THEN 'premium'";
            $cases[] = "WHEN {$normalized} LIKE '%featured%' THEN 'featured'";
            $cases[] = "WHEN {$normalized} LIKE '%basic%' THEN 'basic'";
        }

        $productValuesEmpty = collect($productValues)
            ->map(fn (string $value) => "TRIM(COALESCE({$value}, '')) = ''")
            ->implode(' AND ');
        $fallbackAllowed = "({$productAlias}.id IS NULL OR ({$productValuesEmpty}))";
        $fallback = "TRIM(LOWER(REPLACE(REPLACE(COALESCE({$dealAlias}.plan_type, ''), '_', ' '), '-', ' ')))";

        $cases[] = "WHEN {$fallbackAllowed} AND {$fallback} LIKE '%vvip%' THEN 'vvip'";
        $cases[] = "WHEN {$fallbackAllowed} AND {$fallback} = 'vip' THEN 'vip'";
        $cases[] = "WHEN {$fallbackAllowed} AND {$fallback} LIKE '%premium%' THEN 'premium'";
        $cases[] = "WHEN {$fallbackAllowed} AND {$fallback} LIKE '%featured%' THEN 'featured'";
        $cases[] = "WHEN {$fallbackAllowed} AND {$fallback} LIKE '%basic%' THEN 'basic'";

        return 'CASE ' . implode(' ', $cases) . " ELSE 'unknown' END";
    }

    private function decorateChurnLastPlans($clients): void
    {
        $dealIds = $clients
            ->pluck('last_paid_deal_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $deals = Deal::query()
            ->with('product:id,name,display_name,slug,tier')
            ->whereIn('id', $dealIds)
            ->get()
            ->keyBy('id');

        $clients->each(function (Client $client) use ($deals): void {
            $deal = $deals->get((int) $client->last_paid_deal_id);
            $presentation = $deal
                ? Client::planPresentationFromPackageValues(
                    $deal->product?->tier,
                    $deal->product?->display_name ?: $deal->product?->name ?: $deal->plan_type,
                    $deal->product?->slug,
                )
                : null;

            $client->setAttribute('last_plan_key', $presentation['key'] ?? 'unknown');
            $client->setAttribute('last_plan_label', $presentation['label'] ?? 'Unknown');
            $client->setAttribute('last_plan_started_at', $deal?->activated_at ?? $deal?->created_at);
            $client->setAttribute('last_plan_status', $deal?->status);
            $client->makeHidden(['plan_key', 'plan_label', 'last_paid_deal_id']);
        });
    }

    public function markWonBack(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        if ($client->churned_at === null) {
            return response()->json([
                'message' => 'This client is not currently marked as churned.',
            ], 422);
        }

        $this->clientChurnStamper->clear($client, $validated['note'] ?? 'Manually marked as won-back');

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_MARK_WON_BACK,
            'client',
            (int) $client->id,
            ['churned_at' => optional($client->churned_at)?->toDateTimeString(), 'churn_reason_code' => $client->churn_reason_code],
            ['churned_at' => null, 'note' => $validated['note'] ?? null],
            $validated['note'] ?? 'Manually marked as won-back'
        );

        return response()->json([
            'message' => 'Client marked as won-back.',
            'client' => $client->fresh(['platform', 'assignedAgent']),
        ]);
    }

    private function resolveChurnRange(Request $request): array
    {
        $now = now();

        $from = trim((string) $request->input('from', ''));
        $to = trim((string) $request->input('to', ''));

        if ($from !== '' || $to !== '') {
            try {
                $start = $from !== '' ? Carbon::parse($from)->startOfDay() : $now->copy()->subDays(30)->startOfDay();
                $end = $to !== '' ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay();

                // Cap at 365 days
                $cap = $now->copy()->subYear();
                if ($start->lt($cap)) {
                    $start = $cap;
                }

                return [$start, $end];
            } catch (\Throwable) {
                // fall through to default
            }
        }

        $week = (string) $request->input('week', 'this');

        if ($week === 'last') {
            return [
                $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY),
                $now->copy()->subWeek()->endOfWeek(Carbon::SUNDAY),
            ];
        }

        if ($week === 'month') {
            return [
                $now->copy()->subDays(30)->startOfDay(),
                $now->copy()->endOfDay(),
            ];
        }

        // Default: this week Mon → now
        return [
            $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay(),
            $now->copy()->endOfDay(),
        ];
    }
}
