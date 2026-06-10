<?php

namespace Tests\Unit;

use App\Support\SubscriptionExpiry;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class SubscriptionExpiryTest extends TestCase
{
    private const TZ = 'Africa/Nairobi'; // UTC+3, same offset as the TZ market

    private function ts(string $expr, string $tz = self::TZ): int
    {
        return (new DateTimeImmutable($expr, new DateTimeZone($tz)))->getTimestamp();
    }

    public function test_end_of_day_returns_local_2359_for_market_timezone(): void
    {
        $midnight = $this->ts('2026-06-09 00:00:00');
        $cutoff = SubscriptionExpiry::endOfDay($midnight, self::TZ);

        $this->assertSame(
            '2026-06-09 23:59:59',
            (new DateTimeImmutable('@' . $cutoff))->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d H:i:s')
        );
    }

    public function test_local_midnight_and_end_of_day_are_day_based(): void
    {
        $this->assertTrue(SubscriptionExpiry::isDayBased($this->ts('2026-06-09 00:00:00'), self::TZ));
        $this->assertTrue(SubscriptionExpiry::isDayBased($this->ts('2026-06-09 23:59:59'), self::TZ));
        $this->assertFalse(SubscriptionExpiry::isDayBased($this->ts('2026-06-09 12:34:56'), self::TZ));
    }

    public function test_day_based_detection_is_timezone_sensitive(): void
    {
        // Local midnight in Nairobi (UTC+3) is 21:00 the previous day in UTC.
        $nairobiMidnight = $this->ts('2026-06-09 00:00:00');

        $this->assertTrue(SubscriptionExpiry::isDayBased($nairobiMidnight, self::TZ));
        $this->assertFalse(SubscriptionExpiry::isDayBased($nairobiMidnight, 'UTC'));
    }

    public function test_day_based_expiry_gets_end_of_day_grace(): void
    {
        // Stored as local midnight today: cutoff is end of today → NOT yet expired.
        $midnightToday = $this->ts('today 00:00:00');
        $this->assertFalse(SubscriptionExpiry::isExpired($midnightToday, self::TZ));

        // End-of-day yesterday: cutoff is yesterday 23:59:59 → expired.
        $endOfYesterday = $this->ts('yesterday 23:59:59');
        $this->assertTrue(SubscriptionExpiry::isExpired($endOfYesterday, self::TZ));
    }

    public function test_exact_durations_expire_at_the_raw_timestamp(): void
    {
        // Non-boundary time in the past → expired immediately (no day grace).
        $this->assertTrue(SubscriptionExpiry::isExpired($this->ts('yesterday 12:34:56'), self::TZ));
        // Non-boundary time in the future → not expired.
        $this->assertFalse(SubscriptionExpiry::isExpired($this->ts('tomorrow 12:34:56'), self::TZ));
    }

    public function test_null_or_zero_is_never_expired(): void
    {
        $this->assertFalse(SubscriptionExpiry::isExpired(null, self::TZ));
        $this->assertFalse(SubscriptionExpiry::isExpired(0, self::TZ));
    }

    public function test_invalid_timezone_falls_back_to_utc(): void
    {
        $this->assertTrue(SubscriptionExpiry::isExpired($this->ts('yesterday 12:00:00', 'UTC'), 'Not/AZone'));
    }
}
