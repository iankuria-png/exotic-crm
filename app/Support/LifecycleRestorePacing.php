<?php

namespace App\Support;

/**
 * How fast a market works through its offline backlog.
 *
 * Pacing is a per-market setting rather than a per-run one: it describes an
 * ongoing policy ("trickle 50 a day until the backlog clears"), not a single
 * batch. Stored via FeatureSettingsService under {@see settingsKey()}.
 */
final class LifecycleRestorePacing
{
    /** Nothing happens unless someone presses Run; each batch is capped. */
    public const MANUAL_CAPPED = 'manual_capped';

    /** A scheduled quota per day, per market, until the backlog is gone. */
    public const DAILY_TRICKLE = 'daily_trickle';

    /** Manual runs with the cap lifted — the whole eligible set in one batch. */
    public const UNRESTRICTED = 'unrestricted';

    public const ALL = [
        self::MANUAL_CAPPED,
        self::DAILY_TRICKLE,
        self::UNRESTRICTED,
    ];

    public const LABELS = [
        self::MANUAL_CAPPED => 'Manual, capped',
        self::DAILY_TRICKLE => 'Daily trickle',
        self::UNRESTRICTED => 'Unrestricted',
    ];

    /** Cap applied when pacing is unrestricted — a backstop, not a policy. */
    public const UNRESTRICTED_CEILING = 100000;

    public const DEFAULT_DAILY_QUOTA = 50;

    public static function settingsKey(int $platformId): string
    {
        return "lifecycle.restore.pacing.{$platformId}";
    }

    public static function isValid(?string $mode): bool
    {
        return in_array((string) $mode, self::ALL, true);
    }

    public static function normalize(?string $mode): string
    {
        return self::isValid($mode) ? (string) $mode : self::MANUAL_CAPPED;
    }

    public static function label(string $mode): string
    {
        return self::LABELS[$mode] ?? $mode;
    }

    /** Shape stored in settings, and returned to the UI. */
    public static function defaults(): array
    {
        return [
            'mode' => self::MANUAL_CAPPED,
            'daily_quota' => self::DEFAULT_DAILY_QUOTA,
            'filters' => null,
            'target_state' => null,
        ];
    }
}
