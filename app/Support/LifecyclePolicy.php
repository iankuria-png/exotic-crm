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

    public const ARCHIVE_AFTER_DAYS_KEY = 'lifecycle.archive_after_days';

    public const MIN_ARCHIVE_AFTER_DAYS = 1;

    public const MAX_ARCHIVE_AFTER_DAYS = 3650;

    public static function masterEnabled(): bool
    {
        $default = (bool) config('crm.lifecycle.master_enabled', true);

        return (bool) app(FeatureSettingsService::class)->get(self::MASTER_ENABLED_KEY, $default);
    }

    public static function setMasterEnabled(bool $enabled, ?int $actorId = null): void
    {
        app(FeatureSettingsService::class)->set(self::MASTER_ENABLED_KEY, $enabled, $actorId);
    }

    /**
     * The global Expired dwell window. Unlike the env value, an admin-saved
     * setting takes effect immediately and is shared by every lifecycle path.
     */
    public static function archiveAfterDays(): int
    {
        $default = (int) config('crm.lifecycle.archive_after_days', 90);
        $value = app(FeatureSettingsService::class)->integer(self::ARCHIVE_AFTER_DAYS_KEY, $default);

        return min(max($value, self::MIN_ARCHIVE_AFTER_DAYS), self::MAX_ARCHIVE_AFTER_DAYS);
    }

    public static function setArchiveAfterDays(int $days, ?int $actorId = null): void
    {
        app(FeatureSettingsService::class)->set(
            self::ARCHIVE_AFTER_DAYS_KEY,
            min(max($days, self::MIN_ARCHIVE_AFTER_DAYS), self::MAX_ARCHIVE_AFTER_DAYS),
            $actorId
        );
    }
}
