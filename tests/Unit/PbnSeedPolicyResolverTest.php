<?php

namespace Tests\Unit;

use App\Services\Pbn\PbnSeedPolicyResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PbnSeedPolicyResolverTest extends TestCase
{
    private const START = '2026-09-04 09:00:00';

    private function resolve(array $policy, int $itemCount = 90, int $seed = 7): array
    {
        return (new PbnSeedPolicyResolver())->resolve(
            $itemCount > 0 ? range(1, $itemCount) : [],
            $policy,
            CarbonImmutable::parse(self::START),
            $seed
        );
    }

    /**
     * Percentages are a count, not a dice roll. A 10% VIP setting on 90
     * profiles must mark exactly 9 — rolling per profile would land anywhere
     * between 3 and 17 and make the wizard's preview counts a lie.
     */
    public function test_badge_percentages_allocate_exact_counts(): void
    {
        $resolved = $this->resolve([
            'badges' => ['featured_pct' => 10, 'premium_pct' => 25, 'verified_pct' => 0],
        ]);

        $badges = array_count_values(array_column($resolved, 'badge'));

        $this->assertSame(9, $badges[PbnSeedPolicyResolver::BADGE_FEATURED]);
        $this->assertSame(22, $badges[PbnSeedPolicyResolver::BADGE_PREMIUM]);
        $this->assertSame(59, $badges[PbnSeedPolicyResolver::BADGE_BASIC]);
    }

    /**
     * The destination theme queries featured and premium as separate pools, so
     * a profile in both would be counted twice.
     */
    public function test_featured_and_premium_are_disjoint(): void
    {
        $resolved = $this->resolve([
            'badges' => ['featured_pct' => 40, 'premium_pct' => 40],
        ]);

        foreach ($resolved as $decision) {
            $this->assertContains($decision['badge'], [
                PbnSeedPolicyResolver::BADGE_FEATURED,
                PbnSeedPolicyResolver::BADGE_PREMIUM,
                PbnSeedPolicyResolver::BADGE_BASIC,
            ]);
        }

        $badges = array_count_values(array_column($resolved, 'badge'));
        $this->assertSame(36, $badges[PbnSeedPolicyResolver::BADGE_FEATURED]);
        $this->assertSame(36, $badges[PbnSeedPolicyResolver::BADGE_PREMIUM]);
    }

    /** Shares that exceed the batch must not overflow it. */
    public function test_oversubscribed_percentages_never_exceed_the_batch(): void
    {
        $resolved = $this->resolve(['badges' => ['featured_pct' => 80, 'premium_pct' => 80]], 10);

        $badges = array_count_values(array_column($resolved, 'badge'));
        $this->assertSame(8, $badges[PbnSeedPolicyResolver::BADGE_FEATURED]);
        $this->assertSame(2, $badges[PbnSeedPolicyResolver::BADGE_PREMIUM] ?? 0);
        $this->assertCount(10, $resolved);
    }

    /** Verified defaults to nobody: it asserts a KYC check that never happened. */
    public function test_verified_is_nobody_by_default(): void
    {
        $resolved = $this->resolve(['badges' => ['featured_pct' => 10, 'premium_pct' => 25]]);

        $this->assertSame(0, count(array_filter(array_column($resolved, 'verified'))));
    }

    public function test_verified_allocates_its_own_share_when_configured(): void
    {
        $resolved = $this->resolve(['badges' => ['verified_pct' => 20]]);

        $this->assertSame(18, count(array_filter(array_column($resolved, 'verified'))));
    }

    /**
     * A window spreads expiry so a whole batch does not vanish on one day.
     */
    public function test_expiry_window_spreads_across_the_configured_range(): void
    {
        $resolved = $this->resolve(['expiry' => ['mode' => 'window', 'min_days' => 30, 'max_days' => 90]]);

        $start = CarbonImmutable::parse(self::START);
        $days = [];
        foreach ($resolved as $decision) {
            $days[] = $start->diffInDays(CarbonImmutable::createFromTimestamp($decision['expires_at']));
        }

        $this->assertGreaterThanOrEqual(30, min($days));
        $this->assertLessThanOrEqual(90, max($days));
        $this->assertGreaterThan(20, count(array_unique($days)), 'Expiry should spread, not cluster on a few days.');
    }

    public function test_fixed_expiry_lands_every_profile_on_one_day(): void
    {
        $resolved = $this->resolve(['expiry' => ['mode' => 'fixed', 'min_days' => 45]]);

        $this->assertCount(1, array_unique(array_column($resolved, 'expires_at')));
    }

    public function test_expiry_can_be_switched_off(): void
    {
        $resolved = $this->resolve(['expiry' => ['mode' => 'none']]);

        $this->assertSame([null], array_values(array_unique(array_column($resolved, 'expires_at'))));
    }

    /**
     * Trickle: the first period releases immediately (null), each later period
     * is offset so the batch job can re-queue itself rather than sleep.
     */
    public function test_hourly_trickle_releases_one_period_at_a_time(): void
    {
        $resolved = $this->resolve(['release' => ['mode' => 'hourly', 'per_period' => 10]], 30);

        $releases = array_column($resolved, 'release_at');
        $immediate = array_filter($releases, static fn ($value) => $value === null);

        $this->assertCount(10, $immediate, 'The first period should run immediately.');

        $start = CarbonImmutable::parse(self::START);
        $this->assertTrue($start->addHour()->equalTo($releases[10]));
        $this->assertTrue($start->addHours(2)->equalTo($releases[20]));
    }

    public function test_immediate_release_schedules_nothing(): void
    {
        $resolved = $this->resolve(['release' => ['mode' => 'immediate']], 30);

        $this->assertSame([null], array_values(array_unique(array_column($resolved, 'release_at'))));
    }

    /**
     * A batch must be reproducible from its recorded seed, so the same inputs
     * always explain why a given profile ended up VIP.
     */
    public function test_resolution_is_deterministic_for_a_seed(): void
    {
        $policy = ['badges' => ['featured_pct' => 15, 'premium_pct' => 20, 'verified_pct' => 10]];

        $this->assertEquals($this->resolve($policy, 40, 99), $this->resolve($policy, 40, 99));
        $this->assertNotEquals($this->resolve($policy, 40, 99), $this->resolve($policy, 40, 100));
    }

    public function test_empty_batches_resolve_to_nothing(): void
    {
        $this->assertSame([], $this->resolve([], 0));
    }
}
