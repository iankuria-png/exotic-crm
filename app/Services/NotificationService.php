<?php

namespace App\Services;

use App\Models\Client;
use App\Models\IntegrationSetting;
use App\Services\Sms\AfricasTalkingSmsProvider;
use App\Services\Sms\LegacyGatewaySmsProvider;
use App\Services\Sms\SmsProviderInterface;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    private const SMS_SETTINGS_KEY = 'sms_provider_config';

    /** @var array<string, SmsProviderInterface> */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            'legacy_gateway' => new LegacyGatewaySmsProvider(),
            'africastalking' => new AfricasTalkingSmsProvider(),
        ];
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

        $activeProviderId = (string) ($context['sms_provider'] ?? $smsConfig['active_provider'] ?? 'legacy_gateway');
        $activeResult = $this->dispatchViaProvider($activeProviderId, $normalizedPhone, $message, $smsConfig, $context);

        if ($activeResult['success']) {
            return array_merge($activeResult, [
                'phone' => $normalizedPhone,
                'fallback_attempted' => false,
            ]);
        }

        $fallbackProviderId = (string) ($smsConfig['fallback_provider'] ?? '');
        $canFallback = $fallbackProviderId !== ''
            && $fallbackProviderId !== 'none'
            && $fallbackProviderId !== $activeProviderId;

        if (!$canFallback) {
            return [
                ...$activeResult,
                'phone' => $normalizedPhone,
                'fallback_attempted' => false,
            ];
        }

        $fallbackResult = $this->dispatchViaProvider($fallbackProviderId, $normalizedPhone, $message, $smsConfig, $context);

        if ($fallbackResult['success']) {
            return array_merge($fallbackResult, [
                'phone' => $normalizedPhone,
                'fallback_attempted' => true,
                'fallback_from' => $activeProviderId,
            ]);
        }

        return [
            ...$activeResult,
            'phone' => $normalizedPhone,
            'fallback_attempted' => true,
            'fallback_provider' => $fallbackProviderId,
            'fallback_response' => $fallbackResult['provider_response'] ?? 'Fallback failed',
        ];
    }

    public function currentSmsConfig(bool $masked = true): array
    {
        $config = $this->resolveSmsConfig();
        if (!$masked) {
            return $config;
        }

        if (!empty($config['africastalking']['api_key'])) {
            $config['africastalking']['api_key'] = '••••••••';
            $config['africastalking']['api_key_configured'] = true;
        } else {
            $config['africastalking']['api_key_configured'] = false;
        }

        $maskedMarkets = [];
        foreach ($config['markets'] ?? [] as $platformId => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $maskedEntry = $entry;
            if (!empty($maskedEntry['africastalking']['api_key'])) {
                $maskedEntry['africastalking']['api_key'] = '••••••••';
                $maskedEntry['africastalking']['api_key_configured'] = true;
            } else {
                $maskedEntry['africastalking']['api_key_configured'] = false;
            }

            $maskedMarkets[(string) $platformId] = $maskedEntry;
        }
        $config['markets'] = $maskedMarkets;

        return $config;
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

        $incomingLegacy = $incoming['legacy_gateway'] ?? [];
        if (is_array($incomingLegacy)) {
            if (array_key_exists('gateway_url', $incomingLegacy)) {
                $merged['legacy_gateway']['gateway_url'] = (string) $incomingLegacy['gateway_url'];
            }
            if (array_key_exists('org_code', $incomingLegacy)) {
                $merged['legacy_gateway']['org_code'] = (string) $incomingLegacy['org_code'];
            }
        }

        $incomingAt = $incoming['africastalking'] ?? [];
        if (is_array($incomingAt)) {
            if (array_key_exists('endpoint', $incomingAt) && (string) $incomingAt['endpoint'] !== '') {
                $merged['africastalking']['endpoint'] = (string) $incomingAt['endpoint'];
            }
            if (array_key_exists('username', $incomingAt)) {
                $merged['africastalking']['username'] = (string) $incomingAt['username'];
            }
            if (array_key_exists('sender_id', $incomingAt)) {
                $merged['africastalking']['sender_id'] = (string) $incomingAt['sender_id'];
            }
            if (array_key_exists('api_key', $incomingAt) && (string) $incomingAt['api_key'] !== '') {
                $merged['africastalking']['api_key'] = (string) $incomingAt['api_key'];
            }
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
            'legacy_gateway' => [
                'gateway_url' => '',
                'org_code' => '',
            ],
            'africastalking' => [
                'username' => '',
                'api_key' => '',
                'sender_id' => '',
            ],
        ];
    }

    private function mergeMarketConfig(array $base, array $incoming): array
    {
        $merged = $this->defaultMarketConfig();
        $merged['active_provider'] = $base['active_provider'] ?? null;
        $merged['fallback_provider'] = $base['fallback_provider'] ?? null;
        $merged['legacy_gateway'] = array_merge($merged['legacy_gateway'], is_array($base['legacy_gateway'] ?? null) ? $base['legacy_gateway'] : []);
        $merged['africastalking'] = array_merge($merged['africastalking'], is_array($base['africastalking'] ?? null) ? $base['africastalking'] : []);

        if (array_key_exists('active_provider', $incoming)) {
            $merged['active_provider'] = filled($incoming['active_provider']) ? (string) $incoming['active_provider'] : null;
        }

        if (array_key_exists('fallback_provider', $incoming)) {
            $merged['fallback_provider'] = filled($incoming['fallback_provider']) ? (string) $incoming['fallback_provider'] : null;
        }

        $incomingLegacy = $incoming['legacy_gateway'] ?? null;
        if (is_array($incomingLegacy)) {
            if (array_key_exists('gateway_url', $incomingLegacy)) {
                $merged['legacy_gateway']['gateway_url'] = (string) $incomingLegacy['gateway_url'];
            }
            if (array_key_exists('org_code', $incomingLegacy)) {
                $merged['legacy_gateway']['org_code'] = (string) $incomingLegacy['org_code'];
            }
        }

        $incomingAt = $incoming['africastalking'] ?? null;
        if (is_array($incomingAt)) {
            if (array_key_exists('username', $incomingAt)) {
                $merged['africastalking']['username'] = (string) $incomingAt['username'];
            }
            if (array_key_exists('sender_id', $incomingAt)) {
                $merged['africastalking']['sender_id'] = (string) $incomingAt['sender_id'];
            }
            if (array_key_exists('api_key', $incomingAt) && trim((string) $incomingAt['api_key']) !== '') {
                $merged['africastalking']['api_key'] = (string) $incomingAt['api_key'];
            }
        }

        return $merged;
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

        $marketLegacy = $market['legacy_gateway'] ?? null;
        if (is_array($marketLegacy)) {
            if (!empty($marketLegacy['gateway_url'])) {
                $config['legacy_gateway']['gateway_url'] = (string) $marketLegacy['gateway_url'];
            }
            if (!empty($marketLegacy['org_code'])) {
                $config['legacy_gateway']['org_code'] = (string) $marketLegacy['org_code'];
            }
        }

        $marketAt = $market['africastalking'] ?? null;
        if (is_array($marketAt)) {
            if (!empty($marketAt['username'])) {
                $config['africastalking']['username'] = (string) $marketAt['username'];
            }
            if (!empty($marketAt['api_key'])) {
                $config['africastalking']['api_key'] = (string) $marketAt['api_key'];
            }
            if (!empty($marketAt['sender_id'])) {
                $config['africastalking']['sender_id'] = (string) $marketAt['sender_id'];
            }
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

        $providerConfig = match ($providerId) {
            'legacy_gateway' => $smsConfig['legacy_gateway'] ?? [],
            'africastalking' => $smsConfig['africastalking'] ?? [],
            default => [],
        };

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
