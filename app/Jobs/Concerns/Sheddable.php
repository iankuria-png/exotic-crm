<?php

namespace App\Jobs\Concerns;

use App\Services\Ops\LoadShedder;
use Illuminate\Support\Facades\Log;

/**
 * Lets a queued job stand down while the platform is under pressure.
 *
 * The job is RELEASED with a delay, never failed and never dropped: work is
 * deferred, so a shed costs latency rather than data. Releasing also returns
 * the worker to the pool immediately, which is the point — the process is what
 * is scarce.
 *
 * The scheduler-side gate matters more than this one. `->skip()` on a scheduled
 * task means no process is forked at all; this trait only stops a job that a
 * running worker has already picked up.
 *
 * Usage: `use Sheddable;` plus a `shedCapability(): string` on the job, then
 * `if ($this->shedIfDegraded()) { return; }` as the first line of handle().
 */
trait Sheddable
{
    /**
     * How long a shed job waits before trying again. Long enough that a job
     * released during a shed does not immediately re-occupy the worker it just
     * freed, short enough that recovery is not held up.
     */
    public function shedRetryDelaySeconds(): int
    {
        return 300;
    }

    abstract public function shedCapability(): string;

    /**
     * Returns true when the caller should return without doing any work.
     */
    protected function shedIfDegraded(): bool
    {
        $capability = $this->shedCapability();

        if (app(LoadShedder::class)->allows($capability)) {
            return false;
        }

        Log::info('Job released by load shedder.', [
            'job' => static::class,
            'capability' => $capability,
        ]);

        $this->release($this->shedRetryDelaySeconds());

        return true;
    }
}
