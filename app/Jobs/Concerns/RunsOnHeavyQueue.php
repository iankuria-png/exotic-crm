<?php

namespace App\Jobs\Concerns;

/**
 * Routes a job onto the dedicated "heavy" lane.
 *
 * Jobs whose timeout exceeds the fast lane's `retry_after` must not share a
 * connection with it: the fast lane would redeliver them to a second worker
 * while the first is still running, and every redelivery costs another PHP
 * process. On cPanel that is how the account's entry-process limit gets
 * exhausted and dynamic requests start returning 504 while static files
 * continue to serve normally.
 *
 * The `sync` guard keeps local runs and tests executing inline instead of
 * silently queueing to the database.
 */
trait RunsOnHeavyQueue
{
    protected function routeToHeavyQueue(): void
    {
        if ((string) config('queue.default', 'sync') === 'sync') {
            return;
        }

        $this->onConnection('database_long')->onQueue('heavy');
    }
}
