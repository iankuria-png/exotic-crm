<?php

namespace App\Services;

use App\Models\IntegrationSetting;

/**
 * Per-market configuration for the lifecycle SMS engine (onboarding, failed-payment
 * recovery, renewal links, reactivation). Global defaults + per-market overrides,
 * stored in integration_settings — same model as the SMS routing config.
 *
 * Everything ships DISABLED: the global master switch is off and every market's
 * sms_enabled flag defaults to off, so no lifecycle SMS can fire until a market
 * is explicitly enabled from Settings → SMS Routing → Lifecycle.
 */
class LifecycleSmsSettingsService
{
    public const SETTINGS_KEY = 'lifecycle_sms_config';

    public const FLOWS = ['onboarding', 'recovery', 'renewal', 'reactivation'];

    public function defaultConfig(): array
    {
        return [
            'enabled' => false,
            'defaults' => [
                'channel' => 'sms',
                'rate_cap_count' => 3,
                'rate_cap_days' => 7,
                'onboarding' => [
                    'enabled' => false,
                    'template_id' => null,
                    'product_id' => null,
                    'product_price_id' => null,
                    'free_trial_enabled' => false,
                    'free_trial_days' => 0,
                    'lookback_days' => 14,
                ],
                'recovery' => [
                    'enabled' => false,
                    'template_id' => null,
                    'cadence_hours' => [0],
                ],
                'renewal' => [
                    'payment_link_enabled' => false,
                ],
                'reactivation' => [
                    'enabled' => false,
                    'template_id' => null,
                    'product_id' => null,
                    'product_price_id' => null,
                    'windows_days' => [7],
                ],
            ],
            'markets' => [],
        ];
    }

    public function currentConfig(): array
    {
        $stored = IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');

        return $this->mergeConfig($this->defaultConfig(), is_array($stored) ? $stored : []);
    }

    public function saveConfig(array $payload, ?int $updatedBy = null): array
    {
        $merged = $this->mergeConfig($this->currentConfig(), $payload);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $merged, 'updated_by' => $updatedBy]
        );

        return $merged;
    }

    public function globalEnabled(): bool
    {
        return (bool) ($this->currentConfig()['enabled'] ?? false);
    }

    /**
     * Effective config for one market: global defaults overlaid with that
     * market's overrides, plus the per-market master flag.
     */
    public function marketConfig(int $platformId): array
    {
        $config = $this->currentConfig();
        $defaults = $config['defaults'];
        $market = $config['markets'][(string) $platformId] ?? [];

        $effective = [
            'sms_enabled' => (bool) ($market['sms_enabled'] ?? false),
            'channel' => (string) ($market['channel'] ?? $defaults['channel'] ?? 'sms'),
            'rate_cap_count' => (int) ($market['rate_cap_count'] ?? $defaults['rate_cap_count'] ?? 3),
            'rate_cap_days' => (int) ($market['rate_cap_days'] ?? $defaults['rate_cap_days'] ?? 7),
        ];

        foreach (self::FLOWS as $flow) {
            $flowDefaults = is_array($defaults[$flow] ?? null) ? $defaults[$flow] : [];
            $flowMarket = is_array($market[$flow] ?? null) ? $market[$flow] : [];
            $effective[$flow] = array_replace($flowDefaults, $flowMarket);
        }

        return $effective;
    }

    /**
     * Whether one flow may send for one market: needs the global master switch,
     * the market master switch, AND the flow toggle.
     */
    public function flowEnabled(int $platformId, string $flow): bool
    {
        if (!$this->globalEnabled()) {
            return false;
        }

        $market = $this->marketConfig($platformId);
        if (!$market['sms_enabled']) {
            return false;
        }

        if ($flow === 'renewal') {
            return (bool) ($market['renewal']['payment_link_enabled'] ?? false);
        }

        return (bool) ($market[$flow]['enabled'] ?? false);
    }

    private function mergeConfig(array $base, array $incoming): array
    {
        $merged = $base;

        if (array_key_exists('enabled', $incoming)) {
            $merged['enabled'] = (bool) $incoming['enabled'];
        }

        if (is_array($incoming['defaults'] ?? null)) {
            $merged['defaults'] = $this->mergeScope($base['defaults'] ?? [], $incoming['defaults']);
        }

        if (array_key_exists('markets', $incoming)) {
            $incomingMarkets = is_array($incoming['markets']) ? $incoming['markets'] : [];
            $existingMarkets = is_array($base['markets'] ?? null) ? $base['markets'] : [];
            $merged['markets'] = [];

            foreach ($incomingMarkets as $platformId => $marketData) {
                if (!is_array($marketData)) {
                    continue;
                }

                $existing = $existingMarkets[(string) $platformId] ?? [];
                $entry = $this->mergeScope(is_array($existing) ? $existing : [], $marketData);
                if (array_key_exists('sms_enabled', $marketData)) {
                    $entry['sms_enabled'] = (bool) $marketData['sms_enabled'];
                }

                $merged['markets'][(string) $platformId] = $entry;
            }
        }

        return $merged;
    }

    /**
     * Merge one config scope (the global defaults, or one market's overrides).
     * Flow blocks merge key-by-key so a partial save never wipes sibling keys.
     */
    private function mergeScope(array $base, array $incoming): array
    {
        $merged = $base;

        foreach (['channel'] as $key) {
            if (array_key_exists($key, $incoming) && is_string($incoming[$key]) && $incoming[$key] !== '') {
                $merged[$key] = $incoming[$key];
            }
        }

        foreach (['rate_cap_count', 'rate_cap_days'] as $key) {
            if (array_key_exists($key, $incoming) && is_numeric($incoming[$key])) {
                $merged[$key] = max(0, (int) $incoming[$key]);
            }
        }

        foreach (self::FLOWS as $flow) {
            if (!is_array($incoming[$flow] ?? null)) {
                continue;
            }

            $existingFlow = is_array($merged[$flow] ?? null) ? $merged[$flow] : [];
            $merged[$flow] = $this->mergeFlow($existingFlow, $incoming[$flow]);
        }

        return $merged;
    }

    private function mergeFlow(array $base, array $incoming): array
    {
        $merged = $base;

        foreach (['enabled', 'free_trial_enabled', 'payment_link_enabled'] as $key) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = (bool) $incoming[$key];
            }
        }

        foreach (['template_id', 'product_id', 'product_price_id'] as $key) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = is_numeric($incoming[$key]) && (int) $incoming[$key] > 0
                    ? (int) $incoming[$key]
                    : null;
            }
        }

        if (array_key_exists('free_trial_days', $incoming) && is_numeric($incoming['free_trial_days'])) {
            // Signed on purpose: markets can add bonus days or deduct days.
            $merged['free_trial_days'] = max(-30, min(90, (int) $incoming['free_trial_days']));
        }

        if (array_key_exists('lookback_days', $incoming) && is_numeric($incoming['lookback_days'])) {
            $merged['lookback_days'] = max(1, min(90, (int) $incoming['lookback_days']));
        }

        if (array_key_exists('cadence_hours', $incoming) && is_array($incoming['cadence_hours'])) {
            $hours = collect($incoming['cadence_hours'])
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => max(0, min(720, (int) $value)))
                ->unique()->sort()->values()->all();
            $merged['cadence_hours'] = $hours === [] ? [0] : $hours;
        }

        if (array_key_exists('windows_days', $incoming) && is_array($incoming['windows_days'])) {
            $days = collect($incoming['windows_days'])
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => max(1, min(365, (int) $value)))
                ->unique()->sort()->values()->all();
            $merged['windows_days'] = $days === [] ? [7] : $days;
        }

        return $merged;
    }
}
