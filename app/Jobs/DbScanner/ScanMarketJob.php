<?php

namespace App\Jobs\DbScanner;

use App\Services\DbScanner\Engine\ScanExecutor;
use App\Services\DbScanner\Engine\ScanSliceHeartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * One bounded scanner slice. Carries only a run ID and the generation it
 * expects; the durable run row owns everything else, so a duplicate or stale
 * job is a no-op. The 60-second timeout is the hard watchdog behind the
 * slice's own 45/55-second limits; failure is never retried by the queue —
 * recovery and the outbox own retries.
 */
class ScanMarketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId, public int $generation) {}

    public function handle(ScanExecutor $executor): void
    {
        Cache::put(ScanSliceHeartbeat::KEY, now()->toIso8601String(), now()->addDay());
        $executor->runSlice($this->runId, $this->generation);
    }
}
