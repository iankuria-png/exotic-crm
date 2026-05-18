<?php

namespace App\Providers;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Reads SEO Engine settings from `integration_settings` (key: seo_engine) and
 * merges them into runtime config. DB values override .env defaults.
 *
 * Failure-tolerant: if the table doesn't exist (during install/migration) we
 * silently skip — config falls back to .env.
 */
class SeoEngineConfigProvider extends ServiceProvider
{
    private const KEY = 'seo_engine';

    public function boot(): void
    {
        try {
            // Cache for 5 minutes so we don't hit DB on every request
            $stored = Cache::remember('seo_engine_config', 300, function () {
                if (!Schema::hasTable('integration_settings')) {
                    return null;
                }
                return IntegrationSetting::query()->where('key', self::KEY)->value('value');
            });
        } catch (\Throwable $e) {
            return; // DB not ready / down — fall back to env
        }

        if (!is_array($stored) || empty($stored)) {
            return;
        }

        // Master toggle
        if (array_key_exists('enabled', $stored)) {
            config(['services.seo_engine.enabled' => (bool) $stored['enabled']]);
        }

        if (!empty($stored['platform_allowlist']) && is_array($stored['platform_allowlist'])) {
            config(['services.seo_engine.platform_allowlist' => array_values(array_map('intval', $stored['platform_allowlist']))]);
        }

        if (!empty($stored['providers_order']) && is_array($stored['providers_order'])) {
            config(['services.seo_engine.providers' => array_values($stored['providers_order'])]);
        }

        foreach (['claude', 'openai', 'gemini', 'deepseek'] as $provider) {
            $cfg = $stored['providers'][$provider] ?? null;
            if (!is_array($cfg)) {
                continue;
            }
            if (!empty($cfg['api_key'])) {
                config(["services.seo_engine.{$provider}.api_key" => (string) $cfg['api_key']]);
            }
            if (!empty($cfg['model'])) {
                config(["services.seo_engine.{$provider}.model" => (string) $cfg['model']]);
            }
        }
    }
}
