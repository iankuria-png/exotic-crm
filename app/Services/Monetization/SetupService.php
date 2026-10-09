<?php

namespace App\Services\Monetization;

use App\Models\ContentMonetizationSetting;
use App\Models\PremiumContentEvent;
use App\Services\BillingModeService;
use App\Services\MonetizationSettingsService;
use App\Services\WalletSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetupService
{
    public const REQUIRED_FILES = [
        'app/Console/Commands/CheckMonetizationRelease.php',
        'app/Http/Controllers/CRM/MonetizationController.php',
        'app/Http/Controllers/Wp/PremiumContentController.php',
        'app/Models/ClientMonetizationPass.php',
        'app/Models/ContentMonetizationPrice.php',
        'app/Models/ContentMonetizationSetting.php',
        'app/Models/ContentMonetizationSystemSetting.php',
        'app/Models/MonetizationAutomationItem.php',
        'app/Models/MonetizationAutomationRun.php',
        'app/Models/PremiumContentAsset.php',
        'app/Models/PremiumContentEvent.php',
        'app/Models/PremiumContentOffer.php',
        'app/Models/VisitorContentPurchase.php',
        'app/Models/VisitorContentPurchaseAllocation.php',
        'app/Services/Monetization/AccessService.php',
        'app/Services/Monetization/AdminBundleService.php',
        'app/Services/Monetization/AssetLifecycleService.php',
        'app/Services/Monetization/CheckoutService.php',
        'app/Services/Monetization/ComplimentaryPassService.php',
        'app/Services/Monetization/ExpiryAutomationService.php',
        'app/Services/Monetization/FulfillmentService.php',
        'app/Services/Monetization/ListingEligibility.php',
        'app/Services/Monetization/OfferService.php',
        'app/Services/Monetization/PassService.php',
        'app/Services/Monetization/PurchaseAllocationService.php',
        'app/Services/Monetization/ReadinessService.php',
        'app/Services/Monetization/SetupService.php',
        'app/Services/Monetization/StatsService.php',
        'app/Services/Monetization/SyncService.php',
        'app/Services/Monetization/TeaserService.php',
        'app/Services/Monetization/WordPressDestination.php',
        'app/Services/MonetizationSettingsService.php',
    ];

    public function deployment(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_FILES, fn ($path) => ! is_file(base_path($path))));

        return ['complete' => ! $missing, 'missing' => $missing];
    }

    public function providers(ContentMonetizationSetting $s, ?string $environment = null): array
    {
        $environment ??= $s->premium_access_environment;
        $available = [];
        $unavailable = [];
        foreach (['kopokopo' => 'M-Pesa · KopoKopo', 'pawapay' => 'PawaPay'] as $key => $label) {
            try {
                app(BillingModeService::class)->providerContext($s->platform, $key, true, $environment, 'premium_content');
                $available[] = ['key' => $key, 'label' => $label];
            } catch (\Throwable $e) {
                $unavailable[] = ['key' => $key, 'label' => $label, 'message' => "$label is not enabled or its credentials are incomplete for {$s->platform->name} $environment. Configure it in Settings → Wallet System, then re-check."];
            }
        }

        return ['available' => $available, 'unavailable' => $unavailable, 'environment' => $environment, 'settings_url' => '/settings?tab=integrations&integrationArea=wallet&platform_id='.$s->platform_id];
    }

    public function status(ContentMonetizationSetting $s): array
    {
        $s->loadMissing('platform');
        $s->load('prices');
        $preflight = $s->preflight_json ?? [];
        $stored = $s->setup_json ?? [];
        $providers = $this->providers($s);
        $selected = data_get($s->checkout_policy_json, 'allowed_providers', []);
        $providerReady = count($selected) > 0 && ! array_diff($selected, array_column($providers['available'], 'key'));
        $reported = $preflight['rest_url'] ?? null;
        $canonical = null;
        $connectionError = $preflight['message'] ?? 'Connect WordPress to discover this market’s address and server requirements.';
        try {
            if ($reported) {
                $canonical = app(WordPressDestination::class)->canonical((string) $s->platform->wp_api_url, $reported);
                $configured = app(WordPressDestination::class)->base((string) $s->platform->wp_api_url);
                if ($canonical !== $configured) {
                    $connectionError = "CRM is set to $configured, but WordPress reports $canonical. Use the WordPress address, then re-check.";
                }
            }
        } catch (ValidationException $e) {
            $connectionError = collect($e->errors())->flatten()->first();
        }
        $capabilities = ! array_diff(['guided_preflight_v1', 'protected_storage', 'range_streaming', 'previews', 'media_guards'], $preflight['capabilities'] ?? []);
        $connection = isset($configured) && $configured === $canonical && data_get($preflight, 'schema_version') === 1 && (int) data_get($preflight, 'platform_id') === (int) $s->platform_id && $capabilities;
        if ($reported && ! $capabilities) {
            $connectionError = 'WordPress is missing required Monetize setup capabilities. Upload the complete sync plugin, then re-check.';
        }
        if ($reported && (int) data_get($preflight, 'platform_id') !== (int) $s->platform_id) {
            $connectionError = 'WordPress is linked to a different market. Use Connect WordPress to align it with market #'.$s->platform_id.'.';
        }
        $videoRequired = (bool) data_get($s->offer_policy_json, 'videos_enabled');
        $server = data_get($preflight, 'server.storage.ready') === true && data_get($preflight, 'server.gd') === true && data_get($preflight, 'server.fileinfo') === true && (! $videoRequired || data_get($preflight, 'server.video.ready') === true);
        $walletMode = app(WalletSettingsService::class)->runtimePlatformConfig($s->platform)['effective_mode'] ?? 'disabled';
        $walletEnvironment = $walletMode === 'production' ? 'production' : 'sandbox';
        $wallet = $walletMode !== 'disabled' && $walletEnvironment === $s->premium_access_environment && data_get($preflight, 'wallet_auth.ready') === true;
        $fresh = ! empty($stored['checked_at']) && \Carbon\Carbon::parse($stored['checked_at'])->gt(now()->subMinutes(15));
        $current = (int) ($stored['revision'] ?? 0) === (int) $s->config_revision && ($stored['configured_url'] ?? '') === (string) $s->platform->wp_api_url && ($stored['environment'] ?? '') === $s->premium_access_environment;
        $delivery = $fresh && $current && data_get($s->readiness_json, 'ready') === true && (int) $s->wp_revision === (int) $s->config_revision && data_get($stored, 'sync.status') === 'synced' && data_get($stored, 'credentials.status') === 'synced';
        foreach (['protected_storage', 'anonymous_denied', 'direct_denied', 'range', 'no_store'] as $check) {
            $delivery = $delivery && data_get($s->readiness_json, 'checks.'.$check) === true;
        }
        if ($videoRequired) {
            $delivery = $delivery && data_get($s->readiness_json, 'checks.video_processing') === true;
        }
        $pricing = $s->prices->where('is_active', true)->isNotEmpty();
        $deployment = $this->deployment();
        $pluginComplete = data_get($preflight, 'deployment.complete') === true;
        $system = app(MonetizationSettingsService::class)->system();
        $global = $system->enabled && ! $system->activation_kill_switch && ! $system->checkout_kill_switch && ! $s->activation_kill_switch && ! $s->checkout_kill_switch;
        $ready = $connection && $server && $wallet && $providerReady && $pricing && $delivery && $deployment['complete'] && $pluginComplete && $global && $s->premium_access_environment === 'production' && $walletMode === 'production';
        $gate = fn ($key, $label, $ok, $message) => ['key' => $key, 'label' => $label, 'passed' => (bool) $ok, 'message' => $message];
        $issues = array_filter(data_get($preflight, 'server.issues', []), fn ($issue) => $videoRequired || ! str_starts_with($issue['code'] ?? '', 'ffmpeg') && ($issue['code'] ?? '') !== 'php_exec_disabled');

        return ['market' => ['id' => $s->platform_id, 'name' => $s->platform->name, 'currency' => $s->currency], 'configured_url' => $s->platform->wp_api_url, 'reported_url' => $reported, 'home_url' => $preflight['home_url'] ?? null, 'repair_url' => $canonical, 'canonical_mismatch' => isset($configured) && $canonical !== null && $canonical !== $configured, 'environment' => $s->premium_access_environment, 'wallet_mode' => $walletMode,
            'providers' => $providers, 'ready_to_enable' => (bool) $ready, 'is_live' => $s->enabled && $s->rollout_mode === 'live', 'checked_at' => $stored['checked_at'] ?? null, 'deployment' => $deployment,
            'gates' => [
                $gate('connection', 'Connect WordPress', $connection, $connection ? 'The WordPress address and market match.' : $connectionError),
                $gate('server', 'Check server readiness', $server, $server ? 'Private storage and the required PHP/media tools are available.' : (array_values($issues)[0]['message'] ?? 'Run preflight to identify the server requirements.')),
                $gate('wallet', 'Confirm wallet authentication', $wallet, $wallet ? "WordPress authenticated with CRM in {$s->premium_access_environment}." : 'Connect WordPress using the active Wallet System environment. Check that Wallet System is enabled for this market, then re-check.'),
                $gate('providers', 'Choose available checkout providers', $providerReady, $providerReady ? 'Every selected provider is enabled with credentials for this environment.' : 'Choose an available provider below. If none is available, configure this market in Settings → Wallet System, then re-check.'),
                $gate('pricing', 'Review pricing and selling policies', $pricing, $pricing ? 'Selling-pass pricing is saved. Review Pass pricing and Offers & limits before enabling.' : 'Review and save at least one available selling pass in Pass pricing.'),
                $gate('delivery', 'Verify protected delivery', $delivery, $delivery ? 'WordPress acknowledged this revision and the required delivery checks passed.' : ($s->readiness_json['error'] ?? data_get($stored, 'sync.message') ?? 'Save your settings, then run checks & sync. Checks must match the current revision and be less than 15 minutes old.')),
                $gate('activation', 'Enable live market', $ready, $ready ? 'Configuration is ready to enable. A real payment has not been tested by these checks.' : (! $deployment['complete'] || ! $pluginComplete ? 'Upload the complete CRM release and sync plugin, then re-check.' : (! $global ? 'Enable Monetize globally and release any sales pauses below, then re-check.' : 'Complete the preceding steps using production wallet/provider settings, then enable explicitly.'))),
            ], 'server' => $preflight['server'] ?? null, 'preflight_error' => $preflight['message'] ?? null];
    }

    public function connect(ContentMonetizationSetting $s, string $environment, string $reason, int $actor): array
    {
        $revision = (int) $s->config_revision;
        $result = DB::transaction(function () use ($s, $revision, $environment, $reason, $actor) {
            $s = ContentMonetizationSetting::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ((int) $s->config_revision !== $revision) {
                throw ValidationException::withMessages(['connection' => 'Settings changed. Reload the saved revision before reconnecting.']);
            }
            $mode = app(WalletSettingsService::class)->runtimePlatformConfig($s->platform)['effective_mode'] ?? 'disabled';
            if ($mode === 'disabled' || ($mode === 'production' ? 'production' : 'sandbox') !== $environment || ($s->rollout_mode === 'live' && $environment !== 'production')) {
                throw ValidationException::withMessages(['premium_access_environment' => 'Use the active Wallet System environment for this market. Keep a live market on production. Configure Wallet System first.']);
            }
            $wallet = app(WalletSettingsService::class);
            $pair = $wallet->wpToCrmCredentialPair($s->platform, $environment);
            $rotation = null;
            if (! $pair['bearer_key'] || ! $pair['hmac_secret']) {
                $rotation = $wallet->previewWpCredentialRotation($s->platform, $environment, 'both');
                $pair = $rotation['revealed'];
            }
            $payload = $wallet->wpCredentialSyncPayload($s->platform, $environment, $pair) + ['grant_secret' => $s->grant_secret, 'device_pepper' => $s->device_pepper];
            $result = app(SyncService::class)->adminSend($s, '/wallet-credentials', $payload);
            // Never return the credential payload, rotation or WordPress response to the browser.
            if ($result['status'] !== 'synced') {
                $safe = array_intersect_key($result, array_flip(['status', 'code', 'message']));
                $this->audit($s, 'setup_connection_failed', $reason, $actor, $safe);

                return $safe;
            }
            if ($rotation) {
                $wallet->persistPlatformCredentialsSnapshot($s->platform, $rotation['credentials'], $actor);
            }
            $s->update(['premium_access_environment' => $environment, 'config_revision' => $s->config_revision + 1, 'setup_json' => null]);
            $this->audit($s, 'setup_connected', $reason, $actor, ['environment' => $environment]);

            return ['status' => 'synced'];
        });

        return $result['status'] === 'synced' ? $this->check($s->refresh()) : $result;
    }

    public function check(ContentMonetizationSetting $s): array
    {
        $sync = app(SyncService::class);
        $result = $sync->send($s, '/premium-content/preflight', []);
        $preflight = $result['status'] === 'synced' && data_get($result, 'response.schema_version') === 1 ? $result['response'] : ['message' => $result['message'] ?? 'WordPress returned an unsupported preflight. Upload the complete current sync plugin, then re-check.', 'code' => $result['code'] ?? 'preflight_unsupported'];
        // The signed endpoint returns only this versioned non-secret contract.
        $preflight = $this->safePreflight($preflight);
        $s->refresh()->update(['preflight_json' => $preflight]);
        $status = $this->status($s);
        $canProbe = $status['gates'][0]['passed'] && $status['gates'][1]['passed'] && $status['gates'][2]['passed'] && data_get($preflight, 'deployment.complete') === true;
        // During a staged deployment, keep established Live markets on their existing signed
        // six-check contract. This never permits a new activation without guided preflight.
        $canProbe = $canProbe || ($s->enabled && $s->rollout_mode === 'live' && ($result['code'] ?? null) === 'wordpress_http_404');
        $cause = collect($status['gates'])->first(fn ($gate) => ! $gate['passed'])['message'] ?? 'Upload the complete plugin and re-check.';
        $credentials = $canProbe ? $sync->provision($s) : ['status' => 'failed', 'message' => $cause];
        $pushed = $canProbe && $credentials['status'] === 'synced' ? $sync->push($s) : ['status' => 'failed', 'message' => $credentials['message'] ?? 'Connect WordPress before syncing.'];
        $readiness = $pushed['status'] === 'synced' ? app(ReadinessService::class)->check($s) : ['ready' => false, 'checks' => [], 'error' => $pushed['message'], 'verified_at' => now()->toIso8601String()];
        $safe = fn ($r) => array_intersect_key($r, array_flip(['status', 'code', 'message']));
        $stored = ['revision' => $s->config_revision, 'environment' => $s->premium_access_environment, 'configured_url' => $s->platform->wp_api_url, 'checked_at' => now()->toIso8601String(), 'credentials' => $safe($credentials), 'sync' => $safe($pushed)];
        $s->update(['setup_json' => $stored, 'readiness_json' => array_merge($s->readiness_json ?? [], $readiness)]);

        return ['setup' => $this->status($s), 'sync' => $stored['sync'], 'credentials' => $stored['credentials'], 'readiness' => $readiness];
    }

    public function repairCanonical(ContentMonetizationSetting $s, string $reason, int $actor): array
    {
        $reported = data_get($s->preflight_json, 'rest_url');
        if (! $reported || empty($s->preflight_json['checked_at']) || \Carbon\Carbon::parse($s->preflight_json['checked_at'])->lt(now()->subMinutes(15))) {
            throw ValidationException::withMessages(['connection' => 'Run a fresh WordPress check before using its reported address.']);
        }
        $before = (string) $s->platform->wp_api_url;
        $after = app(WordPressDestination::class)->canonical($before, $reported);
        DB::transaction(function () use ($s, $before, $after, $reason, $actor) {
            $locked = ContentMonetizationSetting::whereKey($s->id)->lockForUpdate()->firstOrFail();
            $platform = $locked->platform()->lockForUpdate()->firstOrFail();
            if ((int) $locked->config_revision !== (int) $s->config_revision || $platform->wp_api_url !== $before) {
                throw ValidationException::withMessages(['connection' => 'Settings changed. Run a fresh check before repairing the WordPress address.']);
            }
            $s->platform->update(['wp_api_url' => $after]);
            $s->update(['config_revision' => $s->config_revision + 1, 'setup_json' => null]);
            $this->audit($s, 'setup_canonical_repaired', $reason, $actor, ['before' => $before, 'after' => $after]);
        });

        return $this->check($s);
    }

    public function activate(ContentMonetizationSetting $s, int $revision, string $reason, int $actor): array
    {
        try {
            return DB::transaction(function () use ($s, $revision, $reason, $actor) {
                $locked = ContentMonetizationSetting::whereKey($s->id)->lockForUpdate()->firstOrFail();
                if ((int) $locked->config_revision !== $revision || ! $this->status($locked)['ready_to_enable']) {
                    throw ValidationException::withMessages(['activation' => 'This market is not ready to enable. Save any changes, resolve the setup steps and run fresh checks & sync.']);
                }
                $locked->update(['enabled' => true, 'rollout_mode' => 'live', 'config_revision' => $locked->config_revision + 1]);
                $sync = app(SyncService::class)->push($locked);
                if ($sync['status'] !== 'synced') {
                    throw ValidationException::withMessages(['activation' => 'Activation was not confirmed by WordPress. The previous market mode is retained. Re-check and retry.']);
                }
                \App\Models\ClientMonetizationPass::where('platform_id', $locked->platform_id)->where('is_sandbox', true)->update(['status' => 'expired', 'active_marker' => null]);
                $stored = $locked->setup_json;
                $stored['revision'] = $locked->config_revision;
                $locked->update(['setup_json' => $stored]);
                $this->audit($locked, 'setup_live_enabled', $reason, $actor, ['outcome' => 'saved_and_synced', 'revision' => $locked->config_revision, 'payment_tested' => false]);

                return ['setup' => $this->status($locked), 'message' => 'Live market enabled and synced. Configuration checks do not verify a real payment.'];
            });
        } catch (ValidationException $e) {
            $s->refresh();
            $this->audit($s, 'setup_activation_failed', $reason, $actor, ['outcome' => 'not_enabled']);
            // If the response was lost after a remote apply, supersede it with the retained mode.
            if (isset($e->errors()['activation']) && str_starts_with($e->errors()['activation'][0], 'Activation was not confirmed')) {
                DB::transaction(function () use ($s) {
                    $locked = ContentMonetizationSetting::whereKey($s->id)->lockForUpdate()->firstOrFail();
                    $locked->update(['config_revision' => $locked->config_revision + 2, 'setup_json' => null]);
                });
                $s->refresh();
                app(SyncService::class)->push($s);
            }
            throw $e;
        }
    }

    private function audit(ContentMonetizationSetting $s, string $kind, string $reason, int $actor, array $metadata): void
    {
        PremiumContentEvent::create(['platform_id' => $s->platform_id, 'actor_id' => $actor, 'kind' => $kind, 'reason' => $reason, 'metadata_json' => $metadata]);
    }

    private function safePreflight(array $input): array
    {
        $only = fn ($value, $keys) => array_intersect_key(is_array($value) ? $value : [], array_flip($keys));
        $report = $only($input, ['schema_version', 'platform_id', 'home_url', 'rest_url', 'plugin_version', 'capabilities', 'checked_at', 'message', 'code']);
        $report['capabilities'] = array_values(array_intersect((array) ($input['capabilities'] ?? []), ['guided_preflight_v1', 'protected_storage', 'range_streaming', 'previews', 'media_guards']));
        $report['deployment'] = $only($input['deployment'] ?? [], ['complete', 'missing']);
        $report['wallet_auth'] = $only($input['wallet_auth'] ?? [], ['ready', 'code', 'message']);
        $server = is_array($input['server'] ?? null) ? $input['server'] : [];
        $report['server'] = $only($server, ['gd', 'fileinfo']);
        $report['server']['storage'] = $only($server['storage'] ?? [], ['configured', 'safe_default', 'exists', 'writable', 'outside_web_root', 'ready']);
        $report['server']['video'] = $only($server['video'] ?? [], ['ready', 'exec_available', 'path', 'runs_under_php', 'libx264', 'aac', 'code']);
        $report['server']['host_setup'] = $only($server['host_setup'] ?? [], ['public_root', 'wordpress_root', 'storage_location', 'config', 'commands', 'instructions']);
        $report['server']['issues'] = array_values(array_map(fn ($issue) => $only($issue, ['code', 'message']), is_array($server['issues'] ?? null) ? $server['issues'] : []));

        return $report;
    }
}
