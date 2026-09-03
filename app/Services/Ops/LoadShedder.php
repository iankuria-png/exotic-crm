<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;

/**
 * The one thing the rest of the application imports: `allows($capability)`.
 *
 * Callers never see the degradation level, the thresholds, or how they were
 * computed, so the policy above can change without touching a single call site.
 *
 * Three properties are load-bearing:
 *
 * 1. **It never touches the database.** This is called from the scheduler's
 *    skip closures on every tick and from every sheddable job's handle(). A
 *    query here would add load in exactly the condition it exists to relieve.
 * 2. **Unknown capabilities fail open.** A capability absent from SHED_AT runs
 *    unconditionally, so a typo in a capability string cannot silently disable
 *    payments.
 * 3. **A missing cache key means normal operation.** If the sampler has never
 *    run, or the cache was flushed, nothing is shed.
 */
class LoadShedder
{
    public const LEVEL_NORMAL = 0;
    public const LEVEL_CAUTIOUS = 1;
    public const LEVEL_LIMP = 2;
    public const LEVEL_CRITICAL = 3;

    public const LEVEL_CACHE_KEY = 'ops.degradation.level';
    public const STATE_CACHE_KEY = 'ops.degradation.state';
    public const FORCED_CACHE_KEY = 'ops.degradation.forced';

    /**
     * Capability => the level at which it stops running.
     *
     * Deliberately a closed, opt-in list. Payments, STK callbacks, alerts,
     * market health, client sync, expiry reconciliation and the sampler itself
     * are absent, which is what makes them ungatable.
     *
     * Client sync in particular is never shed by Ian's instruction: since
     * 391954e a sync run is a bounded ~90-second slice that re-queues itself,
     * so it applies steady pressure rather than spikes, and pausing it would
     * stall profile freshness across 55 markets to reclaim very little.
     */
    private const SHED_AT = [
        'auto_optimize' => self::LEVEL_CAUTIOUS,
        'bulk_bio' => self::LEVEL_CAUTIOUS,
        'pbn_seed' => self::LEVEL_CAUTIOUS,
        'geocoding' => self::LEVEL_CAUTIOUS,
        'push_campaigns' => self::LEVEL_LIMP,
        'ai_briefings' => self::LEVEL_LIMP,
        'retention_insights' => self::LEVEL_LIMP,
        'support_board_sync' => self::LEVEL_LIMP,
        'optimize_queue_worker' => self::LEVEL_CRITICAL,
        'heavy_queue_worker' => self::LEVEL_CRITICAL,
    ];

    public const LABELS = [
        self::LEVEL_NORMAL => 'Normal',
        self::LEVEL_CAUTIOUS => 'Cautious',
        self::LEVEL_LIMP => 'Limp',
        self::LEVEL_CRITICAL => 'Critical',
    ];

    public function allows(string $capability): bool
    {
        $shedAt = self::SHED_AT[$capability] ?? null;

        if ($shedAt === null) {
            return true;
        }

        // Observe-only is the shipping default: levels are computed, recorded
        // and alerted, but nothing is actually paused until an admin turns
        // enforcement on. Read from cache like everything else here — the
        // sampler mirrors the setting into the state payload each minute.
        if (! $this->enforcementEnabled()) {
            return true;
        }

        return $this->level() < $shedAt;
    }

    /**
     * The capabilities that would be paused at a given level, whether or not
     * enforcement is on. Used by the board to say what a shed is costing.
     *
     * @return array<int, string>
     */
    public static function pausedAt(int $level): array
    {
        $paused = [];

        foreach (self::SHED_AT as $capability => $shedAt) {
            if ($level >= $shedAt) {
                $paused[] = $capability;
            }
        }

        return $paused;
    }

    /**
     * @return array<string, int>
     */
    public static function capabilities(): array
    {
        return self::SHED_AT;
    }

    public static function label(int $level): string
    {
        return self::LABELS[$level] ?? 'Unknown';
    }

    public function level(): int
    {
        $state = $this->state();

        return (int) ($state['level'] ?? self::LEVEL_NORMAL);
    }

    public function enforcementEnabled(): bool
    {
        return (bool) ($this->state()['enforcement'] ?? false);
    }

    public function isForced(): bool
    {
        return (bool) ($this->state()['forced'] ?? false);
    }

    /**
     * The full cached state: level, whether it was forced, when it started, and
     * which signal put it there.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        try {
            $state = Cache::get(self::STATE_CACHE_KEY);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($state)) {
            return [];
        }

        // A forced level always carries an expiry. One that has lapsed returns
        // control to the sampled level rather than to zero — an incident that
        // is still in progress must not read as normal just because nobody
        // renewed the override.
        if (($state['forced'] ?? false) && isset($state['forced_expires_at'])) {
            if (strtotime((string) $state['forced_expires_at']) < now()->timestamp) {
                $state['level'] = (int) ($state['sampled_level'] ?? self::LEVEL_NORMAL);
                $state['forced'] = false;
                $state['forced_expired'] = true;
            }
        }

        return $state;
    }
}
