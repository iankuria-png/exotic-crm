<?php

namespace App\Jobs;

use App\Models\LifecycleRestoreRun;
use App\Services\ProfileLifecycleRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backs the SEO Recovery "Run" button. Every profile costs at least two
 * WordPress round-trips, so even a modest batch is far too slow for a web
 * request. Progress and outcome live on the run row itself.
 */
class RunLifecycleRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        public readonly int $runId,
    ) {
    }

    public function handle(ProfileLifecycleRestoreService $restorer): void
    {
        $run = LifecycleRestoreRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        // One restore per market at a time: overlapping runs would double up
        // the WordPress writes and race on the same candidate set.
        $lock = cache()->lock("lifecycle-restore-platform-{$run->platform_id}", 3600);

        if (! $lock->get()) {
            $run->forceFill([
                'status' => LifecycleRestoreRun::STATUS_FAILED,
                'notes' => 'Another restore is already running for this market.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        try {
            $restorer->execute($run);
        } catch (\Throwable $exception) {
            Log::error('SEO Recovery run failed', [
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);

            $run->forceFill([
                'status' => LifecycleRestoreRun::STATUS_FAILED,
                'notes' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();
        } finally {
            $lock->release();
        }
    }
}
