<?php

namespace App\Services;

use App\Mail\WalletSettingsTestMail;
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
    public const MODES = ['disabled', 'sandbox', 'production'];
    public const ENVIRONMENTS = ['sandbox', 'production'];
    public const PROVIDERS = ['pesapal', 'paystack', 'mpesa_stk'];

    public function currentSystemConfig(bool $masked = true): array
    {
        $config = $this->resolveSystemConfig();

        if (!$masked) {
            return $config;
        }

        $maskedConfig = $config;
        $maskedConfig['smtp']['password_configured'] = !empty($config['smtp']['password']);
        $maskedConfig['smtp']['password'] = '';

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
            $current['wp_to_crm'][$environment]['bearer_last_rotated_at'] = now()->toIso8601String();
            $revealed['bearer_key'] = $plainBearer;
        }

        if (in_array($credential, ['hmac', 'both'], true)) {
            $plainHmac = Str::random(64);
            $current['wp_to_crm'][$environment]['hmac_secret_encrypted'] = Crypt::encryptString($plainHmac);
            $current['wp_to_crm'][$environment]['hmac_last_rotated_at'] = now()->toIso8601String();
            $revealed['hmac_secret'] = $plainHmac;
        }

        IntegrationSetting::query()->updateOrCreate(
            ['key' => $this->platformCredentialsKey((int) $platform->id)],
            [
                'value' => $current,
                'updated_by' => $updatedBy,
            ]
        );

        return [
            'environment' => $environment,
            'credential' => $credential,
            'revealed' => $revealed,
            'platform_wallet' => $this->currentPlatformConfig($platform, masked: true),
        ];
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

    private function resolveSystemConfig(): array
    {
        $default = $this->defaultSystemConfig();

        $stored = IntegrationSetting::query()
            ->where('key', self::SYSTEM_SETTINGS_KEY)
            ->value('value');

        if (!is_array($stored)) {
            return $default;
        }

        return $this->mergeSystemConfig($default, $this->systemConfigFromStorage($stored));
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

        return $this->mergePlatformSettings($platform, $this->defaultPlatformSettings($platform), $current);
    }

    private function defaultPlatformSettings(Platform $platform): array
    {
        return [
            'enabled' => false,
            'mode_override' => null,
            'currency_code' => strtoupper((string) ($platform->currency_code ?: 'KES')),
            'max_single_topup' => '50000.00',
            'max_wallet_balance' => '200000.00',
            'topup_presets' => ['500.00', '1000.00', '2000.00', '5000.00'],
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

    private function resolvePlatformCredentials(Platform $platform): array
    {
        $default = $this->defaultPlatformCredentials();

        $stored = IntegrationSetting::query()
            ->where('key', $this->platformCredentialsKey((int) $platform->id))
            ->value('value');

        if (!is_array($stored)) {
            return $default;
        }

        return $this->mergePlatformCredentials($default, $stored);
    }

    private function defaultPlatformCredentials(): array
    {
        return [
            'wp_to_crm' => [
                'sandbox' => [
                    'bearer_key_hash' => '',
                    'bearer_last_rotated_at' => null,
                    'hmac_secret_encrypted' => '',
                    'hmac_last_rotated_at' => null,
                ],
                'production' => [
                    'bearer_key_hash' => '',
                    'bearer_last_rotated_at' => null,
                    'hmac_secret_encrypted' => '',
                    'hmac_last_rotated_at' => null,
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

        if (array_key_exists('recent_transactions_limit', $incoming)) {
            $merged['recent_transactions_limit'] = min(50, max(1, (int) $incoming['recent_transactions_limit']));
        }

        $incomingProviders = $incoming['providers'] ?? [];
        if (is_array($incomingProviders)) {
            foreach (self::PROVIDERS as $provider) {
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

        return $merged;
    }

    private function mergePlatformCredentials(array $base, array $incoming): array
    {
        $merged = $base;

        foreach (self::ENVIRONMENTS as $environment) {
            $wpToCrm = data_get($incoming, "wp_to_crm.{$environment}");
            if (is_array($wpToCrm)) {
                foreach (['bearer_key_hash', 'bearer_last_rotated_at', 'hmac_secret_encrypted', 'hmac_last_rotated_at'] as $key) {
                    if (array_key_exists($key, $wpToCrm)) {
                        $merged['wp_to_crm'][$environment][$key] = $wpToCrm[$key];
                    }
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

    private function maskPlatformCredentials(array $credentials): array
    {
        $masked = $credentials;

        foreach (self::ENVIRONMENTS as $environment) {
            $masked['wp_to_crm'][$environment] = [
                'bearer_key_configured' => !empty($credentials['wp_to_crm'][$environment]['bearer_key_hash']),
                'bearer_last_rotated_at' => $credentials['wp_to_crm'][$environment]['bearer_last_rotated_at'] ?? null,
                'hmac_configured' => !empty($credentials['wp_to_crm'][$environment]['hmac_secret_encrypted']),
                'hmac_last_rotated_at' => $credentials['wp_to_crm'][$environment]['hmac_last_rotated_at'] ?? null,
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

            $masked['mpesa_stk'][$environment] = [
                'transport' => (string) ($credentials['mpesa_stk'][$environment]['transport'] ?? 'django_proxy'),
                'payment_service_base_url' => (string) ($credentials['mpesa_stk'][$environment]['payment_service_base_url'] ?? ''),
                'organization_code' => (string) ($credentials['mpesa_stk'][$environment]['organization_code'] ?? ''),
                'callback_base_url' => (string) ($credentials['mpesa_stk'][$environment]['callback_base_url'] ?? ''),
            ];
        }

        return $masked;
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

        if ($transport !== 'django_proxy') {
            throw new InvalidArgumentException('Only django_proxy transport is supported in the current wallet MVP.');
        }

        if ($paymentServiceBaseUrl === '') {
            throw new InvalidArgumentException('M-Pesa STK payment service base URL is missing.');
        }

        $response = Http::timeout(10)->get($paymentServiceBaseUrl);
        $callbackResult = null;
        if ($callbackBaseUrl !== '') {
            $callbackResponse = Http::timeout(10)->get($callbackBaseUrl);
            $callbackResult = [
                'url' => $callbackBaseUrl,
                'ok' => $callbackResponse->successful(),
                'http_status' => $callbackResponse->status(),
            ];
        }

        return [
            'provider' => 'mpesa_stk',
            'environment' => $environment,
            'transport' => $transport,
            'ok' => $response->successful(),
            'status' => $response->successful() ? 'success' : 'failed',
            'http_status' => $response->status(),
            'message' => $response->successful()
                ? 'M-Pesa STK transport reachability test passed.'
                : 'M-Pesa STK transport reachability test failed.',
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
        if (!in_array($normalized, self::PROVIDERS, true)) {
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
