<?php

namespace App\Services;

use App\Models\Client;
use App\Models\IntegrationSetting;
use App\Services\Sms\AfricasTalkingSmsProvider;
use App\Services\Sms\LegacyGatewaySmsProvider;
use App\Services\Sms\SmsProviderInterface;
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

        return $merged;
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
        if (!$phone) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone);
        $normalized = ltrim((string) $normalized, '+');

        if (str_starts_with($normalized, '0')) {
            $normalized = $prefix . substr($normalized, 1);
        }

        if (!preg_match('/^\d{10,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }
}
