<?php

namespace App\Services\Pbn;

use App\Models\PbnSite;
use Carbon\CarbonImmutable;

/**
 * Turns a batch-level copy policy into one concrete decision per seeded profile.
 *
 * Pure: no database, no clock beyond the start time it is handed, no randomness
 * beyond the seed it is given. That is deliberate — the allocation is the part
 * worth unit testing, and a batch has to be reproducible from its recorded seed
 * when someone asks a fortnight later why a given profile ended up VIP.
 *
 * Badges are allocated by COUNT, not probability. A 10% featured setting on 90
 * profiles marks exactly 9. Rolling a 10% chance per profile would land
 * anywhere between 3 and 17 on a batch that size, which makes small batches
 * unpredictable and the wizard's preview counts a lie.
 */
class PbnSeedPolicyResolver
{
    public const BADGE_FEATURED = 'featured';
    public const BADGE_PREMIUM = 'premium';
    public const BADGE_BASIC = 'basic';

    /**
     * @param  array<int, int>  $itemKeys  Stable per-item keys, in selection order.
     * @return array<int, array<string, mixed>>  Keyed by item key.
     */
    public function resolve(array $itemKeys, array $policy, CarbonImmutable $startsAt, int $seed): array
    {
        $itemKeys = array_values(array_unique(array_map('intval', $itemKeys)));
        $total = count($itemKeys);
        if ($total < 1) {
            return [];
        }

        $policy = array_replace_recursive(PbnSite::defaultCopyPolicy(), $policy ?: []);

        $badges = $this->allocateBadges($itemKeys, $policy['badges'] ?? [], $seed);
        $verified = $this->allocateVerified($itemKeys, $policy['badges'] ?? [], $seed);
        $releases = $this->allocateReleases($itemKeys, $policy['release'] ?? [], $startsAt);

        $resolved = [];
        foreach ($itemKeys as $index => $key) {
            $resolved[$key] = [
                'badge' => $badges[$key] ?? self::BADGE_BASIC,
                'verified' => in_array($key, $verified, true),
                'expires_at' => $this->expiryFor($policy['expiry'] ?? [], $startsAt, $seed, $key),
                'main_image_mode' => (string) ($policy['main_image']['mode'] ?? 'rotate'),
                'main_image_seed' => $this->mix($seed, $key, 'image'),
                'bio_mode' => (string) ($policy['bio']['mode'] ?? 'rewrite'),
                'bio_on_failure' => (string) ($policy['bio']['on_failure'] ?? 'verbatim'),
                'bio_result' => null,
                'release_at' => $releases[$key] ?? null,
                'release_index' => $index,
            ];
        }

        return $resolved;
    }

    /**
     * Featured and premium are disjoint: a profile is one tier or the other,
     * never both, because the destination theme queries them as separate pools
     * and a profile in both would double-count.
     *
     * @param  array<int, int>  $itemKeys
     * @return array<int, string>
     */
    private function allocateBadges(array $itemKeys, array $badgePolicy, int $seed): array
    {
        $total = count($itemKeys);
        $featuredCount = $this->countForPercent($badgePolicy['featured_pct'] ?? 0, $total);
        $premiumCount = $this->countForPercent($badgePolicy['premium_pct'] ?? 0, $total);

        // Featured wins the overlap when the two shares exceed the batch.
        if ($featuredCount + $premiumCount > $total) {
            $premiumCount = max(0, $total - $featuredCount);
        }

        $shuffled = $this->seededShuffle($itemKeys, $this->mix($seed, 0, 'badge'));
        $assigned = [];

        foreach ($shuffled as $position => $key) {
            if ($position < $featuredCount) {
                $assigned[$key] = self::BADGE_FEATURED;
            } elseif ($position < $featuredCount + $premiumCount) {
                $assigned[$key] = self::BADGE_PREMIUM;
            } else {
                $assigned[$key] = self::BADGE_BASIC;
            }
        }

        return $assigned;
    }

    /**
     * Verified is independent of the tier and shuffled separately, so it does
     * not simply land on the same profiles that got featured.
     *
     * @param  array<int, int>  $itemKeys
     * @return array<int, int>
     */
    private function allocateVerified(array $itemKeys, array $badgePolicy, int $seed): array
    {
        $count = $this->countForPercent($badgePolicy['verified_pct'] ?? 0, count($itemKeys));
        if ($count < 1) {
            return [];
        }

        return array_slice($this->seededShuffle($itemKeys, $this->mix($seed, 0, 'verified')), 0, $count);
    }

    /**
     * Trickle release times. Each period releases `per_period` profiles, so a
     * 90-profile batch at 10 per hour finishes in nine hours without any job
     * ever sleeping.
     *
     * @param  array<int, int>  $itemKeys
     * @return array<int, CarbonImmutable|null>
     */
    private function allocateReleases(array $itemKeys, array $releasePolicy, CarbonImmutable $startsAt): array
    {
        $mode = (string) ($releasePolicy['mode'] ?? 'immediate');
        if ($mode === 'immediate') {
            return [];
        }

        $perPeriod = max(1, (int) ($releasePolicy['per_period'] ?? 10));
        $releases = [];

        foreach ($itemKeys as $index => $key) {
            $period = intdiv($index, $perPeriod);
            $releases[$key] = $period < 1
                ? null
                : ($mode === 'daily' ? $startsAt->addDays($period) : $startsAt->addHours($period));
        }

        return $releases;
    }

    private function expiryFor(array $expiryPolicy, CarbonImmutable $startsAt, int $seed, int $key): ?int
    {
        $mode = (string) ($expiryPolicy['mode'] ?? 'window');
        if ($mode === 'none') {
            return null;
        }

        $min = max(1, (int) ($expiryPolicy['min_days'] ?? 30));
        if ($mode === 'fixed') {
            return $startsAt->addDays($min)->getTimestamp();
        }

        $max = max($min, (int) ($expiryPolicy['max_days'] ?? 90));
        $span = $max - $min;
        $offset = $span > 0 ? ($this->mix($seed, $key, 'expiry') % ($span + 1)) : 0;

        return $startsAt->addDays($min + $offset)->getTimestamp();
    }

    private function countForPercent(mixed $percent, int $total): int
    {
        $percent = max(0, min(100, (int) $percent));

        return (int) floor(($total * $percent) / 100);
    }

    /**
     * Deterministic Fisher-Yates. Mirrors the destination theme's own seeded
     * shuffle so the behaviour is familiar, and avoids depending on PHP's
     * global mt_rand state, which a queue worker shares across jobs.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function seededShuffle(array $ids, int $seed): array
    {
        $ids = array_values($ids);
        $count = count($ids);
        if ($count < 2) {
            return $ids;
        }

        $state = $seed > 0 ? $seed : 1;
        for ($i = $count - 1; $i > 0; $i--) {
            $state = (int) (($state * 1103515245 + 12345) & 0x7fffffff);
            $swap = $state % ($i + 1);
            [$ids[$i], $ids[$swap]] = [$ids[$swap], $ids[$i]];
        }

        return $ids;
    }

    private function mix(int $seed, int $key, string $salt): int
    {
        return abs((int) crc32($seed . '|' . $key . '|' . $salt));
    }
}
