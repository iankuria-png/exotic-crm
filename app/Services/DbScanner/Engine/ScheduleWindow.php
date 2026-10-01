<?php

namespace App\Services\DbScanner\Engine;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Market-local time windows. An overnight window (start after end) belongs
 * to the market-local date it starts on.
 */
final class ScheduleWindow
{
    public static function isOpen(array $window, string $timezone, DateTimeInterface $at): bool
    {
        $start = self::minutes((string) ($window['start'] ?? ''));
        $end = self::minutes((string) ($window['end'] ?? ''));
        if ($start === null || $end === null || $start === $end) {
            return true;
        }

        try {
            $local = CarbonImmutable::instance($at)->setTimezone($timezone ?: 'UTC');
        } catch (\Throwable) {
            $local = CarbonImmutable::instance($at)->setTimezone('UTC');
        }
        $now = $local->hour * 60 + $local->minute;

        return $start < $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    private static function minutes(string $value): ?int
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
