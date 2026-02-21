<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\Template;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ClientSyncService;
use App\Services\LeadImportService;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
use App\Services\WpSyncService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly LeadImportService $leadImportService,
        private readonly NotificationService $notificationService
    ) {
    }

    public function integrations(Request $request)
    {
        $platformQuery = Platform::query()->orderBy('id');
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $platformQuery->whereIn('id', $allowedPlatformIds);
        }

        $platforms = $platformQuery->get();
        $platformStatuses = $platforms
            ->map(fn (Platform $platform) => $this->serializePlatformIntegration($platform))
            ->values();

        $smsProvider = $this->notificationService->currentSmsConfig(masked: true);
        $activeProvider = (string) ($smsProvider['active_provider'] ?? 'legacy_gateway');
        $activeConfigured = match ($activeProvider) {
            'africastalking' => (bool) ($smsProvider['africastalking']['username'] ?? null)
                && (bool) ($smsProvider['africastalking']['api_key_configured'] ?? false),
            default => (bool) ($smsProvider['legacy_gateway']['gateway_url'] ?? null)
                && (bool) ($smsProvider['legacy_gateway']['org_code'] ?? null),
        };
        $smsStatus = $activeConfigured
            ? (($smsProvider['enabled'] ?? false) ? 'connected' : 'configured_disabled')
            : 'pending';

        return response()->json([
            'services' => [
                'sms_gateway' => [
                    'status' => $smsStatus,
                    'enabled' => (bool) ($smsProvider['enabled'] ?? false),
                    'gateway_url' => $smsProvider['legacy_gateway']['gateway_url'] ?? null,
                    'org_code' => $smsProvider['legacy_gateway']['org_code'] ?? null,
                    'active_provider' => $activeProvider,
                ],
                'sms_provider' => $smsProvider,
                'kopokopo' => [
                    'status' => config('services.kopokopo.client_id') && config('services.kopokopo.client_secret')
                        ? 'connected'
                        : 'pending',
                    'base_url' => config('services.kopokopo.base_url'),
                    'till_number' => config('services.kopokopo.till_number'),
                ],
                'sendgrid' => [
                    'status' => 'deferred',
                    'note' => 'SendGrid email dispatch is deferred until post Sprint 3 stabilization.',
                ],
            ],
            'platforms' => $platformStatuses,
            'last_checked_at' => now()->toDateTimeString(),
        ]);
    }

    public function updateSmsProvider(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can update SMS provider settings.'
        );

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'active_provider' => 'required|in:legacy_gateway,africastalking',
            'fallback_provider' => 'nullable|in:none,legacy_gateway,africastalking',
            'default_prefix' => ['nullable', 'string', 'max:5', 'regex:/^\d{1,5}$/'],
            'legacy_gateway' => 'nullable|array',
            'legacy_gateway.gateway_url' => 'nullable|url|max:255',
            'legacy_gateway.org_code' => 'nullable|string|max:20',
            'africastalking' => 'nullable|array',
            'africastalking.endpoint' => 'nullable|url|max:255',
            'africastalking.username' => 'nullable|string|max:100',
            'africastalking.api_key' => 'nullable|string|max:255',
            'africastalking.sender_id' => 'nullable|string|max:20',
            'reason' => 'nullable|string|max:500',
        ]);

        if (
            !empty($validated['fallback_provider'])
            && $validated['fallback_provider'] !== 'none'
            && $validated['fallback_provider'] === $validated['active_provider']
        ) {
            return response()->json([
                'message' => 'Fallback provider must be different from the active provider.',
            ], 422);
        }

        $before = $this->notificationService->currentSmsConfig(masked: true);
        $saved = $this->notificationService->saveSmsConfig($validated, (int) $request->user()->id);

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId([]) ?? 1,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'integration_setting',
            1,
            $before,
            $saved,
            $validated['reason'] ?? 'Updated SMS provider routing settings'
        );

        return response()->json([
            'sms_provider' => $saved,
        ]);
    }

    public function testSmsProvider(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can run SMS provider tests.'
        );

        $validated = $request->validate([
            'phone' => 'required|string|max:20',
            'message' => 'required|string|max:500',
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->notificationService->sendSms(
            $validated['phone'],
            $validated['message'],
            [
                'purpose' => 'settings_provider_test',
            ]
        );

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId([]) ?? 1,
            CrmAuditAction::INTEGRATION_CONNECTION_TEST,
            'integration_setting',
            1,
            null,
            [
                'provider' => $result['provider'] ?? null,
                'success' => (bool) ($result['success'] ?? false),
                'status' => $result['status'] ?? null,
            ],
            $validated['reason'] ?? 'SMS provider test dispatch'
        );

        return response()->json([
            'result' => $result,
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function storeIntegrationPlatform(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can create markets.'
        );

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'required|string|max:255|unique:platforms,domain',
            'country' => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
            'wp_api_url' => 'nullable|url|max:255',
            'wp_api_user' => 'nullable|string|max:100',
            'wp_api_password' => 'nullable|string|max:255',
            'phone_prefix' => ['nullable', 'string', 'max:5', 'regex:/^\d{1,5}$/'],
            'timezone' => 'nullable|string|max:50',
            'currency_code' => 'nullable|string|size:3',
            'db_host' => 'nullable|string|max:255',
            'db_name' => 'nullable|string|max:255',
            'db_user' => 'nullable|string|max:255',
            'db_pass' => 'nullable|string|max:255',
            'db_prefix' => 'nullable|string|max:32',
            'reason' => 'nullable|string|max:500',
        ]);

        $platform = Platform::query()->create($this->platformWritePayload($validated));
        $platform->refresh();

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_CREATE,
            'platform',
            (int) $platform->id,
            null,
            $this->platformAuditState($platform),
            $validated['reason'] ?? 'Created market integration profile from CRM settings'
        );

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform),
        ], 201);
    }

    public function updateIntegrationPlatform(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can update market integrations.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'domain' => ['sometimes', 'string', 'max:255', Rule::unique('platforms', 'domain')->ignore($platform->id)],
            'country' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'wp_api_url' => 'sometimes|nullable|url|max:255',
            'wp_api_user' => 'sometimes|nullable|string|max:100',
            'wp_api_password' => 'sometimes|nullable|string|max:255',
            'phone_prefix' => ['sometimes', 'nullable', 'string', 'max:5', 'regex:/^\d{1,5}$/'],
            'timezone' => 'sometimes|nullable|string|max:50',
            'currency_code' => 'sometimes|nullable|string|size:3',
            'db_host' => 'sometimes|nullable|string|max:255',
            'db_name' => 'sometimes|nullable|string|max:255',
            'db_user' => 'sometimes|nullable|string|max:255',
            'db_pass' => 'sometimes|nullable|string|max:255',
            'db_prefix' => 'sometimes|nullable|string|max:32',
            'reason' => 'nullable|string|max:500',
        ]);

        $beforeState = $this->platformAuditState($platform);
        $platform->fill($this->platformWritePayload($validated, true))->save();
        $platform->refresh();

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'platform',
            (int) $platform->id,
            $beforeState,
            $this->platformAuditState($platform),
            $validated['reason'] ?? 'Updated market integration profile from CRM settings'
        );

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform),
        ]);
    }

    public function testPlatformConnection(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can test integrations.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if (!$this->platformHasWpCredentials($platform)) {
            return response()->json([
                'message' => 'WordPress sync credentials are incomplete for this market.',
            ], 422);
        }

        $beforeState = $this->platformAuditState($platform);

        try {
            $stats = (new WpSyncService($platform))->getStats();

            $platform->forceFill([
                'sync_last_checked_at' => now(),
                'sync_last_status' => 'healthy',
                'sync_last_error' => null,
            ])->save();
            $platform->refresh();

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'platform',
                (int) $platform->id,
                $beforeState,
                $this->platformAuditState($platform),
                $validated['reason'] ?? 'Integration connection test executed'
            );

            return response()->json([
                'status' => 'healthy',
                'checked_at' => optional($platform->sync_last_checked_at)->toDateTimeString(),
                'platform' => $this->serializePlatformIntegration($platform),
                'stats' => $stats,
            ]);
        } catch (\Throwable $exception) {
            $platform->forceFill([
                'sync_last_checked_at' => now(),
                'sync_last_status' => 'error',
                'sync_last_error' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();
            $platform->refresh();

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'platform',
                (int) $platform->id,
                $beforeState,
                $this->platformAuditState($platform),
                $validated['reason'] ?? 'Integration connection test failed'
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Connection test failed. Check credentials and API reachability.',
                'error' => $exception->getMessage(),
                'platform' => $this->serializePlatformIntegration($platform),
            ], 422);
        }
    }

    public function runPlatformSync(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can run manual sync.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'scope' => 'required|in:clients,leads,all',
            'mode' => 'nullable|in:full,delta',
            'dry_run' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:20|max:200',
            'reason' => 'nullable|string|max:500',
        ]);

        if (!$this->platformHasWpCredentials($platform)) {
            return response()->json([
                'message' => 'WordPress sync credentials are incomplete for this market.',
            ], 422);
        }

        $scope = $validated['scope'];
        $mode = $validated['mode'] ?? 'delta';
        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $perPage = (int) ($validated['per_page'] ?? 100);

        if ($dryRun && in_array($scope, ['clients', 'all'], true)) {
            return response()->json([
                'message' => 'Dry-run is currently supported for leads sync only. Use scope=leads or disable dry-run.',
            ], 422);
        }

        $beforeState = $this->platformAuditState($platform);

        try {
            $result = [
                'scope' => $scope,
                'mode' => $mode,
                'dry_run' => $dryRun,
                'ran_at' => now()->toDateTimeString(),
                'clients' => null,
                'leads' => null,
            ];

            if (in_array($scope, ['clients', 'all'], true)) {
                $clientSyncService = new ClientSyncService($platform);
                $result['clients'] = $mode === 'full'
                    ? $clientSyncService->fullSync($perPage)
                    : $clientSyncService->deltaSync();
            }

            if (in_array($scope, ['leads', 'all'], true)) {
                $result['leads'] = $this->leadImportService->importPlatform($platform, $dryRun, $perPage);
            }

            $syncStatus = 'success';
            if (!empty($result['leads']['errors']) && count($result['leads']['errors']) > 0) {
                $syncStatus = 'partial';
            }

            $platform->forceFill([
                'sync_last_synced_at' => now(),
                'sync_last_scope' => $scope,
                'sync_last_status' => $syncStatus,
                'sync_last_error' => $syncStatus === 'partial' ? mb_substr((string) $result['leads']['errors'][0], 0, 500) : null,
                'sync_last_result' => $result,
            ])->save();
            $platform->refresh();

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_SYNC_RUN,
                'platform',
                (int) $platform->id,
                $beforeState,
                $this->platformAuditState($platform),
                $validated['reason'] ?? 'Manual platform sync run'
            );

            return response()->json([
                'status' => $syncStatus,
                'result' => $result,
                'platform' => $this->serializePlatformIntegration($platform),
            ]);
        } catch (\Throwable $exception) {
            $platform->forceFill([
                'sync_last_synced_at' => now(),
                'sync_last_scope' => $scope,
                'sync_last_status' => 'error',
                'sync_last_error' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();
            $platform->refresh();

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_SYNC_RUN,
                'platform',
                (int) $platform->id,
                $beforeState,
                $this->platformAuditState($platform),
                $validated['reason'] ?? 'Manual platform sync failed'
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Manual sync failed for this market.',
                'error' => $exception->getMessage(),
                'platform' => $this->serializePlatformIntegration($platform),
            ], 422);
        }
    }

    public function templates(Request $request)
    {
        $query = Template::query()->with('platform');
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $query->where(function ($builder) use ($allowedPlatformIds) {
                $builder->whereNull('platform_id')
                    ->orWhereIn('platform_id', $allowedPlatformIds);
            });
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        return response()->json(
            $query->orderByDesc('updated_at')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function owners(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this market.'
        );

        $platformMap = Platform::query()
            ->select(['id', 'name', 'country'])
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $owners = $this->marketAuthorizationService
            ->eligibleOwnersForPlatform($platformId)
            ->map(function (User $owner) use ($platformMap) {
                $accessibleIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($owner);

                if ($accessibleIds === null) {
                    $assignedMarkets = [[
                        'id' => null,
                        'name' => 'All markets',
                        'country' => 'Global',
                    ]];
                } else {
                    $assignedMarkets = collect($accessibleIds)
                        ->map(function ($marketId) use ($platformMap) {
                            $platform = $platformMap->get((int) $marketId);
                            if (!$platform) {
                                return null;
                            }

                            return [
                                'id' => (int) $platform->id,
                                'name' => $platform->name,
                                'country' => $platform->country,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all();
                }

                return [
                    'id' => (int) $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'role' => $owner->role,
                    'role_label' => $this->roleLabel($owner->role),
                    'assigned_markets' => $assignedMarkets,
                    'market_scope' => $accessibleIds === null ? 'all' : 'restricted',
                ];
            })
            ->values();

        return response()->json([
            'platform_id' => $platformId,
            'owners' => $owners,
        ]);
    }

    public function storeTemplate(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|exists:platforms,id',
            'title' => 'required|string|max:255',
            'category' => 'required|in:payment,renewal,follow_up,welcome,win_back',
            'channel' => 'required|in:email,sms',
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string|max:10000',
            'status' => 'required|in:active,draft',
            'variables' => 'nullable|array',
        ]);

        if (!empty($validated['platform_id']) && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $validated['platform_id'])) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $template = Template::create($validated);
        $template->load('platform');

        return response()->json($template, 201);
    }

    public function updateTemplate(Request $request, Template $template)
    {
        if ($template->platform_id && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $template->platform_id)) {
            return response()->json(['message' => 'You do not have access to this template market.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'category' => 'sometimes|in:payment,renewal,follow_up,welcome,win_back',
            'channel' => 'sometimes|in:email,sms',
            'subject' => 'nullable|string|max:255',
            'body' => 'sometimes|string|max:10000',
            'status' => 'sometimes|in:active,draft',
            'variables' => 'nullable|array',
        ]);

        $template->update($validated);
        $template->load('platform');

        return response()->json($template);
    }

    public function destroyTemplate(Request $request, Template $template)
    {
        if ($template->platform_id && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $template->platform_id)) {
            return response()->json(['message' => 'You do not have access to this template market.'], 403);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted']);
    }

    public function webhookLogs(Request $request)
    {
        $allowedActions = [
            CrmAuditAction::DEAL_ACTIVATE,
            CrmAuditAction::DEAL_DEACTIVATE,
            CrmAuditAction::DEAL_EXTEND,
            CrmAuditAction::PAYMENT_MATCH_AUTO,
            CrmAuditAction::PAYMENT_MATCH_CONFIRM,
            CrmAuditAction::PAYMENT_MATCH_BATCH,
            CrmAuditAction::RENEWAL_SMS_SENT,
            CrmAuditAction::RENEWAL_SMS_FAILED,
            CrmAuditAction::CONVERSATION_SMS_SENT,
            CrmAuditAction::CONVERSATION_SMS_FAILED,
            CrmAuditAction::INTEGRATION_PLATFORM_CREATE,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            CrmAuditAction::INTEGRATION_CONNECTION_TEST,
            CrmAuditAction::INTEGRATION_SYNC_RUN,
            CrmAuditAction::LEAD_SCRAPE_INTAKE,
            CrmAuditAction::LEAD_STATUS_UPDATE,
            CrmAuditAction::LEAD_ASSIGN,
            CrmAuditAction::LEAD_ARCHIVE,
            CrmAuditAction::LEAD_DELETE,
            CrmAuditAction::ROLE_UPDATE,
            CrmAuditAction::USER_CREATE,
            // Legacy action names retained for backward compatibility.
            'deal_activated',
            'deal_deactivated',
            'deal_extended',
            'payment_auto_matched',
            'payment_match_confirmed',
        ];

        $query = AuditLog::query()
            ->with('actor:id,name,email')
            ->whereIn('action', $allowedActions);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('action', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($allowedPlatformIds)) {
            $query->whereIn('platform_id', $allowedPlatformIds);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        $logs->getCollection()->transform(function (AuditLog $log) {
            $incident = $this->buildWebhookIncident($log);
            $payload = $log->toArray();
            $payload['incident'] = $incident;
            $payload['summary'] = $incident['summary'];
            $payload['severity'] = $incident['severity'];
            $payload['category'] = $incident['category'];
            $payload['suggested_action'] = $incident['suggested_action'];
            return $payload;
        });

        return response()->json($logs);
    }

    public function roles()
    {
        $platformMap = Platform::query()
            ->select(['id', 'name', 'country'])
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'status', 'assigned_market_ids'])
            ->with('platforms:id,name,country')
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($platformMap) {
                $assignedMarketIds = $this->decodeMarketIds($user->assigned_market_ids);

                if (empty($assignedMarketIds) && $user->relationLoaded('platforms')) {
                    $assignedMarketIds = $user->platforms->pluck('id')->map(fn ($id) => (int) $id)->all();
                }

                $marketDetails = collect($assignedMarketIds)
                    ->map(function ($marketId) use ($platformMap) {
                        $platform = $platformMap->get((int) $marketId);
                        if (!$platform) {
                            return null;
                        }

                        return [
                            'id' => (int) $platform->id,
                            'name' => $platform->name,
                            'country' => $platform->country,
                        ];
                    })
                    ->filter()
                    ->values();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status ?? 'active',
                    'assigned_market_ids' => array_values(array_unique(array_map('intval', $assignedMarketIds))),
                    'assigned_markets' => $marketDetails,
                ];
            });

        $summary = [
            'admins' => $users->where('role', 'admin')->count(),
            'sub_admins' => $users->where('role', 'sub_admin')->count(),
            'sales' => $users->where('role', 'sales')->count(),
            'inactive' => $users->where('status', 'inactive')->count(),
        ];

        return response()->json([
            'summary' => $summary,
            'users' => $users,
            'available_markets' => $platformMap->values()->map(fn (Platform $platform) => [
                'id' => (int) $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
            ])->values(),
        ]);
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:8|max:120',
            'role' => 'required|in:admin,sub_admin,sales',
            'status' => 'required|in:active,inactive',
            'assigned_market_ids' => 'nullable|array',
            'assigned_market_ids.*' => 'integer|exists:platforms,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $assignedMarketIds = collect($validated['assigned_market_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $passwordHash = Hash::make($validated['password'] ?? Str::random(16));

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower(trim((string) $validated['email'])),
            'password' => $passwordHash,
            'role' => $validated['role'],
            'status' => $validated['status'],
            'assigned_market_ids' => $assignedMarketIds,
        ]);

        if (method_exists($user, 'platforms')) {
            $user->platforms()->sync($assignedMarketIds);
        }

        $auditPlatformId = $this->resolveAuditPlatformId($assignedMarketIds);
        if ($auditPlatformId) {
            $this->auditService->fromRequest(
                $request,
                $auditPlatformId,
                CrmAuditAction::USER_CREATE,
                'user',
                (int) $user->id,
                null,
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status ?? 'active',
                    'assigned_market_ids' => $assignedMarketIds,
                ],
                $validated['reason'] ?? 'Created user from CRM role settings'
            );
        }

        $user->refresh();
        $user->load('platforms:id,name,country');

        $assignedMarkets = $user->platforms
            ->map(fn (Platform $platform) => [
                'id' => (int) $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
            ])
            ->values();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status ?? 'active',
            'assigned_market_ids' => $assignedMarketIds,
            'assigned_markets' => $assignedMarkets,
        ], 201);
    }

    public function updateRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => 'required|in:admin,sub_admin,sales',
            'status' => 'required|in:active,inactive',
            'assigned_market_ids' => 'nullable|array',
            'assigned_market_ids.*' => 'integer|exists:platforms,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $assignedMarketIds = collect($validated['assigned_market_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $beforeState = [
            'role' => $user->role,
            'status' => $user->status ?? 'active',
            'assigned_market_ids' => $this->decodeMarketIds($user->assigned_market_ids),
        ];

        $user->update([
            'role' => $validated['role'],
            'status' => $validated['status'],
            'assigned_market_ids' => $assignedMarketIds,
        ]);

        if (method_exists($user, 'platforms')) {
            $user->platforms()->sync($assignedMarketIds);
        }

        $auditPlatformId = $this->resolveAuditPlatformId($assignedMarketIds);
        if ($auditPlatformId) {
            $this->auditService->fromRequest(
                $request,
                $auditPlatformId,
                CrmAuditAction::ROLE_UPDATE,
                'user',
                (int) $user->id,
                $beforeState,
                [
                    'role' => $user->role,
                    'status' => $user->status ?? 'active',
                    'assigned_market_ids' => $assignedMarketIds,
                ],
                $validated['reason'] ?? 'Role and permission update from CRM settings'
            );
        }

        $user->refresh();
        $user->load('platforms:id,name,country');

        $assignedMarkets = $user->platforms
            ->map(fn (Platform $platform) => [
                'id' => (int) $platform->id,
                'name' => $platform->name,
                'country' => $platform->country,
            ])
            ->values();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status ?? 'active',
            'assigned_market_ids' => $assignedMarketIds,
            'assigned_markets' => $assignedMarkets,
        ]);
    }

    private function serializePlatformIntegration(Platform $platform): array
    {
        $hasWpCredentials = $this->platformHasWpCredentials($platform);
        $lastStatus = (string) ($platform->sync_last_status ?? 'unknown');

        $wpStatus = 'pending';
        if ($hasWpCredentials) {
            $wpStatus = in_array($lastStatus, ['error'], true) ? 'degraded' : 'connected';
        }

        return [
            'platform_id' => (int) $platform->id,
            'platform_name' => $platform->name,
            'domain' => $platform->domain,
            'country' => $platform->country,
            'is_active' => (bool) $platform->is_active,
            'currency' => $platform->currency_code ?: 'KES',
            'timezone' => $platform->timezone ?: 'Africa/Nairobi',
            'phone_prefix' => $platform->phone_prefix ?: '254',
            'wp_sync' => [
                'status' => $wpStatus,
                'credentials_ready' => $hasWpCredentials,
                'api_url' => $platform->wp_api_url,
                'api_user' => $platform->wp_api_user,
                'last_checked_at' => optional($platform->sync_last_checked_at)->toDateTimeString(),
                'last_error' => $platform->sync_last_error,
            ],
            'sync' => [
                'last_synced_at' => optional($platform->sync_last_synced_at)->toDateTimeString(),
                'last_scope' => $platform->sync_last_scope,
                'last_status' => $lastStatus,
                'last_error' => $platform->sync_last_error,
                'last_result' => $platform->sync_last_result,
            ],
        ];
    }

    private function platformWritePayload(array $validated, bool $isPatch = false): array
    {
        $payload = collect($validated)
            ->except(['reason'])
            ->map(function ($value, $key) {
                if (in_array($key, ['currency_code'], true) && is_string($value) && $value !== '') {
                    return strtoupper(trim($value));
                }

                return $value;
            })
            ->all();

        if ($isPatch && array_key_exists('wp_api_password', $payload) && empty($payload['wp_api_password'])) {
            unset($payload['wp_api_password']);
        }

        if (!$isPatch) {
            $payload['is_active'] = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
            $payload['phone_prefix'] = $payload['phone_prefix'] ?? '254';
            $payload['timezone'] = $payload['timezone'] ?? 'Africa/Nairobi';
            $payload['currency_code'] = $payload['currency_code'] ?? 'KES';
        }

        return $payload;
    }

    private function platformAuditState(Platform $platform): array
    {
        return [
            'name' => $platform->name,
            'domain' => $platform->domain,
            'country' => $platform->country,
            'is_active' => (bool) $platform->is_active,
            'wp_api_url' => $platform->wp_api_url,
            'wp_api_user' => $platform->wp_api_user,
            'phone_prefix' => $platform->phone_prefix,
            'timezone' => $platform->timezone,
            'currency_code' => $platform->currency_code,
            'sync_last_checked_at' => optional($platform->sync_last_checked_at)->toDateTimeString(),
            'sync_last_synced_at' => optional($platform->sync_last_synced_at)->toDateTimeString(),
            'sync_last_scope' => $platform->sync_last_scope,
            'sync_last_status' => $platform->sync_last_status,
            'sync_last_error' => $platform->sync_last_error,
        ];
    }

    private function platformHasWpCredentials(Platform $platform): bool
    {
        return !empty($platform->wp_api_url)
            && !empty($platform->wp_api_user)
            && !empty($platform->wp_api_password);
    }

    private function decodeMarketIds($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'Admin',
            'sub_admin' => 'Sub-admin',
            'sales' => 'Sales',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    }

    private function buildWebhookIncident(AuditLog $log): array
    {
        $action = (string) $log->action;
        $catalog = $this->webhookIncidentCatalog();
        $meta = $catalog[$action] ?? [
            'title' => ucwords(str_replace('_', ' ', $action)),
            'category' => 'operations',
            'severity' => 'medium',
            'summary' => 'Operational event recorded.',
            'suggested_action' => 'Inspect details if this event blocks workflow execution.',
        ];

        $after = is_array($log->after_state) ? $log->after_state : [];

        $summary = (string) ($meta['summary'] ?? 'Operational event recorded.');
        $severity = (string) ($meta['severity'] ?? 'medium');
        $suggestedAction = (string) ($meta['suggested_action'] ?? 'Inspect details if this event blocks workflow execution.');

        if ($action === CrmAuditAction::INTEGRATION_CONNECTION_TEST) {
            $passed = (bool) ($after['success'] ?? false);
            $summary = $passed
                ? 'Connection test succeeded for the selected integration.'
                : 'Connection test failed for the selected integration.';
            $severity = $passed ? 'low' : 'high';
            $suggestedAction = $passed
                ? 'No immediate action required.'
                : 'Review credentials and endpoint reachability, then re-run the connection test.';
        } elseif ($action === CrmAuditAction::INTEGRATION_SYNC_RUN) {
            $status = (string) ($after['status'] ?? 'unknown');
            $scope = (string) ($after['scope'] ?? 'unknown');
            $summary = sprintf('Manual %s sync finished with status: %s.', $scope, $status);
            $severity = match ($status) {
                'success' => 'low',
                'partial' => 'medium',
                'error', 'failed' => 'high',
                default => 'medium',
            };
            $suggestedAction = match ($status) {
                'success' => 'No immediate action required.',
                'partial' => 'Review warning details and rerun sync for missing records if needed.',
                'error', 'failed' => 'Open integration workspace, fix connection issues, and rerun sync.',
                default => 'Inspect sync details and rerun if records were not imported as expected.',
            };
        } elseif (in_array($action, [CrmAuditAction::PAYMENT_MATCH_BATCH, 'payment_match_confirmed'], true)) {
            $matched = (int) ($after['matched'] ?? 0);
            $unmatched = (int) ($after['unmatched'] ?? 0);
            if ($matched || $unmatched) {
                $summary = sprintf('Batch payment match completed: %d matched, %d unmatched.', $matched, $unmatched);
                $severity = $unmatched > 0 ? 'medium' : 'low';
                $suggestedAction = $unmatched > 0
                    ? 'Review unmatched payments in the queue and resolve manually.'
                    : 'No immediate action required.';
            }
        } elseif (str_contains($action, '_failed') && !array_key_exists($action, $catalog)) {
            $severity = 'high';
            $summary = sprintf('%s failed and may require intervention.', ucwords(str_replace('_', ' ', $action)));
            $suggestedAction = 'Open incident details, verify provider/integration status, and retry the operation.';
        }

        return [
            'title' => (string) ($meta['title'] ?? ucwords(str_replace('_', ' ', $action))),
            'category' => (string) ($meta['category'] ?? 'operations'),
            'severity' => $severity,
            'summary' => $summary,
            'suggested_action' => $suggestedAction,
            'reason' => $log->reason,
        ];
    }

    private function webhookIncidentCatalog(): array
    {
        return [
            CrmAuditAction::PAYMENT_MATCH_AUTO => [
                'title' => 'Payment auto-match run',
                'category' => 'payments',
                'severity' => 'low',
                'summary' => 'Payment was matched automatically.',
                'suggested_action' => 'No immediate action required unless mismatch is reported.',
            ],
            CrmAuditAction::PAYMENT_MATCH_CONFIRM => [
                'title' => 'Payment manually confirmed',
                'category' => 'payments',
                'severity' => 'low',
                'summary' => 'Payment match was confirmed manually by an operator.',
                'suggested_action' => 'No immediate action required.',
            ],
            CrmAuditAction::PAYMENT_MATCH_BATCH => [
                'title' => 'Batch payment matching',
                'category' => 'payments',
                'severity' => 'medium',
                'summary' => 'Batch matching job completed.',
                'suggested_action' => 'Review unmatched queue items if any remain.',
            ],
            CrmAuditAction::RENEWAL_SMS_SENT => [
                'title' => 'Renewal reminder sent',
                'category' => 'renewals',
                'severity' => 'low',
                'summary' => 'Renewal reminder was sent successfully.',
                'suggested_action' => 'No immediate action required.',
            ],
            CrmAuditAction::RENEWAL_SMS_FAILED => [
                'title' => 'Renewal reminder failed',
                'category' => 'renewals',
                'severity' => 'high',
                'summary' => 'Renewal reminder SMS could not be delivered.',
                'suggested_action' => 'Check SMS provider health and resend from renewals workspace.',
            ],
            CrmAuditAction::CONVERSATION_SMS_SENT => [
                'title' => 'Conversation SMS sent',
                'category' => 'conversations',
                'severity' => 'low',
                'summary' => 'Outbound conversation SMS was delivered.',
                'suggested_action' => 'No immediate action required.',
            ],
            CrmAuditAction::CONVERSATION_SMS_FAILED => [
                'title' => 'Conversation SMS failed',
                'category' => 'conversations',
                'severity' => 'high',
                'summary' => 'Outbound conversation SMS failed to send.',
                'suggested_action' => 'Verify provider connectivity and retry from the conversation panel.',
            ],
            CrmAuditAction::INTEGRATION_PLATFORM_CREATE => [
                'title' => 'Market integration created',
                'category' => 'integrations',
                'severity' => 'low',
                'summary' => 'A new market integration profile was created.',
                'suggested_action' => 'Run connection test before first sync.',
            ],
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE => [
                'title' => 'Integration settings updated',
                'category' => 'integrations',
                'severity' => 'medium',
                'summary' => 'Integration routing or credentials were changed.',
                'suggested_action' => 'Run a connection test to validate the new configuration.',
            ],
            CrmAuditAction::INTEGRATION_CONNECTION_TEST => [
                'title' => 'Integration connection test',
                'category' => 'integrations',
                'severity' => 'medium',
                'summary' => 'Connection health check completed.',
                'suggested_action' => 'Review result and remediate if test failed.',
            ],
            CrmAuditAction::INTEGRATION_SYNC_RUN => [
                'title' => 'Manual sync run',
                'category' => 'integrations',
                'severity' => 'medium',
                'summary' => 'Manual sync execution completed.',
                'suggested_action' => 'Inspect sync totals and errors before proceeding.',
            ],
            CrmAuditAction::DEAL_ACTIVATE => [
                'title' => 'Subscription activated',
                'category' => 'subscriptions',
                'severity' => 'low',
                'summary' => 'Subscription was activated.',
                'suggested_action' => 'No immediate action required.',
            ],
            CrmAuditAction::DEAL_DEACTIVATE => [
                'title' => 'Subscription deactivated',
                'category' => 'subscriptions',
                'severity' => 'medium',
                'summary' => 'Subscription was deactivated.',
                'suggested_action' => 'Confirm deactivation reason and communicate with the client if needed.',
            ],
            CrmAuditAction::DEAL_EXTEND => [
                'title' => 'Subscription extended',
                'category' => 'subscriptions',
                'severity' => 'low',
                'summary' => 'Subscription expiry date was extended.',
                'suggested_action' => 'No immediate action required.',
            ],
            CrmAuditAction::LEAD_ASSIGN => [
                'title' => 'Lead reassigned',
                'category' => 'leads',
                'severity' => 'low',
                'summary' => 'Lead ownership changed.',
                'suggested_action' => 'Ensure new owner follows up within SLA.',
            ],
            CrmAuditAction::LEAD_STATUS_UPDATE => [
                'title' => 'Lead status updated',
                'category' => 'leads',
                'severity' => 'low',
                'summary' => 'Lead pipeline stage changed.',
                'suggested_action' => 'Review conversion movement in reports if needed.',
            ],
            CrmAuditAction::LEAD_ARCHIVE => [
                'title' => 'Lead archived',
                'category' => 'leads',
                'severity' => 'medium',
                'summary' => 'Lead was archived from active pipeline.',
                'suggested_action' => 'Confirm archive reason to avoid accidental pipeline loss.',
            ],
            CrmAuditAction::LEAD_DELETE => [
                'title' => 'Lead deleted',
                'category' => 'leads',
                'severity' => 'high',
                'summary' => 'Lead record was permanently deleted.',
                'suggested_action' => 'Verify deletion reason and recover from backups if this was accidental.',
            ],
            CrmAuditAction::ROLE_UPDATE => [
                'title' => 'Role permissions changed',
                'category' => 'access',
                'severity' => 'medium',
                'summary' => 'User role or market scope was updated.',
                'suggested_action' => 'Confirm least-privilege policy is still enforced.',
            ],
            CrmAuditAction::USER_CREATE => [
                'title' => 'User account created',
                'category' => 'access',
                'severity' => 'low',
                'summary' => 'A new CRM user account was created.',
                'suggested_action' => 'Validate role and assigned markets before onboarding handoff.',
            ],
            'payment_auto_matched' => [
                'title' => 'Payment auto-match run',
                'category' => 'payments',
                'severity' => 'low',
                'summary' => 'Payment was matched automatically.',
                'suggested_action' => 'No immediate action required unless mismatch is reported.',
            ],
            'payment_match_confirmed' => [
                'title' => 'Batch payment matching',
                'category' => 'payments',
                'severity' => 'medium',
                'summary' => 'Batch matching job completed.',
                'suggested_action' => 'Review unmatched queue items if any remain.',
            ],
            'deal_activated' => [
                'title' => 'Subscription activated',
                'category' => 'subscriptions',
                'severity' => 'low',
                'summary' => 'Subscription was activated.',
                'suggested_action' => 'No immediate action required.',
            ],
            'deal_deactivated' => [
                'title' => 'Subscription deactivated',
                'category' => 'subscriptions',
                'severity' => 'medium',
                'summary' => 'Subscription was deactivated.',
                'suggested_action' => 'Confirm deactivation reason and communicate with the client if needed.',
            ],
            'deal_extended' => [
                'title' => 'Subscription extended',
                'category' => 'subscriptions',
                'severity' => 'low',
                'summary' => 'Subscription expiry date was extended.',
                'suggested_action' => 'No immediate action required.',
            ],
        ];
    }

    private function resolveAuditPlatformId(array $assignedMarketIds): ?int
    {
        if (!empty($assignedMarketIds)) {
            return (int) $assignedMarketIds[0];
        }

        $fallback = Platform::query()->orderBy('id')->value('id');
        return $fallback ? (int) $fallback : null;
    }
}
