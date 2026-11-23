<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Models\ScraperRun;
use App\Services\Scraper\ScrapeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Heavy lane: a run fetches up to max_pages + max_profiles pages with politeness
 * delays, so it can take minutes. Timeout stays below database_long retry_after (4200).
 */
class RunScraperSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $runId)
    {
        $this->routeToHeavyQueue();
    }

    public function handle(ScrapeRunner $runner): void
    {
        $run = ScraperRun::query()->find($this->runId);
        if (!$run || !in_array($run->status, ['queued', 'pending'], true)) {
            return;
        }
        $runner->run($run);
    }

    public function failed(\Throwable $exception): void
    {
        ScraperRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'pending', 'running'])->update([
            'status' => 'error',
            'reason' => mb_substr('Run failed: ' . $exception->getMessage(), 0, 500),
            'completed_at' => now(),
        ]);
    }
}
