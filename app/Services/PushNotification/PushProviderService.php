<?php

namespace App\Services\PushNotification;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Log;

class PushProviderService
{
    private const PUSH_SETTINGS_KEY = 'push_provider_config';

    /** @var array<string, PushProviderInterface> */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            'webpushr' => new WebPushrProvider(),
            'wonderpush' => new WonderPushProvider(),
            'izooto' => new IZootoProvider(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getProviders(): array
    {
        return array_keys($this->providers);
    }

    public function sendPush(array $notification, array $context = []): array
    {
        $pushConfig = $this->resolvePushConfig();

        if (!(bool) ($pushConfig['enabled'] ?? false)) {
            return [
                'success' => false,
                'provider' => null,
                'provider_notification_id' => null,
                'provider_response' => 'Push dispatch disabled (push_provider_config.enabled=false).',
                'fallback_attempted' => false,
            ];
        }

        $platformId = isset($context['platform_id']) ? (int) $context['platform_id'] : null;
        $platformConfig = $this->resolvePlatformConfig($pushConfig, $platformId);

        $activeProviderId = (string) ($context['provider'] ?? $platformConfig['active_provider'] ?? $pushConfig['default_provider'] ?? 'webpushr');
        $activeResult = $this->dispatchViaProvider($activeProviderId, $notification, $platformConfig, $context);

        if ($activeResult['success']) {
            return [
                ...$activeResult,
                'fallback_attempted' => false,
            ];
        }

        $fallbackProviderId = (string) ($platformConfig['fallback_provider'] ?? 'none');
        $canFallback = $fallbackProviderId !== ''
            && $fallbackProviderId !== 'none'
            && $fallbackProviderId !== $activeProviderId;

        if (!$canFallback) {
            return [
                ...$activeResult,
                'fallback_attempted' => false,
            ];
        }

        $fallbackResult = $this->dispatchViaProvider($fallbackProviderId, $notification, $platformConfig, $context);

        if ($fallbackResult['success']) {
            return [
                ...$fallbackResult,
                'fallback_attempted' => true,
                'fallback_from' => $activeProviderId,
            ];
        }

        return [
            ...$activeResult,
            'fallback_attempted' => true,
            'fallback_provider' => $fallbackProviderId,
            'fallback_response' => $fallbackResult['provider_response'] ?? 'Fallback failed',
        ];
    }

    public function pollAnalytics(string $providerNotificationId, ?string $provider = null, array $context = []): ?array
    {
        if (trim($providerNotificationId) === '') {
            return null;
        }

        $pushConfig = $this->resolvePushConfig();
        $platformId = isset($context['platform_id']) ? (int) $context['platform_id'] : null;
        $platformConfig = $this->resolvePlatformConfig($pushConfig, $platformId);

        $providerId = (string) ($provider ?: ($platformConfig['active_provider'] ?? $pushConfig['default_provider'] ?? 'webpushr'));
        $instance = $this->providers[$providerId] ?? null;

        if (!$instance) {
            return null;
        }

        $providerConfig = $platformConfig[$providerId] ?? [];

        if (!$instance->configured($providerConfig)) {
            return null;
        }

        try {
            return $instance->getStatus($providerNotificationId, $providerConfig);
        } catch (\Throwable $exception) {
            Log::warning('Push analytics polling failed', [
                'provider' => $providerId,
                'platform_id' => $platformId,
                'provider_notification_id' => $providerNotificationId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{provider:string,total:int,active:int}|null
     */
    public function getSubscriberCountForPlatform(int $platformId): ?array
    {
        $diagnostic = $this->debugSubscriberCountForPlatform($platformId);
        if (!(bool) ($diagnostic['ok'] ?? false)) {
            Log::warning('Push subscriber sync skipped', [
                'platform_id' => $platformId,
                'provider' => $diagnostic['provider'] ?? null,
                'error' => $diagnostic['error'] ?? 'unknown',
            ]);

            return null;
        }

        return [
            'provider' => (string) ($diagnostic['provider'] ?? 'unknown'),
            'total' => (int) ($diagnostic['total'] ?? 0),
            'active' => (int) ($diagnostic['active'] ?? 0),
        ];
    }

    /**
     * @return array{ok:bool,provider:?string,total:int,active:int,error:?string}
     */
    public function debugSubscriberCountForPlatform(int $platformId): array
    {
        if ($platformId <= 0) {
            return [
                'ok' => false,
                'provider' => null,
                'total' => 0,
                'active' => 0,
                'error' => 'Invalid platform id.',
            ];
        }

        $pushConfig = $this->resolvePushConfig();

        if (!(bool) ($pushConfig['enabled'] ?? false)) {
            return [
                'ok' => false,
                'provider' => null,
                'total' => 0,
                'active' => 0,
                'error' => 'Push routing is disabled.',
            ];
        }

        $platformConfig = $this->resolvePlatformConfig($pushConfig, $platformId);
        $providerId = (string) ($platformConfig['active_provider'] ?? $pushConfig['default_provider'] ?? 'webpushr');
        $provider = $this->providers[$providerId] ?? null;

        if (!$provider) {
            return [
                'ok' => false,
                'provider' => $providerId !== '' ? $providerId : null,
                'total' => 0,
                'active' => 0,
                'error' => 'Unsupported provider configured.',
            ];
        }

        $providerConfig = is_array($platformConfig[$providerId] ?? null)
            ? $platformConfig[$providerId]
            : [];

        if (!$provider->configured($providerConfig)) {
            return [
                'ok' => false,
                'provider' => $providerId,
                'total' => 0,
                'active' => 0,
                'error' => 'Provider credentials are incomplete.',
            ];
        }

        try {
            $counts = $provider->getSubscriberCount($providerConfig);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'provider' => $providerId,
                'total' => 0,
                'active' => 0,
                'error' => $exception->getMessage(),
            ];
        }

        if (!$counts) {
            return [
                'ok' => false,
                'provider' => $providerId,
                'total' => 0,
                'active' => 0,
                'error' => 'Provider returned no subscriber data.',
            ];
        }

        return [
            'ok' => true,
            'provider' => $providerId,
            'total' => (int) ($counts['total'] ?? 0),
            'active' => (int) ($counts['active'] ?? 0),
            'error' => null,
        ];
    }

    public function currentPushConfig(bool $masked = true): array
    {
        $config = $this->resolvePushConfig();

        if (!$masked) {
            return $config;
        }

        return $this->maskSecrets($config);
    }

    public function savePushConfig(array $payload, ?int $updatedBy = null): array
    {
        $current = $this->resolvePushConfig();
        $merged = $this->mergePushConfig($current, $payload);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::PUSH_SETTINGS_KEY],
            [
                'value' => $merged,
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentPushConfig(masked: true);
    }

    private function resolvePushConfig(): array
    {
        $default = $this->defaultConfig();

        $stored = IntegrationSetting::query()
            ->where('key', self::PUSH_SETTINGS_KEY)
            ->value('value');

        if (is_array($stored)) {
            return $this->mergePushConfig($default, $stored);
        }

        return $default;
    }

    private function defaultConfig(): array
    {
        return [
            'enabled' => false,
            'default_provider' => 'webpushr',
            'platforms' => [],
        ];
    }

    private function defaultPlatformConfig(string $defaultProvider = 'webpushr'): array
    {
        $activeProvider = in_array($defaultProvider, $this->getProviders(), true)
            ? $defaultProvider
            : 'webpushr';

        return [
            'active_provider' => $activeProvider,
            'fallback_provider' => 'none',
            'webpushr' => [
                'api_key' => '',
                'auth_token' => '',
            ],
            'wonderpush' => [
                'access_token' => '',
                'project_id' => '',
            ],
            'izooto' => [
                'api_token' => '',
            ],
        ];
    }

    private function mergePushConfig(array $base, array $incoming): array
    {
        $merged = $base;

        $merged['enabled'] = array_key_exists('enabled', $incoming)
            ? (bool) $incoming['enabled']
            : (bool) ($base['enabled'] ?? false);

        $defaultProvider = (string) ($incoming['default_provider'] ?? $base['default_provider'] ?? 'webpushr');
        $merged['default_provider'] = in_array($defaultProvider, $this->getProviders(), true)
            ? $defaultProvider
            : 'webpushr';

        $existingPlatforms = is_array($base['platforms'] ?? null) ? $base['platforms'] : [];
        $mergedPlatforms = [];

        foreach ($existingPlatforms as $platformId => $platformConfig) {
            $mergedPlatforms[(string) $platformId] = is_array($platformConfig)
                ? $this->mergePlatformConfig($this->defaultPlatformConfig($merged['default_provider']), $platformConfig, $merged['default_provider'])
                : $this->defaultPlatformConfig($merged['default_provider']);
        }

        $incomingPlatforms = $incoming['platforms'] ?? [];

        if (is_array($incomingPlatforms)) {
            foreach ($incomingPlatforms as $platformId => $platformConfig) {
                if (!is_array($platformConfig)) {
                    continue;
                }

                $platformKey = (string) $platformId;
                $existing = $mergedPlatforms[$platformKey] ?? $this->defaultPlatformConfig($merged['default_provider']);

                $mergedPlatforms[$platformKey] = $this->mergePlatformConfig($existing, $platformConfig, $merged['default_provider']);
            }
        }

        $merged['platforms'] = $mergedPlatforms;

        return $merged;
    }

    private function mergePlatformConfig(array $existing, array $incoming, string $defaultProvider): array
    {
        $merged = $existing;

        if (array_key_exists('active_provider', $incoming)) {
            $candidate = trim((string) $incoming['active_provider']);
            if (in_array($candidate, $this->getProviders(), true)) {
                $merged['active_provider'] = $candidate;
            }
        }

        if (!in_array((string) ($merged['active_provider'] ?? ''), $this->getProviders(), true)) {
            $merged['active_provider'] = $defaultProvider;
        }

        if (array_key_exists('fallback_provider', $incoming)) {
            $candidate = trim((string) $incoming['fallback_provider']);
            if ($candidate === 'none' || in_array($candidate, $this->getProviders(), true)) {
                $merged['fallback_provider'] = $candidate;
            }
        }

        foreach (['webpushr', 'wonderpush', 'izooto'] as $providerId) {
            $incomingProviderConfig = $incoming[$providerId] ?? null;
            if (!is_array($incomingProviderConfig)) {
                continue;
            }

            $currentProviderConfig = is_array($merged[$providerId] ?? null)
                ? $merged[$providerId]
                : [];

            foreach ($incomingProviderConfig as $key => $value) {
                $stringValue = (string) $value;
                $isSecret = in_array($key, ['api_key', 'auth_token', 'access_token', 'api_token'], true);

                if ($isSecret && trim($stringValue) === '') {
                    continue;
                }

                $currentProviderConfig[$key] = $stringValue;
            }

            $merged[$providerId] = $currentProviderConfig;
        }

        return $merged;
    }

    private function resolvePlatformConfig(array $pushConfig, ?int $platformId): array
    {
        $defaultProvider = (string) ($pushConfig['default_provider'] ?? 'webpushr');
        $defaultPlatform = $this->defaultPlatformConfig($defaultProvider);

        if (!$platformId) {
            return $defaultPlatform;
        }

        $platforms = is_array($pushConfig['platforms'] ?? null)
            ? $pushConfig['platforms']
            : [];

        $stored = $platforms[(string) $platformId] ?? $platforms[$platformId] ?? null;

        if (!is_array($stored)) {
            return $defaultPlatform;
        }

        return $this->mergePlatformConfig($defaultPlatform, $stored, $defaultProvider);
    }

    private function dispatchViaProvider(string $providerId, array $notification, array $platformConfig, array $context = []): array
    {
        $provider = $this->providers[$providerId] ?? null;

        if (!$provider) {
            return [
                'success' => false,
                'provider' => $providerId,
                'provider_notification_id' => null,
                'provider_response' => 'Unsupported push provider.',
            ];
        }

        $providerConfig = is_array($platformConfig[$providerId] ?? null)
            ? $platformConfig[$providerId]
            : [];

        if (!$provider->configured($providerConfig)) {
            return [
                'success' => false,
                'provider' => $providerId,
                'provider_notification_id' => null,
                'provider_response' => 'Provider credentials are incomplete.',
            ];
        }

        try {
            return $provider->send($notification, $providerConfig, $context);
        } catch (\Throwable $exception) {
            Log::error('Push dispatch failed', [
                'provider' => $providerId,
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => $providerId,
                'provider_notification_id' => null,
                'provider_response' => $exception->getMessage(),
            ];
        }
    }

    private function maskSecrets(array $config): array
    {
        $masked = $config;

        if (!is_array($masked['platforms'] ?? null)) {
            $masked['platforms'] = [];
            return $masked;
        }

        foreach ($masked['platforms'] as $platformId => $platformConfig) {
            if (!is_array($platformConfig)) {
                continue;
            }

            foreach (['webpushr', 'wonderpush', 'izooto'] as $providerId) {
                $providerConfig = $platformConfig[$providerId] ?? null;

                if (!is_array($providerConfig)) {
                    continue;
                }

                if ($providerId === 'webpushr') {
                    $providerConfig = $this->maskProviderSecret($providerConfig, 'api_key');
                    $providerConfig = $this->maskProviderSecret($providerConfig, 'auth_token');
                }

                if ($providerId === 'wonderpush') {
                    $providerConfig = $this->maskProviderSecret($providerConfig, 'access_token');
                }

                if ($providerId === 'izooto') {
                    $providerConfig = $this->maskProviderSecret($providerConfig, 'api_token');
                }

                $platformConfig[$providerId] = $providerConfig;
            }

            $masked['platforms'][$platformId] = $platformConfig;
        }

        return $masked;
    }

    private function maskProviderSecret(array $providerConfig, string $key): array
    {
        $configuredKey = $key . '_configured';

        if (!empty($providerConfig[$key])) {
            $providerConfig[$key] = '••••••••';
            $providerConfig[$configuredKey] = true;
        } else {
            $providerConfig[$configuredKey] = false;
        }

        return $providerConfig;
    }
}
