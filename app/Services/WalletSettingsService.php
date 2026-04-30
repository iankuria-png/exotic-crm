<?php

namespace App\Services;

use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Support\LegacyBillingConfigProjector;
use App\Billing\Support\LegacyBillingSystemProjector;
use App\Mail\WalletSettingsTestMail;
use App\Models\BillingSystemSetting;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WalletSettingsService
{
    public const SYSTEM_SETTINGS_KEY = 'wallet_system_config';
    public const PLATFORM_CREDENTIALS_KEY_PREFIX = 'wallet_platform_credentials_';
    public const KILL_SWITCHES_KEY = 'billing_kill_switches';
    public const MODES = ['disabled', 'sandbox', 'production'];
    public const ENVIRONMENTS = ['sandbox', 'production'];

    public function __construct(
        private readonly BillingProviderRegistryContract $providerRegistry,
        private readonly LegacyBillingConfigProjector $legacyBillingConfigProjector,
        private readonly LegacyBillingSystemProjector $legacyBillingSystemProjector,
        private readonly KopokopoConfigService $kopokopoConfigService
    ) {
    }

    /**
     * @return list<string>
     */
    public function providerKeys(): array
    {
        return $this->providerRegistry->legacyWalletProviderKeys();
    }

    public function currentSystemConfig(bool $masked = true): array
    {
        $config = $this->resolveSystemConfig();

        if (!$masked) {
            return $config;
        }

        $maskedConfig = $config;
        $maskedConfig['smtp']['password_configured'] = !empty($config['smtp']['password']);
        $maskedConfig['smtp']['password'] = '';
        $maskedConfig['pin_hash'] = '';
        $maskedConfig['pin_set'] = !empty($config['pin_hash']);
        $maskedConfig['free_trial_pin_hash'] = '';
        $maskedConfig['free_trial_pin_set'] = !empty($config['free_trial_pin_hash']);
        $maskedConfig['discount_pin_hash'] = '';
        $maskedConfig['discount_pin_set'] = !empty($config['discount_pin_hash']);

        return $maskedConfig;
    }

    public function saveSystemConfig(array $payload, ?int $updatedBy = null): array
    {
        $current = $this->resolveSystemConfig();
        $merged = $this->mergeSystemConfig($current, $payload);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SYSTEM_SETTINGS_KEY],
            [
                'value' => $this->systemConfigForStorage($merged),
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentSystemConfig(masked: true);
    }

    public function updateOperatorPin(string $pin, ?int $updatedBy = null): array
    {
        $pin = trim($pin);
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new InvalidArgumentException('Wallet PIN must be 4 to 6 digits.');
        }

        $current = $this->resolveSystemConfig();
        $current['pin_hash'] = Hash::make($pin);
        $current['pin_last_updated_at'] = now()->toIso8601String();

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SYSTEM_SETTINGS_KEY],
            [
                'value' => $this->systemConfigForStorage($current),
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentSystemConfig(masked: true);
    }

    public function operatorPinIsConfigured(): bool
    {
        return !empty($this->resolveSystemConfig()['pin_hash'] ?? '');
    }

    public function verifyOperatorPin(string $pin): bool
    {
        $hash = (string) ($this->resolveSystemConfig()['pin_hash'] ?? '');

        return $hash !== '' && Hash::check(trim($pin), $hash);
    }

    public function updateFreeTrialPin(string $pin, ?int $updatedBy = null): array
    {
        $pin = trim($pin);
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new InvalidArgumentException('Free-trial PIN must be 4 to 6 digits.');
        }

        $current = $this->resolveSystemConfig();
        $current['free_trial_pin_hash'] = Hash::make($pin);
        $current['free_trial_pin_last_updated_at'] = now()->toIso8601String();

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SYSTEM_SETTINGS_KEY],
            [
                'value' => $this->systemConfigForStorage($current),
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentSystemConfig(masked: true);
    }

    public function freeTrialPinIsConfigured(): bool
    {
        return !empty($this->resolveSystemConfig()['free_trial_pin_hash'] ?? '');
    }

    public function verifyFreeTrialPin(string $pin): bool
    {
        $hash = (string) ($this->resolveSystemConfig()['free_trial_pin_hash'] ?? '');

        return $hash !== '' && Hash::check(trim($pin), $hash);
    }

    public function updateDiscountPin(string $pin, ?int $updatedBy = null): array
    {
        $pin = trim($pin);
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new InvalidArgumentException('Discount PIN must be 4 to 6 digits.');
        }

        $current = $this->resolveSystemConfig();
        $current['discount_pin_hash'] = Hash::make($pin);
        $current['discount_pin_last_updated_at'] = now()->toIso8601String();

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SYSTEM_SETTINGS_KEY],
            [
                'value' => $this->systemConfigForStorage($current),
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentSystemConfig(masked: true);
    }

    public function discountPinIsConfigured(): bool
    {
        return !empty($this->resolveSystemConfig()['discount_pin_hash'] ?? '');
    }

    public function verifyDiscountPin(string $pin): bool
    {
        $hash = (string) ($this->resolveSystemConfig()['discount_pin_hash'] ?? '');

        return $hash !== '' && Hash::check(trim($pin), $hash);
    }

    public function updateDiscountConfig(array $config, ?int $updatedBy = null): array
    {
        $current = $this->resolveSystemConfig();
        $current['discount_config'] = $this->normalizeDiscountConfig($config);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SYSTEM_SETTINGS_KEY],
            [
                'value' => $this->systemConfigForStorage($current),
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentSystemConfig(masked: true);
    }

    public function getDiscountConfig(): array
    {
        return $this->normalizeDiscountConfig(
            (array) ($this->resolveSystemConfig()['discount_config'] ?? [])
        );
    }

    public function currentPlatformConfig(Platform $platform, bool $masked = true): array
    {
        $settings = $this->resolvePlatformSettings($platform);
        $credentials = $this->resolvePlatformCredentials($platform);
        $system = $this->resolveSystemConfig();

        $config = array_merge($settings, [
            'effective_mode' => $this->effectiveMode($system['mode'], $settings),
            'credentials' => $masked
                ? $this->maskPlatformCredentials($credentials)
                : $credentials,
        ]);

        return $config;
    }

    public function runtimePlatformConfig(Platform $platform): array
    {
        $settings = $this->resolvePlatformSettings($platform);
        $credentials = $this->resolvePlatformCredentials($platform);
        $system = $this->resolveSystemConfig();

        return array_merge($settings, [
            'effective_mode' => $this->effectiveMode($system['mode'], $settings),
            'credentials' => $this->runtimePlatformCredentials($credentials),
        ]);
    }

    public function runtimeRecentTransactionsLimit(?Platform $platform, int $default = 10): int
    {
        if (!$platform) {
            return max(1, min(50, $default));
        }

        return max(
            1,
            min(
                50,
                (int) data_get($this->runtimePlatformConfig($platform), 'recent_transactions_limit', $default)
            )
        );
    }

    public function runtimeWalletCurrencyCode(?Platform $platform, string $default = 'KES'): string
    {
        $fallback = strtoupper(trim($default)) !== '' ? strtoupper(trim($default)) : 'KES';
        if (!$platform) {
            return $fallback;
        }

        $configured = strtoupper(trim((string) data_get($this->runtimePlatformConfig($platform), 'currency_code', '')));

        return $configured !== '' ? $configured : $fallback;
    }

    public function currentPaymentLinkProviders(Platform $platform): ?array
    {
        $legacy = is_array($platform->payment_link_providers)
            ? $platform->payment_link_providers
            : null;

        if (!$this->shadowReadEnabled() && $legacy !== null) {
            return $legacy;
        }

        return $this->legacyBillingConfigProjector->projectPaymentLinkProviders($platform, $legacy);
    }

    public function savePlatformConfig(Platform $platform, array $payload): array
    {
        $current = $this->resolvePlatformSettings($platform);
        $merged = $this->mergePlatformSettings($platform, $current, $payload);

        $platform->forceFill([
            'wallet_settings' => $merged,
        ])->save();

        return $this->currentPlatformConfig($platform->fresh(), masked: true);
    }

    public function savePlatformProviderCredentials(Platform $platform, array $payload, ?int $updatedBy = null): array
    {
        $current = $this->resolvePlatformCredentials($platform);
        $merged = $this->mergePlatformCredentials($current, $payload);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => $this->platformCredentialsKey((int) $platform->id)],
            [
                'value' => $merged,
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentPlatformConfig($platform, masked: true);
    }

    public function rotateWpCredentials(
        Platform $platform,
        string $environment,
        string $credential,
        ?int $updatedBy = null
    ): array {
        $rotation = $this->previewWpCredentialRotation($platform, $environment, $credential);
        $platformWallet = $this->persistPlatformCredentialsSnapshot($platform, $rotation['credentials'], $updatedBy);

        return [
            'environment' => $rotation['environment'],
            'credential' => $rotation['credential'],
            'revealed' => $rotation['revealed'],
            'platform_wallet' => $platformWallet,
        ];
    }

    public function previewWpCredentialRotation(
        Platform $platform,
        string $environment,
        string $credential
    ): array {
        $environment = $this->normalizeEnvironment($environment);
        $credential = strtolower(trim($credential));
        if (!in_array($credential, ['bearer', 'hmac', 'both'], true)) {
            throw new InvalidArgumentException('Credential rotation target must be bearer, hmac, or both.');
        }

        $current = $this->resolvePlatformCredentials($platform);
        $revealed = [];

        if (in_array($credential, ['bearer', 'both'], true)) {
            $plainBearer = 'ew_' . Str::random(48);
            $current['wp_to_crm'][$environment]['bearer_key_hash'] = Hash::make($plainBearer);
            $current['wp_to_crm'][$environment]['bearer_key_encrypted'] = Crypt::encryptString($plainBearer);
            $current['wp_to_crm'][$environment]['bearer_last_rotated_at'] = now()->toIso8601String();
            $revealed['bearer_key'] = $plainBearer;
        }

        if (in_array($credential, ['hmac', 'both'], true)) {
            $plainHmac = Str::random(64);
            $current['wp_to_crm'][$environment]['hmac_secret_encrypted'] = Crypt::encryptString($plainHmac);
            $current['wp_to_crm'][$environment]['hmac_last_rotated_at'] = now()->toIso8601String();
            $revealed['hmac_secret'] = $plainHmac;
        }

        return [
            'environment' => $environment,
            'credential' => $credential,
            'revealed' => $revealed,
            'credentials' => $current,
        ];
    }

    public function persistPlatformCredentialsSnapshot(
        Platform $platform,
        array $credentials,
        ?int $updatedBy = null
    ): array {
        IntegrationSetting::query()->updateOrCreate(
            ['key' => $this->platformCredentialsKey((int) $platform->id)],
            [
                'value' => $this->mergePlatformCredentials($this->defaultPlatformCredentials(), $credentials),
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentPlatformConfig($platform->fresh() ?? $platform, masked: true);
    }

    public function recordWpCredentialSyncAttempt(
        Platform $platform,
        string $environment,
        array $attempt,
        ?int $updatedBy = null
    ): array {
        $environment = $this->normalizeEnvironment($environment);
        $current = $this->resolvePlatformCredentials($platform);
        $current['wp_to_crm'][$environment]['sync'] = $this->normalizeWpCredentialSyncState(
            array_merge(
                (array) ($current['wp_to_crm'][$environment]['sync'] ?? []),
                $attempt
            )
        );

        return $this->persistPlatformCredentialsSnapshot($platform, $current, $updatedBy);
    }

    public function testProvider(Platform $platform, string $provider, string $environment): array
    {
        $provider = $this->normalizeProvider($provider);
        $environment = $this->normalizeEnvironment($environment);
        $credentials = $this->resolvePlatformCredentials($platform);

        return match ($provider) {
            'pesapal' => $this->testPesapal($credentials, $environment),
            'paystack' => $this->testPaystack($credentials, $environment),
            'mpesa_stk' => $this->testMpesaStk($credentials, $environment),
            default => throw new InvalidArgumentException('Unsupported wallet provider.'),
        };
    }

    public function testDomain(string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $system = $this->resolveSystemConfig();
        $url = trim((string) ($system['billing_domains'][$environment] ?? ''));

        if ($url === '') {
            throw new InvalidArgumentException("No {$environment} billing domain is configured.");
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('Configured billing domain is invalid.');
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA + DNS_CNAME);
        $resolved = collect($records)
            ->map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? $record['target'] ?? null)
            ->filter()
            ->values()
            ->all();

        return [
            'environment' => $environment,
            'url' => $url,
            'host' => $host,
            'resolved' => !empty($resolved),
            'records' => $resolved,
            'status' => !empty($resolved) ? 'success' : 'failed',
            'message' => !empty($resolved)
                ? 'Billing domain resolves in DNS.'
                : 'Billing domain did not resolve in DNS.',
        ];
    }

    public function testSsl(string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $system = $this->resolveSystemConfig();
        $url = trim((string) ($system['billing_domains'][$environment] ?? ''));

        if ($url === '') {
            throw new InvalidArgumentException("No {$environment} billing domain is configured.");
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException('Billing domain must use https:// to run the SSL test.');
        }

        $response = Http::timeout(10)->get($url);

        return [
            'environment' => $environment,
            'url' => $url,
            'ok' => $response->successful(),
            'status' => $response->successful() ? 'success' : 'failed',
            'http_status' => $response->status(),
            'message' => $response->successful()
                ? 'HTTPS reachability check passed.'
                : 'HTTPS reachability check failed.',
        ];
    }

    public function testBillingApp(string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $system = $this->resolveSystemConfig();
        $url = trim((string) ($system['billing_domains'][$environment] ?? ''));

        if ($url === '') {
            throw new InvalidArgumentException("No {$environment} billing domain is configured.");
        }

        $healthUrl = rtrim($url, '/') . '/api/billing/health';
        $response = Http::timeout(10)->get($healthUrl);
        $payload = $response->json();

        return [
            'environment' => $environment,
            'url' => $healthUrl,
            'ok' => $response->successful(),
            'status' => $response->successful() ? 'success' : 'failed',
            'http_status' => $response->status(),
            'message' => $response->successful()
                ? 'Billing app reachability test passed.'
                : 'Billing app reachability test failed.',
            'provider_response' => is_array($payload) ? $payload : null,
        ];
    }

    public function sendTestEmail(string $toEmail): array
    {
        $system = $this->resolveSystemConfig();
        $smtp = $system['smtp'] ?? [];

        if (!(bool) ($smtp['enabled'] ?? false)) {
            throw new InvalidArgumentException('Wallet SMTP delivery is disabled.');
        }

        if (
            empty($smtp['host'])
            || empty($smtp['port'])
            || empty($smtp['username'])
            || empty($smtp['password'])
            || empty($smtp['from_address'])
        ) {
            throw new InvalidArgumentException('SMTP settings are incomplete.');
        }

        config([
            'mail.mailers.wallet_smtp' => [
                'transport' => 'smtp',
                'host' => (string) $smtp['host'],
                'port' => (int) $smtp['port'],
                'encryption' => $smtp['encryption'] ?: null,
                'username' => (string) $smtp['username'],
                'password' => (string) $smtp['password'],
                'timeout' => 10,
            ],
            'mail.from.address' => (string) $smtp['from_address'],
            'mail.from.name' => (string) ($smtp['from_name'] ?: config('app.name', 'ExoticCRM')),
        ]);

        Mail::mailer('wallet_smtp')
            ->to($toEmail)
            ->send(new WalletSettingsTestMail(
                (string) ($smtp['from_name'] ?: config('app.name', 'ExoticCRM')),
                (string) ($system['mode'] ?? 'disabled')
            ));

        return [
            'status' => 'success',
            'to_email' => $toEmail,
            'mailer' => 'wallet_smtp',
            'message' => 'Wallet test email sent.',
        ];
    }

    public function verifyWpToCrmBearer(Platform $platform, string $environment, string $plainBearer): bool
    {
        $environment = $this->normalizeEnvironment($environment);
        $credentials = $this->resolvePlatformCredentials($platform);
        $hash = (string) data_get($credentials, "wp_to_crm.{$environment}.bearer_key_hash", '');

        return $hash !== '' && Hash::check($plainBearer, $hash);
    }

    public function wpToCrmHmacSecret(Platform $platform, string $environment): string
    {
        $environment = $this->normalizeEnvironment($environment);
        $credentials = $this->resolvePlatformCredentials($platform);

        return $this->decryptOrEmpty((string) data_get($credentials, "wp_to_crm.{$environment}.hmac_secret_encrypted", ''));
    }

    public function wpToCrmCredentialPair(Platform $platform, string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $credentials = $this->resolvePlatformCredentials($platform);

        return [
            'bearer_key' => $this->decryptOrEmpty((string) data_get($credentials, "wp_to_crm.{$environment}.bearer_key_encrypted", '')),
            'hmac_secret' => $this->decryptOrEmpty((string) data_get($credentials, "wp_to_crm.{$environment}.hmac_secret_encrypted", '')),
        ];
    }

    public function wpCredentialSyncPayload(
        Platform $platform,
        string $environment,
        ?array $credentials = null
    ): array {
        $environment = $this->normalizeEnvironment($environment);
        $credentials = is_array($credentials) ? $credentials : $this->wpToCrmCredentialPair($platform, $environment);

        return [
            'crm_api_base_url' => $this->crmBaseUrl(),
            'wallet_api_base_url' => $this->crmBaseUrl(),
            'platform_id' => (int) $platform->id,
            'bearer_key' => (string) ($credentials['bearer_key'] ?? ''),
            'hmac_secret' => (string) ($credentials['hmac_secret'] ?? ''),
        ];
    }

    private function resolveSystemConfig(): array
    {
        $default = $this->defaultSystemConfig();
        $stored = IntegrationSetting::query()
            ->where('key', self::SYSTEM_SETTINGS_KEY)
            ->value('value');

        $legacy = is_array($stored)
            ? $this->mergeSystemConfig($default, $this->systemConfigFromStorage($stored))
            : $default;

        if (!(bool) config('billing.billing_system_live_read.enabled', false)) {
            return $legacy;
        }

        $setting = BillingSystemSetting::query()
            ->where('scope', 'global')
            ->latest('id')
            ->first();

        return $this->legacyBillingSystemProjector->project($setting, $legacy);
    }

    private function defaultSystemConfig(): array
    {
        return [
            'enabled' => false,
            'mode' => 'disabled',
            'default_currency' => 'KES',
            'max_single_topup_default' => '50000.00',
            'max_wallet_balance_default' => '200000.00',
            'billing_domains' => [
                'sandbox' => '',
                'production' => '',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Exotic Ads Test',
                    'description' => 'Ad credit top-up',
                ],
                'production' => [
                    'business_name' => 'Exotic Ads',
                    'description' => 'Ad credit top-up',
                ],
            ],
            'redirect_delay_seconds' => 3,
            'wallet_refresh_rate_limit_seconds' => 15,
            'wallet_refresh_timeout_seconds' => 15,
            'topup_poll_interval_seconds' => 10,
            'pin_hash' => '',
            'pin_last_updated_at' => null,
            'free_trial_pin_hash' => '',
            'free_trial_pin_last_updated_at' => null,
            'discount_pin_hash' => '',
            'discount_pin_last_updated_at' => null,
            'discount_config' => [
                'max_percentage_by_platform' => [],
            ],
            'smtp' => [
                'enabled' => false,
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_address' => '',
                'from_name' => '',
            ],
        ];
    }

    private function resolvePlatformSettings(Platform $platform): array
    {
        $current = is_array($platform->wallet_settings) ? $platform->wallet_settings : [];
        $legacy = $this->mergePlatformSettings($platform, $this->defaultPlatformSettings($platform), $current);

        if (!$this->shadowReadEnabled()
            || $this->isMarketKilled((int) $platform->id)
            || $this->isSurfaceKilled('wallet_funding')) {
            return $this->normalizeRuntimeWalletSettings($platform, $legacy);
        }

        return $this->normalizeRuntimeWalletSettings(
            $platform,
            $this->legacyBillingConfigProjector->projectWalletSettings($platform, $legacy)
        );
    }

    private function defaultPlatformSettings(Platform $platform): array
    {
        $primaryCurrency = strtoupper((string) ($platform->currency_code ?: 'KES'));

        return [
            'enabled' => false,
            'mode_override' => null,
            'currency_code' => $primaryCurrency,
            'supported_currencies' => [$primaryCurrency],
            'multi_currency_wallet_enabled' => (bool) $platform->multi_currency_wallet_enabled,
            'max_single_topup' => '50000.00',
            'max_wallet_balance' => '200000.00',
            'topup_presets' => ['500.00', '1000.00', '2000.00', '5000.00'],
            'topup_presets_by_currency' => [
                $primaryCurrency => ['500.00', '1000.00', '2000.00', '5000.00'],
            ],
            'limits_by_currency' => [
                $primaryCurrency => [
                    'max_single_topup' => '50000.00',
                    'max_wallet_balance' => '200000.00',
                ],
            ],
            'allow_combined_topup_subscribe' => true,
            'show_refresh_button' => true,
            'recent_transactions_limit' => 10,
            'providers' => [
                'pesapal' => [
                    'enabled' => false,
                    'min_amount' => '100.00',
                    'max_amount' => '150000.00',
                ],
                'paystack' => [
                    'enabled' => false,
                    'min_amount' => '100.00',
                    'max_amount' => '500000.00',
                ],
                'mpesa_stk' => [
                    'enabled' => false,
                    'min_amount' => '100.00',
                    'max_amount' => '150000.00',
                ],
            ],
        ];
    }

    private function normalizeRuntimeWalletSettings(Platform $platform, array $settings): array
    {
        $primaryCurrency = strtoupper((string) ($settings['currency_code'] ?? $platform->primaryCurrency()));
        $effectiveCurrencies = $platform->effectiveCurrencies();
        $configuredSupported = is_array($settings['supported_currencies'] ?? null)
            ? array_values(array_filter(array_map(
                static fn ($value) => strtoupper(trim((string) $value)),
                $settings['supported_currencies']
            )))
            : [];
        $supportedCurrencies = $configuredSupported !== []
            ? array_values(array_intersect($effectiveCurrencies, $configuredSupported))
            : $effectiveCurrencies;

        if ($supportedCurrencies === []) {
            $supportedCurrencies = $effectiveCurrencies !== [] ? $effectiveCurrencies : [$primaryCurrency];
        }

        $topupByCurrency = [];
        $configuredTopupByCurrency = is_array($settings['topup_presets_by_currency'] ?? null)
            ? $settings['topup_presets_by_currency']
            : [];

        foreach ($supportedCurrencies as $currency) {
            $presets = collect($configuredTopupByCurrency[$currency] ?? ($currency === $primaryCurrency ? ($settings['topup_presets'] ?? []) : []))
                ->map(fn ($value) => $this->formatMoneyString($value, null))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $topupByCurrency[$currency] = $presets;
        }

        $limitsByCurrency = [];
        $configuredLimitsByCurrency = is_array($settings['limits_by_currency'] ?? null)
            ? $settings['limits_by_currency']
            : [];

        foreach ($supportedCurrencies as $currency) {
            $limitSource = is_array($configuredLimitsByCurrency[$currency] ?? null)
                ? $configuredLimitsByCurrency[$currency]
                : [];
            $limitsByCurrency[$currency] = [
                'max_single_topup' => $this->formatMoneyString(
                    $limitSource['max_single_topup'] ?? ($currency === $primaryCurrency ? ($settings['max_single_topup'] ?? null) : null),
                    '50000.00'
                ),
                'max_wallet_balance' => $this->formatMoneyString(
                    $limitSource['max_wallet_balance'] ?? ($currency === $primaryCurrency ? ($settings['max_wallet_balance'] ?? null) : null),
                    '200000.00'
                ),
            ];
        }

        $settings['currency_code'] = $primaryCurrency;
        $settings['supported_currencies'] = $supportedCurrencies;
        $settings['multi_currency_wallet_enabled'] = $platform->isMultiCurrencyWalletEnabled();
        $settings['topup_presets_by_currency'] = $topupByCurrency;
        $settings['limits_by_currency'] = $limitsByCurrency;
        $settings['topup_presets'] = $topupByCurrency[$primaryCurrency] ?? ($settings['topup_presets'] ?? []);
        $settings['max_single_topup'] = $limitsByCurrency[$primaryCurrency]['max_single_topup'] ?? ($settings['max_single_topup'] ?? '50000.00');
        $settings['max_wallet_balance'] = $limitsByCurrency[$primaryCurrency]['max_wallet_balance'] ?? ($settings['max_wallet_balance'] ?? '200000.00');

        return $settings;
    }

    private function resolvePlatformCredentials(Platform $platform): array
    {
        $default = $this->defaultPlatformCredentials();

        $stored = IntegrationSetting::query()
            ->where('key', $this->platformCredentialsKey((int) $platform->id))
            ->value('value');

        $legacy = is_array($stored)
            ? $this->mergePlatformCredentials($default, $stored)
            : $default;

        if (!$this->shadowReadEnabled()) {
            return $legacy;
        }

        return $this->legacyBillingConfigProjector->projectPlatformCredentials($platform, $legacy);
    }

    private function defaultPlatformCredentials(): array
    {
        return [
            'wp_to_crm' => [
                'sandbox' => [
                    'bearer_key_hash' => '',
                    'bearer_key_encrypted' => '',
                    'bearer_last_rotated_at' => null,
                    'hmac_secret_encrypted' => '',
                    'hmac_last_rotated_at' => null,
                    'sync' => $this->defaultWpCredentialSyncState(),
                ],
                'production' => [
                    'bearer_key_hash' => '',
                    'bearer_key_encrypted' => '',
                    'bearer_last_rotated_at' => null,
                    'hmac_secret_encrypted' => '',
                    'hmac_last_rotated_at' => null,
                    'sync' => $this->defaultWpCredentialSyncState(),
                ],
            ],
            'pesapal' => [
                'sandbox' => [
                    'consumer_key_encrypted' => '',
                    'consumer_secret_encrypted' => '',
                    'ipn_id' => '',
                ],
                'production' => [
                    'consumer_key_encrypted' => '',
                    'consumer_secret_encrypted' => '',
                    'ipn_id' => '',
                ],
            ],
            'paystack' => [
                'sandbox' => [
                    'public_key_encrypted' => '',
                    'secret_key_encrypted' => '',
                ],
                'production' => [
                    'public_key_encrypted' => '',
                    'secret_key_encrypted' => '',
                ],
            ],
            'pawapay' => [
                'sandbox' => [
                    'api_key_encrypted' => '',
                ],
                'production' => [
                    'api_key_encrypted' => '',
                ],
            ],
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'django_proxy',
                    'payment_service_base_url' => (string) config('services.django.base_url', ''),
                    'organization_code' => '76',
                    'callback_base_url' => '',
                ],
                'production' => [
                    'transport' => 'django_proxy',
                    'payment_service_base_url' => (string) config('services.django.base_url', ''),
                    'organization_code' => '76',
                    'callback_base_url' => '',
                ],
            ],
        ];
    }

    private function mergeSystemConfig(array $base, array $incoming): array
    {
        $merged = $base;

        if (array_key_exists('mode', $incoming)) {
            $mode = strtolower(trim((string) $incoming['mode']));
            if (in_array($mode, self::MODES, true)) {
                $merged['mode'] = $mode;
                $merged['enabled'] = $mode !== 'disabled';
            }
        }

        if (array_key_exists('enabled', $incoming) && !array_key_exists('mode', $incoming)) {
            $merged['enabled'] = (bool) $incoming['enabled'];
            if (!$merged['enabled']) {
                $merged['mode'] = 'disabled';
            } elseif ($merged['mode'] === 'disabled') {
                $merged['mode'] = 'sandbox';
            }
        }

        foreach (['default_currency'] as $key) {
            if (array_key_exists($key, $incoming) && trim((string) $incoming[$key]) !== '') {
                $merged[$key] = strtoupper(trim((string) $incoming[$key]));
            }
        }

        foreach (['max_single_topup_default', 'max_wallet_balance_default'] as $key) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = $this->formatMoneyString($incoming[$key], $merged[$key]);
            }
        }

        foreach (self::ENVIRONMENTS as $environment) {
            $domain = data_get($incoming, "billing_domains.{$environment}");
            if ($domain !== null) {
                $merged['billing_domains'][$environment] = trim((string) $domain);
            }

            $businessName = data_get($incoming, "billing_branding.{$environment}.business_name");
            if ($businessName !== null) {
                $merged['billing_branding'][$environment]['business_name'] = trim((string) $businessName);
            }

            $description = data_get($incoming, "billing_branding.{$environment}.description");
            if ($description !== null) {
                $merged['billing_branding'][$environment]['description'] = trim((string) $description);
            }
        }

        foreach ([
            'redirect_delay_seconds' => 3,
            'wallet_refresh_rate_limit_seconds' => 15,
            'wallet_refresh_timeout_seconds' => 15,
            'topup_poll_interval_seconds' => 10,
        ] as $key => $fallback) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = max(1, (int) $incoming[$key]) ?: $fallback;
            }
        }

        $incomingSmtp = $incoming['smtp'] ?? [];
        if (is_array($incomingSmtp)) {
            if (array_key_exists('enabled', $incomingSmtp)) {
                $merged['smtp']['enabled'] = (bool) $incomingSmtp['enabled'];
            }
            foreach (['host', 'username', 'from_address', 'from_name'] as $key) {
                if (array_key_exists($key, $incomingSmtp)) {
                    $merged['smtp'][$key] = trim((string) $incomingSmtp[$key]);
                }
            }
            if (array_key_exists('port', $incomingSmtp)) {
                $merged['smtp']['port'] = max(1, (int) $incomingSmtp['port']);
            }
            if (array_key_exists('encryption', $incomingSmtp)) {
                $merged['smtp']['encryption'] = trim((string) $incomingSmtp['encryption']);
            }
            if (array_key_exists('password', $incomingSmtp) && trim((string) $incomingSmtp['password']) !== '') {
                $merged['smtp']['password'] = (string) $incomingSmtp['password'];
            }
        }

        if (array_key_exists('pin_hash', $incoming) && trim((string) $incoming['pin_hash']) !== '') {
            $merged['pin_hash'] = (string) $incoming['pin_hash'];
        }
        if (array_key_exists('pin_last_updated_at', $incoming)) {
            $merged['pin_last_updated_at'] = $incoming['pin_last_updated_at']
                ? (string) $incoming['pin_last_updated_at']
                : null;
        }
        if (array_key_exists('free_trial_pin_hash', $incoming) && trim((string) $incoming['free_trial_pin_hash']) !== '') {
            $merged['free_trial_pin_hash'] = (string) $incoming['free_trial_pin_hash'];
        }
        if (array_key_exists('free_trial_pin_last_updated_at', $incoming)) {
            $merged['free_trial_pin_last_updated_at'] = $incoming['free_trial_pin_last_updated_at']
                ? (string) $incoming['free_trial_pin_last_updated_at']
                : null;
        }
        if (array_key_exists('discount_pin_hash', $incoming) && trim((string) $incoming['discount_pin_hash']) !== '') {
            $merged['discount_pin_hash'] = (string) $incoming['discount_pin_hash'];
        }
        if (array_key_exists('discount_pin_last_updated_at', $incoming)) {
            $merged['discount_pin_last_updated_at'] = $incoming['discount_pin_last_updated_at']
                ? (string) $incoming['discount_pin_last_updated_at']
                : null;
        }
        if (array_key_exists('discount_config', $incoming) && is_array($incoming['discount_config'])) {
            $merged['discount_config'] = $this->normalizeDiscountConfig($incoming['discount_config']);
        }

        return $merged;
    }

    private function mergePlatformSettings(Platform $platform, array $base, array $incoming): array
    {
        $merged = $base;

        foreach (['enabled', 'allow_combined_topup_subscribe', 'show_refresh_button'] as $key) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = (bool) $incoming[$key];
            }
        }

        if (array_key_exists('mode_override', $incoming)) {
            $modeOverride = $incoming['mode_override'];
            if ($modeOverride === null || $modeOverride === '' || $modeOverride === 'inherit') {
                $merged['mode_override'] = null;
            } else {
                $modeOverride = strtolower(trim((string) $modeOverride));
                $merged['mode_override'] = in_array($modeOverride, ['sandbox', 'production'], true)
                    ? $modeOverride
                    : $base['mode_override'];
            }
        }

        if (array_key_exists('currency_code', $incoming) && trim((string) $incoming['currency_code']) !== '') {
            $merged['currency_code'] = strtoupper(trim((string) $incoming['currency_code']));
        }

        if (array_key_exists('supported_currencies', $incoming) && is_array($incoming['supported_currencies'])) {
            $supportedCurrencies = collect($incoming['supported_currencies'])
                ->map(fn ($value) => strtoupper(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($supportedCurrencies !== []) {
                $merged['supported_currencies'] = $supportedCurrencies;
            }
        }

        if (array_key_exists('multi_currency_wallet_enabled', $incoming)) {
            $merged['multi_currency_wallet_enabled'] = (bool) $incoming['multi_currency_wallet_enabled'];
        }

        foreach (['max_single_topup', 'max_wallet_balance'] as $key) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = $this->formatMoneyString($incoming[$key], $merged[$key]);
            }
        }

        if (array_key_exists('topup_presets', $incoming) && is_array($incoming['topup_presets'])) {
            $presets = collect($incoming['topup_presets'])
                ->map(fn ($value) => $this->formatMoneyString($value, null))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (!empty($presets)) {
                $merged['topup_presets'] = $presets;
            }
        }

        if (array_key_exists('topup_presets_by_currency', $incoming) && is_array($incoming['topup_presets_by_currency'])) {
            $presetsByCurrency = collect($incoming['topup_presets_by_currency'])
                ->mapWithKeys(function ($values, $currency) {
                    if (!is_array($values)) {
                        return [];
                    }

                    $normalized = collect($values)
                        ->map(fn ($value) => $this->formatMoneyString($value, null))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    return $normalized === []
                        ? []
                        : [strtoupper(trim((string) $currency)) => $normalized];
                })
                ->all();

            if ($presetsByCurrency !== []) {
                $merged['topup_presets_by_currency'] = $presetsByCurrency;
            }
        }

        if (array_key_exists('limits_by_currency', $incoming) && is_array($incoming['limits_by_currency'])) {
            $limitsByCurrency = collect($incoming['limits_by_currency'])
                ->mapWithKeys(function ($values, $currency) {
                    if (!is_array($values)) {
                        return [];
                    }

                    $normalized = [];
                    foreach (['max_single_topup', 'max_wallet_balance'] as $key) {
                        if (array_key_exists($key, $values)) {
                            $normalized[$key] = $this->formatMoneyString($values[$key], null);
                        }
                    }

                    return $normalized === []
                        ? []
                        : [strtoupper(trim((string) $currency)) => $normalized];
                })
                ->all();

            if ($limitsByCurrency !== []) {
                $merged['limits_by_currency'] = $limitsByCurrency;
            }
        }

        if (array_key_exists('recent_transactions_limit', $incoming)) {
            $merged['recent_transactions_limit'] = min(50, max(1, (int) $incoming['recent_transactions_limit']));
        }

        $incomingProviders = $incoming['providers'] ?? [];
        if (is_array($incomingProviders)) {
            foreach ($this->providerKeys() as $provider) {
                $providerInput = $incomingProviders[$provider] ?? null;
                if (!is_array($providerInput)) {
                    continue;
                }

                if (array_key_exists('enabled', $providerInput)) {
                    $merged['providers'][$provider]['enabled'] = (bool) $providerInput['enabled'];
                }
                foreach (['min_amount', 'max_amount'] as $key) {
                    if (array_key_exists($key, $providerInput)) {
                        $merged['providers'][$provider][$key] = $this->formatMoneyString(
                            $providerInput[$key],
                            $merged['providers'][$provider][$key]
                        );
                    }
                }
            }
        }

        if ($merged['currency_code'] === '') {
            $merged['currency_code'] = strtoupper((string) ($platform->currency_code ?: 'KES'));
        }

        $primaryCurrency = strtoupper((string) $merged['currency_code']);
        if ($primaryCurrency !== '') {
            if (is_array($merged['topup_presets'] ?? null) && $merged['topup_presets'] !== []) {
                $merged['topup_presets_by_currency'] = array_merge(
                    is_array($merged['topup_presets_by_currency'] ?? null) ? $merged['topup_presets_by_currency'] : [],
                    [$primaryCurrency => $merged['topup_presets']]
                );
            }

            $merged['limits_by_currency'] = array_merge(
                is_array($merged['limits_by_currency'] ?? null) ? $merged['limits_by_currency'] : [],
                [
                    $primaryCurrency => array_filter([
                        'max_single_topup' => $merged['max_single_topup'] ?? null,
                        'max_wallet_balance' => $merged['max_wallet_balance'] ?? null,
                    ], static fn ($value) => $value !== null && $value !== ''),
                ]
            );
        }

        return $merged;
    }

    private function mergePlatformCredentials(array $base, array $incoming): array
    {
        $merged = $base;

        foreach (self::ENVIRONMENTS as $environment) {
            $wpToCrm = data_get($incoming, "wp_to_crm.{$environment}");
            if (is_array($wpToCrm)) {
                foreach (['bearer_key_hash', 'bearer_key_encrypted', 'bearer_last_rotated_at', 'hmac_secret_encrypted', 'hmac_last_rotated_at'] as $key) {
                    if (array_key_exists($key, $wpToCrm)) {
                        $merged['wp_to_crm'][$environment][$key] = $wpToCrm[$key];
                    }
                }
                if (array_key_exists('sync', $wpToCrm) && is_array($wpToCrm['sync'])) {
                    $merged['wp_to_crm'][$environment]['sync'] = $this->normalizeWpCredentialSyncState($wpToCrm['sync']);
                }
            }

            $pesapal = data_get($incoming, "pesapal.{$environment}");
            if (is_array($pesapal)) {
                if (array_key_exists('consumer_key', $pesapal) && trim((string) $pesapal['consumer_key']) !== '') {
                    $merged['pesapal'][$environment]['consumer_key_encrypted'] = Crypt::encryptString((string) $pesapal['consumer_key']);
                }
                if (array_key_exists('consumer_secret', $pesapal) && trim((string) $pesapal['consumer_secret']) !== '') {
                    $merged['pesapal'][$environment]['consumer_secret_encrypted'] = Crypt::encryptString((string) $pesapal['consumer_secret']);
                }
                if (array_key_exists('consumer_key_encrypted', $pesapal)) {
                    $merged['pesapal'][$environment]['consumer_key_encrypted'] = (string) $pesapal['consumer_key_encrypted'];
                }
                if (array_key_exists('consumer_secret_encrypted', $pesapal)) {
                    $merged['pesapal'][$environment]['consumer_secret_encrypted'] = (string) $pesapal['consumer_secret_encrypted'];
                }
                if (array_key_exists('ipn_id', $pesapal)) {
                    $merged['pesapal'][$environment]['ipn_id'] = trim((string) $pesapal['ipn_id']);
                }
            }

            $paystack = data_get($incoming, "paystack.{$environment}");
            if (is_array($paystack)) {
                if (array_key_exists('public_key', $paystack) && trim((string) $paystack['public_key']) !== '') {
                    $merged['paystack'][$environment]['public_key_encrypted'] = Crypt::encryptString((string) $paystack['public_key']);
                }
                if (array_key_exists('secret_key', $paystack) && trim((string) $paystack['secret_key']) !== '') {
                    $merged['paystack'][$environment]['secret_key_encrypted'] = Crypt::encryptString((string) $paystack['secret_key']);
                }
                if (array_key_exists('public_key_encrypted', $paystack)) {
                    $merged['paystack'][$environment]['public_key_encrypted'] = (string) $paystack['public_key_encrypted'];
                }
                if (array_key_exists('secret_key_encrypted', $paystack)) {
                    $merged['paystack'][$environment]['secret_key_encrypted'] = (string) $paystack['secret_key_encrypted'];
                }
            }

            $pawapay = data_get($incoming, "pawapay.{$environment}");
            if (is_array($pawapay)) {
                if (array_key_exists('api_key', $pawapay) && trim((string) $pawapay['api_key']) !== '') {
                    $merged['pawapay'][$environment]['api_key_encrypted'] = Crypt::encryptString((string) $pawapay['api_key']);
                }
                if (array_key_exists('api_key_encrypted', $pawapay)) {
                    $merged['pawapay'][$environment]['api_key_encrypted'] = (string) $pawapay['api_key_encrypted'];
                }
            }

            $mpesa = data_get($incoming, "mpesa_stk.{$environment}");
            if (is_array($mpesa)) {
                foreach (['payment_service_base_url', 'organization_code', 'callback_base_url'] as $key) {
                    if (array_key_exists($key, $mpesa)) {
                        $merged['mpesa_stk'][$environment][$key] = trim((string) $mpesa[$key]);
                    }
                }
                if (array_key_exists('transport', $mpesa)) {
                    $candidate = trim((string) $mpesa['transport']);
                    $merged['mpesa_stk'][$environment]['transport'] = in_array($candidate, ['django_proxy', 'direct_provider'], true)
                        ? $candidate
                        : $merged['mpesa_stk'][$environment]['transport'];
                }
            }
        }

        return $merged;
    }

    private function systemConfigForStorage(array $config): array
    {
        $stored = $config;
        $stored['pin_hash'] = (string) ($config['pin_hash'] ?? '');
        $stored['pin_last_updated_at'] = $config['pin_last_updated_at'] ?? null;
        $stored['free_trial_pin_hash'] = (string) ($config['free_trial_pin_hash'] ?? '');
        $stored['free_trial_pin_last_updated_at'] = $config['free_trial_pin_last_updated_at'] ?? null;
        $stored['discount_pin_hash'] = (string) ($config['discount_pin_hash'] ?? '');
        $stored['discount_pin_last_updated_at'] = $config['discount_pin_last_updated_at'] ?? null;
        $stored['discount_config'] = $this->normalizeDiscountConfig(
            (array) ($config['discount_config'] ?? [])
        );
        $stored['smtp'] = [
            'enabled' => (bool) ($config['smtp']['enabled'] ?? false),
            'host' => (string) ($config['smtp']['host'] ?? ''),
            'port' => (int) ($config['smtp']['port'] ?? 587),
            'username' => (string) ($config['smtp']['username'] ?? ''),
            'password_encrypted' => !empty($config['smtp']['password'])
                ? Crypt::encryptString((string) $config['smtp']['password'])
                : '',
            'encryption' => (string) ($config['smtp']['encryption'] ?? 'tls'),
            'from_address' => (string) ($config['smtp']['from_address'] ?? ''),
            'from_name' => (string) ($config['smtp']['from_name'] ?? ''),
        ];

        return $stored;
    }

    private function systemConfigFromStorage(array $stored): array
    {
        $config = $stored;
        $config['pin_hash'] = (string) ($stored['pin_hash'] ?? '');
        $config['pin_last_updated_at'] = $stored['pin_last_updated_at'] ?? null;
        $config['free_trial_pin_hash'] = (string) ($stored['free_trial_pin_hash'] ?? '');
        $config['free_trial_pin_last_updated_at'] = $stored['free_trial_pin_last_updated_at'] ?? null;
        $config['discount_pin_hash'] = (string) ($stored['discount_pin_hash'] ?? '');
        $config['discount_pin_last_updated_at'] = $stored['discount_pin_last_updated_at'] ?? null;
        $config['discount_config'] = $this->normalizeDiscountConfig(
            is_array($stored['discount_config'] ?? null) ? $stored['discount_config'] : []
        );
        $smtp = is_array($stored['smtp'] ?? null) ? $stored['smtp'] : [];
        $config['smtp'] = [
            'enabled' => (bool) ($smtp['enabled'] ?? false),
            'host' => (string) ($smtp['host'] ?? ''),
            'port' => (int) ($smtp['port'] ?? 587),
            'username' => (string) ($smtp['username'] ?? ''),
            'password' => !empty($smtp['password_encrypted'])
                ? $this->decryptOrEmpty((string) $smtp['password_encrypted'])
                : '',
            'encryption' => (string) ($smtp['encryption'] ?? 'tls'),
            'from_address' => (string) ($smtp['from_address'] ?? ''),
            'from_name' => (string) ($smtp['from_name'] ?? ''),
        ];

        return $config;
    }

    private function normalizeDiscountConfig(array $config): array
    {
        $normalized = [
            'max_percentage_by_platform' => [],
        ];

        $rawMaxes = is_array($config['max_percentage_by_platform'] ?? null)
            ? $config['max_percentage_by_platform']
            : [];

        foreach ($rawMaxes as $platformId => $value) {
            if (!is_numeric((string) $platformId)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $normalized['max_percentage_by_platform'][(string) ((int) $platformId)] = round(
                max(0, min(99, (float) $value)),
                2
            );
        }

        ksort($normalized['max_percentage_by_platform']);

        return $normalized;
    }

    private function maskPlatformCredentials(array $credentials): array
    {
        $masked = $credentials;

        foreach (self::ENVIRONMENTS as $environment) {
            $masked['wp_to_crm'][$environment] = [
                'bearer_key_configured' => !empty($credentials['wp_to_crm'][$environment]['bearer_key_hash']),
                'bearer_last_rotated_at' => $credentials['wp_to_crm'][$environment]['bearer_last_rotated_at'] ?? null,
                'hmac_configured' => !empty($credentials['wp_to_crm'][$environment]['hmac_secret_encrypted']),
                'hmac_last_rotated_at' => $credentials['wp_to_crm'][$environment]['hmac_last_rotated_at'] ?? null,
                'sync' => $this->normalizeWpCredentialSyncState(
                    (array) ($credentials['wp_to_crm'][$environment]['sync'] ?? [])
                ),
            ];

            $masked['pesapal'][$environment] = [
                'consumer_key_configured' => !empty($credentials['pesapal'][$environment]['consumer_key_encrypted']),
                'consumer_secret_configured' => !empty($credentials['pesapal'][$environment]['consumer_secret_encrypted']),
                'ipn_id' => $credentials['pesapal'][$environment]['ipn_id'] ?? '',
            ];

            $masked['paystack'][$environment] = [
                'public_key_configured' => !empty($credentials['paystack'][$environment]['public_key_encrypted']),
                'secret_key_configured' => !empty($credentials['paystack'][$environment]['secret_key_encrypted']),
            ];

            $masked['pawapay'][$environment] = [
                'api_key_configured' => !empty($credentials['pawapay'][$environment]['api_key_encrypted']),
            ];

            $masked['mpesa_stk'][$environment] = [
                'transport' => (string) ($credentials['mpesa_stk'][$environment]['transport'] ?? 'django_proxy'),
                'payment_service_base_url' => (string) ($credentials['mpesa_stk'][$environment]['payment_service_base_url'] ?? ''),
                'organization_code' => (string) ($credentials['mpesa_stk'][$environment]['organization_code'] ?? ''),
                'callback_base_url' => (string) ($credentials['mpesa_stk'][$environment]['callback_base_url'] ?? ''),
            ];
        }

        return $masked;
    }

    private function runtimePlatformCredentials(array $credentials): array
    {
        $runtime = $credentials;

        foreach (self::ENVIRONMENTS as $environment) {
            $runtime['wp_to_crm'][$environment] = [
                'bearer_key_hash' => (string) ($credentials['wp_to_crm'][$environment]['bearer_key_hash'] ?? ''),
                'bearer_last_rotated_at' => $credentials['wp_to_crm'][$environment]['bearer_last_rotated_at'] ?? null,
                'hmac_secret' => $this->decryptOrEmpty((string) ($credentials['wp_to_crm'][$environment]['hmac_secret_encrypted'] ?? '')),
                'hmac_last_rotated_at' => $credentials['wp_to_crm'][$environment]['hmac_last_rotated_at'] ?? null,
                'sync' => $this->normalizeWpCredentialSyncState(
                    (array) ($credentials['wp_to_crm'][$environment]['sync'] ?? [])
                ),
            ];

            $runtime['pesapal'][$environment] = [
                'consumer_key' => $this->decryptOrEmpty((string) ($credentials['pesapal'][$environment]['consumer_key_encrypted'] ?? '')),
                'consumer_secret' => $this->decryptOrEmpty((string) ($credentials['pesapal'][$environment]['consumer_secret_encrypted'] ?? '')),
                'ipn_id' => (string) ($credentials['pesapal'][$environment]['ipn_id'] ?? ''),
            ];

            $runtime['paystack'][$environment] = [
                'public_key' => $this->decryptOrEmpty((string) ($credentials['paystack'][$environment]['public_key_encrypted'] ?? '')),
                'secret_key' => $this->decryptOrEmpty((string) ($credentials['paystack'][$environment]['secret_key_encrypted'] ?? '')),
            ];

            $runtime['pawapay'][$environment] = [
                'api_key' => $this->decryptOrEmpty((string) ($credentials['pawapay'][$environment]['api_key_encrypted'] ?? '')),
            ];

            $runtime['mpesa_stk'][$environment] = [
                'transport' => (string) ($credentials['mpesa_stk'][$environment]['transport'] ?? 'django_proxy'),
                'payment_service_base_url' => (string) ($credentials['mpesa_stk'][$environment]['payment_service_base_url'] ?? ''),
                'organization_code' => (string) ($credentials['mpesa_stk'][$environment]['organization_code'] ?? ''),
                'callback_base_url' => (string) ($credentials['mpesa_stk'][$environment]['callback_base_url'] ?? ''),
            ];
        }

        return $runtime;
    }

    private function crmBaseUrl(): string
    {
        $url = rtrim((string) config('app.url', ''), '/');
        if ($url === '') {
            $url = rtrim((string) url('/'), '/');
        }

        if ($url === '') {
            throw new InvalidArgumentException('APP_URL must be configured before syncing wallet credentials to WordPress.');
        }

        return $url;
    }

    private function effectiveMode(string $systemMode, array $platformConfig): string
    {
        if ($systemMode === 'disabled') {
            return 'disabled';
        }

        if (!(bool) ($platformConfig['enabled'] ?? false)) {
            return 'disabled';
        }

        return $platformConfig['mode_override'] ?: $systemMode;
    }

    /**
     * Compute a shadow-read diff for a market's wallet settings.
     * Compares what the legacy KV store would serve against what the new billing
     * tables project. Returns null when shadow-read is disabled or no projection
     * data exists for the market.
     *
     * @return array{surface: string, clean: bool, divergent_field_count: int, divergent_fields: list<string>, diff: array<string, array{legacy: mixed, projected: mixed}>, computed_at: string}|null
     */
    public function computeWalletSettingsDiff(Platform $platform): ?array
    {
        if (!$this->shadowReadEnabled()) {
            return null;
        }

        $current = is_array($platform->wallet_settings) ? $platform->wallet_settings : [];
        $legacy = $this->mergePlatformSettings($platform, $this->defaultPlatformSettings($platform), $current);
        $projected = $this->legacyBillingConfigProjector->projectWalletSettings($platform, $legacy);

        $divergent = $this->flatConfigDiff($legacy, $projected);

        return [
            'surface' => 'wallet_settings',
            'clean' => count($divergent) === 0,
            'divergent_field_count' => count($divergent),
            'divergent_fields' => array_keys($divergent),
            'diff' => $divergent,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Recursively diff two config arrays, returning only the divergent leaf paths.
     * Skips arrays deeper than 2 levels and any path matching a sensitive key segment.
     *
     * @param  array<string, mixed>  $a  Legacy config
     * @param  array<string, mixed>  $b  Projected config
     * @return array<string, array{legacy: mixed, projected: mixed}>
     */
    private function flatConfigDiff(array $a, array $b, string $prefix = '', int $depth = 0): array
    {
        static $sensitiveSegments = ['pin_hash', 'free_trial_pin_hash', 'discount_pin_hash',
            'consumer_key', 'consumer_secret', 'secret_key', 'public_key', 'bearer', 'hmac'];

        if ($depth > 2) {
            return [];
        }

        $result = [];
        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));

        foreach ($allKeys as $key) {
            // Skip any path segment that looks like a credential
            foreach ($sensitiveSegments as $seg) {
                if (str_contains((string) $key, $seg)) {
                    continue 2;
                }
            }

            $path = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;
            $aVal = $a[$key] ?? null;
            $bVal = $b[$key] ?? null;

            if (is_array($aVal) && is_array($bVal)) {
                $result = array_merge($result, $this->flatConfigDiff($aVal, $bVal, $path, $depth + 1));
                continue;
            }

            $aNorm = is_scalar($aVal) ? (string) $aVal : null;
            $bNorm = is_scalar($bVal) ? (string) $bVal : null;

            if ($aNorm !== $bNorm) {
                $result[$path] = ['legacy' => $aVal, 'projected' => $bVal];
            }
        }

        return $result;
    }

    private function shadowReadEnabled(): bool
    {
        return (bool) config('billing.shadow_read.enabled', false);
    }

    public function currentKillSwitches(): array
    {
        return $this->getKillSwitches();
    }

    public function saveKillSwitches(array $marketIds, array $surfaces): void
    {
        IntegrationSetting::updateOrCreate(
            ['key' => self::KILL_SWITCHES_KEY],
            ['value' => [
                'market_ids' => array_values(array_unique($marketIds)),
                'surfaces'   => array_values(array_unique($surfaces)),
            ]]
        );
    }

    private function getKillSwitches(): array
    {
        $stored = IntegrationSetting::query()
            ->where('key', self::KILL_SWITCHES_KEY)
            ->value('value');

        return is_array($stored) ? $stored : ['market_ids' => [], 'surfaces' => []];
    }

    private function isMarketKilled(int $marketId): bool
    {
        return in_array($marketId, $this->getKillSwitches()['market_ids'] ?? [], strict: true);
    }

    private function isSurfaceKilled(string $surface): bool
    {
        return in_array($surface, $this->getKillSwitches()['surfaces'] ?? [], strict: true);
    }

    private function defaultWpCredentialSyncState(): array
    {
        return [
            'last_attempt_at' => null,
            'last_status' => 'unknown',
            'last_synced_at' => null,
            'last_reason' => null,
            'last_error' => null,
            'last_credential_action' => null,
            'last_result' => null,
        ];
    }

    private function normalizeWpCredentialSyncState(array $state): array
    {
        $default = $this->defaultWpCredentialSyncState();

        return [
            'last_attempt_at' => $state['last_attempt_at'] ?? $default['last_attempt_at'],
            'last_status' => (string) ($state['last_status'] ?? $default['last_status']),
            'last_synced_at' => $state['last_synced_at'] ?? $default['last_synced_at'],
            'last_reason' => $state['last_reason'] ?? $default['last_reason'],
            'last_error' => $state['last_error'] ?? $default['last_error'],
            'last_credential_action' => $state['last_credential_action'] ?? $default['last_credential_action'],
            'last_result' => is_array($state['last_result'] ?? null) ? $state['last_result'] : $default['last_result'],
        ];
    }

    private function testPesapal(array $credentials, string $environment): array
    {
        $consumerKey = $this->decryptOrEmpty((string) ($credentials['pesapal'][$environment]['consumer_key_encrypted'] ?? ''));
        $consumerSecret = $this->decryptOrEmpty((string) ($credentials['pesapal'][$environment]['consumer_secret_encrypted'] ?? ''));

        if ($consumerKey === '' || $consumerSecret === '') {
            throw new InvalidArgumentException('Pesapal credentials are incomplete for this environment.');
        }

        $url = $environment === 'sandbox'
            ? 'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken'
            : 'https://pay.pesapal.com/v3/api/Auth/RequestToken';

        $response = Http::timeout(15)->post($url, [
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
        ]);

        return [
            'provider' => 'pesapal',
            'environment' => $environment,
            'ok' => $response->successful(),
            'status' => $response->successful() ? 'success' : 'failed',
            'http_status' => $response->status(),
            'message' => $response->successful()
                ? 'Pesapal authentication test passed.'
                : 'Pesapal authentication test failed.',
            'provider_response' => $response->json(),
        ];
    }

    private function testPaystack(array $credentials, string $environment): array
    {
        $secretKey = $this->decryptOrEmpty((string) ($credentials['paystack'][$environment]['secret_key_encrypted'] ?? ''));

        if ($secretKey === '') {
            throw new InvalidArgumentException('Paystack secret key is incomplete for this environment.');
        }

        $response = Http::timeout(15)
            ->withToken($secretKey)
            ->get('https://api.paystack.co/bank', [
                'perPage' => 1,
            ]);

        return [
            'provider' => 'paystack',
            'environment' => $environment,
            'ok' => $response->successful(),
            'status' => $response->successful() ? 'success' : 'failed',
            'http_status' => $response->status(),
            'message' => $response->successful()
                ? 'Paystack credentials test passed.'
                : 'Paystack credentials test failed.',
            'provider_response' => $response->json(),
        ];
    }

    private function testMpesaStk(array $credentials, string $environment): array
    {
        $config = $credentials['mpesa_stk'][$environment] ?? [];
        $transport = trim((string) ($config['transport'] ?? 'django_proxy'));
        $paymentServiceBaseUrl = trim((string) ($config['payment_service_base_url'] ?? ''));
        $callbackBaseUrl = trim((string) ($config['callback_base_url'] ?? ''));

        if ($transport === 'direct_provider') {
            $kopokopoConfig = $this->kopokopoConfigService->currentConfig(masked: false);
            $kopokopoBaseUrl = trim((string) ($kopokopoConfig['base_url'] ?? ''));
            $configured = $this->kopokopoConfigService->credentialsReady($kopokopoConfig);
            $status = (string) ($kopokopoConfig['status'] ?? ($configured ? 'success' : 'failed'));
            $message = $configured
                ? (($kopokopoConfig['enabled'] ?? true)
                    ? 'Direct KopoKopo M-Pesa configuration is present.'
                    : 'Direct KopoKopo M-Pesa configuration is stored but currently disabled.')
                : 'Direct KopoKopo M-Pesa configuration is incomplete.';

            return [
                'provider' => 'mpesa_stk',
                'environment' => $environment,
                'transport' => $transport,
                'ok' => $configured,
                'status' => $status,
                'http_status' => $configured ? 200 : 422,
                'message' => $message,
                'provider_response' => [
                    'base_url' => $kopokopoBaseUrl,
                    'till_number' => (string) ($kopokopoConfig['till_number'] ?? ''),
                ],
            ];
        }

        if ($transport !== 'django_proxy') {
            throw new InvalidArgumentException('Unsupported M-Pesa STK transport.');
        }

        if ($paymentServiceBaseUrl === '') {
            throw new InvalidArgumentException('M-Pesa STK payment service base URL is missing.');
        }

        $response = Http::timeout(10)->get($paymentServiceBaseUrl);
        $body = strtolower(trim((string) $response->body()));
        $isSuspended = str_contains($body, 'suspendedpage')
            || str_contains($body, 'account has been suspended');
        $callbackResult = null;
        if ($callbackBaseUrl !== '') {
            $callbackResponse = Http::timeout(10)->get($callbackBaseUrl);
            $callbackResult = [
                'url' => $callbackBaseUrl,
                'ok' => $callbackResponse->successful(),
                'http_status' => $callbackResponse->status(),
            ];
        }

        $ok = $response->successful() && !$isSuspended;

        return [
            'provider' => 'mpesa_stk',
            'environment' => $environment,
            'transport' => $transport,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'failed',
            'http_status' => $response->status(),
            'message' => $isSuspended
                ? 'M-Pesa STK transport points to a suspended host.'
                : ($ok
                    ? 'M-Pesa STK transport reachability test passed.'
                    : ($response->status() === 522 || $response->status() === 524 || str_contains($body, 'connection timed out')
                        ? 'M-Pesa STK transport timed out at the upstream host.'
                        : 'M-Pesa STK transport reachability test failed.')),
            'provider_response' => [
                'payment_service_base_url' => $paymentServiceBaseUrl,
                'callback_base_url' => $callbackBaseUrl,
                'callback_check' => $callbackResult,
            ],
        ];
    }

    private function decryptOrEmpty(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatMoneyString(mixed $value, ?string $fallback): ?string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function platformCredentialsKey(int $platformId): string
    {
        return self::PLATFORM_CREDENTIALS_KEY_PREFIX . $platformId;
    }

    private function normalizeProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));
        if (!in_array($normalized, $this->providerKeys(), true)) {
            throw new InvalidArgumentException('Provider must be pesapal, paystack, or mpesa_stk.');
        }

        return $normalized;
    }

    private function normalizeEnvironment(string $environment): string
    {
        $normalized = strtolower(trim($environment));
        if (!in_array($normalized, self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('Environment must be sandbox or production.');
        }

        return $normalized;
    }
}
