<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the scheduler invariants documented in App\Console\Kernel.
 *
 * `schedule:run` executes foreground events sequentially in one process with no
 * timeout. When enough slow tasks were due in the same minute, a single tick
 * outlived the once-a-minute cron and the processes stacked up until the cPanel
 * account ran out of entry processes — dynamic requests 504'd while static
 * files kept serving. These assertions exist so that failure mode has to be
 * reintroduced deliberately rather than by accident.
 */
class SchedulerConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build the schedule with a real queue connection. Tests run on the `sync`
     * driver, and the worker definitions are (correctly) skipped there — so
     * without this the worker assertions below would silently pass on an empty
     * set.
     *
     * @return array<int, Event>
     */
    private function events(string $queueConnection = 'database'): array
    {
        config()->set('queue.default', $queueConnection);

        $schedule = new Schedule();
        $kernel = new \App\Console\Kernel($this->app, $this->app['events']);

        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule->events();
    }

    private function commandOf(Event $event): string
    {
        return (string) $event->command;
    }

    public function test_queue_workers_never_block_the_scheduler(): void
    {
        $workers = array_filter(
            $this->events(),
            fn (Event $event) => str_contains($this->commandOf($event), 'queue:work')
        );

        $this->assertNotEmpty($workers, 'Expected the scheduler to define queue workers.');

        foreach ($workers as $event) {
            $this->assertTrue(
                $event->runInBackground,
                sprintf(
                    'Queue worker [%s] runs in the foreground. Each one blocks schedule:run for its '
                    .'full --max-time, so the tick cannot finish inside a minute.',
                    $event->getSummaryForDisplay()
                )
            );
        }
    }

    /**
     * `withoutOverlapping()` is a cache TTL in MINUTES, not a lock held for the
     * life of the process. Two minutes expired while long jobs were still
     * running, so the next tick started a duplicate worker on the same queue.
     */
    public function test_queue_worker_overlap_windows_outlast_their_jobs(): void
    {
        foreach ($this->events() as $event) {
            if (! str_contains($this->commandOf($event), 'queue:work')) {
                continue;
            }

            $this->assertTrue($event->withoutOverlapping);
            $this->assertGreaterThanOrEqual(
                5,
                $event->expiresAt,
                sprintf(
                    'Queue worker [%s] has a %d-minute overlap window; that expires while a job is '
                    .'still running and a second worker is started on top of it.',
                    $event->getSummaryForDisplay(),
                    $event->expiresAt
                )
            );
        }
    }

    /**
     * Every cadence helper fires on minute 0, which is how nineteen tasks ended
     * up due in the same tick. Foreground tasks are the ones that actually stack
     * up, so the ceiling is asserted against those.
     */
    public function test_minute_zero_is_not_crowded_with_blocking_tasks(): void
    {
        $foregroundAtMinuteZero = [];

        foreach ($this->events() as $event) {
            if ($event->runInBackground) {
                continue;
            }

            $expression = (string) $event->expression;
            $minuteField = explode(' ', $expression)[0] ?? '';

            $firesOnMinuteZero = $minuteField === '*'
                || in_array('0', array_map('trim', explode(',', $minuteField)), true);

            if ($firesOnMinuteZero) {
                $foregroundAtMinuteZero[] = $event->getSummaryForDisplay();
            }
        }

        $this->assertLessThanOrEqual(
            6,
            count($foregroundAtMinuteZero),
            "Too many blocking tasks share minute 0:\n - ".implode("\n - ", $foregroundAtMinuteZero)
        );
    }

    /**
     * A job that outruns its connection's `retry_after` is handed to a second
     * worker while the first is still running. Every redelivery is another PHP
     * process, which is what exhausted the entry-process limit.
     */
    public function test_job_timeouts_fit_inside_their_connection_retry_after(): void
    {
        $lanes = [
            'database' => [
                \App\Jobs\RunClientSyncJob::class,
                \App\Jobs\RunAiBriefingJob::class,
                \App\Jobs\RunSupportBoardSyncJob::class,
                \App\Jobs\GeocodeMarketCitiesJob::class,
                \App\Jobs\OptimizeProfileJob::class,
                \App\Jobs\ApplyAutoOptimizeItemJob::class,
                \App\Jobs\RecomputeSeoScoreJob::class,
                \App\Jobs\SendPushNotificationJob::class,
                \App\Jobs\SendLifecycleRecoverySmsJob::class,
                // 600s each. They stay on the fast lane deliberately: the push
                // upload UI looks these up by queue name, and 600s still fits
                // inside this connection's retry_after.
                \App\Jobs\ProcessPushUploadJob::class,
                \App\Jobs\ExtractPushProfilesJob::class,
            ],
            'database_long' => [
                \App\Jobs\RollbackLifecycleProfilesJob::class,
                \App\Jobs\RunLifecycleRestoreJob::class,
                \App\Jobs\RunPbnSeedBatchJob::class,
                \App\Jobs\ScrubProfileBiosJob::class,
                \App\Jobs\RefreshClientRetentionInsightsJob::class,
            ],
        ];

        foreach ($lanes as $connection => $jobs) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");
            $this->assertGreaterThan(0, $retryAfter, "Connection [{$connection}] has no retry_after.");

            foreach ($jobs as $job) {
                $timeout = $this->timeoutFor($job);

                $this->assertLessThan(
                    $retryAfter,
                    $timeout,
                    sprintf(
                        '%s has a %ds timeout but runs on [%s] whose retry_after is %ds. The job will be '
                        .'redelivered to a second worker while the first is still running.',
                        class_basename($job),
                        $timeout,
                        $connection,
                        $retryAfter
                    )
                );
            }
        }
    }

    private function timeoutFor(string $job): int
    {
        $reflection = new \ReflectionClass($job);

        if ($reflection->hasMethod('timeout')) {
            return (int) $reflection->newInstanceWithoutConstructor()->timeout();
        }

        $defaults = $reflection->getDefaultProperties();

        return (int) ($defaults['timeout'] ?? 60);
    }
}
