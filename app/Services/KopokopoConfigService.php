<?php

namespace App\Services;

use App\Models\IntegrationSetting;

class KopokopoConfigService
{
    public const SETTINGS_KEY = 'kopokopo_config';

    public function currentConfig(bool $masked = true): array
    {
        $config = $this->resolveConfig();
        $credentialsReady = $this->credentialsReady($config);

        $config['credentials_ready'] = $credentialsReady;
        $config['status'] = $credentialsReady
            ? ($config['enabled'] ? 'connected' : 'configured_disabled')
            : 'pending';

        if (!$masked) {
            return $config;
        }

        $maskedConfig = $config;
        $maskedConfig['client_id_configured'] = !empty($config['client_id']);
        $maskedConfig['client_secret_configured'] = !empty($config['client_secret']);
        $maskedConfig['api_key_configured'] = !empty($config['api_key']);

        if (!empty($maskedConfig['client_id'])) {
            $maskedConfig['client_id'] = $this->maskToken((string) $maskedConfig['client_id']);
        }
        if (!empty($maskedConfig['client_secret'])) {
            $maskedConfig['client_secret'] = '••••••••';
        }
        if (!empty($maskedConfig['api_key'])) {
            $maskedConfig['api_key'] = '••••••••';
        }

        return $maskedConfig;
    }

    public function saveConfig(array $payload, ?int $updatedBy = null): array
    {
        $current = $this->resolveConfig();
        $merged = $this->mergeConfig($current, $payload);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            [
                'value' => $merged,
                'updated_by' => $updatedBy,
            ]
        );

        return $this->currentConfig(masked: true);
    }

    public function credentialsReady(?array $config = null): bool
    {
        $resolved = $config ?? $this->resolveConfig();

        return !empty($resolved['base_url'])
            && !empty($resolved['till_number'])
            && !empty($resolved['client_id'])
            && !empty($resolved['client_secret']);
    }

    private function resolveConfig(): array
    {
        $default = [
            'enabled' => true,
            'base_url' => (string) (config('services.kopokopo.base_url') ?: config('kopokopo.base_url', '')),
            'till_number' => (string) config('services.kopokopo.till_number', ''),
            'client_id' => (string) config('services.kopokopo.client_id', ''),
            'client_secret' => (string) config('services.kopokopo.client_secret', ''),
            'api_key' => (string) config('services.kopokopo.api_key', ''),
        ];

        $stored = IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');

        if (is_array($stored)) {
            return $this->mergeConfig($default, $stored);
        }

        return $default;
    }

    private function mergeConfig(array $base, array $incoming): array
    {
        $merged = $base;

        if (array_key_exists('enabled', $incoming)) {
            $merged['enabled'] = (bool) $incoming['enabled'];
        }
        if (array_key_exists('base_url', $incoming) && trim((string) $incoming['base_url']) !== '') {
            $merged['base_url'] = trim((string) $incoming['base_url']);
        }
        if (array_key_exists('till_number', $incoming) && trim((string) $incoming['till_number']) !== '') {
            $merged['till_number'] = trim((string) $incoming['till_number']);
        }
        if (array_key_exists('client_id', $incoming) && trim((string) $incoming['client_id']) !== '') {
            $merged['client_id'] = trim((string) $incoming['client_id']);
        }
        if (array_key_exists('client_secret', $incoming) && trim((string) $incoming['client_secret']) !== '') {
            $merged['client_secret'] = trim((string) $incoming['client_secret']);
        }
        if (array_key_exists('api_key', $incoming) && trim((string) $incoming['api_key']) !== '') {
            $merged['api_key'] = trim((string) $incoming['api_key']);
        }

        return $merged;
    }

    private function maskToken(string $value): string
    {
        $length = strlen($value);
        if ($length <= 4) {
            return '••••';
        }

        return str_repeat('•', max(0, $length - 4)) . substr($value, -4);
    }
}
