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
use App\Services\WpSyncService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly LeadImportService $leadImportService
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

        $smsEnabled = (bool) config('services.sms.enabled', false);
        $smsConfigured = (bool) config('services.sms.gateway_url') && (bool) config('services.sms.org_code');

        return response()->json([
            'services' => [
                'sms_gateway' => [
                    'status' => $smsConfigured ? ($smsEnabled ? 'connected' : 'configured_disabled') : 'pending',
                    'enabled' => $smsEnabled,
                    'gateway_url' => config('services.sms.gateway_url'),
                    'org_code' => config('services.sms.org_code'),
                ],
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
        $query = AuditLog::query()
            ->with('actor:id,name,email')
            ->whereIn('action', [
                'deal_activate',
                'deal_deactivate',
                'deal_extend',
                'deal_activated',
                'deal_deactivated',
                'deal_extended',
                'payment_auto_matched',
                'payment_match_confirmed',
                'payment_match_auto',
                'payment_match_confirm',
                'payment_match_batch',
            ]);

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

        return response()->json(
            $query->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 25))
        );
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

    private function resolveAuditPlatformId(array $assignedMarketIds): ?int
    {
        if (!empty($assignedMarketIds)) {
            return (int) $assignedMarketIds[0];
        }

        $fallback = Platform::query()->orderBy('id')->value('id');
        return $fallback ? (int) $fallback : null;
    }
}
