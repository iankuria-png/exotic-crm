<?php

namespace App\Http\Controllers\CRM;

use App\Billing\Contracts\BillingDiagnosticsAssembler as BillingDiagnosticsAssemblerContract;
use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Contracts\ProviderCredentialSchemaRegistry as ProviderCredentialSchemaRegistryContract;
use App\Billing\Diagnostics\BillingDiagnosticsPresenter;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ProviderCapability;
use App\Billing\BillingPermissions;
use App\Billing\Support\ProviderProfileManager;
use App\Http\Controllers\Controller;
use App\Jobs\RunSbLeadImportJob;
use App\Jobs\RunClientSyncJob;
use App\Jobs\RunSupportBoardSyncJob;
use App\Models\AuditLog;
use App\Models\ClientSyncRun;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Models\SbLeadImportRun;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\SupportBoardSyncRun;
use App\Models\Template;
use App\Models\User;
use App\Models\BillingRoutingRule;
use App\Models\BillingWalletRule;
use App\Models\BillingSubscriptionRule;
use App\Models\BillingManualPaymentMethod;
use App\Models\ReportingFxRate;
use App\Models\BillingProviderProfile;
use App\Models\BillingMarketProviderBinding;
use App\Services\AuditService;
use App\Services\ClientSyncRunService;
use App\Services\ClientSyncService;
use App\Services\LeadImportService;
use App\Services\MarketAuthorizationService;
use App\Services\SbLeadImportRunService;
use App\Services\SupportBoardLeadImportService;
use App\Services\NotificationService;
use App\Services\ProductCatalogService;
use App\Services\PushNotification\PushProviderService;
use App\Services\ReportingCurrencyService;
use App\Services\ScraperSourceService;
use App\Services\SupportBoardSyncRunService;
use App\Services\SupportBoardService;
use App\Services\WalletSyncService;
use App\Services\WalletSettingsService;
use App\Services\WordPressSyncKeyService;
use App\Services\WpSyncService;
use App\Support\CrmAuditAction;
use App\Support\MarketTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    private const SALES_DASHBOARD_WIDGETS_KEY = 'sales_dashboard_widgets';
    private const SALES_DASHBOARD_WIDGET_DEFAULTS = [
        'todos' => true,
        'goals' => true,
        'expiring_subs' => true,
        'payment_recovery' => true,
        'top_countries' => true,
        'top_packages' => true,
        'profile_engagement' => true,
        'missed_chats' => true,
    ];

    private const MANUAL_PAYMENT_METHOD_DEFINITIONS = [
        'collector' => [
            'label' => 'Collector',
            'detail_fields' => ['network', 'phone_number', 'recipient_name', 'collector_label'],
        ],
        'paybill' => [
            'label' => 'Paybill',
            'detail_fields' => ['provider_name', 'business_number', 'account_reference_hint', 'recipient_name'],
        ],
        'bank' => [
            'label' => 'Bank transfer',
            'detail_fields' => ['bank_name', 'account_number', 'account_name', 'branch'],
        ],
    ];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly LeadImportService $leadImportService,
        private readonly NotificationService $notificationService,
        private readonly PushProviderService $pushProviderService,
        private readonly ScraperSourceService $scraperSourceService,
        private readonly SbLeadImportRunService $sbLeadImportRunService,
        private readonly SupportBoardLeadImportService $supportBoardLeadImportService,
        private readonly SupportBoardSyncRunService $supportBoardSyncRunService,
        private readonly ClientSyncRunService $clientSyncRunService,
        private readonly WalletSettingsService $walletSettingsService,
        private readonly WalletSyncService $walletSyncService,
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly BillingDiagnosticsAssemblerContract $billingDiagnosticsAssembler,
        private readonly BillingDiagnosticsPresenter $billingDiagnosticsPresenter,
        private readonly BillingProviderRegistryContract $billingProviderRegistry,
        private readonly ProviderCredentialSchemaRegistryContract $providerCredentialSchemaRegistry,
        private readonly ProviderProfileManager $providerProfileManager
    ) {
    }

    public function integrations(Request $request)
    {
        [$platforms, $platformStatuses, $allowedPlatformIds] = $this->accessiblePlatformsAndStatuses($request);

        $smsProvider = $this->scopeSmsConfigForUser(
            $this->notificationService->currentSmsConfig(masked: true),
            $request->user()
        );
        $pushProvider = $this->scopePushConfigForUser(
            $this->pushProviderService->currentPushConfig(masked: true),
            $request->user()
        );
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
            ->map(fn(ScraperSource $source) => $this->serializeScraperSource($source))
            ->values();

        $scraperRuns = $scraperRunsQuery->limit(15)->get()
            ->map(fn(ScraperRun $run) => $this->serializeScraperRun($run))
            ->values();

        return response()->json([
            'services' => [
                'wallet_system' => $this->walletSystemSummary($platformStatuses),
                'sms_gateway' => [
                    'status' => $smsStatus,
                    'enabled' => (bool) ($smsProvider['enabled'] ?? false),
                    'gateway_url' => $smsProvider['legacy_gateway']['gateway_url'] ?? null,
                    'org_code' => $smsProvider['legacy_gateway']['org_code'] ?? null,
                    'active_provider' => $activeProvider,
                ],
                'sms_provider' => $smsProvider,
                'push_provider' => $pushProvider,
                'kopokopo' => [
                    'status' => config('services.kopokopo.client_id') && config('services.kopokopo.client_secret') && config('services.kopokopo.api_key')
                        ? 'connected'
                        : 'pending',
                    'base_url' => config('services.kopokopo.base_url'),
                    'till_number' => config('services.kopokopo.till_number'),
                ],
                'payment_service' => [
                    'status' => config('services.django.base_url') ? 'connected' : 'pending',
                    'base_url' => config('services.django.base_url'),
                    'payment_link_path' => config('services.payment_link.path'),
                    'note' => 'STK push (including retry) and payment initiation use this Django proxy URL.',
                ],
                'sendgrid' => [
                    'status' => 'deferred',
                    'note' => 'SendGrid email dispatch is deferred until post Sprint 3 stabilization.',
                ],
            ],
            'billing' => [
                'enabled' => (bool) config('services.billing.enabled', false),
                'features' => (array) config('services.billing.features', []),
                'provider_families' => (array) config('services.billing.provider_family', []),
                'registry' => [
                    'providers' => array_values(array_map(
                        static fn ($definition) => $definition->toArray(),
                        $this->billingProviderRegistry->definitions()
                    )),
                    'schemas' => $this->serializeProviderSchemas(),
                ],
            ],
            'wallet' => [
                'system' => $this->walletSettingsService->currentSystemConfig(masked: true),
                'provider_keys' => $this->walletSettingsService->providerKeys(),
                'provider_schemas' => $this->serializeProviderSchemas($this->walletSettingsService->providerKeys()),
                'mode_options' => WalletSettingsService::MODES,
                'environment_options' => WalletSettingsService::ENVIRONMENTS,
            ],
            'platforms' => $platformStatuses,
            'scraper' => [
                'sources' => $scraperSources,
                'recent_runs' => $scraperRuns,
                'presets' => $this->scraperSourceService->competitorPresets(),
                'parser_profiles' => ScraperSourceService::PARSER_PROFILES,
                'fetch_schedules' => ScraperSourceService::FETCH_SCHEDULES,
                'dedupe_modes' => ScraperSourceService::DEDUPE_MODES,
            ],
            'last_checked_at' => now()->toDateTimeString(),
        ]);
    }

    public function reportingCurrency(Request $request)
    {
        $settings = $this->reportingCurrencyService->settings();

        return response()->json([
            'settings' => $settings,
            'mode_options' => [
                ['value' => ReportingCurrencyService::MODE_FLAT, 'label' => 'Converted USD'],
                ['value' => ReportingCurrencyService::MODE_NATIVE, 'label' => 'Native currencies'],
            ],
            'recommended_defaults' => [
                'all_market_management' => ReportingCurrencyService::MODE_FLAT,
                'single_market_operations' => ReportingCurrencyService::MODE_NATIVE,
                'payments_rows' => ReportingCurrencyService::MODE_NATIVE,
                'exports' => 'both',
            ],
            'guardrails' => [
                'Reporting FX is read-only and does not mutate payment, subscription, wallet, or matching records.',
                'Native transaction currency remains authoritative for payment operations and reconciliation.',
                'Missing rates mark converted totals as partial instead of silently presenting bad totals.',
            ],
        ]);
    }

    public function updateReportingCurrency(Request $request)
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can update reporting currency settings.'
        );

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'target_currency' => 'sometimes|string|min:3|max:8',
            'provider' => ['sometimes', 'string', 'max:40', Rule::in(['currencyapi', 'manual'])],
            'allow_user_override' => 'sometimes|boolean',
            'stale_days' => 'sometimes|integer|min:0|max:31',
            'rate_policy' => ['sometimes', 'string', Rule::in(['historical_locked'])],
            'fallback_behavior' => ['sometimes', 'string', Rule::in(['partial_with_native', 'native_only'])],
            'api_key' => 'sometimes|nullable|string|max:200',
        ]);

        $settings = $this->reportingCurrencyService->updateSettings($validated, $request->user()?->id);

        return response()->json([
            'settings' => $settings,
        ]);
    }

    public function testReportingCurrencyProvider(Request $request)
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can test FX provider connectivity.'
        );

        $result = $this->reportingCurrencyService->testProvider();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function listReportingFxRates(Request $request)
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can manage manual FX rates.'
        );

        $rates = ReportingFxRate::query()
            ->where('provider', 'manual')
            ->orderByDesc('rate_date')
            ->orderBy('source_currency')
            ->get()
            ->map(fn (ReportingFxRate $r) => $this->formatFxRate($r));

        return response()->json(['data' => $rates]);
    }

    public function createReportingFxRate(Request $request)
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can manage manual FX rates.'
        );

        $validated = $request->validate([
            'source_currency' => 'required|string|min:3|max:8',
            'target_currency' => 'required|string|min:3|max:8',
            'rate_date' => 'required|date',
            'rate' => 'required|numeric|min:0.0000000001',
            'notes' => 'nullable|string|max:255',
        ]);

        $rate = ReportingFxRate::query()->updateOrCreate(
            [
                'provider' => 'manual',
                'source_currency' => strtoupper(trim($validated['source_currency'])),
                'target_currency' => strtoupper(trim($validated['target_currency'])),
                'rate_date' => $validated['rate_date'],
            ],
            [
                'rate' => (float) $validated['rate'],
                'fetched_at' => now(),
                'metadata' => array_filter(['notes' => $validated['notes'] ?? null]),
            ]
        );

        return response()->json(['rate' => $this->formatFxRate($rate)], 201);
    }

    public function updateReportingFxRate(Request $request, ReportingFxRate $reportingFxRate)
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can manage manual FX rates.'
        );

        if ($reportingFxRate->provider !== 'manual') {
            return response()->json(['message' => 'Only manual rates can be edited.'], 422);
        }

        $validated = $request->validate([
            'rate' => 'sometimes|numeric|min:0.0000000001',
            'notes' => 'nullable|string|max:255',
        ]);

        if (isset($validated['rate'])) {
            $reportingFxRate->rate = (float) $validated['rate'];
        }
        if (array_key_exists('notes', $validated)) {
            $meta = (array) ($reportingFxRate->metadata ?? []);
            $meta['notes'] = $validated['notes'];
            $reportingFxRate->metadata = array_filter($meta);
        }
        $reportingFxRate->fetched_at = now();
        $reportingFxRate->save();

        return response()->json(['rate' => $this->formatFxRate($reportingFxRate)]);
    }

    public function deleteReportingFxRate(Request $request, ReportingFxRate $reportingFxRate)
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can manage manual FX rates.'
        );

        if ($reportingFxRate->provider !== 'manual') {
            return response()->json(['message' => 'Only manual rates can be deleted.'], 422);
        }

        $reportingFxRate->delete();

        return response()->noContent();
    }

    private function formatFxRate(ReportingFxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'provider' => $rate->provider,
            'source_currency' => $rate->source_currency,
            'target_currency' => $rate->target_currency,
            'rate_date' => $rate->rate_date?->toDateString(),
            'rate' => (float) $rate->rate,
            'notes' => $rate->metadata['notes'] ?? null,
            'updated_at' => $rate->updated_at?->toIso8601String(),
        ];
    }

    public function getSalesDashboardWidgets(Request $request)
    {
        return response()->json([
            'widgets' => $this->resolveSalesDashboardWidgets(),
            'defaults' => self::SALES_DASHBOARD_WIDGET_DEFAULTS,
            'editable' => $this->marketAuthorizationService->isManager($request->user()),
        ]);
    }

    public function updateSalesDashboardWidgets(Request $request)
    {
        $this->marketAuthorizationService->ensureManager(
            $request->user(),
            'Only admin or sub-admin users can update sales dashboard widgets.'
        );

        $validated = $request->validate([
            'widgets' => 'required|array',
            'widgets.todos' => 'sometimes|boolean',
            'widgets.goals' => 'sometimes|boolean',
            'widgets.expiring_subs' => 'sometimes|boolean',
            'widgets.payment_recovery' => 'sometimes|boolean',
            'widgets.top_countries' => 'sometimes|boolean',
            'widgets.top_packages' => 'sometimes|boolean',
            'widgets.profile_engagement' => 'sometimes|boolean',
            'widgets.missed_chats' => 'sometimes|boolean',
        ]);

        $widgets = $this->normalizeSalesDashboardWidgets($validated['widgets'] ?? []);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SALES_DASHBOARD_WIDGETS_KEY],
            [
                'value' => $widgets,
                'updated_by' => $request->user()->id,
            ]
        );

        return response()->json([
            'widgets' => $widgets,
            'defaults' => self::SALES_DASHBOARD_WIDGET_DEFAULTS,
            'editable' => true,
        ]);
    }

    public function billingOverview(Request $request)
    {
        if (!BillingPermissions::canAccessBillingWorkspace($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        [$platforms, $platformStatuses] = $this->accessiblePlatformsAndStatuses($request);
        $walletSystem = $this->walletSettingsService->currentSystemConfig(masked: true);
        $billing = $this->billingWorkspaceMetadata();

        return response()->json([
            'billing' => $billing,
            'summary' => [
                'billingEnabled' => (bool) $billing['enabled'],
                'walletMode' => $walletSystem['mode'] ?? 'disabled',
                'totalMarkets' => $platformStatuses->count(),
                'walletEnabledMarkets' => $platformStatuses
                    ->filter(fn (array $platform) => (bool) data_get($platform, 'wallet.enabled'))
                    ->count(),
            ],
            'markets' => $platforms->map(function (Platform $platform) {
                $wallet = $this->walletSettingsService->currentPlatformConfig($platform, masked: true);

                return [
                    'id' => (int) $platform->id,
                    'name' => $platform->name,
                    'country' => $platform->country,
                    'wallet' => [
                        'enabled' => (bool) ($wallet['enabled'] ?? false),
                        'mode_override' => $wallet['mode_override'] ?? null,
                    ],
                ];
            })->values(),
            'last_checked_at' => now()->toDateTimeString(),
        ]);
    }

    public function billingSystem(Request $request)
    {
        if (!BillingPermissions::canViewBillingSystem($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $system = $this->walletSettingsService->currentSystemConfig(masked: true);

        return response()->json([
            'system' => [
                'mode' => $system['mode'] ?? 'disabled',
                'default_currency' => $system['default_currency'] ?? 'KES',
                'max_single_topup_default' => $system['max_single_topup_default'] ?? null,
                'max_wallet_balance_default' => $system['max_wallet_balance_default'] ?? null,
                'billing_domains' => (array) ($system['billing_domains'] ?? []),
                'billing_branding' => (array) ($system['billing_branding'] ?? []),
                'timing' => [
                    'redirect_delay_seconds' => $system['redirect_delay_seconds'] ?? null,
                    'wallet_refresh_rate_limit_seconds' => $system['wallet_refresh_rate_limit_seconds'] ?? null,
                    'wallet_refresh_timeout_seconds' => $system['wallet_refresh_timeout_seconds'] ?? null,
                    'topup_poll_interval_seconds' => $system['topup_poll_interval_seconds'] ?? null,
                ],
                'smtp' => [
                    'enabled' => (bool) data_get($system, 'smtp.enabled', false),
                    'host' => data_get($system, 'smtp.host'),
                    'port' => data_get($system, 'smtp.port'),
                    'username' => data_get($system, 'smtp.username'),
                    'encryption' => data_get($system, 'smtp.encryption'),
                    'from_address' => data_get($system, 'smtp.from_address'),
                    'from_name' => data_get($system, 'smtp.from_name'),
                    'password_configured' => (bool) data_get($system, 'smtp.password_configured', false),
                ],
                'discount_config' => (array) ($system['discount_config'] ?? []),
                'pin_policy' => [
                    'operator_pin_set' => (bool) ($system['pin_set'] ?? false),
                    'operator_pin_last_updated_at' => $system['pin_last_updated_at'] ?? null,
                    'free_trial_pin_set' => (bool) ($system['free_trial_pin_set'] ?? false),
                    'free_trial_pin_last_updated_at' => $system['free_trial_pin_last_updated_at'] ?? null,
                    'discount_pin_set' => (bool) ($system['discount_pin_set'] ?? false),
                    'discount_pin_last_updated_at' => $system['discount_pin_last_updated_at'] ?? null,
                ],
            ],
            'source' => [
                'editable' => BillingPermissions::canEditBillingConfig($request->user()),
                'live_read_enabled' => (bool) config('services.billing.billing_system_live_read.enabled', false),
                'source_of_truth' => (bool) config('services.billing.billing_system_live_read.enabled', false)
                    ? 'billing_system_settings'
                    : 'wallet_system_config',
                'rollout' => [
                    'precedence_state' => (bool) config('services.billing.billing_system_live_read.enabled', false)
                        ? 'new_model_primary'
                        : 'legacy_primary',
                    'shadow_read_enabled' => (bool) config('billing.shadow_read.enabled', false),
                    'dual_write_enabled' => (bool) config('billing.dual_write.enabled', false),
                    'workspace_enabled' => (bool) config('billing.workspace.enabled', false),
                    'diagnostics_v2_enabled' => (bool) config('billing.diagnostics.v2.enabled', false),
                    'market_surface_cutover_count' => count((array) config('billing.market_surface_cutover', [])),
                    'rollback_scope' => (bool) config('services.billing.billing_system_live_read.enabled', false)
                        ? 'legacy_fallback_available'
                        : 'legacy_primary_still_active',
                    'kill_switches' => $this->walletSettingsService->currentKillSwitches(),
                ],
            ],
        ]);
    }

    public function updateBillingKillSwitches(Request $request)
    {
        if (!BillingPermissions::canEditBillingConfig($request->user())) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'market_ids'   => ['required', 'array'],
            'market_ids.*' => ['integer', 'min:1'],
            'surfaces'     => ['required', 'array'],
            'surfaces.*'   => ['string', Rule::in(array_column(BillingSurface::cases(), 'value'))],
        ]);

        $this->walletSettingsService->saveKillSwitches(
            $validated['market_ids'],
            $validated['surfaces']
        );

        return response()->json([
            'ok'            => true,
            'kill_switches' => $this->walletSettingsService->currentKillSwitches(),
        ]);
    }

    public function billingDiagnosticsSummary(Request $request)
    {
        if (!BillingPermissions::canViewBillingDiagnostics($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        [, $platformStatuses, $allowedPlatformIds] = $this->accessiblePlatformsAndStatuses($request);
        $marketId = $request->filled('market_id') ? (int) $request->input('market_id') : null;
        $providerKey = $request->filled('provider_key') ? (string) $request->input('provider_key') : null;

        if ($marketId) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                $marketId,
                'You do not have access to this billing diagnostics market.'
            );
        }

        return response()->json([
            'services' => $this->billingDiagnosticsServices($request, $platformStatuses),
            'diagnostics' => $this->billingDiagnosticsPresenter->present(
                $this->billingDiagnosticsAssembler->assembleBilling($marketId, $providerKey, $allowedPlatformIds),
                $request->user(),
                $allowedPlatformIds,
                BillingPermissions::canUseBillingRouteSimulator($request->user()),
                BillingPermissions::canDrillAcrossBillingMarkets($request->user())
            ),
            'last_checked_at' => now()->toDateTimeString(),
        ]);
    }

    public function billingDiagnosticsRouteSimulator(Request $request)
    {
        if (!BillingPermissions::canViewBillingDiagnostics($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!BillingPermissions::canUseBillingRouteSimulator($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'market_id' => ['required', 'integer', 'exists:platforms,id'],
            'surface' => ['required', Rule::in(array_map(
                static fn (BillingSurface $surface) => $surface->value,
                BillingSurface::cases()
            ))],
            'provider_key' => ['nullable', 'string', 'max:80'],
        ]);

        $marketId = (int) $validated['market_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $marketId,
            'You do not have access to this billing diagnostics market.'
        );

        $market = Platform::query()->findOrFail($marketId);
        $surface = BillingSurface::from((string) $validated['surface']);
        $providerKey = trim((string) ($validated['provider_key'] ?? ''));
        $providerProfiles = BillingProviderProfile::query()
            ->where('market_id', $market->id)
            ->get();
        $paymentLinkProviders = is_array($market->payment_link_providers) ? $market->payment_link_providers : [];
        $activePaymentLinkProvider = trim((string) ($paymentLinkProviders['active_provider'] ?? ''));

        $results = collect($this->billingProviderRegistry->definitions())
            ->map(fn ($definition) => $definition)
            ->filter(function ($definition) use ($providerKey, $surface) {
                if (!$definition->supportsSurface($surface)) {
                    return false;
                }

                if ($providerKey === '') {
                    return true;
                }

                return $definition->matches($providerKey);
            })
            ->sortBy(fn ($definition) => $definition->label)
            ->values()
            ->map(function ($definition) use ($providerProfiles, $surface, $market, $activePaymentLinkProvider) {
                $profiles = $providerProfiles
                    ->filter(fn (BillingProviderProfile $profile) => $definition->matches((string) $profile->provider_type_key))
                    ->values();
                $activeProfiles = $profiles->where('active', true)->values();
                $environments = $activeProfiles
                    ->pluck('environment')
                    ->filter()
                    ->map(fn ($value) => strtoupper((string) $value))
                    ->unique()
                    ->values()
                    ->all();
                $supportsStatusQueries = $definition->capabilities->has(ProviderCapability::StatusQueries);
                $supportsSandbox = $definition->capabilities->has(ProviderCapability::SandboxAvailable);
                $eligible = $activeProfiles->isNotEmpty() && $definition->supportsCurrency($market->currency_code);

                return [
                    'provider_key' => $definition->key,
                    'label' => $definition->label,
                    'eligible' => $eligible,
                    'reason' => $eligible
                        ? 'Provider has active profiles and meets the current market/surface posture.'
                        : 'Provider is missing an active profile or does not match the current market posture.',
                    'profiles_active' => $activeProfiles->count(),
                    'environments' => $environments,
                    'status_queries' => $supportsStatusQueries,
                    'sandbox_available' => $supportsSandbox,
                    'proxy_supported' => $definition->supportsSurface(BillingSurface::ProxyHostedCheckout),
                    'selected_by_market_policy' => $activePaymentLinkProvider !== ''
                        && str_contains(strtolower($activePaymentLinkProvider), strtolower($definition->key)),
                ];
            })
            ->values();

        return response()->json([
            'market' => [
                'id' => (int) $market->id,
                'name' => $market->name,
                'country' => $market->country,
                'currency_code' => $market->currency_code,
            ],
            'surface' => $surface->value,
            'provider_key' => $providerKey !== '' ? $providerKey : null,
            'results' => $results,
            'meta' => [
                'simulated_at' => now()->toDateTimeString(),
                'permissions' => [
                    'route_simulator' => true,
                ],
            ],
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
            'markets' => 'sometimes|array',
            'markets.*' => 'array',
            'markets.*.active_provider' => 'nullable|in:legacy_gateway,africastalking',
            'markets.*.fallback_provider' => 'nullable|in:none,legacy_gateway,africastalking',
            'markets.*.legacy_gateway' => 'nullable|array',
            'markets.*.legacy_gateway.gateway_url' => 'nullable|url|max:255',
            'markets.*.legacy_gateway.org_code' => 'nullable|string|max:20',
            'markets.*.africastalking' => 'nullable|array',
            'markets.*.africastalking.username' => 'nullable|string|max:100',
            'markets.*.africastalking.api_key' => 'nullable|string|max:255',
            'markets.*.africastalking.sender_id' => 'nullable|string|max:20',
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

        foreach ($validated['markets'] ?? [] as $platformId => $marketConfig) {
            $marketActive = $marketConfig['active_provider'] ?? null;
            $marketFallback = $marketConfig['fallback_provider'] ?? null;

            if ($marketActive && $marketFallback && $marketFallback !== 'none' && $marketActive === $marketFallback) {
                return response()->json([
                    'message' => "Market {$platformId}: active and fallback provider cannot be the same.",
                ], 422);
            }
        }

        $before = $this->notificationService->currentSmsConfig(masked: true);
        $saved = $this->scopeSmsConfigForUser(
            $this->notificationService->saveSmsConfig($validated, (int) $request->user()->id),
            $request->user()
        );

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
            'market_id' => 'nullable|integer|exists:platforms,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $market = !empty($validated['market_id'])
            ? Platform::query()->find((int) $validated['market_id'])
            : null;

        $result = $this->notificationService->sendSms(
            $validated['phone'],
            $validated['message'],
            [
                'platform_id' => $market?->id,
                'phone_prefix' => $market?->phone_prefix ?: null,
                'purpose' => 'settings_provider_test',
            ]
        );

        $this->auditService->fromRequest(
            $request,
            $market?->id ?? ($this->resolveAuditPlatformId([]) ?? 1),
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

    public function pushProviderConfig(Request $request)
    {
        $config = $this->pushProviderService->currentPushConfig(masked: true);

        return response()->json([
            'push_provider' => $this->scopePushConfigForUser($config, $request->user()),
        ]);
    }

    public function updatePushProvider(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can update push provider settings.'
        );

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'default_provider' => 'required|in:webpushr,wonderpush,izooto',
            'platforms' => 'nullable|array',
            'platforms.*.active_provider' => 'nullable|in:webpushr,wonderpush,izooto',
            'platforms.*.fallback_provider' => 'nullable|in:none,webpushr,wonderpush,izooto',
            'platforms.*.webpushr' => 'nullable|array',
            'platforms.*.webpushr.api_key' => 'nullable|string|max:255',
            'platforms.*.webpushr.auth_token' => 'nullable|string|max:255',
            'platforms.*.wonderpush' => 'nullable|array',
            'platforms.*.wonderpush.access_token' => 'nullable|string|max:255',
            'platforms.*.wonderpush.project_id' => 'nullable|string|max:255',
            'platforms.*.izooto' => 'nullable|array',
            'platforms.*.izooto.api_token' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $incomingPlatforms = is_array($validated['platforms'] ?? null)
            ? $validated['platforms']
            : [];

        $platformIds = [];
        foreach ($incomingPlatforms as $platformId => $platformConfig) {
            if (!is_numeric((string) $platformId)) {
                return response()->json([
                    'message' => 'platforms must be keyed by platform id.',
                ], 422);
            }

            $numericPlatformId = (int) $platformId;
            if ($numericPlatformId <= 0) {
                return response()->json([
                    'message' => 'platforms must be keyed by valid platform ids greater than zero.',
                ], 422);
            }

            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                $numericPlatformId,
                'You do not have access to one or more selected markets.'
            );

            if (!is_array($platformConfig)) {
                return response()->json([
                    'message' => 'Each platforms entry must be an object.',
                ], 422);
            }

            $activeProvider = (string) ($platformConfig['active_provider'] ?? '');
            $fallbackProvider = (string) ($platformConfig['fallback_provider'] ?? 'none');

            if ($activeProvider !== '' && $fallbackProvider !== 'none' && $activeProvider === $fallbackProvider) {
                return response()->json([
                    'message' => "Fallback provider must be different from active provider for platform {$numericPlatformId}.",
                ], 422);
            }

            $platformIds[] = $numericPlatformId;
        }

        $before = $this->scopePushConfigForUser(
            $this->pushProviderService->currentPushConfig(masked: true),
            $request->user()
        );
        $saved = $this->scopePushConfigForUser(
            $this->pushProviderService->savePushConfig($validated, (int) $request->user()->id),
            $request->user()
        );

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId($platformIds) ?? 1,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'integration_setting',
            2,
            $before,
            $saved,
            $validated['reason'] ?? 'Updated push provider routing settings'
        );

        return response()->json([
            'push_provider' => $saved,
        ]);
    }

    public function testPushProvider(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can run push provider tests.'
        );

        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'title' => 'required|string|max:100',
            'message' => 'required|string|max:255',
            'target_url' => 'required|url|max:500',
            'icon_url' => 'nullable|url|max:500',
            'reason' => 'nullable|string|max:500',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this market.'
        );

        $result = $this->pushProviderService->sendPush([
            'title' => (string) $validated['title'],
            'message' => (string) $validated['message'],
            'target_url' => (string) $validated['target_url'],
            'icon_url' => $validated['icon_url'] ?? null,
            'campaign_name' => 'CRM Push Provider Test',
        ], [
            'platform_id' => $platformId,
        ]);

        $this->auditService->fromRequest(
            $request,
            $platformId,
            CrmAuditAction::INTEGRATION_CONNECTION_TEST,
            'integration_setting',
            2,
            null,
            [
                'provider' => $result['provider'] ?? null,
                'success' => (bool) ($result['success'] ?? false),
                'provider_notification_id' => $result['provider_notification_id'] ?? null,
            ],
            $validated['reason'] ?? 'Push provider test dispatch'
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
            'timezone' => 'required|string|max:64',
            'currency_code' => 'nullable|string|size:3',
            'db_host' => 'nullable|string|max:255',
            'db_name' => 'nullable|string|max:255',
            'db_user' => 'nullable|string|max:255',
            'db_pass' => 'nullable|string|max:255',
            'db_prefix' => 'nullable|string|max:32',
            'support_chat_url' => 'nullable|url|max:500',
            'support_board_api_url' => 'nullable|url|max:500',
            'support_board_token' => 'nullable|string',
            'support_board_sender_id' => 'nullable|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $platform = Platform::query()->create($this->platformWritePayload($validated));
        $platform->refresh();
        $this->ensureDefaultPackagesForPlatform($platform);

        $activationDeferred = false;
        $packageSetup = $this->platformPackageSetup($platform);
        if ((bool) $platform->is_active && !$packageSetup['can_go_live']) {
            $platform->forceFill([
                'is_active' => false,
            ])->save();
            $platform->refresh();
            $activationDeferred = true;
        }

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
            'activation_deferred' => $activationDeferred,
            'package_setup' => $this->platformPackageSetup($platform),
        ], 201);
    }

    /**
     * Get the complete provider catalog from the billing provider registry.
     * Used by the Providers Tab (BILL-302) to display all available providers.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function providersCatalog()
    {
        if (!BillingPermissions::canViewProviderProfiles(auth()->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $providers = array_map(
            fn ($definition) => $definition->toArray(),
            $this->billingProviderRegistry->definitions()
        );

        return response()->json([
            'providers' => array_values($providers),
            'count' => count($providers),
        ]);
    }

    /**
     * Get provider profiles with masking for sensitive data.
     * Returns all configured provider profiles grouped by provider type.
     * Secrets are masked to prevent exposure in responses.
     * Phase 3: Read-only view; write operations deferred to Phase 4.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function providerProfiles()
    {
        if (!BillingPermissions::canViewProviderProfiles(auth()->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $profiles = BillingProviderProfile::query()
            ->select([
                'id',
                'provider_type_key',
                'profile_name',
                'country_code',
                'market_id',
                'environment',
                'config_json',
                'secrets_json',
                'active',
                'tested_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('provider_type_key')
            ->orderBy('profile_name')
            ->get()
            ->map(fn (BillingProviderProfile $profile) => $this->providerProfileManager->maskedProfile($profile))
            ->values();

        // Return profiles with provider definitions for context
        return response()->json([
            'profiles' => $profiles,
            'providers' => array_values(array_map(
                fn ($definition) => $definition->toArray(),
                $this->billingProviderRegistry->definitions()
            )),
            'schemas' => $this->serializeProviderSchemas(),
            'editable' => BillingPermissions::canEditBillingConfig(auth()->user()),
            'count' => count($profiles),
        ]);
    }

    public function storeProviderProfile(Request $request)
    {
        if (!BillingPermissions::canEditBillingConfig($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'provider_type_key' => ['required', 'string', 'max:50'],
            'profile_name' => ['required', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'market_id' => ['nullable', 'integer', 'exists:platforms,id'],
            'environment' => ['required', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
            'merchant_scope_json' => ['nullable', 'array'],
            'config_json' => ['nullable', 'array'],
            'secrets_json' => ['nullable', 'array'],
            'fields' => ['nullable', 'array'],
        ]);

        $profile = $this->providerProfileManager->create($validated);

        return response()->json([
            'profile' => $this->providerProfileManager->maskedProfile($profile),
        ], 201);
    }

    public function updateProviderProfile(Request $request, BillingProviderProfile $profile)
    {
        if (!BillingPermissions::canEditBillingConfig($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'provider_type_key' => ['required', 'string', 'max:50'],
            'profile_name' => ['required', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'market_id' => ['nullable', 'integer', 'exists:platforms,id'],
            'environment' => ['required', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
            'merchant_scope_json' => ['nullable', 'array'],
            'config_json' => ['nullable', 'array'],
            'secrets_json' => ['nullable', 'array'],
            'fields' => ['nullable', 'array'],
        ]);

        $profile = $this->providerProfileManager->update($profile, $validated);

        return response()->json([
            'profile' => $this->providerProfileManager->maskedProfile($profile),
        ]);
    }

    public function billingRoutingRules(int $marketId)
    {
        // BILL-307: Authorization check - Billing workspace restricted to admin/sub_admin
        if (!BillingPermissions::canViewRoutingRules(auth()->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->select(['id', 'name', 'country'])->findOrFail($marketId);
        $profiles = BillingProviderProfile::query()
            ->where(function ($query) use ($marketId) {
                $query->where('market_id', $marketId)
                    ->orWhereNull('market_id');
            })
            ->orderByDesc('market_id')
            ->orderBy('provider_type_key')
            ->orderBy('profile_name')
            ->get()
            ->map(fn (BillingProviderProfile $profile) => $this->providerProfileManager->maskedProfile($profile))
            ->values();

        $bindings = BillingMarketProviderBinding::query()
            ->with(['providerProfile'])
            ->where('market_id', $marketId)
            ->orderBy('billing_surface')
            ->orderBy('priority')
            ->get()
            ->map(fn (BillingMarketProviderBinding $binding) => $this->serializeRoutingBinding($binding))
            ->values();

        $rules = BillingRoutingRule::query()
            ->with(["primaryBinding.providerProfile", "market:id,name,country"])
            ->where("market_id", $marketId)
            ->orderBy("billing_surface")
            ->get()
            ->map(fn (BillingRoutingRule $rule) => $this->serializeRoutingRule($rule))
            ->values();

        return response()->json([
            "market" => $market,
            "routing_rules" => $rules,
            "bindings" => $bindings,
            "profiles" => $profiles,
            "surfaces" => array_map(
                static fn (BillingSurface $surface) => [
                    'key' => $surface->value,
                    'label' => Str::headline(str_replace('_', ' ', $surface->value)),
                ],
                BillingSurface::cases()
            ),
            "editable" => BillingPermissions::canEditBillingConfig(auth()->user()),
            "count" => count($rules),
        ]);
    }

    public function storeBillingRoutingRules(Request $request, int $marketId)
    {
        if (!BillingPermissions::canEditBillingConfig($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->findOrFail($marketId);
        $surfaceValues = array_map(static fn (BillingSurface $surface) => $surface->value, BillingSurface::cases());

        $validated = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.billing_surface' => ['required', 'string', Rule::in($surfaceValues)],
            'rules.*.active' => ['required', 'boolean'],
            'rules.*.primary_profile_id' => ['nullable', 'integer', 'exists:billing_provider_profiles,id'],
            'rules.*.fallback_profile_ids' => ['nullable', 'array'],
            'rules.*.fallback_profile_ids.*' => ['integer', 'exists:billing_provider_profiles,id'],
            'rules.*.execution_mode' => ['nullable', 'string', Rule::in(['direct', 'proxy'])],
            'rules.*.operator_enabled' => ['nullable', 'boolean'],
            'rules.*.self_service_enabled' => ['nullable', 'boolean'],
            'rules.*.notes' => ['nullable', 'string', 'max:500'],
            'rules.*.min_amount' => ['nullable', 'numeric', 'min:0'],
            'rules.*.max_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $rules = collect($validated['rules'])->keyBy('billing_surface');
        $referencedProfileIds = $rules
            ->flatMap(function (array $rule) {
                return array_filter(array_merge(
                    [$rule['primary_profile_id'] ?? null],
                    $rule['fallback_profile_ids'] ?? []
                ));
            })
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $profilesById = BillingProviderProfile::query()
            ->whereIn('id', $referencedProfileIds)
            ->get()
            ->keyBy('id');

        $profileScopeErrors = [];
        foreach ($referencedProfileIds as $profileId) {
            $profile = $profilesById->get($profileId);

            if (!$profile) {
                continue;
            }

            if ($profile->market_id !== null && (int) $profile->market_id !== (int) $market->id) {
                $profileScopeErrors["rules.profile_{$profileId}"] = 'Selected provider profile does not belong to this market.';
            }
        }

        if ($profileScopeErrors !== []) {
            throw ValidationException::withMessages($profileScopeErrors);
        }

        DB::transaction(function () use ($rules, $market) {
            foreach ($rules as $surface => $rule) {
                $active = (bool) ($rule['active'] ?? false);
                $executionMode = strtolower(trim((string) ($rule['execution_mode'] ?? 'direct'))) ?: 'direct';
                $operatorEnabled = array_key_exists('operator_enabled', $rule) ? (bool) $rule['operator_enabled'] : true;
                $selfServiceEnabled = array_key_exists('self_service_enabled', $rule) ? (bool) $rule['self_service_enabled'] : false;
                $notes = trim((string) ($rule['notes'] ?? ''));
                $minAmount = isset($rule['min_amount']) && $rule['min_amount'] !== '' ? (float) $rule['min_amount'] : null;
                $maxAmount = isset($rule['max_amount']) && $rule['max_amount'] !== '' ? (float) $rule['max_amount'] : null;
                $primaryProfileId = isset($rule['primary_profile_id']) ? (int) $rule['primary_profile_id'] : null;
                $fallbackProfileIds = collect($rule['fallback_profile_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->reject(fn ($id) => $id === $primaryProfileId)
                    ->unique()
                    ->values();

                $selectedProfileIds = collect([$primaryProfileId])
                    ->filter()
                    ->merge($fallbackProfileIds)
                    ->values();

                $bindingsByProfile = BillingMarketProviderBinding::query()
                    ->where('market_id', $market->id)
                    ->where('billing_surface', $surface)
                    ->get()
                    ->keyBy('provider_profile_id');

                $orderedBindings = collect();

                foreach ($selectedProfileIds as $index => $profileId) {
                    $binding = BillingMarketProviderBinding::query()->updateOrCreate(
                        [
                            'market_id' => $market->id,
                            'provider_profile_id' => $profileId,
                            'billing_surface' => $surface,
                        ],
                        [
                            'enabled' => $active,
                            'operator_enabled' => $operatorEnabled,
                            'self_service_enabled' => $selfServiceEnabled,
                            'execution_mode' => $executionMode,
                            'priority' => 100 + ($index * 100),
                            'fallback_group' => $fallbackProfileIds->isNotEmpty() ? "{$surface}-ordered" : null,
                            'restriction_json' => array_filter([
                                'managed_in_billing_workspace' => true,
                                'min_amount' => $minAmount,
                                'max_amount' => $maxAmount,
                            ], fn ($v) => $v !== null),
                            'notes' => $notes !== '' ? $notes : null,
                        ]
                    );

                    $orderedBindings->push($binding);
                }

                $bindingsByProfile
                    ->reject(fn (BillingMarketProviderBinding $binding) => $selectedProfileIds->contains((int) $binding->provider_profile_id))
                    ->each(function (BillingMarketProviderBinding $binding) use ($notes) {
                        $binding->update([
                            'enabled' => false,
                            'priority' => 999,
                            'fallback_group' => null,
                            'notes' => $notes !== '' ? $notes : $binding->notes,
                        ]);
                    });

                $primaryBinding = $orderedBindings->first();
                $fallbackBindings = $orderedBindings->slice(1)->values();

                BillingRoutingRule::query()->updateOrCreate(
                    [
                        'market_id' => $market->id,
                        'billing_surface' => $surface,
                    ],
                    [
                        'primary_binding_id' => $primaryBinding?->id,
                        'fallback_strategy_json' => $fallbackBindings->isNotEmpty()
                            ? [
                                'type' => 'ordered_bindings',
                                'binding_ids' => $fallbackBindings->pluck('id')->values()->all(),
                                'provider_profile_ids' => $fallbackBindings->pluck('provider_profile_id')->values()->all(),
                            ]
                            : ['type' => 'none', 'binding_ids' => [], 'provider_profile_ids' => []],
                        'risk_policy_json' => [
                            'execution_mode' => $executionMode,
                            'operator_enabled' => $operatorEnabled,
                            'self_service_enabled' => $selfServiceEnabled,
                        ],
                        'active' => $active && $primaryBinding !== null,
                    ]
                );
            }
        });

        // Push updated provider config to WordPress so changes are reflected
        // immediately on the site (e.g. PawaPay appearing in the top-up modal).
        try {
            $this->walletSyncService->syncPlatformConfig($market);
        } catch (\Throwable $e) {
            Log::warning('Wallet config sync after routing rules update failed', [
                'market_id' => $marketId,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->billingRoutingRules($marketId);
    }

    public function billingWalletRules(int $marketId)
    {
        if (!BillingPermissions::canViewWalletRules(auth()->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->select([
            'id',
            'name',
            'country',
            'currency_code',
            'supported_currencies',
            'multi_currency_wallet_enabled',
        ])->findOrFail($marketId);
        $rule = BillingWalletRule::query()
            ->with(["market:id,name,country"])
            ->where("market_id", $marketId)
            ->first();

        if (!$rule) {
            $rule = [
                "id" => null,
                "market_id" => $marketId,
                "enabled" => false,
                "currency_code" => null,
                "supported_currencies_json" => null,
                "topup_preset_json" => null,
                "topup_preset_by_currency_json" => null,
                "limit_json" => null,
                "limit_by_currency_json" => null,
                "auto_renew_json" => null,
                "ui_json" => null,
                "fx_override_json" => null,
                "created_at" => null,
                "updated_at" => null,
            ];
        } else {
            $rule = $rule->toArray();
        }

        return response()->json([
            'market' => $market,
            "wallet_rule" => $rule,
            'editable' => BillingPermissions::canEditBillingConfig(auth()->user()),
        ]);
    }

    public function storeBillingWalletRules(Request $request, int $marketId)
    {
        if (!BillingPermissions::canEditBillingConfig($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->findOrFail($marketId);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'supported_currencies_json' => ['nullable', 'array'],
            'supported_currencies_json.*' => ['nullable', 'string', 'size:3'],
            'topup_preset_json' => ['nullable', 'array'],
            'topup_preset_json.*' => ['nullable'],
            'topup_preset_by_currency_json' => ['nullable', 'array'],
            'limit_json' => ['nullable', 'array'],
            'limit_json.min_single_topup' => ['nullable'],
            'limit_json.max_single_topup' => ['nullable'],
            'limit_json.max_wallet_balance' => ['nullable'],
            'limit_by_currency_json' => ['nullable', 'array'],
            'auto_renew_json' => ['nullable', 'array'],
            'auto_renew_json.enabled' => ['nullable', 'boolean'],
            'ui_json' => ['nullable', 'array'],
            'ui_json.allow_combined_topup_subscribe' => ['nullable', 'boolean'],
            'ui_json.show_refresh_button' => ['nullable', 'boolean'],
            'ui_json.recent_transactions_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'ui_json.wallet_funding_label' => ['nullable', 'string', 'max:120'],
            'fx_override_json' => ['nullable', 'array'],
            'fx_override_json.enabled' => ['nullable', 'boolean'],
            'fx_override_json.currency' => ['nullable', 'string', 'max:8'],
            'fx_override_json.rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $presets = collect($validated['topup_preset_json'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $supportedCurrencies = collect($validated['supported_currencies_json'] ?? [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $presetsByCurrency = collect($validated['topup_preset_by_currency_json'] ?? [])
            ->mapWithKeys(function ($values, $currency) {
                if (!is_array($values)) {
                    return [];
                }

                $normalized = collect($values)
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->values()
                    ->all();

                return $normalized === []
                    ? []
                    : [strtoupper(trim((string) $currency)) => $normalized];
            })
            ->all();

        $limitJson = collect($validated['limit_json'] ?? [])
            ->mapWithKeys(function ($value, $key) {
                $normalized = trim((string) $value);

                return $normalized === '' ? [] : [$key => $normalized];
            })
            ->all();

        $limitByCurrency = collect($validated['limit_by_currency_json'] ?? [])
            ->mapWithKeys(function ($values, $currency) {
                if (!is_array($values)) {
                    return [];
                }

                $normalized = collect($values)
                    ->mapWithKeys(function ($value, $key) {
                        $trimmed = trim((string) $value);

                        return $trimmed === '' ? [] : [$key => $trimmed];
                    })
                    ->all();

                return $normalized === []
                    ? []
                    : [strtoupper(trim((string) $currency)) => $normalized];
            })
            ->all();

        $uiJson = collect($validated['ui_json'] ?? [])
            ->mapWithKeys(function ($value, $key) {
                if (is_bool($value)) {
                    return [$key => $value];
                }

                if ($value === null) {
                    return [];
                }

                $normalized = trim((string) $value);

                return $normalized === '' ? [] : [$key => $normalized];
            })
            ->all();

        $autoRenewJson = [
            'enabled' => (bool) data_get($validated, 'auto_renew_json.enabled', false),
        ];

        $fxOverrideJson = null;
        if (isset($validated['fx_override_json'])) {
            $fxRate = data_get($validated, 'fx_override_json.rate');
            $fxOverrideJson = [
                'enabled' => (bool) data_get($validated, 'fx_override_json.enabled', false),
                'currency' => strtoupper(trim((string) data_get($validated, 'fx_override_json.currency', ''))),
                'rate' => $fxRate !== null ? (float) $fxRate : null,
            ];
        }

        BillingWalletRule::query()->updateOrCreate(
            ['market_id' => $market->id],
            [
                'enabled' => (bool) $validated['enabled'],
                'currency_code' => strtoupper(trim((string) ($validated['currency_code'] ?? $market->currency_code ?? ''))),
                'supported_currencies_json' => $supportedCurrencies,
                'topup_preset_json' => $presets,
                'topup_preset_by_currency_json' => $presetsByCurrency,
                'limit_json' => $limitJson,
                'limit_by_currency_json' => $limitByCurrency,
                'auto_renew_json' => $autoRenewJson,
                'ui_json' => $uiJson,
                'fx_override_json' => $fxOverrideJson,
            ]
        );

        return $this->billingWalletRules($marketId);
    }

    public function billingManualPaymentMethods(int $marketId)
    {
        if (!BillingPermissions::canViewSubscriptionRules(auth()->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->select(['id', 'name', 'country', 'currency_code'])->findOrFail($marketId);
        $storedMethods = BillingManualPaymentMethod::query()
            ->where('market_id', $marketId)
            ->get()
            ->keyBy(fn (BillingManualPaymentMethod $method) => strtolower(trim((string) $method->method_key)));

        $methods = collect(array_keys(self::MANUAL_PAYMENT_METHOD_DEFINITIONS))
            ->map(function (string $methodKey) use ($marketId, $storedMethods) {
                $method = $storedMethods->get($methodKey);

                if ($method instanceof BillingManualPaymentMethod) {
                    return $this->serializeManualPaymentMethod($method);
                }

                return $this->defaultManualPaymentMethodState($marketId, $methodKey);
            })
            ->values();

        return response()->json([
            'market' => $market,
            'manual_methods' => $methods,
            'supported_methods' => array_values(array_map(function (string $methodKey, array $definition) {
                return [
                    'key' => $methodKey,
                    'label' => $definition['label'],
                    'detail_fields' => $definition['detail_fields'],
                ];
            }, array_keys(self::MANUAL_PAYMENT_METHOD_DEFINITIONS), self::MANUAL_PAYMENT_METHOD_DEFINITIONS)),
            'editable' => BillingPermissions::canEditBillingConfig(auth()->user()),
        ]);
    }

    public function storeBillingManualPaymentMethods(Request $request, int $marketId)
    {
        if (!BillingPermissions::canEditBillingConfig($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->findOrFail($marketId);
        $supportedMethods = array_keys(self::MANUAL_PAYMENT_METHOD_DEFINITIONS);

        $validated = $request->validate([
            'methods' => ['required', 'array', 'min:1'],
            'methods.*.method_key' => ['required', 'string', Rule::in($supportedMethods)],
            'methods.*.enabled' => ['required', 'boolean'],
            'methods.*.display_name' => ['nullable', 'string', 'max:160'],
            'methods.*.instruction_intro' => ['nullable', 'string', 'max:1000'],
            'methods.*.instruction_footer' => ['nullable', 'string', 'max:1000'],
            'methods.*.proof_required' => ['nullable', 'boolean'],
            'methods.*.sender_name_required' => ['nullable', 'boolean'],
            'methods.*.transaction_id_required' => ['nullable', 'boolean'],
            'methods.*.auto_activate_on_submission' => ['nullable', 'boolean'],
            'methods.*.details' => ['nullable', 'array'],
        ]);

        $methodsByKey = collect($validated['methods'])
            ->keyBy(fn (array $method) => strtolower(trim((string) $method['method_key'])));

        DB::transaction(function () use ($supportedMethods, $methodsByKey, $market) {
            foreach ($supportedMethods as $methodKey) {
                $payload = $methodsByKey->get($methodKey, [
                    'method_key' => $methodKey,
                    'enabled' => false,
                ]);

                $normalized = $this->normalizeManualPaymentMethodPayload($methodKey, $payload);

                BillingManualPaymentMethod::query()->updateOrCreate(
                    [
                        'market_id' => (int) $market->id,
                        'method_key' => $methodKey,
                    ],
                    $normalized
                );
            }
        });

        return $this->billingManualPaymentMethods($marketId);
    }

    public function billingSubscriptionRules(int $marketId)
    {
        // BILL-307: Authorization check - Billing workspace restricted to admin/sub_admin
        if (!BillingPermissions::canViewSubscriptionRules(auth()->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->select(['id', 'name', 'country', 'currency_code'])->findOrFail($marketId);

        $rule = BillingSubscriptionRule::query()
            ->with(["market:id,name,country"])
            ->where("market_id", $marketId)
            ->first();

        if (!$rule) {
            $legacySystem = $this->walletSettingsService->currentSystemConfig(masked: false);
            $legacyDiscountMax = data_get($legacySystem, "discount_config.max_percentage_by_platform.{$marketId}");
            $seededDiscountJson = $legacyDiscountMax !== null
                ? [
                    'enabled' => (int) $legacyDiscountMax > 0,
                    'max_percent' => (int) $legacyDiscountMax,
                    'requires_pin' => (bool) ($legacySystem['discount_pin_set'] ?? false),
                ]
                : null;

            $rule = [
                "id" => null,
                "market_id" => $marketId,
                "activation_method_json" => null,
                "renewal_method_json" => null,
                "free_trial_json" => null,
                "discount_json" => $seededDiscountJson,
                "expiry_policy_json" => null,
                "created_at" => null,
                "updated_at" => null,
            ];
        } else {
            $rule = $rule->toArray();
        }

        return response()->json([
            'market' => $market,
            "subscription_rule" => $rule,
            'editable' => BillingPermissions::canEditBillingConfig(auth()->user()),
        ]);
    }

    public function storeBillingSubscriptionRules(Request $request, int $marketId)
    {
        if (!BillingPermissions::canEditBillingConfig($request->user())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $market = Platform::query()->findOrFail($marketId);

        $activationMethods = ['manual', 'payment_link', 'stk_push', 'wallet_balance'];
        $renewalMethods = ['wallet_balance', 'payment_link', 'manual'];

        $validated = $request->validate([
            'activation_method_json' => ['nullable', 'array'],
            'activation_method_json.methods' => ['nullable', 'array'],
            'activation_method_json.methods.*' => ['string', Rule::in($activationMethods)],
            'renewal_method_json' => ['nullable', 'array'],
            'renewal_method_json.methods' => ['nullable', 'array'],
            'renewal_method_json.methods.*' => ['string', Rule::in($renewalMethods)],
            'renewal_method_json.wallet_auto_renew' => ['nullable', 'boolean'],
            'free_trial_json' => ['nullable', 'array'],
            'free_trial_json.enabled' => ['nullable', 'boolean'],
            'free_trial_json.duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'discount_json' => ['nullable', 'array'],
            'discount_json.enabled' => ['nullable', 'boolean'],
            'discount_json.max_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'discount_json.requires_pin' => ['nullable', 'boolean'],
            'discount_json.self_service_incentive' => ['nullable', 'array'],
            'discount_json.self_service_incentive.enabled' => ['nullable', 'boolean'],
            'discount_json.self_service_incentive.percent' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'discount_json.self_service_incentive.label' => ['nullable', 'string', 'max:80'],
            'discount_json.self_service_incentive.starts_at' => ['nullable', 'string', 'date'],
            'discount_json.self_service_incentive.expires_at' => [
                'nullable',
                'string',
                'date',
                'after_or_equal:discount_json.self_service_incentive.starts_at',
            ],
            'discount_json.self_service_incentive.sources' => ['nullable', 'array'],
            'discount_json.self_service_incentive.sources.*' => [
                'string',
                Rule::in(['wallet', 'self_checkout', 'manual_submission']),
            ],
            'expiry_policy_json' => ['nullable', 'array'],
            'expiry_policy_json.grace_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'expiry_policy_json.suspend_after_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $activationJson = [
            'methods' => collect(data_get($validated, 'activation_method_json.methods', []))
                ->map(fn ($value) => strtolower(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];

        $renewalJson = [
            'methods' => collect(data_get($validated, 'renewal_method_json.methods', []))
                ->map(fn ($value) => strtolower(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'wallet_auto_renew' => (bool) data_get($validated, 'renewal_method_json.wallet_auto_renew', false),
        ];

        $freeTrialJson = [
            'enabled' => (bool) data_get($validated, 'free_trial_json.enabled', false),
            'duration_days' => data_get($validated, 'free_trial_json.duration_days'),
        ];

        $incentiveInput = data_get($validated, 'discount_json.self_service_incentive');
        $discountJson = [
            'enabled' => (bool) data_get($validated, 'discount_json.enabled', false),
            'max_percent' => data_get($validated, 'discount_json.max_percent'),
            'requires_pin' => (bool) data_get($validated, 'discount_json.requires_pin', false),
            'self_service_incentive' => $incentiveInput ? [
                'enabled' => (bool) data_get($incentiveInput, 'enabled', false),
                'percent' => data_get($incentiveInput, 'percent') !== null
                    ? round((float) data_get($incentiveInput, 'percent'), 2)
                    : null,
                'label' => data_get($incentiveInput, 'label') ?: null,
                'starts_at' => data_get($incentiveInput, 'starts_at') ?: null,
                'expires_at' => data_get($incentiveInput, 'expires_at') ?: null,
                'sources' => data_get($incentiveInput, 'sources') ?? ['wallet', 'self_checkout', 'manual_submission'],
            ] : null,
        ];

        $expiryPolicyJson = [
            'grace_period_days' => data_get($validated, 'expiry_policy_json.grace_period_days'),
            'suspend_after_days' => data_get($validated, 'expiry_policy_json.suspend_after_days'),
        ];

        BillingSubscriptionRule::query()->updateOrCreate(
            ['market_id' => $market->id],
            [
                'activation_method_json' => $activationJson,
                'renewal_method_json' => $renewalJson,
                'free_trial_json' => $freeTrialJson,
                'discount_json' => $discountJson,
                'expiry_policy_json' => $expiryPolicyJson,
            ]
        );

        // Dual-write discount cap to legacy wallet_system_config so old surfaces stay consistent
        if (config('billing.dual_write.enabled', false) && $discountJson['max_percent'] !== null) {
            $legacySystem = $this->walletSettingsService->currentSystemConfig(masked: false);
            $maxByPlatform = (array) data_get($legacySystem, 'discount_config.max_percentage_by_platform', []);
            $maxByPlatform[(string) $market->id] = $discountJson['max_percent'];
            $this->walletSettingsService->updateDiscountConfig(
                ['max_percentage_by_platform' => $maxByPlatform],
                (int) $request->user()->id
            );
        }

        return $this->billingSubscriptionRules($marketId);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultManualPaymentMethodState(int $marketId, string $methodKey): array
    {
        $definition = self::MANUAL_PAYMENT_METHOD_DEFINITIONS[$methodKey] ?? [
            'label' => Str::headline($methodKey),
            'detail_fields' => [],
        ];

        return [
            'id' => null,
            'market_id' => $marketId,
            'method_key' => $methodKey,
            'enabled' => false,
            'display_name' => $definition['label'],
            'instruction_intro' => "Make payment and send the payment screenshot with the sender's name and transaction ID.",
            'instruction_footer' => '',
            'proof_required' => true,
            'sender_name_required' => true,
            'transaction_id_required' => true,
            'auto_activate_on_submission' => false,
            'details' => array_fill_keys($definition['detail_fields'], ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeManualPaymentMethod(BillingManualPaymentMethod $method): array
    {
        $default = $this->defaultManualPaymentMethodState((int) $method->market_id, (string) $method->method_key);

        return array_merge($default, [
            'id' => (int) $method->id,
            'enabled' => (bool) $method->enabled,
            'display_name' => $method->display_name ?: $default['display_name'],
            'instruction_intro' => $method->instruction_intro ?: $default['instruction_intro'],
            'instruction_footer' => $method->instruction_footer ?: '',
            'proof_required' => true,
            'sender_name_required' => true,
            'transaction_id_required' => true,
            'auto_activate_on_submission' => (bool) $method->auto_activate_on_submission,
            'details' => array_merge(
                is_array($default['details']) ? $default['details'] : [],
                is_array($method->details_json) ? $method->details_json : []
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeManualPaymentMethodPayload(string $methodKey, array $payload): array
    {
        $definition = self::MANUAL_PAYMENT_METHOD_DEFINITIONS[$methodKey] ?? [
            'label' => Str::headline($methodKey),
            'detail_fields' => [],
        ];
        $detailFields = $definition['detail_fields'];
        $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $normalizedDetails = [];

        foreach ($detailFields as $field) {
            $normalizedDetails[$field] = trim((string) ($details[$field] ?? ''));
        }

        return [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'display_name' => trim((string) ($payload['display_name'] ?? '')) ?: $definition['label'],
            'instruction_intro' => trim((string) ($payload['instruction_intro'] ?? "Make payment and send the payment screenshot with the sender's name and transaction ID.")) ?: null,
            'instruction_footer' => trim((string) ($payload['instruction_footer'] ?? '')) ?: null,
            'proof_required' => true,
            'sender_name_required' => true,
            'transaction_id_required' => true,
            'auto_activate_on_submission' => (bool) ($payload['auto_activate_on_submission'] ?? false),
            'details_json' => $normalizedDetails,
        ];
    }

    private function serializeRoutingRule(BillingRoutingRule $rule): array
    {
        return [
            "id" => $rule->id,
            "market_id" => $rule->market_id,
            "billing_surface" => $rule->billing_surface,
            "active" => $rule->active,
            "fallback_strategy_json" => $rule->fallback_strategy_json,
            "risk_policy_json" => $rule->risk_policy_json,
            "primary_binding" => $rule->primaryBinding ? $this->serializeRoutingBinding($rule->primaryBinding) : null,
        ];
    }

    private function serializeRoutingBinding(BillingMarketProviderBinding $binding): array
    {
        $binding->loadMissing('providerProfile');

        return [
            'id' => $binding->id,
            'billing_surface' => $binding->billing_surface,
            'priority' => $binding->priority,
            'enabled' => (bool) $binding->enabled,
            'operator_enabled' => (bool) $binding->operator_enabled,
            'self_service_enabled' => (bool) $binding->self_service_enabled,
            'execution_mode' => $binding->execution_mode,
            'fallback_group' => $binding->fallback_group,
            'notes' => $binding->notes,
            'provider_profile_id' => $binding->provider_profile_id,
            'provider_profile' => $binding->providerProfile ? $this->providerProfileManager->maskedProfile($binding->providerProfile) : null,
        ];
    }

    public function wallet(Request $request)
    {
        $platformQuery = Platform::query()->orderBy('id');
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $platformQuery->whereIn('id', $allowedPlatformIds);
        }

        $platforms = $platformQuery->get()
            ->map(fn (Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'platform_name' => $platform->name,
                'wallet' => $this->walletSettingsService->currentPlatformConfig($platform, masked: true),
            ])
            ->values();

        return response()->json([
            'system' => $this->walletSettingsService->currentSystemConfig(masked: true),
            'platforms' => $platforms,
            'provider_keys' => $this->walletSettingsService->providerKeys(),
            'provider_schemas' => $this->serializeProviderSchemas($this->walletSettingsService->providerKeys()),
            'mode_options' => WalletSettingsService::MODES,
            'environment_options' => WalletSettingsService::ENVIRONMENTS,
        ]);
    }

    /**
     * @param  list<string>|null  $providerKeys
     * @return array<string, array<string, mixed>>
     */
    private function serializeProviderSchemas(?array $providerKeys = null): array
    {
        $schemas = $this->providerCredentialSchemaRegistry->all();

        if ($providerKeys !== null) {
            $schemas = array_filter(
                $schemas,
                static fn ($schema, string $providerKey): bool => in_array($providerKey, $providerKeys, true),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return array_map(
            static fn ($schema) => [
                'provider_key' => $schema->providerKey(),
                'label' => $schema->label(),
                'supported_environments' => $schema->supportedEnvironments(),
                'fields' => $schema->fields(),
            ],
            $schemas
        );
    }

    public function updateWallet(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can update wallet system settings.'
        );

        $validated = $request->validate([
            'mode' => ['required', Rule::in(WalletSettingsService::MODES)],
            'default_currency' => 'required|string|size:3',
            'max_single_topup_default' => 'required|numeric|min:0',
            'max_wallet_balance_default' => 'required|numeric|min:0',
            'billing_domains' => 'required|array',
            'billing_domains.sandbox' => 'nullable|url|max:255',
            'billing_domains.production' => 'nullable|url|max:255',
            'billing_branding' => 'required|array',
            'billing_branding.sandbox.business_name' => 'required|string|max:120',
            'billing_branding.sandbox.description' => 'required|string|max:255',
            'billing_branding.production.business_name' => 'required|string|max:120',
            'billing_branding.production.description' => 'required|string|max:255',
            'redirect_delay_seconds' => 'required|integer|min:1|max:30',
            'wallet_refresh_rate_limit_seconds' => 'required|integer|min:1|max:120',
            'wallet_refresh_timeout_seconds' => 'required|integer|min:1|max:120',
            'topup_poll_interval_seconds' => 'required|integer|min:1|max:120',
            'smtp' => 'required|array',
            'smtp.enabled' => 'required|boolean',
            'smtp.host' => 'nullable|string|max:255',
            'smtp.port' => 'nullable|integer|min:1|max:65535',
            'smtp.username' => 'nullable|string|max:255',
            'smtp.password' => 'nullable|string|max:255',
            'smtp.encryption' => 'nullable|string|max:20',
            'smtp.from_address' => 'nullable|email|max:255',
            'smtp.from_name' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = $this->walletSettingsService->currentSystemConfig(masked: true);
        $saved = $this->walletSettingsService->saveSystemConfig($validated, (int) $request->user()->id);
        $syncResults = $this->walletSyncService->syncAllPlatformStates((int) $request->user()->id);

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId([]) ?? 1,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'integration_setting',
            1,
            $before,
            $saved,
            $validated['reason'] ?? 'Updated wallet system settings'
        );

        return response()->json([
            'system' => $saved,
            'wallet_sync' => $syncResults,
            'wallet_config_sync' => collect($syncResults)->map(fn (array $result) => $result['config'] ?? null)->all(),
            'wallet_credentials_sync' => collect($syncResults)->map(fn (array $result) => $result['credentials'] ?? null)->all(),
        ]);
    }

    public function updateWalletPin(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can update the wallet PIN.'
        );

        $validated = $request->validate([
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
            'pin_confirmation' => 'required|string|same:pin',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = $this->walletSettingsService->currentSystemConfig(masked: true);
        $saved = $this->walletSettingsService->updateOperatorPin(
            (string) $validated['pin'],
            (int) $request->user()->id
        );

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId([]) ?? 1,
            CrmAuditAction::WALLET_PIN_UPDATE,
            'integration_setting',
            1,
            [
                'pin_set' => (bool) ($before['pin_set'] ?? false),
                'pin_last_updated_at' => $before['pin_last_updated_at'] ?? null,
            ],
            [
                'pin_set' => (bool) ($saved['pin_set'] ?? false),
                'pin_last_updated_at' => $saved['pin_last_updated_at'] ?? null,
            ],
            $validated['reason'] ?? 'Updated wallet operator PIN'
        );

        return response()->json([
            'system' => $saved,
        ]);
    }

    public function updateFreeTrialPin(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can update the free-trial PIN.'
        );

        $validated = $request->validate([
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
            'pin_confirmation' => 'required|string|same:pin',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = $this->walletSettingsService->currentSystemConfig(masked: true);
        $saved = $this->walletSettingsService->updateFreeTrialPin(
            (string) $validated['pin'],
            (int) $request->user()->id
        );

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId([]) ?? 1,
            CrmAuditAction::FREE_TRIAL_PIN_UPDATE,
            'integration_setting',
            1,
            [
                'free_trial_pin_set' => (bool) ($before['free_trial_pin_set'] ?? false),
                'free_trial_pin_last_updated_at' => $before['free_trial_pin_last_updated_at'] ?? null,
            ],
            [
                'free_trial_pin_set' => (bool) ($saved['free_trial_pin_set'] ?? false),
                'free_trial_pin_last_updated_at' => $saved['free_trial_pin_last_updated_at'] ?? null,
            ],
            $validated['reason'] ?? 'Updated free-trial PIN'
        );

        return response()->json([
            'system' => $saved,
        ]);
    }

    public function updateDiscountPin(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can update the discount PIN.'
        );

        $validated = $request->validate([
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
            'pin_confirmation' => 'required|string|same:pin',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = $this->walletSettingsService->currentSystemConfig(masked: true);
        $saved = $this->walletSettingsService->updateDiscountPin(
            (string) $validated['pin'],
            (int) $request->user()->id
        );

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId([]) ?? 1,
            CrmAuditAction::DISCOUNT_PIN_UPDATE,
            'integration_setting',
            1,
            [
                'discount_pin_set' => (bool) ($before['discount_pin_set'] ?? false),
                'discount_pin_last_updated_at' => $before['discount_pin_last_updated_at'] ?? null,
            ],
            [
                'discount_pin_set' => (bool) ($saved['discount_pin_set'] ?? false),
                'discount_pin_last_updated_at' => $saved['discount_pin_last_updated_at'] ?? null,
            ],
            $validated['reason'] ?? 'Updated discount PIN'
        );

        return response()->json([
            'system' => $saved,
        ]);
    }

    public function updateDiscountConfig(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can update discount configuration.'
        );

        $validated = $request->validate([
            'discount_config' => 'required|array',
            'discount_config.max_percentage_by_platform' => 'required|array',
            'discount_config.max_percentage_by_platform.*' => 'nullable|numeric|min:0|max:99',
            'reason' => 'nullable|string|max:500',
        ]);

        $maxByPlatform = is_array($validated['discount_config']['max_percentage_by_platform'] ?? null)
            ? $validated['discount_config']['max_percentage_by_platform']
            : [];

        foreach (array_keys($maxByPlatform) as $platformId) {
            if (!is_numeric((string) $platformId) || (int) $platformId <= 0) {
                throw ValidationException::withMessages([
                    'discount_config.max_percentage_by_platform' => 'Discount config must be keyed by valid platform ids.',
                ]);
            }

            if (!Platform::query()->whereKey((int) $platformId)->exists()) {
                throw ValidationException::withMessages([
                    'discount_config.max_percentage_by_platform' => "Unknown platform id [{$platformId}] in discount config.",
                ]);
            }
        }

        $before = $this->walletSettingsService->currentSystemConfig(masked: true);
        $saved = $this->walletSettingsService->updateDiscountConfig(
            (array) $validated['discount_config'],
            (int) $request->user()->id
        );

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId(array_map('intval', array_keys($maxByPlatform))) ?? 1,
            CrmAuditAction::DISCOUNT_CONFIG_UPDATE,
            'integration_setting',
            1,
            [
                'discount_config' => $before['discount_config'] ?? ['max_percentage_by_platform' => []],
            ],
            [
                'discount_config' => $saved['discount_config'] ?? ['max_percentage_by_platform' => []],
            ],
            $validated['reason'] ?? 'Updated discount configuration'
        );

        return response()->json([
            'system' => $saved,
        ]);
    }

    public function updatePlatformWallet(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can update platform wallet settings.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'mode_override' => ['nullable', Rule::in(['inherit', 'sandbox', 'production'])],
            'currency_code' => 'required|string|size:3',
            'supported_currencies' => 'nullable|array|min:1|max:10',
            'supported_currencies.*' => 'nullable|string|size:3',
            'multi_currency_wallet_enabled' => 'required|boolean',
            'min_single_topup' => 'nullable|numeric|min:0',
            'max_single_topup' => 'required|numeric|min:0',
            'max_wallet_balance' => 'required|numeric|min:0',
            'topup_presets' => 'required|array|min:1|max:10',
            'topup_presets.*' => 'numeric|min:0',
            'topup_presets_by_currency' => 'nullable|array',
            'topup_presets_by_currency.*' => 'nullable|array|max:10',
            'topup_presets_by_currency.*.*' => 'numeric|min:0',
            'limits_by_currency' => 'nullable|array',
            'limits_by_currency.*' => 'nullable|array',
            'limits_by_currency.*.min_single_topup' => 'nullable|numeric|min:0',
            'limits_by_currency.*.max_single_topup' => 'nullable|numeric|min:0',
            'limits_by_currency.*.max_wallet_balance' => 'nullable|numeric|min:0',
            'allow_combined_topup_subscribe' => 'required|boolean',
            'show_refresh_button' => 'required|boolean',
            'recent_transactions_limit' => 'required|integer|min:1|max:50',
            'providers' => 'required|array',
            'providers.pesapal.enabled' => 'required|boolean',
            'providers.pesapal.min_amount' => 'required|numeric|min:0',
            'providers.pesapal.max_amount' => 'required|numeric|min:0',
            'providers.paystack.enabled' => 'required|boolean',
            'providers.paystack.min_amount' => 'required|numeric|min:0',
            'providers.paystack.max_amount' => 'required|numeric|min:0',
            'providers.mpesa_stk.enabled' => 'required|boolean',
            'providers.mpesa_stk.min_amount' => 'required|numeric|min:0',
            'providers.mpesa_stk.max_amount' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        $primaryCurrency = strtoupper(trim((string) $validated['currency_code']));
        $supportedCurrencies = collect($validated['supported_currencies'] ?? [])
            ->prepend($primaryCurrency)
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $topupPresetsByCurrency = collect($validated['topup_presets_by_currency'] ?? [])
            ->mapWithKeys(function ($values, $currency) use ($supportedCurrencies) {
                $normalizedCurrency = strtoupper(trim((string) $currency));
                if (!is_array($values) || !in_array($normalizedCurrency, $supportedCurrencies, true)) {
                    return [];
                }

                $normalizedValues = collect($values)
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->values()
                    ->all();

                return $normalizedValues === []
                    ? []
                    : [$normalizedCurrency => $normalizedValues];
            })
            ->all();

        $limitsByCurrency = collect($validated['limits_by_currency'] ?? [])
            ->mapWithKeys(function ($values, $currency) use ($supportedCurrencies) {
                $normalizedCurrency = strtoupper(trim((string) $currency));
                if (!is_array($values) || !in_array($normalizedCurrency, $supportedCurrencies, true)) {
                    return [];
                }

                $normalizedValues = collect($values)
                    ->mapWithKeys(function ($value, $key) {
                        $trimmed = trim((string) $value);

                        return $trimmed === '' ? [] : [$key => $trimmed];
                    })
                    ->all();

                return $normalizedValues === []
                    ? []
                    : [$normalizedCurrency => $normalizedValues];
            })
            ->all();

        $beforeState = $this->platformAuditState($platform);
        $platform->forceFill([
            'currency_code' => $primaryCurrency,
            'supported_currencies' => $supportedCurrencies,
            'multi_currency_wallet_enabled' => (bool) $validated['multi_currency_wallet_enabled'],
        ])->save();
        $platformWallet = $this->walletSettingsService->savePlatformConfig($platform, array_merge($validated, [
            'currency_code' => $primaryCurrency,
            'supported_currencies' => $supportedCurrencies,
            'topup_presets_by_currency' => $topupPresetsByCurrency,
            'limits_by_currency' => $limitsByCurrency,
        ]));
        $platform = $platform->fresh();
        $syncResult = $this->walletSyncService->syncPlatformState($platform, null, (int) $request->user()->id);

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'platform',
            (int) $platform->id,
            $beforeState,
            $this->platformAuditState($platform),
            $validated['reason'] ?? 'Updated platform wallet settings'
        );

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform),
            'wallet' => $platformWallet,
            'wallet_sync' => $syncResult,
            'wallet_config_sync' => $syncResult['config'] ?? null,
            'wallet_credentials_sync' => $syncResult['credentials'] ?? null,
        ]);
    }

    public function updatePlatformWalletProviders(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can update platform wallet provider credentials.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'pesapal' => 'nullable|array',
            'pesapal.sandbox.consumer_key' => 'nullable|string|max:255',
            'pesapal.sandbox.consumer_secret' => 'nullable|string|max:255',
            'pesapal.sandbox.ipn_id' => 'nullable|string|max:255',
            'pesapal.production.consumer_key' => 'nullable|string|max:255',
            'pesapal.production.consumer_secret' => 'nullable|string|max:255',
            'pesapal.production.ipn_id' => 'nullable|string|max:255',
            'paystack' => 'nullable|array',
            'paystack.sandbox.public_key' => 'nullable|string|max:255',
            'paystack.sandbox.secret_key' => 'nullable|string|max:255',
            'paystack.production.public_key' => 'nullable|string|max:255',
            'paystack.production.secret_key' => 'nullable|string|max:255',
            'mpesa_stk' => 'nullable|array',
            'mpesa_stk.sandbox.transport' => ['nullable', Rule::in(['django_proxy', 'direct_provider'])],
            'mpesa_stk.sandbox.payment_service_base_url' => 'nullable|url|max:255',
            'mpesa_stk.sandbox.organization_code' => 'nullable|string|max:50',
            'mpesa_stk.sandbox.callback_base_url' => 'nullable|url|max:255',
            'mpesa_stk.production.transport' => ['nullable', Rule::in(['django_proxy', 'direct_provider'])],
            'mpesa_stk.production.payment_service_base_url' => 'nullable|url|max:255',
            'mpesa_stk.production.organization_code' => 'nullable|string|max:50',
            'mpesa_stk.production.callback_base_url' => 'nullable|url|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $beforeState = $this->platformAuditState($platform);
        $platformWallet = $this->walletSettingsService->savePlatformProviderCredentials($platform, $validated, (int) $request->user()->id);
        $syncResult = $this->walletSyncService->syncPlatformState($platform, null, (int) $request->user()->id);

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'platform',
            (int) $platform->id,
            $beforeState,
            $this->platformAuditState($platform->fresh()),
            $validated['reason'] ?? 'Updated wallet provider credentials'
        );

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform->fresh()),
            'wallet' => $platformWallet,
            'wallet_sync' => $syncResult,
            'wallet_config_sync' => $syncResult['config'] ?? null,
            'wallet_credentials_sync' => $syncResult['credentials'] ?? null,
        ]);
    }

    public function rotatePlatformWalletWpCredentials(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can rotate wallet WP credentials.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'environment' => ['required', Rule::in(WalletSettingsService::ENVIRONMENTS)],
            'credential' => ['required', Rule::in(['bearer', 'hmac', 'both'])],
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->walletSyncService->rotateWpCredentials(
            $platform,
            $validated['environment'],
            $validated['credential'],
            (int) $request->user()->id
        );

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'platform',
            (int) $platform->id,
            null,
            [
                'wallet_wp_credential_rotation' => [
                    'environment' => $validated['environment'],
                    'credential' => $validated['credential'],
                ],
            ],
            $validated['reason'] ?? 'Rotated wallet WP credentials'
        );

        return response()->json($result);
    }

    public function pushPlatformWalletWpCredentials(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can push wallet WP credentials.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $syncResult = $this->walletSyncService->pushActiveWpCredentials(
            $platform,
            (int) $request->user()->id
        );

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'platform',
            (int) $platform->id,
            null,
            [
                'wallet_wp_credentials_push' => [
                    'status' => $syncResult['status'] ?? 'unknown',
                    'environment' => $syncResult['environment'] ?? null,
                    'reason' => $syncResult['reason'] ?? null,
                ],
            ],
            $validated['reason'] ?? 'Pushed active wallet WP credentials'
        );

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform->fresh() ?? $platform),
            'wallet_credentials_sync' => $syncResult,
        ]);
    }

    public function testPlatformWalletProvider(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can test wallet providers.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'provider' => ['required', Rule::in($this->walletSettingsService->providerKeys())],
            'environment' => ['required', Rule::in(WalletSettingsService::ENVIRONMENTS)],
            'reason' => 'nullable|string|max:500',
        ]);

        $beforeState = $this->platformAuditState($platform);

        try {
            $result = $this->walletSettingsService->testProvider($platform, $validated['provider'], $validated['environment']);

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'platform',
                (int) $platform->id,
                $beforeState,
                $this->platformAuditState($platform),
                $validated['reason'] ?? 'Wallet provider test executed'
            );

            return response()->json([
                'result' => $result,
            ], ($result['ok'] ?? false) ? 200 : 422);
        } catch (\Throwable $exception) {
            $result = [
                'provider' => $validated['provider'],
                'environment' => $validated['environment'],
                'ok' => false,
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'platform',
                (int) $platform->id,
                $beforeState,
                $this->platformAuditState($platform),
                $validated['reason'] ?? 'Wallet provider test failed'
            );

            return response()->json([
                'result' => $result,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function testWalletEmail(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can test wallet email delivery.'
        );

        $validated = $request->validate([
            'to_email' => 'required|email|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->walletSettingsService->sendTestEmail($validated['to_email']);

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId([]) ?? 1,
            CrmAuditAction::INTEGRATION_CONNECTION_TEST,
            'integration_setting',
            1,
            null,
            $result,
            $validated['reason'] ?? 'Wallet SMTP test email sent'
        );

        return response()->json([
            'result' => $result,
        ]);
    }

    public function testWalletDomain(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can test wallet billing domains.'
        );

        $validated = $request->validate([
            'environment' => ['required', Rule::in(WalletSettingsService::ENVIRONMENTS)],
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->walletSettingsService->testDomain($validated['environment']);

            $this->auditService->fromRequest(
                $request,
                $this->resolveAuditPlatformId([]) ?? 1,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'integration_setting',
                1,
                null,
                $result,
                $validated['reason'] ?? 'Wallet billing domain test executed'
            );

            return response()->json([
                'result' => $result,
            ], ($result['resolved'] ?? false) ? 200 : 422);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'result' => [
                    'environment' => $validated['environment'],
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }
    }

    public function testWalletSsl(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can test wallet billing SSL.'
        );

        $validated = $request->validate([
            'environment' => ['required', Rule::in(WalletSettingsService::ENVIRONMENTS)],
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->walletSettingsService->testSsl($validated['environment']);

            $this->auditService->fromRequest(
                $request,
                $this->resolveAuditPlatformId([]) ?? 1,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'integration_setting',
                1,
                null,
                $result,
                $validated['reason'] ?? 'Wallet billing SSL test executed'
            );

            return response()->json([
                'result' => $result,
            ], ($result['ok'] ?? false) ? 200 : 422);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'result' => [
                    'environment' => $validated['environment'],
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }
    }

    public function testWalletApp(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can test wallet billing app reachability.'
        );

        $validated = $request->validate([
            'environment' => ['required', Rule::in(WalletSettingsService::ENVIRONMENTS)],
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->walletSettingsService->testBillingApp($validated['environment']);

            $this->auditService->fromRequest(
                $request,
                $this->resolveAuditPlatformId([]) ?? 1,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'integration_setting',
                1,
                null,
                ['wallet_billing_app_test' => $result],
                $validated['reason'] ?? 'Wallet billing app test executed'
            );

            return response()->json([
                'result' => $result,
            ], ($result['ok'] ?? false) ? 200 : 422);
        } catch (\Throwable $exception) {
            $result = [
                'environment' => $validated['environment'],
                'ok' => false,
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];

            $this->auditService->fromRequest(
                $request,
                $this->resolveAuditPlatformId([]) ?? 1,
                CrmAuditAction::INTEGRATION_CONNECTION_TEST,
                'integration_setting',
                1,
                null,
                ['wallet_billing_app_test' => $result],
                $validated['reason'] ?? 'Wallet billing app test executed'
            );

            return response()->json([
                'message' => $exception->getMessage(),
                'result' => $result,
            ], 422);
        }
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
            'timezone' => 'sometimes|required|string|max:64',
            'currency_code' => 'sometimes|nullable|string|size:3',
            'db_host' => 'sometimes|nullable|string|max:255',
            'db_name' => 'sometimes|nullable|string|max:255',
            'db_user' => 'sometimes|nullable|string|max:255',
            'db_pass' => 'sometimes|nullable|string|max:255',
            'db_prefix' => 'sometimes|nullable|string|max:32',
            'support_chat_url' => 'sometimes|nullable|url|max:500',
            'support_board_api_url' => 'sometimes|nullable|url|max:500',
            'support_board_token' => 'sometimes|nullable|string',
            'support_board_sender_id' => 'sometimes|nullable|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $beforeState = $this->platformAuditState($platform);
        $payload = $this->platformWritePayload($validated, true);
        $activationRequested = array_key_exists('is_active', $payload) && (bool) $payload['is_active'];
        $currencyUpdated = array_key_exists('currency_code', $payload);

        DB::transaction(function () use ($platform, $payload, $activationRequested, $currencyUpdated): void {
            $platform->fill($payload)->save();
            $platform->refresh();

            if ($currencyUpdated) {
                $this->syncPackageCurrenciesForPlatform($platform);
            }

            $this->ensureDefaultPackagesForPlatform($platform);
            $packageSetup = $this->platformPackageSetup($platform);
            if ($activationRequested && !$packageSetup['can_go_live']) {
                throw ValidationException::withMessages([
                    'is_active' => 'Package setup is incomplete. Configure at least one active priced package before activating this market.',
                ]);
            }
        });

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

    public function updatePlatformPackages(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can update market package catalogs.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'packages' => 'required|array|min:1|max:50',
            'packages.*.id' => 'nullable|integer',
            'packages.*.name' => 'required|string|max:64',
            'packages.*.display_name' => 'nullable|string|max:64',
            'packages.*.slug' => 'nullable|string|max:80',
            'packages.*.tier' => 'nullable|string|max:32',
            'packages.*.sort_order' => 'nullable|integer|min:0|max:10000',
            'packages.*.is_active' => 'required|boolean',
            'packages.*.is_public' => 'nullable|boolean',
            'packages.*.is_archived' => 'nullable|boolean',
            'packages.*.weekly_price' => 'nullable|numeric|min:0',
            'packages.*.biweekly_price' => 'nullable|numeric|min:0',
            'packages.*.monthly_price' => 'nullable|numeric|min:0',
            'packages.*.prices' => 'nullable|array|min:1|max:20',
            'packages.*.prices.*.id' => 'nullable|integer',
            'packages.*.prices.*.duration_key' => 'required_with:packages.*.prices|string|max:50',
            'packages.*.prices.*.duration_label' => 'required_with:packages.*.prices|string|max:120',
            'packages.*.prices.*.duration_days' => 'nullable|integer|min:1|max:365',
            'packages.*.prices.*.price' => 'required_with:packages.*.prices|numeric|min:0',
            'packages.*.prices.*.currency' => 'nullable|string|size:3',
            'packages.*.prices.*.is_active' => 'required_with:packages.*.prices|boolean',
            'packages.*.prices.*.sort_order' => 'nullable|integer|min:0|max:10000',
            'reason' => 'nullable|string|max:500',
        ]);

        $beforeState = [
            'packages' => $this->platformPackageRows($platform),
            'package_setup' => $this->platformPackageSetup($platform),
        ];

        DB::transaction(function () use ($platform, $validated): void {
            $currency = strtoupper((string) ($platform->currency_code ?: 'KES'));

            $existing = Product::query()
                ->where('platform_id', (int) $platform->id)
                ->with('prices')
                ->get()
                ->keyBy(fn(Product $product) => (int) $product->id);

            $seen = [];
            $touchedProductIds = [];

            foreach ($validated['packages'] as $row) {
                $name = $this->normalizePackageName((string) ($row['name'] ?? ''));
                $displayName = trim((string) ($row['display_name'] ?? ''));
                $displayName = $displayName !== '' ? $displayName : Str::title(strtolower($name));
                $isArchived = (bool) ($row['is_archived'] ?? false);
                $isActive = !$isArchived && (bool) ($row['is_active'] ?? false);
                $isPublic = (bool) ($row['is_public'] ?? true);

                if (array_key_exists($name, $seen)) {
                    throw ValidationException::withMessages([
                        'packages' => "Duplicate package name '{$name}' submitted. Submit each package once.",
                    ]);
                }

                $priceRows = $this->normalizeSubmittedPriceRows($row, $platform, $isActive);
                $activePricedDurations = collect($priceRows)
                    ->filter(fn(array $price) => (bool) $price['is_active'] && (float) $price['price'] > 0);

                if ($isActive && $activePricedDurations->isEmpty()) {
                    throw ValidationException::withMessages([
                        'packages' => "{$name} cannot be active without at least one active duration price greater than zero.",
                    ]);
                }

                $productId = (int) ($row['id'] ?? 0);
                $product = $productId > 0 ? $existing->get($productId) : null;

                if ($productId > 0 && !$product) {
                    throw ValidationException::withMessages([
                        'packages' => "Package row id {$productId} does not belong to this market.",
                    ]);
                }

                if (!$product) {
                    $product = Product::query()
                        ->where('platform_id', (int) $platform->id)
                        ->whereRaw('UPPER(name) = ?', [$name])
                        ->first();
                }

                $product = $product ?? new Product();
                $rowSlug = trim((string) ($row['slug'] ?? ''));
                $slug = ProductCatalogService::generateUniqueSlugForPlatform(
                    (int) $platform->id,
                    $rowSlug !== '' ? $rowSlug : $name,
                    $product->exists ? (int) $product->id : null
                );
                $product->platform_id = (int) $platform->id;
                $product->name = $name;
                $product->display_name = $displayName;
                $product->slug = $slug;
                $product->tier = $this->normalizePackageTier((string) ($row['tier'] ?? ''), $name);
                $product->sort_order = (int) ($row['sort_order'] ?? ((count($touchedProductIds) + 1) * 10));
                $product->currency = $currency;
                $product->is_active = $isActive;
                $product->is_public = $isPublic;
                $product->is_archived = $isArchived;
                $product->weekly_price = $product->weekly_price ?? 0;
                $product->biweekly_price = $product->biweekly_price ?? 0;
                $product->monthly_price = $product->monthly_price ?? 0;
                $product->save();

                $this->syncProductPriceRows($product, $priceRows);
                $this->syncLegacyPriceColumnsForProduct($product, $priceRows, $currency);

                $touchedProductIds[] = (int) $product->id;
                $seen[$name] = true;
            }

            Product::query()
                ->where('platform_id', (int) $platform->id)
                ->when(
                    !empty($touchedProductIds),
                    fn($query) => $query->whereNotIn('id', $touchedProductIds)
                )
                ->update([
                    'is_active' => false,
                    'is_archived' => true,
                ]);

            if (!empty($touchedProductIds)) {
                ProductPrice::query()
                    ->whereIn('product_id', Product::query()
                        ->where('platform_id', (int) $platform->id)
                        ->whereNotIn('id', $touchedProductIds)
                        ->pluck('id')
                        ->all())
                    ->update(['is_active' => false]);
            }

            $platform->refresh();
            $packageSetup = $this->platformPackageSetup($platform);
            if ((bool) $platform->is_active && !$packageSetup['can_go_live']) {
                throw ValidationException::withMessages([
                    'packages' => 'Cannot keep market active with incomplete package setup. Configure at least one active priced package first.',
                ]);
            }
        });

        $platform->refresh();
        $afterState = [
            'packages' => $this->platformPackageRows($platform),
            'package_setup' => $this->platformPackageSetup($platform),
        ];

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'platform',
            (int) $platform->id,
            $beforeState,
            $afterState,
            $validated['reason'] ?? 'Updated market package catalog from CRM settings'
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

        if ($scope === 'all') {
            return response()->json([
                'message' => 'Run client sync and lead sync separately. Combined clients + leads runs are disabled during the background client sync rollout.',
            ], 422);
        }

        $beforeState = $this->platformAuditState($platform);

        try {
            if ($scope === 'leads') {
                $result = [
                    'scope' => $scope,
                    'mode' => $mode,
                    'dry_run' => $dryRun,
                    'ran_at' => now()->toDateTimeString(),
                    'clients' => null,
                    'leads' => null,
                ];

                $result['leads'] = $this->leadImportService->importPlatform($platform, $dryRun, $perPage);

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
                    $validated['reason'] ?? 'Manual lead sync run'
                );

                return response()->json([
                    'status' => $syncStatus,
                    'result' => $result,
                    'platform' => $this->serializePlatformIntegration($platform),
                ]);
            }

            $queue = $this->clientSyncRunService->queueReadiness();
            if (!($queue['available'] ?? false)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $queue['issues'][0] ?? 'Background client sync is not available.',
                    'queue' => $queue,
                ], 503);
            }

            $runMode = $mode === 'full' ? 'reconcile' : 'delta';
            $started = $this->clientSyncRunService->startManualRun(
                $platform,
                $request->user(),
                $runMode,
                $validated['reason'] ?? null
            );
            $run = $started['run'];

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_SYNC_RUN,
                'platform',
                (int) $platform->id,
                $beforeState,
                array_merge($this->platformAuditState($platform), [
                    'client_sync' => [
                        'run_id' => (int) $run->id,
                        'status' => $run->status,
                        'mode' => $run->mode,
                        'origin' => $run->origin,
                    ],
                ]),
                $validated['reason'] ?? ($runMode === 'reconcile' ? 'Manual full client sync queued' : 'Manual delta client sync queued')
            );

            if (!$started['reused']) {
                RunClientSyncJob::dispatch((int) $run->id, $perPage)
                    ->onQueue($runMode === 'reconcile' ? 'sync-clients-reconcile' : 'sync-clients');
            }

            return response()->json([
                'status' => $started['reused'] ? 'running' : 'queued',
                'message' => $started['reused']
                    ? 'A client sync is already running for this market.'
                    : 'Client sync has been queued.',
                'reused_run' => (bool) $started['reused'],
                'platform' => $this->serializePlatformIntegration($platform->fresh(), supportBoardSyncRun: null, clientSyncRun: $run),
                'run' => $this->clientSyncRunService->serializeRun($run),
            ], 202);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $scope === 'clients'
                    ? 'Failed to queue the client sync for this market.'
                    : 'Manual sync failed for this market.',
                'error' => $exception->getMessage(),
                'platform' => $this->serializePlatformIntegration($platform->fresh()),
            ], 422);
        }
    }

    public function runSalesMarketSync(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [
                MarketAuthorizationService::ROLE_ADMIN,
                MarketAuthorizationService::ROLE_SUB_ADMIN,
                MarketAuthorizationService::ROLE_SALES,
            ],
            'Only admin, sub-admin, or sales users can run market sync.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'per_page' => 'nullable|integer|min:20|max:200',
        ]);

        if (!$this->platformHasWpCredentials($platform)) {
            return response()->json([
                'message' => 'WordPress sync credentials are incomplete for this market.',
            ], 422);
        }

        $beforeState = $this->platformAuditState($platform);
        $perPage = (int) ($validated['per_page'] ?? 100);

        try {
            $queue = $this->clientSyncRunService->queueReadiness();
            if (!($queue['available'] ?? false)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $queue['issues'][0] ?? 'Background market sync is not available.',
                    'queue' => $queue,
                ], 503);
            }

            $started = $this->clientSyncRunService->startManualRun(
                $platform,
                $request->user(),
                'delta',
                $validated['reason'] ?? 'Sales delta sync'
            );
            $run = $started['run'];

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_SYNC_RUN,
                'platform',
                (int) $platform->id,
                $beforeState,
                array_merge($this->platformAuditState($platform), [
                    'client_sync' => [
                        'run_id' => (int) $run->id,
                        'status' => $run->status,
                        'mode' => $run->mode,
                        'origin' => $run->origin,
                    ],
                ]),
                $validated['reason'] ?? 'Sales delta sync queued'
            );

            if (!$started['reused']) {
                RunClientSyncJob::dispatch((int) $run->id, $perPage)->onQueue('sync-clients');
            }

            return response()->json([
                'status' => $started['reused'] ? 'running' : 'queued',
                'message' => $started['reused']
                    ? 'A market sync is already running for this market.'
                    : 'Market sync has been queued.',
                'reused_run' => (bool) $started['reused'],
                'platform' => $this->serializePlatformIntegration($platform->fresh(), supportBoardSyncRun: null, clientSyncRun: $run),
                'run' => $this->clientSyncRunService->serializeRun($run),
            ], 202);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue the market sync.',
                'error' => $exception->getMessage(),
                'platform' => $this->serializePlatformIntegration($platform->fresh()),
            ], 422);
        }
    }

    public function latestPlatformClientSync(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [
                MarketAuthorizationService::ROLE_ADMIN,
                MarketAuthorizationService::ROLE_SUB_ADMIN,
                MarketAuthorizationService::ROLE_SALES,
            ],
            'You are not allowed to view market sync status.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $run = $this->clientSyncRunService->latestRunForPlatform((int) $platform->id);

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform, supportBoardSyncRun: null, clientSyncRun: $run),
            'run' => $this->clientSyncRunService->serializeRun($run),
        ]);
    }

    public function resetPlatformClientSyncCursor(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can reset client sync cursors.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        if ($this->clientSyncRunService->activeRunForPlatform((int) $platform->id)) {
            return response()->json([
                'message' => 'Stop or wait for the active client sync run before resetting the cursor.',
            ], 409);
        }

        $platform->forceFill([
            'client_sync_checkpoint_at' => null,
            'client_sync_checkpoint_post_id' => null,
            'client_sync_tombstone_checkpoint_at' => null,
            'client_sync_tombstone_checkpoint_post_id' => null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Client sync cursors were reset for this market.',
            'platform' => $this->serializePlatformIntegration($platform->fresh()),
        ]);
    }

    public function refreshPlatformClientSyncCapabilities(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can refresh sync capabilities.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        try {
            $probe = (new WpSyncService($platform))->probeClientSyncMeta();
            $status = (string) ($probe['status'] ?? 'unknown');
            $meta = is_array($probe['meta'] ?? null) ? $probe['meta'] : null;

            if ($status === 'v2') {
                $platform->forceFill([
                    'client_sync_capability_checked_at' => now(),
                    'client_sync_capability_status' => 'v2',
                    'client_sync_protocol' => 'v2',
                    'client_sync_contract_version' => (string) ($meta['sync_contract_version'] ?? '2'),
                ])->save();
            } elseif (in_array($status, ['legacy', 'legacy_not_found'], true)) {
                $platform->forceFill([
                    'client_sync_capability_checked_at' => now(),
                    'client_sync_capability_status' => 'legacy_not_found',
                    'client_sync_protocol' => 'v1',
                ])->save();
            }

            return response()->json([
                'status' => 'success',
                'capability' => $probe,
                'platform' => $this->serializePlatformIntegration($platform->fresh()),
            ]);
        } catch (\Throwable $exception) {
            $platform->forceFill([
                'client_sync_capability_checked_at' => now(),
                'client_sync_capability_status' => 'probe_error',
            ])->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to refresh market sync capabilities.',
                'error' => $exception->getMessage(),
                'platform' => $this->serializePlatformIntegration($platform->fresh()),
            ], 422);
        }
    }

    public function runPlatformSupportBoardSync(
        Request $request,
        Platform $platform
    ) {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can run Support Board link sync.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'refresh' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        if (!(new SupportBoardService($platform))->isConfigured()) {
            return response()->json([
                'message' => 'Support Board is not configured for this market.',
            ], 422);
        }

        $queue = $this->supportBoardSyncRunService->queueReadiness();
        if (!($queue['available'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $queue['issues'][0] ?? 'Support Board background sync is not available.',
                'queue' => $queue,
            ], 503);
        }

        $refresh = (bool) ($validated['refresh'] ?? false);
        $beforeState = array_merge($this->platformAuditState($platform), [
            'support_board_sync' => null,
        ]);

        try {
            $started = $this->supportBoardSyncRunService->startRun(
                $platform,
                $request->user(),
                $refresh,
                $validated['reason'] ?? null
            );
            $run = $started['run'];

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::INTEGRATION_SYNC_RUN,
                'platform',
                (int) $platform->id,
                $beforeState,
                array_merge($this->platformAuditState($platform), [
                    'support_board_sync' => [
                        'run_id' => (int) $run->id,
                        'status' => $run->status,
                        'mode' => $run->mode,
                        'refresh' => $refresh,
                        'candidates' => (int) ($run->candidates ?? 0),
                        'processed' => (int) ($run->processed ?? 0),
                        'matched' => (int) ($run->matched ?? 0),
                        'updated' => (int) ($run->updated ?? 0),
                        'cleared' => (int) ($run->cleared ?? 0),
                        'unchanged' => (int) ($run->unchanged ?? 0),
                        'errors' => (int) ($run->errors ?? 0),
                    ],
                ]),
                $validated['reason'] ?? ($refresh
                    ? 'Manual Support Board link revalidation run'
                    : 'Manual Support Board link sync run')
            );

            if (!$started['reused']) {
                RunSupportBoardSyncJob::dispatch((int) $run->id);
            }

            return response()->json([
                'status' => $started['reused'] ? 'running' : 'queued',
                'message' => $started['reused']
                    ? 'A Support Board link sync is already running for this market.'
                    : 'Support Board link sync has been queued.',
                'reused_run' => (bool) $started['reused'],
                'platform' => $this->serializePlatformIntegration($platform->fresh(), $run),
                'run' => $this->supportBoardSyncRunService->serializeRun($run),
            ], 202);
        } catch (\Throwable $exception) {
            $failedRun = isset($run) && $run instanceof SupportBoardSyncRun
                ? $this->supportBoardSyncRunService->markFailed($run, $exception)
                : null;

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue the Support Board link sync.',
                'error' => $exception->getMessage(),
                'platform' => $this->serializePlatformIntegration($platform->fresh(), $failedRun),
                'run' => $this->supportBoardSyncRunService->serializeRun($failedRun),
            ], 500);
        }
    }

    public function latestPlatformSupportBoardSync(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can view Support Board link sync status.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $run = $this->supportBoardSyncRunService->latestRunForPlatform((int) $platform->id);

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform, $run),
            'run' => $this->supportBoardSyncRunService->serializeRun($run),
        ]);
    }

    public function updatePaymentLinkProviders(Request $request, Platform $platform)
    {
        if (!Schema::hasColumn('platforms', 'payment_link_providers')) {
            return response()->json([
                'message' => 'Sprint 6 migration is pending for payment link providers. Run `php artisan migrate` before updating provider configuration.',
                'missing_column' => 'platforms.payment_link_providers',
            ], 409);
        }

        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can update payment link providers.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'payment_link_providers' => 'required|array',
            'payment_link_providers.active_provider' => 'required|string|max:120',
            'payment_link_providers.providers' => 'required|array|min:1',
            'payment_link_providers.providers.*' => 'required|array',
            'payment_link_providers.providers.*.label' => 'nullable|string|max:120',
            'payment_link_providers.providers.*.mode' => ['nullable', Rule::in(['static_url', 'proxy_hosted_checkout'])],
            'payment_link_providers.providers.*.enabled' => 'nullable|boolean',
            'payment_link_providers.providers.*.url' => 'nullable|url|max:500',
            'payment_link_providers.providers.*.base_url' => 'nullable|url|max:500',
            'payment_link_providers.providers.*.path' => 'nullable|string|max:255',
            'payment_link_providers.providers.*.wallet_provider_key' => ['nullable', Rule::in($this->billingProviderRegistry->keysForSurface(BillingSurface::ProxyHostedCheckout))],
            'payment_link_providers.providers.*.environment' => ['nullable', Rule::in(['sandbox', 'production'])],
            'payment_link_providers.providers.*.self_checkout_fx_enabled' => 'nullable|boolean',
            'payment_link_providers.providers.*.self_checkout_fx_currency' => 'nullable|string|size:3',
            'payment_link_providers.providers.*.self_checkout_fx_rate' => 'nullable|numeric|min:0.000001|max:1000000',
            'reason' => 'nullable|string|max:500',
        ]);

        $normalized = $this->normalizePaymentLinkProviders($validated['payment_link_providers']);
        if ($normalized === null) {
            return response()->json([
                'message' => 'payment_link_providers must include active_provider and providers map.',
            ], 422);
        }

        $beforeState = $this->platformAuditState($platform);
        $platform->forceFill([
            'payment_link_providers' => $normalized,
        ])->save();
        $platform->refresh();

        $this->auditService->fromRequest(
            $request,
            (int) $platform->id,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            'platform',
            (int) $platform->id,
            $beforeState,
            $this->platformAuditState($platform),
            $validated['reason'] ?? 'Updated payment link provider configuration'
        );

        return response()->json([
            'platform' => $this->serializePlatformIntegration($platform),
        ]);
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
            'source_url' => [
                'sometimes',
                'url',
                'max:500',
                Rule::unique('scraper_sources', 'source_url')->ignore($scraperSource->id)->where(function ($query) use ($scraperSource) {
                    return $query->where('platform_id', (int) $scraperSource->platform_id);
                })
            ],
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
                    $assignedMarkets = [
                        [
                            'id' => null,
                            'name' => 'All markets',
                            'country' => 'Global',
                        ]
                    ];
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
            'category' => 'required|in:payment,renewal,follow_up,welcome,win_back,credential_setup_link,credential_temp_password',
            'channel' => 'required|in:email,sms,whatsapp',
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
            'category' => 'sometimes|in:payment,renewal,follow_up,welcome,win_back,credential_setup_link,credential_temp_password',
            'channel' => 'sometimes|in:email,sms,whatsapp',
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
            CrmAuditAction::DEAL_CREATE_CUSTOM,
            CrmAuditAction::PRODUCT_CREATE_SALES,
            CrmAuditAction::PAYMENT_MATCH_AUTO,
            CrmAuditAction::PAYMENT_MATCH_CONFIRM,
            CrmAuditAction::PAYMENT_MATCH_BATCH,
            CrmAuditAction::PAYMENT_MANUAL_CLOSE,
            CrmAuditAction::RENEWAL_SMS_SENT,
            CrmAuditAction::RENEWAL_SMS_FAILED,
            CrmAuditAction::CONVERSATION_SMS_SENT,
            CrmAuditAction::CONVERSATION_SMS_FAILED,
            CrmAuditAction::INTEGRATION_PLATFORM_CREATE,
            CrmAuditAction::INTEGRATION_PLATFORM_UPDATE,
            CrmAuditAction::INTEGRATION_CONNECTION_TEST,
            CrmAuditAction::INTEGRATION_SYNC_RUN,
            CrmAuditAction::SYSTEM_DEPLOY_START,
            CrmAuditAction::SYSTEM_DEPLOY_SUCCESS,
            CrmAuditAction::SYSTEM_DEPLOY_FAILED,
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
            CrmAuditAction::FIELD_SALES_CLIENT_LOGIN_AS_CLIENT,
            CrmAuditAction::FIELD_SALES_TRIAL_ACTIVATE,
            CrmAuditAction::FIELD_SALES_SETTINGS_UPDATE,
            CrmAuditAction::COMMISSION_MARK_PAID,
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
            ->select(['id', 'name', 'email', 'role', 'is_ceo', 'status', 'phone', 'notification_prefs', 'assigned_market_ids', 'sb_agent_id'])
            ->with('platforms:id,name,country')
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($platformMap) {
                $assignedMarketIds = $this->decodeMarketIds($user->assigned_market_ids);

                if (empty($assignedMarketIds) && $user->relationLoaded('platforms')) {
                    $assignedMarketIds = $user->platforms->pluck('id')->map(fn($id) => (int) $id)->all();
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
                    'is_ceo' => (bool) ($user->is_ceo ?? false),
                    'status' => $user->status ?? 'active',
                    'payment_failure_sms_state' => $user->paymentFailureSmsState(),
                    'sb_agent_id' => $user->sb_agent_id ? (int) $user->sb_agent_id : null,
                    'phone' => $user->phone,
                    'notification_prefs' => is_array($user->notification_prefs) ? $user->notification_prefs : null,
                    'assigned_market_ids' => array_values(array_unique(array_map('intval', $assignedMarketIds))),
                    'assigned_markets' => $marketDetails,
                ];
            });

        $summary = [
            'admins' => $users->where('role', 'admin')->count(),
            'sub_admins' => $users->where('role', 'sub_admin')->count(),
            'sales' => $users->where('role', 'sales')->count(),
            'field_sales' => $users->where('role', 'field_sales')->count(),
            'ceos' => $users->where('is_ceo', true)->count(),
            'inactive' => $users->where('status', 'inactive')->count(),
        ];

        return response()->json([
            'summary' => $summary,
            'users' => $users,
            'available_markets' => $platformMap->values()->map(fn(Platform $platform) => [
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
            'role' => 'required|in:admin,sub_admin,sales,field_sales,marketing',
            'is_ceo' => 'nullable|boolean',
            'status' => 'required|in:active,inactive',
            'sb_agent_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:30',
            'assigned_market_ids' => 'nullable|array',
            'assigned_market_ids.*' => 'integer|exists:platforms,id',
            'notification_prefs' => 'nullable|array',
            'notification_prefs.payment_failure_sms' => 'nullable|array',
            'notification_prefs.payment_failure_sms.enabled' => 'nullable|boolean',
            'notification_prefs.payment_failure_sms.market_ids' => 'nullable|array',
            'notification_prefs.payment_failure_sms.market_ids.*' => 'integer|exists:platforms,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $assignedMarketIds = collect($validated['assigned_market_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $passwordHash = Hash::make($validated['password'] ?? Str::random(16));
        $isCeo = ($validated['role'] === 'admin') && (bool) ($validated['is_ceo'] ?? false);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower(trim((string) $validated['email'])),
            'password' => $passwordHash,
            'role' => $validated['role'],
            'is_ceo' => $isCeo,
            'status' => $validated['status'],
            'phone' => $validated['phone'] ?? null,
            'notification_prefs' => $validated['notification_prefs'] ?? null,
            'sb_agent_id' => $validated['sb_agent_id'] ?? null,
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
                    'is_ceo' => (bool) ($user->is_ceo ?? false),
                    'status' => $user->status ?? 'active',
                    'phone' => $user->phone,
                    'notification_prefs' => $user->notification_prefs,
                    'sb_agent_id' => $user->sb_agent_id ? (int) $user->sb_agent_id : null,
                    'assigned_market_ids' => $assignedMarketIds,
                ],
                $validated['reason'] ?? 'Created user from CRM role settings'
            );
        }

        $user->refresh();
        $user->load('platforms:id,name,country');

        $assignedMarkets = $user->platforms
            ->map(fn(Platform $platform) => [
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
            'is_ceo' => (bool) ($user->is_ceo ?? false),
            'status' => $user->status ?? 'active',
            'payment_failure_sms_state' => $user->paymentFailureSmsState(),
            'phone' => $user->phone,
            'notification_prefs' => $user->notification_prefs,
            'sb_agent_id' => $user->sb_agent_id ? (int) $user->sb_agent_id : null,
            'assigned_market_ids' => $assignedMarketIds,
            'assigned_markets' => $assignedMarkets,
        ], 201);
    }

    public function updateRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => 'required|in:admin,sub_admin,sales,field_sales,marketing',
            'is_ceo' => 'nullable|boolean',
            'status' => 'required|in:active,inactive',
            'sb_agent_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:30',
            'assigned_market_ids' => 'nullable|array',
            'assigned_market_ids.*' => 'integer|exists:platforms,id',
            'notification_prefs' => 'nullable|array',
            'notification_prefs.payment_failure_sms' => 'nullable|array',
            'notification_prefs.payment_failure_sms.enabled' => 'nullable|boolean',
            'notification_prefs.payment_failure_sms.market_ids' => 'nullable|array',
            'notification_prefs.payment_failure_sms.market_ids.*' => 'integer|exists:platforms,id',
            'password' => 'nullable|string|min:8',
            'reason' => 'nullable|string|max:500',
        ]);

        $assignedMarketIds = collect($validated['assigned_market_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $beforeState = [
            'role' => $user->role,
            'is_ceo' => (bool) ($user->is_ceo ?? false),
            'status' => $user->status ?? 'active',
            'phone' => $user->phone,
            'notification_prefs' => $user->notification_prefs,
            'sb_agent_id' => $user->sb_agent_id ? (int) $user->sb_agent_id : null,
            'assigned_market_ids' => $this->decodeMarketIds($user->assigned_market_ids),
        ];

        $updateData = [
            'role' => $validated['role'],
            'is_ceo' => ($validated['role'] === 'admin') && (bool) ($validated['is_ceo'] ?? false),
            'status' => $validated['status'],
            'phone' => $validated['phone'] ?? null,
            'notification_prefs' => array_key_exists('notification_prefs', $validated)
                ? $validated['notification_prefs']
                : $user->notification_prefs,
            'sb_agent_id' => $validated['sb_agent_id'] ?? null,
            'assigned_market_ids' => $assignedMarketIds,
        ];
        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }
        $user->update($updateData);

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
                    'is_ceo' => (bool) ($user->is_ceo ?? false),
                    'status' => $user->status ?? 'active',
                    'phone' => $user->phone,
                    'notification_prefs' => $user->notification_prefs,
                    'sb_agent_id' => $user->sb_agent_id ? (int) $user->sb_agent_id : null,
                    'assigned_market_ids' => $assignedMarketIds,
                    ...(!empty($validated['password']) ? ['password_changed' => true] : []),
                ],
                $validated['reason'] ?? 'Role and permission update from CRM settings'
            );
        }

        $user->refresh();
        $user->load('platforms:id,name,country');

        $assignedMarkets = $user->platforms
            ->map(fn(Platform $platform) => [
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
            'is_ceo' => (bool) ($user->is_ceo ?? false),
            'status' => $user->status ?? 'active',
            'payment_failure_sms_state' => $user->paymentFailureSmsState(),
            'phone' => $user->phone,
            'notification_prefs' => $user->notification_prefs,
            'sb_agent_id' => $user->sb_agent_id ? (int) $user->sb_agent_id : null,
            'assigned_market_ids' => $assignedMarketIds,
            'assigned_markets' => $assignedMarkets,
        ]);
    }

    public function impersonationLink(Request $request, User $user)
    {
        $actor = $request->user();

        if (($actor->role ?? null) !== 'admin') {
            return response()->json([
                'message' => 'Only admins can open CRM users in impersonation mode.',
            ], 403);
        }

        if ((int) $actor->id === (int) $user->id) {
            return response()->json([
                'message' => 'Use your current session instead of impersonating your own account.',
            ], 422);
        }

        if (($user->status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'Inactive users cannot be opened in impersonation mode.',
            ], 422);
        }

        if (($user->role ?? null) === 'admin') {
            return response()->json([
                'message' => 'Admin accounts cannot be opened in impersonation mode.',
            ], 422);
        }

        $bridge = Str::random(48);
        Cache::put('crm_impersonation_bridge:' . $bridge, [
            'user' => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status ?? 'active',
            ],
            'impersonator' => [
                'id' => (int) $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
                'role' => $actor->role,
            ],
            'redirect_to' => '/',
            'started_at' => now()->toIso8601String(),
        ], now()->addMinutes(5));

        $this->auditService->fromRequest(
            $request,
            $this->resolveAuditPlatformId($this->decodeMarketIds($user->assigned_market_ids)),
            CrmAuditAction::USER_IMPERSONATION_START,
            'user',
            (int) $user->id,
            null,
            [
                'impersonation_link_generated' => true,
                'target_role' => $user->role,
                'target_status' => $user->status ?? 'active',
                'bridge_expires_at' => now()->addMinutes(5)->toIso8601String(),
            ],
            'Admin opened CRM impersonation session from settings'
        );

        return response()->json([
            'url' => URL::temporarySignedRoute(
                'crm.impersonation.consume',
                now()->addMinutes(5),
                ['bridge' => $bridge]
            ),
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

    private function requiredPackageNames(): array
    {
        return ['BASIC', 'PREMIUM', 'VIP'];
    }

    private function normalizePackageName(string $value): string
    {
        return ProductCatalogService::normalizePackageName($value);
    }

    private function normalizePackageTier(string $tier, string $name): string
    {
        return ProductCatalogService::normalizePackageTier($tier, $name);
    }

    /**
     * Normalize submitted price rows from either legacy 3-tier format or new flexible prices array.
     *
     * @return array<int, array{duration_key: string, duration_label: string, duration_days: int, price: float, currency: string, is_active: bool, sort_order: int, id: int|null}>
     */
    private function normalizeSubmittedPriceRows(array $row, Platform $platform, bool $isActive): array
    {
        $primaryCurrency = strtoupper((string) ($platform->currency_code ?: 'KES'));
        $supportedCurrencies = $platform->supportedCurrencies();

        if (!empty($row['prices']) && is_array($row['prices'])) {
            $normalized = [];
            foreach ($row['prices'] as $priceRow) {
                $durationKey = trim((string) ($priceRow['duration_key'] ?? ''));
                if ($durationKey === '') {
                    continue;
                }

                $rowCurrency = strtoupper(trim((string) ($priceRow['currency'] ?? $primaryCurrency)));
                if (!in_array($rowCurrency, $supportedCurrencies, true)) {
                    throw ValidationException::withMessages([
                        'packages' => "{$rowCurrency} is not enabled for this market.",
                    ]);
                }

                $normalized[] = [
                    'id' => isset($priceRow['id']) ? (int) $priceRow['id'] : null,
                    'duration_key' => $durationKey,
                    'duration_label' => trim((string) ($priceRow['duration_label'] ?? ucwords(str_replace('_', ' ', $durationKey)))),
                    'duration_days' => isset($priceRow['duration_days']) ? (int) $priceRow['duration_days'] : $this->inferDurationDays($durationKey),
                    'price' => (float) ($priceRow['price'] ?? 0),
                    'currency' => $rowCurrency,
                    'is_active' => (bool) ($priceRow['is_active'] ?? $isActive),
                    'sort_order' => (int) ($priceRow['sort_order'] ?? 0),
                ];
            }

            return $normalized;
        }

        // Legacy 3-tier format fallback
        $legacyMap = [
            ['key' => '1_week', 'label' => '1 Week', 'days' => 7, 'field' => 'weekly_price', 'sort' => 10],
            ['key' => '2_weeks', 'label' => '2 Weeks', 'days' => 14, 'field' => 'biweekly_price', 'sort' => 20],
            ['key' => '1_month', 'label' => '1 Month', 'days' => 30, 'field' => 'monthly_price', 'sort' => 30],
        ];

        $normalized = [];
        foreach ($legacyMap as $entry) {
            $price = (float) ($row[$entry['field']] ?? 0);
            $normalized[] = [
                'id' => null,
                'duration_key' => $entry['key'],
                'duration_label' => $entry['label'],
                'duration_days' => $entry['days'],
                'price' => $price,
                'currency' => $primaryCurrency,
                'is_active' => $price > 0 && $isActive,
                'sort_order' => $entry['sort'],
            ];
        }

        return $normalized;
    }

    private function inferDurationDays(string $durationKey): int
    {
        $map = [
            '1_week' => 7,
            '2_weeks' => 14,
            '3_weeks' => 21,
            '1_month' => 30,
            '2_months' => 60,
            '3_months' => 90,
            '6_months' => 180,
            '1_year' => 365,
        ];

        return $map[$durationKey] ?? 30;
    }

    private function syncProductPriceRows(Product $product, array $priceRows): void
    {
        $touchedIds = [];
        $existingByKey = ProductPrice::query()
            ->where('product_id', (int) $product->id)
            ->get()
            ->keyBy(fn (ProductPrice $price) => $price->duration_key . ':' . strtoupper((string) $price->currency));

        foreach ($priceRows as $priceRow) {
            $durationKey = (string) $priceRow['duration_key'];
            $currency = strtoupper((string) ($priceRow['currency'] ?? ''));
            $existing = $existingByKey->get($durationKey . ':' . $currency);

            if ($existing) {
                $existing->update([
                    'duration_label' => $priceRow['duration_label'],
                    'duration_days' => $priceRow['duration_days'],
                    'price' => $priceRow['price'],
                    'currency' => $currency,
                    'is_active' => (bool) $priceRow['is_active'],
                    'sort_order' => $priceRow['sort_order'],
                ]);
                $touchedIds[] = (int) $existing->id;
            } else {
                $newPrice = ProductPrice::create([
                    'product_id' => (int) $product->id,
                    'duration_key' => $durationKey,
                    'duration_label' => $priceRow['duration_label'],
                    'duration_days' => $priceRow['duration_days'],
                    'price' => $priceRow['price'],
                    'currency' => $currency,
                    'is_active' => (bool) $priceRow['is_active'],
                    'sort_order' => $priceRow['sort_order'],
                ]);
                $touchedIds[] = (int) $newPrice->id;
            }
        }

        // Remove price rows that were not submitted (soft-remove by deactivating, not deleting, to preserve history)
        if (!empty($touchedIds)) {
            ProductPrice::query()
                ->where('product_id', (int) $product->id)
                ->whereNotIn('id', $touchedIds)
                ->update(['is_active' => false]);
        }
    }

    /**
     * Mirror active price rows back to legacy weekly/biweekly/monthly columns for backward compatibility.
     */
    private function syncLegacyPriceColumnsForProduct(Product $product, array $priceRows, string $primaryCurrency): void
    {
        $legacyMap = [
            '1_week' => 'weekly_price',
            '2_weeks' => 'biweekly_price',
            '1_month' => 'monthly_price',
        ];

        $updates = [
            'weekly_price' => 0,
            'biweekly_price' => 0,
            'monthly_price' => 0,
        ];

        foreach ($priceRows as $priceRow) {
            $key = $priceRow['duration_key'] ?? '';
            if (
                isset($legacyMap[$key])
                && strtoupper((string) ($priceRow['currency'] ?? '')) === strtoupper($primaryCurrency)
                && (bool) ($priceRow['is_active'] ?? false)
            ) {
                $updates[$legacyMap[$key]] = (float) ($priceRow['price'] ?? 0);
            }
        }

        // Direct DB update to avoid the Product mutator that auto-calculates
        Product::query()->where('id', (int) $product->id)->update($updates);
    }

    /**
     * Only creates default BASIC/PREMIUM/VIP stubs for platforms that have ZERO products.
     * Once a market has any products (e.g. from the dynamic catalog seed), this is a no-op.
     */
    private function ensureDefaultPackagesForPlatform(Platform $platform): void
    {
        $existingCount = Product::query()
            ->where('platform_id', (int) $platform->id)
            ->count();

        if ($existingCount > 0) {
            return;
        }

        $requiredNames = $this->requiredPackageNames();
        $currency = strtoupper((string) ($platform->currency_code ?: 'KES'));

        foreach ($requiredNames as $name) {
            Product::query()->create([
                'platform_id' => (int) $platform->id,
                'name' => $name,
                'display_name' => ucfirst(strtolower($name)),
                'slug' => strtolower($name),
                'tier' => strtolower($name),
                'weekly_price' => 0,
                'biweekly_price' => 0,
                'monthly_price' => 0,
                'currency' => $currency,
                'is_active' => false,
                'is_public' => true,
                'sort_order' => match ($name) { 'BASIC' => 30, 'PREMIUM' => 20, 'VIP' => 10, default => 40 },
            ]);
        }
    }

    private function syncPackageCurrenciesForPlatform(Platform $platform): void
    {
        if (count($platform->effectiveCurrencies()) > 1) {
            return;
        }

        $currency = strtoupper((string) ($platform->currency_code ?: 'KES'));
        Product::query()
            ->where('platform_id', (int) $platform->id)
            ->update(['currency' => $currency]);
    }

    private function platformPackageRows(Platform $platform): array
    {
        $currency = strtoupper((string) ($platform->currency_code ?: 'KES'));

        $products = Product::query()
            ->where('platform_id', (int) $platform->id)
            ->where('is_archived', false)
            ->with(['prices', 'creator:id,name,email'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            // Ensure legacy platforms still get default rows for backward compatibility
            $this->ensureDefaultPackagesForPlatform($platform);
            $products = Product::query()
                ->where('platform_id', (int) $platform->id)
                ->where('is_archived', false)
                ->with(['prices', 'creator:id,name,email'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        return $products->map(function (Product $product) use ($currency): array {
            $productCurrency = strtoupper((string) ($product->currency ?: $currency));

            $prices = $product->prices
                ->sortBy('sort_order')
                ->values()
                ->map(fn(ProductPrice $price) => [
                    'id' => (int) $price->id,
                    'duration_key' => $price->duration_key,
                    'duration_label' => $price->duration_label,
                    'duration_days' => $price->duration_days,
                    'price' => (float) $price->price,
                    'currency' => strtoupper((string) ($price->currency ?: $productCurrency)),
                    'is_active' => (bool) $price->is_active,
                    'sort_order' => (int) $price->sort_order,
                ])
                ->all();

            return [
                'id' => (int) $product->id,
                'platform_id' => (int) $product->platform_id,
                'name' => $this->normalizePackageName((string) $product->name),
                'display_name' => $product->display_name ?: Str::title(strtolower((string) $product->name)),
                'slug' => $product->slug,
                'tier' => $product->tier ?: 'custom',
                'plan_type' => strtolower((string) $product->name),
                'weekly_price' => $product->weekly_price !== null ? (float) $product->weekly_price : 0.0,
                'biweekly_price' => $product->biweekly_price !== null ? (float) $product->biweekly_price : 0.0,
                'monthly_price' => $product->monthly_price !== null ? (float) $product->monthly_price : 0.0,
                'currency' => $productCurrency,
                'is_active' => (bool) $product->is_active,
                'is_public' => (bool) ($product->is_public ?? true),
                'is_archived' => (bool) $product->is_archived,
                'origin' => $product->origin ?: 'admin',
                'created_by_user_id' => $product->created_by_user_id ? (int) $product->created_by_user_id : null,
                'creator' => $product->creator ? [
                    'id' => (int) $product->creator->id,
                    'name' => $product->creator->name,
                    'email' => $product->creator->email,
                ] : null,
                'sort_order' => (int) $product->sort_order,
                'prices' => $prices,
            ];
        })->values()->all();
    }

    private function platformPackageSetup(Platform $platform, ?array $rows = null): array
    {
        $rows = collect($rows ?? $this->platformPackageRows($platform));

        // Dynamic catalog rule: market can go live if it has at least one active package
        // with at least one active duration price > 0
        $hasActivePricedPackage = $rows->contains(function (array $row): bool {
            if (!(bool) ($row['is_active'] ?? false)) {
                return false;
            }

            $prices = $row['prices'] ?? [];
            if (empty($prices)) {
                // Fallback: check legacy columns
                return (float) ($row['weekly_price'] ?? 0) > 0
                    || (float) ($row['biweekly_price'] ?? 0) > 0
                    || (float) ($row['monthly_price'] ?? 0) > 0;
            }

            return collect($prices)->contains(fn(array $price) => (bool) ($price['is_active'] ?? false) && (float) ($price['price'] ?? 0) > 0);
        });

        $warnings = [];
        foreach ($rows as $row) {
            $isActive = (bool) ($row['is_active'] ?? false);
            if (!$isActive) {
                continue;
            }

            $prices = $row['prices'] ?? [];
            $hasActivePrice = !empty($prices)
                ? collect($prices)->contains(fn(array $p) => (bool) ($p['is_active'] ?? false) && (float) ($p['price'] ?? 0) > 0)
                : ((float) ($row['weekly_price'] ?? 0) > 0 || (float) ($row['biweekly_price'] ?? 0) > 0 || (float) ($row['monthly_price'] ?? 0) > 0);

            if (!$hasActivePrice) {
                $warnings[] = [
                    'name' => $row['name'],
                    'label' => $row['display_name'] ?? ucfirst(strtolower((string) $row['name'])),
                    'reason' => 'active_but_no_priced_duration',
                ];
            }
        }

        return [
            'status' => $hasActivePricedPackage ? 'complete' : 'incomplete',
            'can_go_live' => $hasActivePricedPackage,
            'missing_requirements' => $hasActivePricedPackage ? [] : [['name' => '*', 'label' => 'Any package', 'reason' => 'no_active_priced_package']],
            'warnings' => $warnings,
            'currency' => strtoupper((string) ($platform->currency_code ?: 'KES')),
        ];
    }

    public function serializePlatformIntegration(
        Platform $platform,
        ?SupportBoardSyncRun $supportBoardSyncRun = null,
        ?ClientSyncRun $clientSyncRun = null
    ): array
    {
        $packageRows = $this->platformPackageRows($platform);
        $packageSetup = $this->platformPackageSetup($platform, $packageRows);
        $hasWpCredentials = $this->platformHasWpCredentials($platform);
        $hasWpDatabaseCredentials = $this->platformHasWpDatabaseCredentials($platform);
        $lastStatus = (string) ($platform->sync_last_status ?? 'unknown');
        $supportBoardSyncRun = $supportBoardSyncRun ?: $this->supportBoardSyncRunService->latestRunForPlatform((int) $platform->id);
        $clientSyncRun = $clientSyncRun ?: $this->clientSyncRunService->latestRunForPlatform((int) $platform->id);
        $clientSyncCapabilityStatus = (string) ($platform->client_sync_capability_status ?? '');
        $legacyCorrectnessRisk = ($platform->client_sync_protocol ?? null) === 'v1'
            || $clientSyncCapabilityStatus === 'legacy_not_found';

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
            'supported_currencies' => $platform->supportedCurrencies(),
            'effective_currencies' => $platform->effectiveCurrencies(),
            'multi_currency_wallet_enabled' => (bool) $platform->multi_currency_wallet_enabled,
            'timezone' => MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC')),
            'phone_prefix' => $platform->phone_prefix ?: '254',
            'support_chat_url' => $platform->support_chat_url,
            'support_board_api_url' => $platform->support_board_api_url,
            'support_board_token_configured' => !empty($platform->support_board_token),
            'support_board_sender_id' => $platform->support_board_sender_id ? (int) $platform->support_board_sender_id : null,
            'support_board_sync' => [
                'queue' => $this->supportBoardSyncRunService->queueReadiness(),
                'latest_run' => $this->supportBoardSyncRunService->serializeRun($supportBoardSyncRun),
            ],
            'client_sync' => [
                'queue' => $this->clientSyncRunService->queueReadiness(),
                'latest_run' => $this->clientSyncRunService->serializeRun($clientSyncRun),
                'protocol' => $platform->client_sync_protocol,
                'contract_version' => $platform->client_sync_contract_version,
                'capability_status' => $clientSyncCapabilityStatus ?: null,
                'capability_checked_at' => optional($platform->client_sync_capability_checked_at)->toDateTimeString(),
                'checkpoint_at' => optional($platform->client_sync_checkpoint_at)->toDateTimeString(),
                'tombstone_checkpoint_at' => optional($platform->client_sync_tombstone_checkpoint_at)->toDateTimeString(),
                'last_reconciled_at' => optional($platform->client_sync_last_reconciled_at)->toDateTimeString(),
                'legacy_correctness_risk' => $legacyCorrectnessRisk,
            ],
            'wp_sync' => [
                'status' => $wpStatus,
                'credentials_ready' => $hasWpCredentials,
                'api_url' => $platform->wp_api_url,
                'api_user' => $platform->wp_api_user,
                'last_checked_at' => optional($platform->sync_last_checked_at)->toDateTimeString(),
                'last_error' => $platform->sync_last_error,
                'client_sync_capability_status' => $clientSyncCapabilityStatus ?: null,
            ],
            'wp_provisioning' => [
                'credentials_ready' => $hasWpDatabaseCredentials,
                'db_host' => $platform->db_host,
                'db_name' => $platform->db_name,
                'db_user' => $platform->db_user,
                'db_prefix' => $platform->db_prefix,
                'db_pass_configured' => !empty($platform->db_pass),
            ],
            'sync' => [
                'last_synced_at' => optional($platform->sync_last_synced_at)->toDateTimeString(),
                'last_scope' => $platform->sync_last_scope,
                'last_status' => $lastStatus,
                'last_error' => $platform->sync_last_error,
                'last_result' => $platform->sync_last_result,
            ],
            'payment_link_providers' => $this->walletSettingsService->currentPaymentLinkProviders($platform),
            'wallet' => $this->walletSettingsService->currentPlatformConfig($platform, masked: true),
            'packages' => $packageRows,
            'package_setup' => $packageSetup,
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

        if ($isPatch && array_key_exists('db_pass', $payload) && empty($payload['db_pass'])) {
            unset($payload['db_pass']);
        }

        if ($isPatch && array_key_exists('support_board_token', $payload) && empty($payload['support_board_token'])) {
            unset($payload['support_board_token']);
        }

        if (!$isPatch) {
            $payload['is_active'] = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : false;
            $payload['phone_prefix'] = $payload['phone_prefix'] ?? '254';
            $payload['currency_code'] = $payload['currency_code'] ?? 'KES';
            $payload['db_prefix'] = $payload['db_prefix'] ?? 'wp_';
        }

        if (array_key_exists('timezone', $payload)) {
            $normalizedTimezone = MarketTimezone::normalize(is_string($payload['timezone']) ? $payload['timezone'] : null);
            if ($normalizedTimezone === null) {
                throw ValidationException::withMessages([
                    'timezone' => $isPatch
                        ? MarketTimezone::validationMessage()
                        : MarketTimezone::requiredValidationMessage(),
                ]);
            }

            $payload['timezone'] = $normalizedTimezone;
        } elseif (!$isPatch) {
            throw ValidationException::withMessages([
                'timezone' => MarketTimezone::requiredValidationMessage(),
            ]);
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
            'timezone' => MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC')),
            'currency_code' => $platform->currency_code,
            'support_chat_url' => $platform->support_chat_url,
            'support_board_api_url' => $platform->support_board_api_url,
            'support_board_token_configured' => !empty($platform->support_board_token),
            'support_board_sender_id' => $platform->support_board_sender_id ? (int) $platform->support_board_sender_id : null,
            'sync_last_checked_at' => optional($platform->sync_last_checked_at)->toDateTimeString(),
            'sync_last_synced_at' => optional($platform->sync_last_synced_at)->toDateTimeString(),
            'sync_last_scope' => $platform->sync_last_scope,
            'sync_last_status' => $platform->sync_last_status,
            'sync_last_error' => $platform->sync_last_error,
            'payment_link_providers' => $this->walletSettingsService->currentPaymentLinkProviders($platform),
            'wallet' => $this->walletSettingsService->currentPlatformConfig($platform, masked: true),
        ];
    }

    private function walletSystemSummary($platformStatuses): array
    {
        $system = $this->walletSettingsService->currentSystemConfig(masked: true);
        $enabledMarkets = collect($platformStatuses)
            ->filter(fn (array $platform) => (bool) data_get($platform, 'wallet.enabled'))
            ->count();
        $productionOverrides = collect($platformStatuses)
            ->filter(fn (array $platform) => data_get($platform, 'wallet.mode_override') === 'production')
            ->count();

        return [
            'status' => ($system['mode'] ?? 'disabled') === 'disabled'
                ? 'configured_disabled'
                : ($enabledMarkets > 0 ? 'connected' : 'pending'),
            'mode' => $system['mode'] ?? 'disabled',
            'enabled_markets' => $enabledMarkets,
            'production_overrides' => $productionOverrides,
        ];
    }

    private function billingWorkspaceMetadata(): array
    {
        return [
            'enabled' => (bool) config('services.billing.enabled', false),
            'features' => (array) config('services.billing.features', []),
            'provider_families' => (array) config('services.billing.provider_family', []),
        ];
    }

    private function billingDiagnosticsServices(Request $request, $platformStatuses): array
    {
        $smsProvider = $this->scopeSmsConfigForUser(
            $this->notificationService->currentSmsConfig(masked: true),
            $request->user()
        );
        $pushProvider = $this->scopePushConfigForUser(
            $this->pushProviderService->currentPushConfig(masked: true),
            $request->user()
        );
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

        return [
            'wallet_system' => $this->walletSystemSummary($platformStatuses),
            'sms_gateway' => [
                'status' => $smsStatus,
                'enabled' => (bool) ($smsProvider['enabled'] ?? false),
                'gateway_url' => $smsProvider['legacy_gateway']['gateway_url'] ?? null,
                'org_code' => $smsProvider['legacy_gateway']['org_code'] ?? null,
                'active_provider' => $activeProvider,
            ],
            'sms_provider' => $smsProvider,
            'push_provider' => $pushProvider,
            'kopokopo' => [
                'status' => config('services.kopokopo.client_id') && config('services.kopokopo.client_secret') && config('services.kopokopo.api_key')
                    ? 'connected'
                    : 'pending',
                'base_url' => config('services.kopokopo.base_url'),
                'till_number' => config('services.kopokopo.till_number'),
            ],
            'payment_service' => [
                'status' => config('services.django.base_url') ? 'connected' : 'pending',
                'base_url' => config('services.django.base_url'),
                'payment_link_path' => config('services.payment_link.path'),
                'note' => 'STK push (including retry) and payment initiation use this Django proxy URL.',
            ],
            'sendgrid' => [
                'status' => 'deferred',
                'note' => 'SendGrid email dispatch is deferred until post Sprint 3 stabilization.',
            ],
        ];
    }

    private function accessiblePlatformsAndStatuses(Request $request): array
    {
        $platformQuery = Platform::query()->orderBy('id');
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $platformQuery->whereIn('id', $allowedPlatformIds);
        }

        $platforms = $platformQuery->get();
        $supportBoardSyncRuns = $this->supportBoardSyncRunService->latestRunsForPlatforms(
            $platforms->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $clientSyncRuns = $this->clientSyncRunService->latestRunsForPlatforms(
            $platforms->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $platformStatuses = $platforms
            ->map(fn (Platform $platform) => $this->serializePlatformIntegration(
                $platform,
                $supportBoardSyncRuns->get((int) $platform->id),
                $clientSyncRuns->get((int) $platform->id)
            ))
            ->values();

        return [$platforms, $platformStatuses, $allowedPlatformIds];
    }

    private function resolveSalesDashboardWidgets(): array
    {
        $stored = IntegrationSetting::query()
            ->where('key', self::SALES_DASHBOARD_WIDGETS_KEY)
            ->value('value');

        return $this->normalizeSalesDashboardWidgets(is_array($stored) ? $stored : []);
    }

    private function normalizeSalesDashboardWidgets(array $widgets): array
    {
        $normalized = self::SALES_DASHBOARD_WIDGET_DEFAULTS;

        foreach (array_keys(self::SALES_DASHBOARD_WIDGET_DEFAULTS) as $key) {
            if (array_key_exists($key, $widgets)) {
                $normalized[$key] = (bool) $widgets[$key];
            }
        }

        return $normalized;
    }

    private function normalizePaymentLinkProviders(array $config): ?array
    {
        $activeProvider = trim((string) ($config['active_provider'] ?? ''));
        $providers = $config['providers'] ?? null;

        if ($activeProvider === '' || !is_array($providers) || empty($providers)) {
            return null;
        }

        $normalizedProviders = [];
        $enabledProviderKeys = [];
        $errors = [];
        foreach ($providers as $key => $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $providerKey = trim((string) $key);
            if ($providerKey === '') {
                continue;
            }

            $label = trim((string) ($provider['label'] ?? $providerKey));
            $mode = strtolower(trim((string) ($provider['mode'] ?? 'static_url')));
            $enabled = array_key_exists('enabled', $provider) ? (bool) $provider['enabled'] : true;
            $url = trim((string) ($provider['url'] ?? ''));
            $baseUrl = trim((string) ($provider['base_url'] ?? ''));
            $path = trim((string) ($provider['path'] ?? ''));
            $walletProviderKey = strtolower(trim((string) ($provider['wallet_provider_key'] ?? '')));
            $environment = strtolower(trim((string) ($provider['environment'] ?? '')));
            $selfCheckoutFxEnabled = array_key_exists('self_checkout_fx_enabled', $provider)
                ? (bool) $provider['self_checkout_fx_enabled']
                : false;
            $selfCheckoutFxCurrency = strtoupper(trim((string) ($provider['self_checkout_fx_currency'] ?? '')));
            $selfCheckoutFxRateRaw = $provider['self_checkout_fx_rate'] ?? null;
            $selfCheckoutFxRate = is_numeric($selfCheckoutFxRateRaw)
                ? round((float) $selfCheckoutFxRateRaw, 6)
                : null;

            if (!in_array($mode, ['static_url', 'proxy_hosted_checkout'], true)) {
                $errors["payment_link_providers.providers.{$providerKey}.mode"] = 'Provider mode must be static_url or proxy_hosted_checkout.';
                continue;
            }

            if ($mode === 'proxy_hosted_checkout') {
                $proxyCapableKeys = $this->billingProviderRegistry->keysForSurface(BillingSurface::ProxyHostedCheckout);
                if (!in_array($walletProviderKey, $proxyCapableKeys, true)) {
                    $errors["payment_link_providers.providers.{$providerKey}.wallet_provider_key"] = 'Proxy providers require a hosted-checkout-capable wallet_provider_key.';
                }

                if (!in_array($environment, ['sandbox', 'production'], true)) {
                    $errors["payment_link_providers.providers.{$providerKey}.environment"] = 'Proxy providers require environment of sandbox or production.';
                }

                if (
                    isset($errors["payment_link_providers.providers.{$providerKey}.wallet_provider_key"])
                    || isset($errors["payment_link_providers.providers.{$providerKey}.environment"])
                ) {
                    continue;
                }

                if ($selfCheckoutFxEnabled) {
                    if ($selfCheckoutFxCurrency === '') {
                        $errors["payment_link_providers.providers.{$providerKey}.self_checkout_fx_currency"] = 'Self-checkout FX override requires a target charge currency.';
                    }

                    if ($selfCheckoutFxRate === null || $selfCheckoutFxRate <= 0) {
                        $errors["payment_link_providers.providers.{$providerKey}.self_checkout_fx_rate"] = 'Self-checkout FX override requires an exchange rate greater than zero.';
                    }

                    if (
                        isset($errors["payment_link_providers.providers.{$providerKey}.self_checkout_fx_currency"])
                        || isset($errors["payment_link_providers.providers.{$providerKey}.self_checkout_fx_rate"])
                    ) {
                        continue;
                    }
                }

                $normalizedProviders[$providerKey] = [
                    'label' => mb_substr($label, 0, 120),
                    'mode' => $mode,
                    'enabled' => $enabled,
                    'wallet_provider_key' => $walletProviderKey,
                    'environment' => $environment,
                    'self_checkout_fx_enabled' => $selfCheckoutFxEnabled,
                    'self_checkout_fx_currency' => $selfCheckoutFxEnabled ? $selfCheckoutFxCurrency : null,
                    'self_checkout_fx_rate' => $selfCheckoutFxEnabled ? $selfCheckoutFxRate : null,
                ];
            } else {
                if ($url === '' && $baseUrl === '') {
                    $errors["payment_link_providers.providers.{$providerKey}.url"] = 'Static URL providers require either url or base_url.';
                    continue;
                }

                $normalizedProviders[$providerKey] = [
                    'label' => mb_substr($label, 0, 120),
                    'mode' => $mode,
                    'enabled' => $enabled,
                    'url' => $url !== '' ? mb_substr($url, 0, 500) : null,
                    'base_url' => $baseUrl !== '' ? mb_substr($baseUrl, 0, 500) : null,
                    'path' => $path !== '' ? mb_substr($path, 0, 255) : null,
                ];
            }

            if ($enabled) {
                $enabledProviderKeys[] = $providerKey;
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        if (empty($normalizedProviders)) {
            return null;
        }

        if (empty($enabledProviderKeys)) {
            throw ValidationException::withMessages([
                'payment_link_providers.providers' => 'At least one enabled payment link provider is required.',
            ]);
        }

        $normalizedActiveProvider = array_key_exists($activeProvider, $normalizedProviders)
            && (bool) ($normalizedProviders[$activeProvider]['enabled'] ?? false)
            ? $activeProvider
            : $enabledProviderKeys[0];

        return [
            'active_provider' => $normalizedActiveProvider,
            'providers' => $normalizedProviders,
        ];
    }

    private function platformHasWpCredentials(Platform $platform): bool
    {
        return !empty($platform->wp_api_url)
            && !empty($platform->wp_api_user)
            && !empty($platform->wp_api_password);
    }

    private function platformHasWpDatabaseCredentials(Platform $platform): bool
    {
        return !empty($platform->db_host)
            && !empty($platform->db_name)
            && !empty($platform->db_user)
            && !empty($platform->db_pass);
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
            CrmAuditAction::PAYMENT_MANUAL_CLOSE => [
                'title' => 'Payment manually closed',
                'category' => 'payments',
                'severity' => 'medium',
                'summary' => 'Pending payment was manually closed by an operator.',
                'suggested_action' => 'Review closure reason and confirm customer follow-up was completed.',
            ],
            CrmAuditAction::PAYMENT_MANUAL_APPROVE => [
                'title' => 'Manual payment approved',
                'category' => 'payments',
                'severity' => 'medium',
                'summary' => 'An operator approved a manual payment submission and activated the subscription.',
                'suggested_action' => 'Confirm the profile is live and that the payment evidence was stored correctly.',
            ],
            CrmAuditAction::PAYMENT_MANUAL_VERIFY => [
                'title' => 'Manual payment verified',
                'category' => 'payments',
                'severity' => 'low',
                'summary' => 'An already-active manual payment submission was verified by an operator.',
                'suggested_action' => 'No immediate action required unless the customer disputes the verification.',
            ],
            CrmAuditAction::PAYMENT_MANUAL_REJECT => [
                'title' => 'Manual payment rejected',
                'category' => 'payments',
                'severity' => 'high',
                'summary' => 'A manual payment submission was rejected and the customer was informed.',
                'suggested_action' => 'Review the rejection reason and confirm any rollback or deactivation completed cleanly.',
            ],
            CrmAuditAction::PAYMENT_MARK_TEST => [
                'title' => 'Payment marked as test',
                'category' => 'payments',
                'severity' => 'medium',
                'summary' => 'An admin excluded a payment from business views by marking it as test.',
                'suggested_action' => 'Confirm this was a non-business record before relying on KPI deltas.',
            ],
            CrmAuditAction::PAYMENT_DELETE_TEST => [
                'title' => 'Test payment deleted',
                'category' => 'payments',
                'severity' => 'high',
                'summary' => 'An admin permanently removed a test payment after recording an audit snapshot.',
                'suggested_action' => 'Review the stored snapshot if historical reconciliation questions come up.',
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
            CrmAuditAction::SYSTEM_DEPLOY_START => [
                'title' => 'Manual deployment started',
                'category' => 'operations',
                'severity' => 'medium',
                'summary' => 'An operator started a manual application deployment.',
                'suggested_action' => 'Monitor the Updates card until the deployment finishes.',
            ],
            CrmAuditAction::SYSTEM_DEPLOY_SUCCESS => [
                'title' => 'Manual deployment succeeded',
                'category' => 'operations',
                'severity' => 'low',
                'summary' => 'Manual deployment completed successfully.',
                'suggested_action' => 'Confirm the deployed version and verify post-deploy health checks.',
            ],
            CrmAuditAction::SYSTEM_DEPLOY_FAILED => [
                'title' => 'Manual deployment failed',
                'category' => 'operations',
                'severity' => 'high',
                'summary' => 'Manual deployment finished with an error.',
                'suggested_action' => 'Review deployment logs and rerun only after the failure is understood.',
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
            CrmAuditAction::USER_IMPERSONATION_START => [
                'title' => 'Impersonation session opened',
                'category' => 'access',
                'severity' => 'medium',
                'summary' => 'An admin opened a CRM session as another user.',
                'suggested_action' => 'Confirm the session was intentional and return to the admin account after verification.',
            ],
            CrmAuditAction::USER_CREATE => [
                'title' => 'User account created',
                'category' => 'access',
                'severity' => 'low',
                'summary' => 'A new CRM user account was created.',
                'suggested_action' => 'Validate role and assigned markets before onboarding handoff.',
            ],
            CrmAuditAction::FIELD_SALES_CLIENT_LOGIN_AS_CLIENT => [
                'title' => 'Field client session opened',
                'category' => 'field_sales',
                'severity' => 'medium',
                'summary' => 'A field agent opened a client session for deposit handoff.',
                'suggested_action' => 'Confirm the session aligns with the client onboarding timeline.',
            ],
            CrmAuditAction::FIELD_SALES_TRIAL_ACTIVATE => [
                'title' => 'Field trial activated',
                'category' => 'field_sales',
                'severity' => 'low',
                'summary' => 'A field agent activated a no-PIN free trial after deposit verification.',
                'suggested_action' => 'Review conversion follow-up if the trial does not become paid.',
            ],
            CrmAuditAction::FIELD_SALES_SETTINGS_UPDATE => [
                'title' => 'Field settings changed',
                'category' => 'field_sales',
                'severity' => 'medium',
                'summary' => 'Field sales deposit, trial, or commission settings were updated.',
                'suggested_action' => 'Confirm the new rates and thresholds match the approved field policy.',
            ],
            CrmAuditAction::COMMISSION_MARK_PAID => [
                'title' => 'Commission payout recorded',
                'category' => 'field_sales',
                'severity' => 'medium',
                'summary' => 'Earned field commissions were marked paid.',
                'suggested_action' => 'Reconcile the payout reference against finance records.',
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

    private function scopePushConfigForUser(array $config, User $user): array
    {
        if ($user->role === MarketAuthorizationService::ROLE_ADMIN) {
            return $config;
        }

        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($user);
        if (!is_array($allowedPlatformIds)) {
            return $config;
        }

        $platforms = is_array($config['platforms'] ?? null)
            ? $config['platforms']
            : [];

        $scopedPlatforms = [];
        foreach ($platforms as $platformId => $platformConfig) {
            $numericPlatformId = (int) $platformId;
            if ($numericPlatformId <= 0) {
                continue;
            }

            if (in_array($numericPlatformId, $allowedPlatformIds, true)) {
                $scopedPlatforms[(string) $numericPlatformId] = is_array($platformConfig) ? $platformConfig : [];
            }
        }

        $config['platforms'] = $scopedPlatforms;

        return $config;
    }

    private function scopeSmsConfigForUser(array $config, User $user): array
    {
        if ($user->role === MarketAuthorizationService::ROLE_ADMIN) {
            return $config;
        }

        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($user);
        if (!is_array($allowedPlatformIds)) {
            return $config;
        }

        $markets = is_array($config['markets'] ?? null) ? $config['markets'] : [];
        $scopedMarkets = [];

        foreach ($markets as $platformId => $marketConfig) {
            $numericPlatformId = (int) $platformId;
            if ($numericPlatformId <= 0) {
                continue;
            }

            if (in_array($numericPlatformId, $allowedPlatformIds, true)) {
                $scopedMarkets[(string) $numericPlatformId] = is_array($marketConfig) ? $marketConfig : [];
            }
        }

        $config['markets'] = $scopedMarkets;

        return $config;
    }

    public function runSbLeadImport(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can run Support Board lead import.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $validated = $request->validate([
            'mode' => 'nullable|string|in:bootstrap,incremental',
            'reason' => 'nullable|string|max:500',
        ]);

        if (!(new SupportBoardService($platform))->isConfigured()) {
            return response()->json([
                'message' => 'Support Board is not configured for this market.',
            ], 422);
        }

        $queue = $this->sbLeadImportRunService->queueReadiness();
        if (!($queue['available'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $queue['issues'][0] ?? 'Background lead import is not available.',
                'queue' => $queue,
            ], 503);
        }

        $mode = $validated['mode'] ?? 'bootstrap';

        try {
            // Pre-fetch candidate user IDs (synchronous, but fast — just API calls)
            $candidateUserIds = $this->supportBoardLeadImportService->fetchCandidateUserIds($platform, $mode);

            if (empty($candidateUserIds)) {
                return response()->json([
                    'status' => 'empty',
                    'message' => 'No Support Board conversations found to import.',
                    'run' => null,
                ], 200);
            }

            $started = $this->sbLeadImportRunService->startRun(
                $platform,
                $request->user(),
                $mode,
                $validated['reason'] ?? null,
                $candidateUserIds
            );
            $run = $started['run'];

            $this->auditService->fromRequest(
                $request,
                (int) $platform->id,
                CrmAuditAction::LEAD_SB_IMPORT_COMMIT,
                'platform',
                (int) $platform->id,
                [],
                [
                    'sb_lead_import' => [
                        'run_id' => (int) $run->id,
                        'status' => $run->status,
                        'mode' => $mode,
                        'candidates' => (int) ($run->candidates ?? 0),
                    ],
                ],
                $validated['reason'] ?? 'Manual Support Board lead import run'
            );

            if (!$started['reused']) {
                RunSbLeadImportJob::dispatch((int) $run->id);
            }

            return response()->json([
                'status' => $started['reused'] ? 'running' : 'queued',
                'message' => $started['reused']
                    ? 'A Support Board lead import is already running for this market.'
                    : 'Support Board lead import has been queued.',
                'reused_run' => (bool) $started['reused'],
                'run' => $this->sbLeadImportRunService->serializeRun($run),
            ], 202);
        } catch (\Throwable $exception) {
            $failedRun = isset($run) && $run instanceof SbLeadImportRun
                ? $this->sbLeadImportRunService->markFailed($run, $exception)
                : null;

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue the Support Board lead import.',
                'error' => $exception->getMessage(),
                'run' => $this->sbLeadImportRunService->serializeRun($failedRun),
            ], 500);
        }
    }

    public function latestSbLeadImportRun(Request $request, Platform $platform)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN, MarketAuthorizationService::ROLE_SUB_ADMIN],
            'Only admin or sub-admin users can view Support Board lead import status.'
        );
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $platform->id,
            'You do not have access to this market.'
        );

        $run = $this->sbLeadImportRunService->latestRunForPlatform((int) $platform->id);

        return response()->json([
            'run' => $this->sbLeadImportRunService->serializeRun($run),
        ]);
    }

    private function resolveAuditPlatformId(array $assignedMarketIds): ?int
    {
        if (!empty($assignedMarketIds)) {
            return (int) $assignedMarketIds[0];
        }

        $fallback = Platform::query()->orderBy('id')->value('id');
        return $fallback ? (int) $fallback : null;
    }

    public function showWpSharedKey(Request $request, WordPressSyncKeyService $service)
    {
        $this->assertSharedKeyAdmin($request);

        return response()->json($service->status());
    }

    public function rotateWpSharedKey(Request $request, WordPressSyncKeyService $service)
    {
        $this->assertSharedKeyAdmin($request);

        $result = $service->rotate($request->user()?->id);

        return response()->json([
            'plain' => $result['plain'],
            'status' => $result['status'],
            'message' => 'A new WordPress sync key has been generated. Copy it now — it will not be shown in full again.',
        ]);
    }

    public function clearWpSharedKey(Request $request, WordPressSyncKeyService $service)
    {
        $this->assertSharedKeyAdmin($request);

        return response()->json([
            'status' => $service->clear($request->user()?->id),
            'message' => 'WordPress sync key cleared. The .env fallback (if set) is now active.',
        ]);
    }

    private function assertSharedKeyAdmin(Request $request): void
    {
        $role = (string) ($request->user()->role ?? '');
        abort_unless(in_array($role, ['admin', 'sub_admin'], true), 403, 'Unauthorized');
    }
}
