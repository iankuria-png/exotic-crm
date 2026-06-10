<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Timezone-aware expiry cutoff math, mirrored from the WordPress escortwp-child
 * policy (escortwp_child_expiry_effective_cutoff / _is_expired) so the CRM never
 * expires a profile earlier than WordPress itself would.
 *
 * Stored expiry timestamps (escort_expire, premium_expire, ...) are Unix seconds.
 * CRM-activated subscriptions store them at local end-of-day, so day-based access
 * is the normal case; sub-day (exact/hourly) durations fall through to the raw ts.
 */
final class SubscriptionExpiry
{
    /**
     * End of the local calendar day (23:59:59 in $tz) containing $timestamp.
     */
    public static function endOfDay(int $timestamp, string $tz): int
    {
        if ($timestamp < 1) {
            return 0;
        }

        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::zone($tz));

        return (int) $date->setTime(23, 59, 59)->getTimestamp();
    }

    /**
     * A stored expiry is "day-based" when, rendered in the market timezone, its
     * time-of-day is local midnight or local end-of-day. Those get end-of-day grace.
     */
    public static function isDayBased(int $timestamp, string $tz): bool
    {
        if ($timestamp < 1) {
            return false;
        }

        $localTime = (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::zone($tz))->format('H:i:s');

        return $localTime === '00:00:00' || $localTime === '23:59:59';
    }

    /**
     * The effective cutoff used for destructive expiry decisions.
     */
    public static function effectiveCutoff(int $timestamp, string $tz): int
    {
        if ($timestamp < 1) {
            return 0;
        }

        return self::isDayBased($timestamp, $tz)
            ? self::endOfDay($timestamp, $tz)
            : $timestamp;
    }

    /**
     * Whether the given expiry timestamp has passed under the shared policy.
     */
    public static function isExpired(?int $timestamp, string $tz, ?int $now = null): bool
    {
        $timestamp = (int) $timestamp;
        $cutoff = self::effectiveCutoff($timestamp, $tz);
        $now = is_int($now) ? $now : time();

        return $cutoff > 0 && $cutoff < $now;
    }

    private static function zone(string $tz): DateTimeZone
    {
        try {
            return new DateTimeZone($tz);
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
