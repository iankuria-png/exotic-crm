<?php

namespace App\Jobs\Concerns;

use App\Services\Ops\LoadShedder;
use Illuminate\Support\Facades\Log;

/**
 * Lets a queued job stand down while the platform is under pressure.
 *
 * The job is DEFERRED, never failed and never dropped: a shed costs latency
 * rather than data. Deferring also returns the worker to the pool immediately,
 * which is the point — the process is what is scarce.
 *
 * The scheduler-side gate matters more than this one. `->skip()` on a scheduled
 * task means no process is forked at all; this trait only stops a job that a
 * running worker has already picked up.
 *
 * Usage: `use Sheddable;` plus a `shedCapability(): string` on the job, then
 * `if ($this->shedIfDegraded()) { return; }` as the first line of handle().
 *
 * ## Why a shed does not call release()
 *
 * `release()` re-queues the *original payload* with the attempt counter the
 * worker already incremented when it reserved the job. So every shed spends one
 * of the job's tries, and the worker fails the job outright once attempts pass
 * the limit — `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts`.
 *
 * Three jobs using this trait run with `$tries = 1`, which makes a *single*
 * shed fatal, and all three are dispatched from a controller by someone
 * clicking a button. Losing that silently is the worst outcome available here,
 * and it is exactly what would happen the first time load shedding is switched
 * on during an incident — the one moment it is meant to help.
 *
 * Re-queueing a fresh copy resets the counter, so sheds and genuine failures
 * stop sharing a budget. Sheds are bounded separately by MAX_SHEDS.
 */
trait Sheddable
{
    /**
     * How many times one job may stand down before it gives up and takes its
     * chances with the normal retry budget. At the default delay this is an
     * hour of waiting, which is far longer than any shed should last.
     */
    private const MAX_SHEDS = 12;

    /**
     * Sheds this job has already taken. Public so it survives re-serialization
     * into the replacement job — release() would not have carried it, because
     * it re-pushes the original payload byte-for-byte.
     */
    public int $shedCount = 0;

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
            'shed_count' => $this->shedCount,
        ]);

        $this->deferWithoutSpendingAnAttempt();

        return true;
    }

    private function deferWithoutSpendingAnAttempt(): void
    {
        $delay = $this->shedRetryDelaySeconds();

        // A job that has stood down this many times is no longer waiting out a
        // blip. Hand it back to the normal retry budget so it can fail visibly
        // instead of orbiting forever.
        if ($this->shedCount >= self::MAX_SHEDS) {
            Log::warning('Job shed repeatedly; handing it back to the retry budget.', [
                'job' => static::class,
                'capability' => $this->shedCapability(),
                'shed_count' => $this->shedCount,
            ]);

            $this->release($delay);

            return;
        }

        try {
            $replacement = clone $this;
            $replacement->shedCount = $this->shedCount + 1;

            // InteractsWithQueue hands the running job a reference to the queue
            // driver, which closes over the container and cannot be serialized.
            // A freshly dispatched job never carries it, so clear it here too.
            $replacement->job = null;

            // Queued before the current record is deleted. If the process dies
            // between the two, the job runs twice rather than not at all —
            // at-least-once is the right way round for work someone asked for.
            app('queue')
                ->connection($this->job?->getConnectionName())
                ->later($delay, $replacement, '', $this->job?->getQueue());

            $this->delete();
        } catch (\Throwable $exception) {
            // Never let the shed path itself lose the job. Falling back to
            // release() restores the previous behaviour, which spends an
            // attempt but keeps the work queued.
            Log::warning('Shed re-queue failed; falling back to release.', [
                'job' => static::class,
                'error' => $exception->getMessage(),
            ]);

            $this->release($delay);
        }
    }
}
