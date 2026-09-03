<?php

namespace App\Support;

use App\Services\Ops\OperationsSettingsService;

/**
 * Wall-clock and page budget for one slice of a client sync run.
 *
 * A market's sync used to run as a single unbounded `while (has_more)` loop.
 * One slow WordPress site could therefore occupy a CRM queue worker for the
 * job's full 20-minute timeout, and because the scheduler's overlap mutex
 * expires after a couple of minutes, the next `schedule:run` would start yet
 * another worker on top of it. That is how a WordPress-side slowdown turned
 * into CRM-wide 504s.
 *
 * Slicing makes the unit of work bounded instead: a slice stops at whichever
 * limit it hits first, persists its cursor, and re-queues itself.
 */
class SyncSliceBudget
{
    private float $startedAt;

    public function __construct(
        private readonly int $maxSeconds,
        private readonly int $maxPages,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * The live budget.
     *
     * Reads Settings → Operations first and falls back to config, so changing
     * the slice budget takes effect on the next queued slice with no deploy and
     * no `config:cache`. A settings failure — a missing table during a
     * migration, a cache outage — falls straight through to the config value
     * rather than taking client sync down with it.
     */
    public static function fromConfig(): self
    {
        $seconds = max(15, (int) config('services.client_sync.slice_seconds', 90));
        $pages = max(1, (int) config('services.client_sync.slice_max_pages', 25));

        try {
            $settings = app(OperationsSettingsService::class);
            $seconds = max(15, $settings->integer('ops.sync.slice_seconds'));
            $pages = max(1, $settings->integer('ops.sync.slice_max_pages'));
        } catch (\Throwable) {
            // Keep the config-derived values.
        }

        return new self($seconds, $pages);
    }

    /**
     * An effectively unlimited budget, for callers that must run to completion
     * (tests, one-off console runs).
     */
    public static function unlimited(): self
    {
        return new self(PHP_INT_MAX, PHP_INT_MAX);
    }

    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    /**
     * True once this slice has spent its budget. Checked between pages, so a
     * slice can overrun by at most one page's worth of work.
     */
    public function isExhausted(int $pagesConsumed): bool
    {
        return $pagesConsumed >= $this->maxPages
            || $this->elapsedSeconds() >= $this->maxSeconds;
    }

    public function maxSeconds(): int
    {
        return $this->maxSeconds;
    }

    public function maxPages(): int
    {
        return $this->maxPages;
    }
}
