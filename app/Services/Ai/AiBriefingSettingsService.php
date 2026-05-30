<?php

namespace App\Services\Ai;

use App\Models\IntegrationSetting;

/**
 * Resolves AI weekly-briefing configuration.
 *
 * Defaults come from config('ai.briefings') (env-driven). A stored
 * IntegrationSetting row keyed `ai_briefings_config` overrides those defaults
 * (Settings wins). Reads are deep-merged so partial overrides are safe.
 */
class AiBriefingSettingsService
{
    public const KEY = 'ai_briefings_config';

    public function settings(): array
    {
        return array_replace_recursive($this->defaults(), $this->storedSettings());
    }

    public function enabled(): bool
    {
        return (bool) data_get($this->settings(), 'enabled', false);
    }

    public function weeklyCostCapUsd(): float
    {
        return (float) data_get($this->settings(), 'weekly_cost_cap_usd', 5.00);
    }

    public function linkTtlDays(): int
    {
        return (int) data_get($this->settings(), 'link_ttl_days', 14);
    }

    public function baseUrl(): string
    {
        return rtrim((string) data_get($this->settings(), 'base_url', ''), '/');
    }

    public function timezone(): string
    {
        return (string) data_get($this->settings(), 'timezone', 'Africa/Nairobi');
    }

    public function adminOverride(): bool
    {
        return (bool) data_get($this->settings(), 'admin_override', true);
    }

    public function smsProviderOverride(): ?string
    {
        $value = data_get($this->settings(), 'sms_provider_override');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function schedule(): array
    {
        return (array) data_get($this->settings(), 'schedule', []);
    }

    /**
     * Curated recipient list. Each entry: user_id (required), name, phone,
     * audience, optional scope_platform_ids, opt_out.
     *
     * @return array<int, array>
     */
    public function recipients(): array
    {
        return array_values(array_filter(
            (array) data_get($this->settings(), 'recipients', []),
            fn ($entry) => is_array($entry) && (int) ($entry['user_id'] ?? 0) > 0
        ));
    }

    /**
     * Opted-in recipients for an audience (opt_out=false).
     *
     * @return array<int, array>
     */
    public function activeRecipientsForAudience(string $audience): array
    {
        return array_values(array_filter(
            $this->recipients(),
            fn (array $r) => ($r['audience'] ?? null) === $audience && !(bool) ($r['opt_out'] ?? false)
        ));
    }

    public function saveRecipients(array $recipients, ?int $actorId = null): array
    {
        $clean = [];
        foreach ($recipients as $entry) {
            $userId = (int) ($entry['user_id'] ?? 0);
            if ($userId <= 0) {
                continue; // user_id is mandatory — deep-link auth depends on it
            }

            $audience = in_array(($entry['audience'] ?? 'sales'), ['ceo', 'sales'], true)
                ? $entry['audience']
                : 'sales';

            $scope = $entry['scope_platform_ids'] ?? null;
            if (is_array($scope)) {
                $scope = array_values(array_unique(array_filter(array_map('intval', $scope), fn ($id) => $id > 0)));
                $scope = $scope === [] ? null : $scope;
            } else {
                $scope = null;
            }

            $clean[] = [
                'user_id' => $userId,
                'name' => trim((string) ($entry['name'] ?? '')) ?: null,
                'phone' => trim((string) ($entry['phone'] ?? '')) ?: null,
                'audience' => $audience,
                'scope_platform_ids' => $scope,
                'opt_out' => (bool) ($entry['opt_out'] ?? false),
            ];
        }

        $current = $this->settings();
        $current['recipients'] = $clean;

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $current, 'updated_by' => $actorId]
        );

        return $this->recipients();
    }

    public function save(array $input, ?int $actorId = null): array
    {
        $current = $this->settings();
        $next    = $current;

        foreach (['enabled', 'admin_override'] as $boolKey) {
            if (array_key_exists($boolKey, $input)) {
                $next[$boolKey] = (bool) $input[$boolKey];
            }
        }

        if (array_key_exists('weekly_cost_cap_usd', $input)) {
            $next['weekly_cost_cap_usd'] = max(0.0, (float) $input['weekly_cost_cap_usd']);
        }

        if (array_key_exists('link_ttl_days', $input)) {
            $next['link_ttl_days'] = max(1, (int) $input['link_ttl_days']);
        }

        if (array_key_exists('timezone', $input)) {
            $next['timezone'] = (string) $input['timezone'];
        }

        if (array_key_exists('base_url', $input)) {
            $next['base_url'] = rtrim((string) $input['base_url'], '/');
        }

        if (array_key_exists('sms_provider_override', $input)) {
            $value = trim((string) $input['sms_provider_override']);
            $next['sms_provider_override'] = $value === '' ? null : $value;
        }

        $scheduleInput = (array) ($input['schedule'] ?? []);
        foreach (['ceo_enabled', 'sales_enabled'] as $boolKey) {
            if (array_key_exists($boolKey, $scheduleInput)) {
                $next['schedule'][$boolKey] = (bool) $scheduleInput[$boolKey];
            }
        }
        foreach (['ceo_time', 'sales_time'] as $timeKey) {
            if (array_key_exists($timeKey, $scheduleInput)) {
                $next['schedule'][$timeKey] = (string) $scheduleInput[$timeKey];
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
        return (array) config('ai.briefings', []);
    }

    private function storedSettings(): array
    {
        $setting = IntegrationSetting::query()->where('key', self::KEY)->first();

        return is_array($setting?->value) ? $setting->value : [];
    }
}
