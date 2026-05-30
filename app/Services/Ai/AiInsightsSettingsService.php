<?php

namespace App\Services\Ai;

use App\Models\IntegrationSetting;

/**
 * Resolves "Talk to Your Data" (NL->SQL) configuration.
 *
 * Defaults come from config('ai.insights'); a stored IntegrationSetting row
 * keyed `ai_insights_config` overrides them (Settings wins).
 */
class AiInsightsSettingsService
{
    public const KEY = 'ai_insights_config';

    public function settings(): array
    {
        return array_replace_recursive($this->defaults(), $this->storedSettings());
    }

    public function enabled(): bool
    {
        return (bool) data_get($this->settings(), 'enabled', false);
    }

    /** @return string[] Roles allowed in addition to CEO. */
    public function allowedRoles(): array
    {
        return array_values(array_filter((array) data_get($this->settings(), 'allowed_roles', [])));
    }

    public function sourceEnabled(string $source): bool
    {
        return (bool) data_get($this->settings(), "sources.{$source}", false);
    }

    public function defaultRowLimit(): int
    {
        return max(1, (int) data_get($this->settings(), 'default_row_limit', 100));
    }

    public function maxRowLimit(): int
    {
        return max($this->defaultRowLimit(), (int) data_get($this->settings(), 'max_row_limit', 1000));
    }

    public function sqlTimeoutSeconds(): int
    {
        return max(1, (int) data_get($this->settings(), 'sql_timeout_seconds', 10));
    }

    public function showGeneratedSql(): bool
    {
        return (bool) data_get($this->settings(), 'show_generated_sql', true);
    }

    public function chartSuggestions(): bool
    {
        return (bool) data_get($this->settings(), 'chart_suggestions', true);
    }

    public function rateLimitPerMinute(): int
    {
        return max(1, (int) data_get($this->settings(), 'rate_limit_per_minute', 12));
    }

    public function dailyCostCapUsd(): float
    {
        return (float) data_get($this->settings(), 'daily_cost_cap_usd', 5.00);
    }

    public function save(array $input, ?int $actorId = null): array
    {
        $current = $this->settings();
        $next    = $current;

        if (array_key_exists('enabled', $input)) {
            $next['enabled'] = (bool) $input['enabled'];
        }

        foreach (['chart_suggestions', 'show_generated_sql'] as $boolKey) {
            if (array_key_exists($boolKey, $input)) {
                $next[$boolKey] = (bool) $input[$boolKey];
            }
        }

        foreach (['default_row_limit', 'max_row_limit', 'sql_timeout_seconds', 'rate_limit_per_minute'] as $intKey) {
            if (array_key_exists($intKey, $input)) {
                $next[$intKey] = max(1, (int) $input[$intKey]);
            }
        }

        if (array_key_exists('daily_cost_cap_usd', $input)) {
            $next['daily_cost_cap_usd'] = max(0.0, (float) $input['daily_cost_cap_usd']);
        }

        if (array_key_exists('allowed_roles', $input)) {
            $next['allowed_roles'] = array_values(array_filter(
                array_map(fn ($r) => trim((string) $r), (array) $input['allowed_roles'])
            ));
        }

        $sourcesInput = (array) ($input['sources'] ?? []);
        foreach (['business_data', 'sales_data', 'project_status', 'hybrid'] as $source) {
            if (array_key_exists($source, $sourcesInput)) {
                $next['sources'][$source] = (bool) $sourcesInput[$source];
            }
        }

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $next, 'updated_by' => $actorId]
        );

        return $this->settings();
    }

    private function defaults(): array
    {
        return (array) config('ai.insights', []);
    }

    private function storedSettings(): array
    {
        $setting = IntegrationSetting::query()->where('key', self::KEY)->first();

        return is_array($setting?->value) ? $setting->value : [];
    }
}
