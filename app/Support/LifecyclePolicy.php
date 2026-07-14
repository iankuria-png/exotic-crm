<?php

namespace App\Support;

use App\Services\FeatureSettingsService;

/**
 * Resolves the global master switch for the SEO-preserving profile lifecycle.
 *
 * The switch is managed from CRM settings (feature_settings key
 * `lifecycle.master_enabled`) so it can be flipped without a deploy. The
 * CRM_LIFECYCLE_MASTER_ENABLED env/config value acts only as the default when
 * no setting has been saved yet. When off, every market behaves as legacy
 * (expire = take offline) regardless of its per-market flag.
 */
class LifecyclePolicy
{
    public const MASTER_ENABLED_KEY = 'lifecycle.master_enabled';

    public static function masterEnabled(): bool
    {
        $default = (bool) config('crm.lifecycle.master_enabled', true);

        return (bool) app(FeatureSettingsService::class)->get(self::MASTER_ENABLED_KEY, $default);
    }

    public static function setMasterEnabled(bool $enabled, ?int $actorId = null): void
    {
        app(FeatureSettingsService::class)->set(self::MASTER_ENABLED_KEY, $enabled, $actorId);
    }
}
