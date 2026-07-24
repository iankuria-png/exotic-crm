<?php

namespace App\Services;

use App\Models\Client;
use App\Models\IntegrationSetting;
use App\Models\SmsLog;
use App\Services\Sms\AfricasTalkingSmsProvider;
use App\Services\Sms\BalanceAwareSmsProvider;
use App\Services\Sms\BriqSmsProvider;
use App\Services\Sms\GhanaBulkSmsProvider;
use App\Services\Sms\KullSmsProvider;
use App\Services\Sms\LegacyGatewaySmsProvider;
use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\UgandaBulkSmsProvider;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    private const SMS_SETTINGS_KEY = 'sms_provider_config';

    private const SECRET_MASK = '••••••••';

    /** @var array<string, SmsProviderInterface> */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            'legacy_gateway' => new LegacyGatewaySmsProvider(),
            'africastalking' => new AfricasTalkingSmsProvider(),
            'briq' => new BriqSmsProvider(),
            'uganda_bulk_sms' => new UgandaBulkSmsProvider(),
            'kullsms' => new KullSmsProvider(),
            'ghana_bulk_sms' => new GhanaBulkSmsProvider(),
        ];
    }

    /**
     * Provider metadata for the settings UI: id, label, and credential-field
     * descriptors. Drives the dynamic SMS routing form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function smsProviderOptions(): array
    {
        return collect($this->providers)
            ->map(fn (SmsProviderInterface $provider) => [
                'id' => $provider->id(),
                'label' => $provider->label(),
                'fields' => $provider->credentialFields(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string> the registered provider ids
     */
    public function smsProviderIds(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Whether the given provider has complete credentials in the resolved config
     * (optionally with a market override applied). Uses unmasked config.
     */
    public function providerConfigured(string $providerId, ?int $platformId = null): bool
    {
        $provider = $this->providers[$providerId] ?? null;
        if (!$provider) {
            return false;
        }

        $config = $this->resolveMarketConfig($this->resolveSmsConfig(), $platformId);
        $providerConfig = is_array($config[$providerId] ?? null) ? $config[$providerId] : [];

        return $provider->configured($providerConfig);
    }

    public function sendSmsToClient(Client $client, string $message, array $context = []): array
    {
        return $this->sendSms($client->phone_normalized, $message, array_merge([
            'client_id' => $client->id,
            'platform_id' => $client->platform_id,
        ], $context));
    }

    public function sendSms(?string $phone, string $message, array $context = []): array
    {
        $smsConfig = $this->resolveSmsConfig();
        $platformId = isset($context['platform_id']) ? (int) $context['platform_id'] : null;
        $smsConfig = $this->resolveMarketConfig($smsConfig, $platformId);
        $prefix = (string) ($context['phone_prefix'] ?? $smsConfig['default_prefix'] ?? '254');
        $normalizedPhone = $this->normalizePhone($phone, $prefix);

        if (!$normalizedPhone) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => null,
                'phone' => null,
                'provider_response' => 'Missing or invalid phone number',
            ];
        }

        $enabled = (bool) ($smsConfig['enabled'] ?? false);

        if (!$enabled) {
            Log::info('SMS dispatch skipped: SMS disabled in configuration.', [
                'phone' => $normalizedPhone,
                'context' => $context,
            ]);

            return [
                'success' => true,
                'status' => 'disabled',
                'provider' => null,
                'phone' => $normalizedPhone,
                'provider_response' => 'SMS dispatch disabled (SMS_ENABLED=false)',
            ];
        }

        // Per-dispatch controls (used mainly by the settings Test Dispatch panel):
        // skip_fallback runs only the chosen provider; trace attaches a redacted
        // diagnostics record of each attempt.
        $skipFallback = (bool) ($context['skip_fallback'] ?? false);
        $collectTrace = (bool) ($context['trace'] ?? false);
        $attempts = [];

        $activeProviderId = (string) ($context['sms_provider'] ?? $smsConfig['active_provider'] ?? 'legacy_gateway');
        $activeResult = $this->dispatchViaProvider($activeProviderId, $normalizedPhone, $message, $smsConfig, $context);
        if ($collectTrace) {
            $attempts[] = $this->traceAttempt($activeProviderId, 'active', $activeResult, $smsConfig);
        }

        $fallbackProviderId = (string) ($smsConfig['fallback_provider'] ?? '');
        $fallbackConfigured = $fallbackProviderId !== ''
            && $fallbackProviderId !== 'none'
            && $fallbackProviderId !== $activeProviderId;

        if ($activeResult['success']) {
            $out = array_merge($activeResult, [
                'phone' => $normalizedPhone,
                'fallback_attempted' => false,
            ]);
        } elseif ($skipFallback || !$fallbackConfigured) {
            $out = array_merge($activeResult, [
                'phone' => $normalizedPhone,
                'fallback_attempted' => false,
                'fallback_skipped' => $skipFallback && $fallbackConfigured,
            ]);
        } else {
            $fallbackResult = $this->dispatchViaProvider($fallbackProviderId, $normalizedPhone, $message, $smsConfig, $context);
            if ($collectTrace) {
                $attempts[] = $this->traceAttempt($fallbackProviderId, 'fallback', $fallbackResult, $smsConfig);
            }

            if ($fallbackResult['success']) {
                $out = array_merge($fallbackResult, [
                    'phone' => $normalizedPhone,
                    'fallback_attempted' => true,
                    'fallback_from' => $activeProviderId,
                ]);
            } else {
                $out = array_merge($activeResult, [
                    'phone' => $normalizedPhone,
                    'fallback_attempted' => true,
                    'fallback_provider' => $fallbackProviderId,
                    'fallback_response' => $fallbackResult['provider_response'] ?? 'Fallback failed',
                ]);
            }
        }

        if ($collectTrace) {
            $out['trace'] = [
                'normalized_phone' => $normalizedPhone,
                'requested_provider' => $activeProviderId,
                'active_provider' => (string) ($smsConfig['active_provider'] ?? ''),
                'fallback_provider' => $fallbackProviderId !== '' ? $fallbackProviderId : 'none',
                'skip_fallback' => $skipFallback,
                'attempts' => $attempts,
            ];
        }

        // Record every routed dispatch for the Recent Dispatches surface. Callers
        // that keep their own sms_logs row (e.g. AI briefings) opt out via
        // context.log_dispatch=false. Never let logging break a send.
        if ($context['log_dispatch'] ?? true) {
            $this->recordDispatch($out, $message, $context, $platformId);
        }

        return $out;
    }

    private function recordDispatch(array $result, string $message, array $context, ?int $platformId): void
    {
        try {
            $response = $result['provider_response'] ?? null;
            if (!is_string($response)) {
                $response = json_encode($response);
            }

            SmsLog::create([
                'phone' => $result['phone'] ?? null,
                'message' => mb_substr($message, 0, 2000),
                'status' => ($result['success'] ?? false) ? 'sent' : 'failed',
                'response' => $response !== null ? mb_substr($response, 0, 255) : null,
                'result_code' => $result['actual_success_code'] ?? null,
                'provider' => $result['provider'] ?? null,
                'platform_id' => $platformId,
                'http_code' => is_numeric($result['http_code'] ?? null) ? (int) $result['http_code'] : null,
                'purpose' => isset($context['purpose']) ? mb_substr((string) $context['purpose'], 0, 64) : null,
                'fallback_used' => (bool) ($result['fallback_attempted'] ?? false),
                'sent_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to record SMS dispatch log', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Build a redacted, structured record of a single dispatch attempt for the
     * on-demand diagnostics trace. Secrets are never included.
     */
    private function traceAttempt(string $providerId, string $role, array $result, array $smsConfig): array
    {
        $provider = $this->providers[$providerId] ?? null;
        $providerConfig = is_array($smsConfig[$providerId] ?? null) ? $smsConfig[$providerId] : [];

        $response = $result['provider_response'] ?? null;
        if (is_string($response) && strlen($response) > 500) {
            $response = substr($response, 0, 500) . '…';
        }

        return [
            'provider' => $providerId,
            'provider_label' => $provider?->label() ?? $providerId,
            'role' => $role, // active | fallback
            'configured' => $provider ? $provider->configured($providerConfig) : false,
            'request' => $this->redactProviderConfig($providerId, $providerConfig),
            'success' => (bool) ($result['success'] ?? false),
            'status' => $result['status'] ?? null,
            'http_code' => $result['http_code'] ?? null,
            'expected_success_code' => $result['expected_success_code'] ?? null,
            'actual_success_code' => $result['actual_success_code'] ?? null,
            'provider_response' => $response,
        ];
    }

    /**
     * A safe echo of the config a provider would use: non-secret fields verbatim,
     * secret fields reduced to a set/unset flag.
     */
    private function redactProviderConfig(string $providerId, array $providerConfig): array
    {
        $provider = $this->providers[$providerId] ?? null;
        if (!$provider) {
            return [];
        }

        $out = [];
        foreach ($provider->credentialFields() as $field) {
            $key = $field['key'] ?? null;
            if (!$key) {
                continue;
            }

            if ($this->isSecretField($field)) {
                $out[$key] = !empty($providerConfig[$key]) ? 'set' : 'not set';
            } else {
                $out[$key] = $providerConfig[$key] ?? ($field['default'] ?? null);
            }
        }

        return $out;
    }

    public function currentSmsConfig(bool $masked = true): array
    {
        $config = $this->resolveSmsConfig();
        if (!$masked) {
            return $config;
        }

        $config = $this->maskProviderSecrets($config);

        $maskedMarkets = [];
        foreach ($config['markets'] ?? [] as $platformId => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $maskedMarkets[(string) $platformId] = $this->maskProviderSecrets($entry);
        }
        $config['markets'] = $maskedMarkets;

        return $config;
    }

    /**
     * Replace secret credential values (per each provider's credentialFields)
     * with a mask and add a "{key}_configured" flag so the UI can show whether a
     * secret is set without exposing it. Operates on a flat config where each
     * provider's credentials live at $config[$providerId] — used for both the
     * global config and a single market entry.
     */
    private function maskProviderSecrets(array $config): array
    {
        foreach ($this->providers as $providerId => $provider) {
            if (!isset($config[$providerId]) || !is_array($config[$providerId])) {
                continue;
            }

            foreach ($provider->credentialFields() as $field) {
                $key = $field['key'] ?? null;
                if (!$key || !$this->isSecretField($field)) {
                    continue;
                }

                $configuredKey = "{$key}_configured";
                if (!empty($config[$providerId][$key])) {
                    $config[$providerId][$key] = self::SECRET_MASK;
                    $config[$providerId][$configuredKey] = true;
                } else {
                    $config[$providerId][$configuredKey] = false;
                }
            }
        }

        return $config;
    }

    private function isSecretField(array $field): bool
    {
        return !empty($field['secret']) || ($field['type'] ?? '') === 'password';
    }

    public function saveSmsConfig(array $payload, ?int $updatedBy = null): array
    {
        $current = $this->resolveSmsConfig();
        $merged = $this->mergeSmsConfig($current, $payload);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SMS_SETTINGS_KEY],
            [
                'value' => $merged,
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentSmsConfig(masked: true);
    }

    private function resolveSmsConfig(): array
    {
        $default = [
            'enabled' => (bool) config('services.sms.enabled', false),
            'active_provider' => (string) config('services.sms.active_provider', 'legacy_gateway'),
            'fallback_provider' => (string) config('services.sms.fallback_provider', 'none'),
            'default_prefix' => (string) config('services.sms.default_prefix', '254'),
            'legacy_gateway' => [
                'gateway_url' => (string) config('services.sms.gateway_url'),
                'org_code' => (string) config('services.sms.org_code', '76'),
            ],
            'africastalking' => [
                'endpoint' => (string) config('services.africastalking.endpoint', 'https://api.africastalking.com/version1/messaging'),
                'username' => (string) config('services.africastalking.username', ''),
                'api_key' => (string) config('services.africastalking.api_key', ''),
                'sender_id' => (string) config('services.africastalking.sender_id', ''),
            ],
            'briq' => [
                'base_url' => (string) config('services.briq.base_url', 'https://karibu.briq.tz'),
                'api_key' => (string) config('services.briq.api_key', ''),
                'sender_id' => (string) config('services.briq.sender_id', ''),
            ],
            'uganda_bulk_sms' => [
                'base_url' => (string) config('services.uganda_bulk_sms.base_url', 'http://bluesmsuganda.com/api-sub.php'),
                'username' => (string) config('services.uganda_bulk_sms.username', ''),
                'password' => (string) config('services.uganda_bulk_sms.password', ''),
                'sender_id' => (string) config('services.uganda_bulk_sms.sender_id', ''),
                'success_code' => (string) config('services.uganda_bulk_sms.success_code', '1701'),
            ],
            'kullsms' => [
                'base_url' => (string) config('services.kullsms.base_url', 'https://kullsms.com/customer/api/'),
                'username' => (string) config('services.kullsms.username', ''),
                'password' => (string) config('services.kullsms.password', ''),
                'sender_id' => (string) config('services.kullsms.sender_id', ''),
                'success_code' => (string) config('services.kullsms.success_code', '1701'),
            ],
            'ghana_bulk_sms' => [
                'base_url' => (string) config('services.ghana_bulk_sms.base_url', 'https://clientlogin.bulksmsgh.com/smsapi'),
                'api_key' => (string) config('services.ghana_bulk_sms.api_key', ''),
                'sender_id' => (string) config('services.ghana_bulk_sms.sender_id', ''),
                'success_code' => (string) config('services.ghana_bulk_sms.success_code', '1000'),
            ],
            'markets' => [],
        ];

        $stored = IntegrationSetting::query()
            ->where('key', self::SMS_SETTINGS_KEY)
            ->value('value');

        if (is_array($stored)) {
            return $this->mergeSmsConfig($default, $stored);
        }

        return $default;
    }

    private function mergeSmsConfig(array $base, array $incoming): array
    {
        $merged = $base;
        $merged['enabled'] = array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : $base['enabled'];
        $merged['active_provider'] = (string) ($incoming['active_provider'] ?? $base['active_provider']);
        $merged['fallback_provider'] = (string) ($incoming['fallback_provider'] ?? $base['fallback_provider']);
        $merged['default_prefix'] = (string) ($incoming['default_prefix'] ?? $base['default_prefix']);

        // Global per-provider credentials live at the top level, keyed by
        // provider id (e.g. $config['africastalking']). Merge each registered
        // provider's incoming credentials field-by-field.
        foreach ($this->providers as $providerId => $provider) {
            $incomingProvider = $incoming[$providerId] ?? null;
            if (!is_array($incomingProvider)) {
                continue;
            }

            $existing = is_array($merged[$providerId] ?? null) ? $merged[$providerId] : [];
            $merged[$providerId] = $this->mergeProviderCredentials($existing, $incomingProvider, $provider);
        }

        if (array_key_exists('markets', $incoming)) {
            $incomingMarkets = is_array($incoming['markets']) ? $incoming['markets'] : [];
            $existingMarkets = is_array($base['markets'] ?? null) ? $base['markets'] : [];
            $merged['markets'] = [];

            foreach ($incomingMarkets as $platformId => $marketData) {
                if (!is_array($marketData)) {
                    continue;
                }

                $existingMarket = $existingMarkets[(string) $platformId] ?? $existingMarkets[$platformId] ?? $this->defaultMarketConfig();
                if (!is_array($existingMarket)) {
                    $existingMarket = $this->defaultMarketConfig();
                }

                $merged['markets'][(string) $platformId] = $this->mergeMarketConfig($existingMarket, $marketData);
            }
        } else {
            $merged['markets'] = is_array($base['markets'] ?? null) ? $base['markets'] : [];
        }

        return $merged;
    }

    private function defaultMarketConfig(): array
    {
        return [
            'active_provider' => null,
            'fallback_provider' => null,
        ];
    }

    private function mergeMarketConfig(array $base, array $incoming): array
    {
        $merged = [
            'active_provider' => $base['active_provider'] ?? null,
            'fallback_provider' => $base['fallback_provider'] ?? null,
        ];

        // Carry over any existing per-provider credential blocks from the stored
        // market config so untouched providers survive a partial save.
        foreach ($this->providers as $providerId => $provider) {
            if (is_array($base[$providerId] ?? null)) {
                $merged[$providerId] = $base[$providerId];
            }
        }

        if (array_key_exists('active_provider', $incoming)) {
            $merged['active_provider'] = filled($incoming['active_provider']) ? (string) $incoming['active_provider'] : null;
        }

        if (array_key_exists('fallback_provider', $incoming)) {
            $merged['fallback_provider'] = filled($incoming['fallback_provider']) ? (string) $incoming['fallback_provider'] : null;
        }

        foreach ($this->providers as $providerId => $provider) {
            $incomingProvider = $incoming[$providerId] ?? null;
            if (!is_array($incomingProvider)) {
                continue;
            }

            $existing = is_array($merged[$providerId] ?? null) ? $merged[$providerId] : [];
            $mergedProvider = $this->mergeProviderCredentials($existing, $incomingProvider, $provider);

            if ($mergedProvider === []) {
                unset($merged[$providerId]);
            } else {
                $merged[$providerId] = $mergedProvider;
            }
        }

        return $merged;
    }

    /**
     * Field-aware merge of one provider's credentials. Trims incoming values,
     * never overwrites a stored secret with a blank or masked value, and drops
     * keys that are cleared to empty.
     */
    private function mergeProviderCredentials(array $base, array $incoming, SmsProviderInterface $provider): array
    {
        $merged = $base;

        foreach ($provider->credentialFields() as $field) {
            $key = $field['key'] ?? null;
            if (!$key || !array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];
            $value = is_string($value) ? trim($value) : $value;

            if ($this->isSecretField($field)) {
                // Keep the stored secret when the UI submits it blank or masked.
                if ($value === null || $value === '' || $value === self::SECRET_MASK) {
                    continue;
                }
            }

            if ($value === null || $value === '') {
                unset($merged[$key]);
                continue;
            }

            $merged[$key] = $value;
        }

        // Non-credential per-provider config: cost per SMS, used to estimate
        // spend in the Providers tab. Stored alongside the credentials block.
        if (array_key_exists('unit_cost', $incoming)) {
            $unitCost = $incoming['unit_cost'];
            if ($unitCost === null || $unitCost === '' || !is_numeric($unitCost)) {
                unset($merged['unit_cost']);
            } else {
                $merged['unit_cost'] = round((float) $unitCost, 4);
            }
        }

        return $merged;
    }

    /**
     * Per-provider balance + estimated spend for the Providers tab. Live balance
     * is fetched only for providers that expose an API (others → null, "check
     * portal"); spend is estimated from sms_logs volume × the configured unit
     * cost. All defensive: a provider failure never breaks the response.
     *
     * @return array<int, array<string, mixed>>
     */
    public function providerBalancesAndCosts(int $days = 30): array
    {
        $config = $this->resolveSmsConfig();
        $since = now()->subDays(max(1, $days));

        return collect($this->providers)->map(function (SmsProviderInterface $provider, string $providerId) use ($config, $since, $days) {
            $providerConfig = is_array($config[$providerId] ?? null) ? $config[$providerId] : [];
            $unitCost = isset($providerConfig['unit_cost']) && is_numeric($providerConfig['unit_cost'])
                ? (float) $providerConfig['unit_cost']
                : null;

            $balance = null;
            if ($provider instanceof BalanceAwareSmsProvider && $provider->configured($providerConfig)) {
                $balance = Cache::remember(
                    'sms_balance:' . $providerId . ':' . md5(json_encode($providerConfig)),
                    300,
                    fn () => $provider->fetchBalance($providerConfig)
                );
            }

            $sentCount = (int) SmsLog::query()
                ->where('provider', $providerId)
                ->where('status', 'sent')
                ->where('sent_at', '>=', $since)
                ->count();

            return [
                'id' => $providerId,
                'label' => $provider->label(),
                'balance_supported' => $provider instanceof BalanceAwareSmsProvider,
                'balance' => $balance,
                'unit_cost' => $unitCost,
                'sent_count' => $sentCount,
                'window_days' => $days,
                'estimated_spend' => $unitCost !== null ? round($unitCost * $sentCount, 2) : null,
            ];
        })->values()->all();
    }

    private function resolveMarketConfig(array $config, ?int $platformId): array
    {
        if (!$platformId) {
            return $config;
        }

        $markets = is_array($config['markets'] ?? null) ? $config['markets'] : [];
        $market = $markets[(string) $platformId] ?? $markets[$platformId] ?? null;

        if (!is_array($market)) {
            return $config;
        }

        if (!empty($market['active_provider'])) {
            $config['active_provider'] = (string) $market['active_provider'];
        }

        if (array_key_exists('fallback_provider', $market) && $market['fallback_provider'] !== null) {
            $config['fallback_provider'] = (string) $market['fallback_provider'];
        }

        // Overlay each market-level provider credential block onto the global
        // provider config used for dispatch. Only non-empty overrides win.
        foreach ($this->providers as $providerId => $provider) {
            $marketProvider = $market[$providerId] ?? null;
            if (!is_array($marketProvider)) {
                continue;
            }

            $overrides = array_filter(
                $marketProvider,
                fn ($value) => $value !== null && $value !== ''
            );

            if ($overrides === []) {
                continue;
            }

            $config[$providerId] = array_merge(
                is_array($config[$providerId] ?? null) ? $config[$providerId] : [],
                $overrides
            );
        }

        return $config;
    }

    private function dispatchViaProvider(string $providerId, string $phone, string $message, array $smsConfig, array $context = []): array
    {
        $provider = $this->providers[$providerId] ?? null;
        if (!$provider) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $providerId,
                'provider_response' => 'Unsupported SMS provider.',
            ];
        }

        $providerConfig = is_array($smsConfig[$providerId] ?? null)
            ? $smsConfig[$providerId]
            : [];

        if (!$provider->configured($providerConfig)) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $providerId,
                'provider_response' => 'Provider credentials are incomplete.',
            ];
        }

        try {
            return $provider->send($phone, $message, $providerConfig, $context);
        } catch (\Throwable $exception) {
            Log::error('SMS dispatch failed', [
                'provider' => $providerId,
                'phone' => $phone,
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $providerId,
                'provider_response' => $exception->getMessage(),
            ];
        }
    }

    private function normalizePhone(?string $phone, string $prefix = '254'): ?string
    {
        $normalized = PhoneNormalizer::normalize($phone, $prefix);

        if (!$normalized || !preg_match('/^\d{10,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }
}
