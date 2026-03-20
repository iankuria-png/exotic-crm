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
use App\Services\AuditService;
use App\Services\ClientRetentionInsightService;
use App\Services\LeadAssignmentService;
use App\Services\MarketAuthorizationService;
use App\Services\PaymentMatchingService;
use App\Services\CredentialDeliveryService;
use App\Services\ClientSyncService;
use App\Services\SupportBoardService;
use App\Services\WpDirectProvisioningService;
use App\Services\WpSyncService;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly LeadAssignmentService $leadAssignmentService,
        private readonly AuditService $auditService,
        private readonly CredentialDeliveryService $credentialDeliveryService,
        private readonly ClientRetentionInsightService $clientRetentionInsightService
    ) {
    }

    public function index(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this client market.'
        );

        $query = Client::with([
            'platform',
            'assignedAgent',
            'retentionInsight:client_id,score,band,primary_tag,computed_at',
        ]);
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $numeric = (int) $search;
                    $q->orWhere('id', $numeric)
                        ->orWhere('wp_post_id', $numeric)
                        ->orWhere('wp_user_id', $numeric);
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('profile_status', $request->status);
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        if ($request->filled('plan')) {
            if ($request->plan === 'premium') {
                $query->where('premium', true);
            } elseif ($request->plan === 'featured') {
                $query->where('featured', true);
            } elseif ($request->plan === 'basic') {
                $query->where('premium', false)->where('featured', false);
            }
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

        $statsQuery = clone $query;
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'active' => (clone $statsQuery)->where('profile_status', 'publish')->count(),
            'premium' => (clone $statsQuery)->where('premium', true)->count(),
            'verified' => (clone $statsQuery)->where('verified', true)->count(),
            'inactive' => (clone $statsQuery)->where('profile_status', 'private')->count(),
            'with_chat' => (clone $statsQuery)->whereNotNull('sb_user_id')->count(),
            'online_now' => (clone $statsQuery)->where('last_online_at', '>=', now()->subMinutes(15)->timestamp)->count(),
            'retention_watch' => (clone $statsQuery)->whereHas('retentionInsight', function ($builder) {
                $builder->whereIn('band', ClientRetentionInsightService::WATCH_BANDS);
            })->count(),
        ];

        $clients = $query->orderBy('updated_at', 'desc')
            ->paginate($request->get('per_page', 25));

        $payload = $clients->toArray();
        $payload['stats'] = $stats;

        return response()->json($payload);
    }

    public function store(Request $request)
    {
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
            'reason' => 'nullable|string|max:500',
        ]);

        $onboardingMode = (string) ($validated['onboarding_mode'] ?? 'manual');
        if (
            $onboardingMode === 'wp_provision'
            && empty($validated['email'])
            && empty($validated['phone_normalized'])
        ) {
            return response()->json([
                'message' => 'Email or phone is required when provisioning a WordPress profile.',
            ], 422);
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

        $client->load([
            'platform',
            'assignedAgent',
            'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
            'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'activeDeal.product',
        ]);

        return response()->json($client);
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
            'note_type' => 'required|in:call,email,sms,internal,system,support_chat',
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
            $client->load([
                'platform',
                'assignedAgent',
                'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
                'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'activeDeal.product',
            ]);

            return response()->json($client);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sync failed: ' . $e->getMessage(),
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
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to fetch WordPress profile.',
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

        $fields = $this->normalizeWpProfileFields($validated['fields']);
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

        $attemptedBlocked = array_values(array_intersect(array_keys($fields), $blockedFields));
        if (!empty($attemptedBlocked)) {
            return response()->json([
                'message' => 'Subscription and activation fields are not editable from profile management.',
                'blocked_fields' => $attemptedBlocked,
            ], 422);
        }

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $currentProfile = $wpSync->getClientProfile((int) $client->wp_post_id);

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

            $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
            $syncService = new \App\Services\ClientSyncService($platform);
            $syncService->syncOne((int) $client->wp_post_id);
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
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to fetch client media.',
                'error' => $exception->getMessage(),
            ], 502);
        }
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
            'file' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'set_main' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $result = $wpSync->uploadClientMedia(
                (int) $client->wp_post_id,
                $request->file('file'),
                (bool) ($validated['set_main'] ?? false)
            );

            $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
            (new \App\Services\ClientSyncService($platform))->syncOne((int) $client->wp_post_id);

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::CLIENT_PROFILE_EDIT,
                'client',
                (int) $client->id,
                null,
                [
                    'media_upload' => [
                        'attachment_id' => $result['attachment']['id'] ?? null,
                        'filename' => $result['attachment']['filename'] ?? null,
                        'set_main' => (bool) ($validated['set_main'] ?? false),
                    ],
                ],
                $validated['reason'] ?? 'Uploaded profile media from CRM'
            );

            return response()->json($result);
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

                $matcher = new PaymentMatchingService();
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

    public function sendCredentials(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'method' => 'required|in:setup_link,temporary_password',
            'channel' => 'required|in:email,sms,both',
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

        $provisioningResult = (new WpDirectProvisioningService($platform))->provisionEscort([
            'name' => $name,
            'email' => !empty($payload['email']) ? trim((string) $payload['email']) : '',
            'phone' => PhoneNormalizer::normalize($payload['phone_normalized'] ?? null, $phonePrefix) ?? '',
            'city' => !empty($payload['city']) ? trim((string) $payload['city']) : '',
            'post_status' => $profileStatus,
            'username' => !empty($payload['wp_username']) ? trim((string) $payload['wp_username']) : '',
            'password' => !empty($payload['wp_password']) ? (string) $payload['wp_password'] : '',
        ]);

        $wpPostId = (int) ($provisioningResult['wp_post_id'] ?? 0);
        $wpUserId = (int) ($provisioningResult['wp_user_id'] ?? 0);
        if ($wpPostId <= 0 || $wpUserId <= 0) {
            throw new \RuntimeException('WordPress provisioning did not return valid profile IDs.');
        }

        $client = Client::updateOrCreate(
            [
                'platform_id' => $platformId,
                'wp_post_id' => $wpPostId,
            ],
            [
                'wp_user_id' => $wpUserId,
                'client_type' => 'escort',
                'name' => $name,
                'phone_normalized' => PhoneNormalizer::normalize($payload['phone_normalized'] ?? null, $phonePrefix),
                'email' => !empty($payload['email']) ? trim((string) $payload['email']) : null,
                'city' => !empty($payload['city']) ? trim((string) $payload['city']) : null,
                'profile_status' => (string) ($provisioningResult['wp_post_status'] ?? $profileStatus),
                'assigned_to' => $assignedTo,
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
                $syncedClient->save();
            }
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
                'assigned_to' => $client->assigned_to,
                'profile_status' => $client->profile_status,
                'wp_post_id' => $client->wp_post_id,
                'wp_user_id' => $client->wp_user_id,
                'linked_existing_user' => (bool) ($provisioningResult['linked_existing_user'] ?? false),
                'placeholder_email_used' => (bool) ($provisioningResult['placeholder_email_used'] ?? false),
                'sync_status' => $syncStatus,
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
            ],
            $reason
        );

        $client->load(['platform', 'assignedAgent']);

        return $client;
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

        $manualWpPostId = $this->nextManualWpPostId($platformId);

        $client = Client::create([
            'platform_id' => $platformId,
            'wp_post_id' => $manualWpPostId,
            'wp_user_id' => !empty($payload['wp_user_id']) ? (int) $payload['wp_user_id'] : null,
            'client_type' => 'escort',
            'name' => $name,
            'phone_normalized' => PhoneNormalizer::normalize($payload['phone_normalized'] ?? null, $phonePrefix),
            'email' => !empty($payload['email']) ? trim((string) $payload['email']) : null,
            'city' => !empty($payload['city']) ? trim((string) $payload['city']) : null,
            'profile_status' => $profileStatus,
            'assigned_to' => $assignedTo,
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
                'assigned_to' => $client->assigned_to,
                'profile_status' => $client->profile_status,
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
            ],
            $reason
        );

        $client->load(['platform', 'assignedAgent']);

        return $client;
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
            && !empty($platform->db_user);
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

        if (array_key_exists('services', $normalized)) {
            $normalized['services'] = $this->normalizeWpProfileServices($normalized['services']);
        }

        if (array_key_exists('height', $normalized)) {
            $normalized['height'] = $this->normalizeWpProfileHeight($normalized['height']);
        }

        return $normalized;
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

    private function normalizeWpProfileServices(mixed $value): mixed
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
            $resolved = $this->normalizeWpProfileEnumCode('services', $token);
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
        // Child-theme canonical maps used by operators in production.
        return [
            'gender' => [
                '1' => 'Female',
                '2' => 'Male',
                '3' => 'Couple',
                '4' => 'Gay',
                '5' => 'Transsexual',
            ],
            'ethnicity' => [
                '1' => 'Latin',
                '2' => 'Caucasian',
                '3' => 'Black',
                '4' => 'White',
                '5' => 'MiddleEast',
                '6' => 'Asian',
                '7' => 'Indian',
                '8' => 'Aborigine',
                '9' => 'Native American',
                '10' => 'Other',
            ],
            'build' => [
                '1' => 'Skinny',
                '2' => 'Slim',
                '3' => 'Regular',
                '4' => 'Curvy',
                '5' => 'Fat',
            ],
            'services' => [
                '1' => 'BDSM',
                '2' => 'Couples',
                '3' => 'Domination',
                '4' => 'Escort',
                '5' => 'Massage',
                '6' => 'Fetish',
                '7' => 'Mature',
                '8' => 'GFE',
            ],
        ];
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
                $snapshot[$field] = data_get($taxonomies, 'city.name');
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
        $fields[] = ['key' => 'photo', 'label' => 'At least 1 photo', 'filled' => (bool) $client->main_image_url];

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
}
