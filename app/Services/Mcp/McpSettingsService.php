<?php

namespace App\Services\Mcp;

use App\Models\IntegrationSetting;

class McpSettingsService
{
    public const KEY = 'mcp_config';

    public function settings(): array
    {
        $stored = IntegrationSetting::query()->where('key', self::KEY)->value('value');
        $defaults = (array) config('mcp', []);
        $overrides = is_array($stored) ? $stored : [];
        $settings = array_replace_recursive($defaults, $overrides);
        $settings['protocol_versions'] = array_values(array_unique(array_merge(
            (array) ($defaults['protocol_versions'] ?? []),
            (array) ($overrides['protocol_versions'] ?? [])
        )));

        return $settings;
    }

    public function save(array $input, ?int $actorId = null): array
    {
        $current = $this->settings();
        $next = array_replace_recursive($current, $input);
        $next['enabled'] = (bool) ($next['enabled'] ?? false);
        $next['limits']['rate_per_minute'] = $this->boundedInt($next['limits']['rate_per_minute'] ?? 30, 1, 600);
        $next['limits']['daily_row_budget'] = $this->boundedInt($next['limits']['daily_row_budget'] ?? 200000, 1, 10000000);
        $next['limits']['daily_bytes_budget'] = $this->boundedInt($next['limits']['daily_bytes_budget'] ?? 50000000, 1, 1000000000);
        $next['sql_hatch']['default_row_limit'] = $this->boundedInt($next['sql_hatch']['default_row_limit'] ?? 50, 1, 500);
        $next['sql_hatch']['max_row_limit'] = $this->boundedInt($next['sql_hatch']['max_row_limit'] ?? 500, $next['sql_hatch']['default_row_limit'], 5000);
        $next['sql_hatch']['timeout_seconds'] = $this->boundedInt($next['sql_hatch']['timeout_seconds'] ?? 10, 1, 60);
        $next['token_policy']['default_ttl_days'] = $this->boundedInt($next['token_policy']['default_ttl_days'] ?? 90, 1, 365);
        $next['token_policy']['max_ttl_days'] = $this->boundedInt($next['token_policy']['max_ttl_days'] ?? 365, $next['token_policy']['default_ttl_days'], 365);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $next, 'updated_by' => $actorId]
        );

        return $next;
    }

    public function tool(string $name): array
    {
        $default = (array) data_get(config('mcp.tools'), $name, []);
        $stored = (array) data_get($this->settings(), 'tools.'.$name, []);

        return array_replace($default, $stored);
    }

    public function enabled(): bool
    {
        return (bool) data_get($this->settings(), 'enabled', false);
    }

    private function boundedInt($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
