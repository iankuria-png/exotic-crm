<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\Template;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ClientSyncService;
use App\Services\LeadImportService;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
use App\Services\ScraperSourceService;
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
        private readonly NotificationService $notificationService,
        private readonly ScraperSourceService $scraperSourceService
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

        $scraperSourcesQuery = ScraperSource::query()
            ->with('platform:id,name,country')
            ->orderByDesc('updated_at');

        $scraperRunsQuery = ScraperRun::query()
            ->with([
                'source:id,name',
                'platform:id,name,country',
                'initiatedBy:id,name,email',
            ])
            ->orderByDesc('id');

        if (is_array($allowedPlatformIds)) {
            $scraperSourcesQuery->whereIn('platform_id', $allowedPlatformIds);
            $scraperRunsQuery->whereIn('platform_id', $allowedPlatformIds);
        }

        $scraperSources = $scraperSourcesQuery->get()
            ->map(fn (ScraperSource $source) => $this->serializeScraperSource($source))
            ->values();

        $scraperRuns = $scraperRunsQuery->limit(15)->get()
            ->map(fn (ScraperRun $run) => $this->serializeScraperRun($run))
            ->values();

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
            'scraper' => [
                'sources' => $scraperSources,
                'recent_runs' => $scraperRuns,
                'parser_profiles' => ScraperSourceService::PARSER_PROFILES,
                'fetch_schedules' => ScraperSourceService::FETCH_SCHEDULES,
                'dedupe_modes' => ScraperSourceService::DEDUPE_MODES,
            ],
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

    public function storeScraperSource(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can create scraper sources.'
        );

        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'name' => 'required|string|max:255',
            'source_url' => 'required|url|max:500',
            'parser_profile' => ['required', Rule::in(ScraperSourceService::PARSER_PROFILES)],
            'fetch_schedule' => ['required', Rule::in(ScraperSourceService::FETCH_SCHEDULES)],
            'dedupe_mode' => ['required', Rule::in(ScraperSourceService::DEDUPE_MODES)],
            'is_active' => 'nullable|boolean',
            'compliance_ack_robots' => 'nullable|boolean',
            'compliance_ack_tos' => 'nullable|boolean',
            'compliance_notes' => 'nullable|string|max:500',
            'parser_rules' => 'nullable|array',
            'parser_rules.row_selector' => 'nullable|string|max:255',
            'parser_rules.name_selector' => 'nullable|string|max:255',
            'parser_rules.phone_selector' => 'nullable|string|max:255',
            'parser_rules.email_selector' => 'nullable|string|max:255',
            'parser_rules.link_selector' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this market.'
        );

        $sourceUrl = trim((string) $validated['source_url']);
        if (ScraperSource::query()->where('platform_id', $platformId)->where('source_url', $sourceUrl)->exists()) {
            return response()->json([
                'message' => 'This source URL is already configured for the selected market.',
            ], 422);
        }

        $source = ScraperSource::query()->create([
            'platform_id' => $platformId,
            'name' => trim((string) $validated['name']),
            'source_url' => $sourceUrl,
            'parser_profile' => (string) $validated['parser_profile'],
            'fetch_schedule' => (string) $validated['fetch_schedule'],
            'dedupe_mode' => (string) $validated['dedupe_mode'],
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
            'compliance_ack_robots' => (bool) ($validated['compliance_ack_robots'] ?? false),
            'compliance_ack_tos' => (bool) ($validated['compliance_ack_tos'] ?? false),
            'compliance_notes' => !empty($validated['compliance_notes']) ? trim((string) $validated['compliance_notes']) : null,
            'parser_rules' => $this->normalizeParserRules($validated['parser_rules'] ?? []),
            'created_by' => (int) $request->user()->id,
            'updated_by' => (int) $request->user()->id,
        ]);
        $source->load('platform:id,name,country');

        $this->auditService->fromRequest(
            $request,
            $platformId,
            CrmAuditAction::SCRAPER_SOURCE_CREATE,
            'scraper_source',
            (int) $source->id,
            null,
            $this->scraperSourceAuditState($source),
            $validated['reason'] ?? 'Created scraper source from settings'
        );

        return response()->json([
            'source' => $this->serializeScraperSource($source),
        ], 201);
    }

    public function updateScraperSource(Request $request, ScraperSource $scraperSource)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can update scraper sources.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $scraperSource->platform_id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'source_url' => ['sometimes', 'url', 'max:500', Rule::unique('scraper_sources', 'source_url')->ignore($scraperSource->id)->where(function ($query) use ($scraperSource) {
                return $query->where('platform_id', (int) $scraperSource->platform_id);
            })],
            'parser_profile' => ['sometimes', Rule::in(ScraperSourceService::PARSER_PROFILES)],
            'fetch_schedule' => ['sometimes', Rule::in(ScraperSourceService::FETCH_SCHEDULES)],
            'dedupe_mode' => ['sometimes', Rule::in(ScraperSourceService::DEDUPE_MODES)],
            'is_active' => 'sometimes|boolean',
            'compliance_ack_robots' => 'sometimes|boolean',
            'compliance_ack_tos' => 'sometimes|boolean',
            'compliance_notes' => 'sometimes|nullable|string|max:500',
            'parser_rules' => 'sometimes|array',
            'parser_rules.row_selector' => 'nullable|string|max:255',
            'parser_rules.name_selector' => 'nullable|string|max:255',
            'parser_rules.phone_selector' => 'nullable|string|max:255',
            'parser_rules.email_selector' => 'nullable|string|max:255',
            'parser_rules.link_selector' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = $this->scraperSourceAuditState($scraperSource);

        $nextPayload = [];
        foreach (['name', 'source_url', 'parser_profile', 'fetch_schedule', 'dedupe_mode', 'is_active', 'compliance_ack_robots', 'compliance_ack_tos', 'compliance_notes'] as $key) {
            if (array_key_exists($key, $validated)) {
                $nextPayload[$key] = $validated[$key];
            }
        }
        if (array_key_exists('parser_rules', $validated)) {
            $nextPayload['parser_rules'] = $this->normalizeParserRules($validated['parser_rules'] ?? []);
        }
        $nextPayload['updated_by'] = (int) $request->user()->id;

        $scraperSource->fill($nextPayload)->save();
        $scraperSource->refresh();
        $scraperSource->load('platform:id,name,country');

        $this->auditService->fromRequest(
            $request,
            (int) $scraperSource->platform_id,
            CrmAuditAction::SCRAPER_SOURCE_UPDATE,
            'scraper_source',
            (int) $scraperSource->id,
            $before,
            $this->scraperSourceAuditState($scraperSource),
            $validated['reason'] ?? 'Updated scraper source from settings'
        );

        return response()->json([
            'source' => $this->serializeScraperSource($scraperSource),
        ]);
    }

    public function runScraperSource(Request $request, ScraperSource $scraperSource)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can run scraper sources.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $scraperSource->platform_id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'dry_run' => 'nullable|boolean',
            'max_candidates' => 'nullable|integer|min:1|max:250',
            'reason' => 'nullable|string|max:500',
        ]);

        $dryRun = (bool) ($validated['dry_run'] ?? true);
        $maxCandidates = (int) ($validated['max_candidates'] ?? 50);

        $result = $this->scraperSourceService->runSource(
            $scraperSource,
            $request->user(),
            $dryRun,
            $maxCandidates
        );

        $this->auditService->fromRequest(
            $request,
            (int) $scraperSource->platform_id,
            CrmAuditAction::SCRAPER_RUN,
            'scraper_source',
            (int) $scraperSource->id,
            null,
            [
                'status' => $result['status'] ?? 'error',
                'dry_run' => $dryRun,
                'discovered' => (int) ($result['discovered'] ?? 0),
                'created' => (int) ($result['created'] ?? 0),
                'duplicates' => (int) ($result['duplicates'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'error_count' => count($result['errors'] ?? []),
            ],
            $validated['reason'] ?? ($dryRun ? 'Dry-run scraper execution from settings' : 'Scraper import run from settings')
        );

        $scraperSource->refresh();
        $scraperSource->load('platform:id,name,country');

        $statusCode = in_array(($result['status'] ?? ''), ['blocked', 'error'], true) ? 422 : 200;

        return response()->json([
            'source' => $this->serializeScraperSource($scraperSource),
            'result' => $result,
        ], $statusCode);
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
            CrmAuditAction::SCRAPER_SOURCE_CREATE,
            CrmAuditAction::SCRAPER_SOURCE_UPDATE,
            CrmAuditAction::SCRAPER_RUN,
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

    private function serializeScraperSource(ScraperSource $source): array
    {
        return [
            'id' => (int) $source->id,
            'platform_id' => (int) $source->platform_id,
            'platform_name' => $source->platform?->name,
            'platform_country' => $source->platform?->country,
            'name' => $source->name,
            'source_url' => $source->source_url,
            'parser_profile' => $source->parser_profile,
            'parser_rules' => is_array($source->parser_rules) ? $source->parser_rules : [],
            'fetch_schedule' => $source->fetch_schedule,
            'dedupe_mode' => $source->dedupe_mode,
            'is_active' => (bool) $source->is_active,
            'compliance_ack_robots' => (bool) $source->compliance_ack_robots,
            'compliance_ack_tos' => (bool) $source->compliance_ack_tos,
            'compliance_notes' => $source->compliance_notes,
            'last_run_at' => optional($source->last_run_at)->toDateTimeString(),
            'last_run_status' => $source->last_run_status,
            'last_run_summary' => is_array($source->last_run_summary) ? $source->last_run_summary : null,
            'updated_at' => optional($source->updated_at)->toDateTimeString(),
        ];
    }

    private function serializeScraperRun(ScraperRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'scraper_source_id' => (int) $run->scraper_source_id,
            'source_name' => $run->source?->name,
            'platform_id' => (int) $run->platform_id,
            'platform_name' => $run->platform?->name,
            'mode' => $run->mode,
            'status' => $run->status,
            'reason' => $run->reason,
            'discovered_count' => (int) $run->discovered_count,
            'created_count' => (int) $run->created_count,
            'duplicate_count' => (int) $run->duplicate_count,
            'skipped_count' => (int) $run->skipped_count,
            'error_count' => (int) $run->error_count,
            'preview' => is_array($run->preview) ? $run->preview : [],
            'result' => is_array($run->result) ? $run->result : null,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'completed_at' => optional($run->completed_at)->toDateTimeString(),
            'initiated_by' => $run->initiatedBy ? [
                'id' => (int) $run->initiatedBy->id,
                'name' => $run->initiatedBy->name,
                'email' => $run->initiatedBy->email,
            ] : null,
        ];
    }

    private function normalizeParserRules(array $rules): array
    {
        $normalized = [];
        foreach (['row_selector', 'name_selector', 'phone_selector', 'email_selector', 'link_selector'] as $key) {
            if (!array_key_exists($key, $rules)) {
                continue;
            }

            $value = trim((string) $rules[$key]);
            if ($value !== '') {
                $normalized[$key] = mb_substr($value, 0, 255);
            }
        }

        return $normalized;
    }

    private function scraperSourceAuditState(ScraperSource $source): array
    {
        return [
            'platform_id' => (int) $source->platform_id,
            'name' => $source->name,
            'source_url' => $source->source_url,
            'parser_profile' => $source->parser_profile,
            'fetch_schedule' => $source->fetch_schedule,
            'dedupe_mode' => $source->dedupe_mode,
            'is_active' => (bool) $source->is_active,
            'compliance_ack_robots' => (bool) $source->compliance_ack_robots,
            'compliance_ack_tos' => (bool) $source->compliance_ack_tos,
            'compliance_notes' => $source->compliance_notes,
            'parser_rules' => is_array($source->parser_rules) ? $source->parser_rules : [],
            'last_run_status' => $source->last_run_status,
        ];
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
        } elseif ($action === CrmAuditAction::SCRAPER_RUN) {
            $status = (string) ($after['status'] ?? 'unknown');
            $discovered = (int) ($after['discovered'] ?? 0);
            $created = (int) ($after['created'] ?? 0);
            $summary = sprintf('Scraper run finished with status: %s (%d discovered, %d created).', $status, $discovered, $created);
            $severity = match ($status) {
                'success' => 'low',
                'partial' => 'medium',
                'blocked', 'error', 'failed' => 'high',
                default => 'medium',
            };
            $suggestedAction = match ($status) {
                'success' => 'No immediate action required.',
                'partial' => 'Review run warnings and rerun after parser adjustments.',
                'blocked' => 'Confirm robots/terms acknowledgement and resolve policy blockers before rerun.',
                'error', 'failed' => 'Inspect scrape source configuration and retry with dry-run.',
                default => 'Inspect run output before retrying.',
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
            CrmAuditAction::SCRAPER_SOURCE_CREATE => [
                'title' => 'Scraper source created',
                'category' => 'integrations',
                'severity' => 'low',
                'summary' => 'A new scraper source profile was created.',
                'suggested_action' => 'Run a dry-run scrape to validate parser and dedupe rules.',
            ],
            CrmAuditAction::SCRAPER_SOURCE_UPDATE => [
                'title' => 'Scraper source updated',
                'category' => 'integrations',
                'severity' => 'medium',
                'summary' => 'Scraper source settings were updated.',
                'suggested_action' => 'Run a dry-run to confirm extraction quality before live import.',
            ],
            CrmAuditAction::SCRAPER_RUN => [
                'title' => 'Scraper run executed',
                'category' => 'leads',
                'severity' => 'medium',
                'summary' => 'Scraper pipeline run completed.',
                'suggested_action' => 'Review run summary and resolve blocked/failed states before retrying.',
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
