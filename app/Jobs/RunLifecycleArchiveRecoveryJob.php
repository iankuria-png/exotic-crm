<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Models\LifecycleArchiveRecoveryRun;
use App\Services\LifecycleArchiveRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunLifecycleArchiveRecoveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public readonly int $runId)
    {
        $this->routeToHeavyQueue();
    }

    public function handle(LifecycleArchiveRecoveryService $recovery): void
    {
        $run = LifecycleArchiveRecoveryRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        $lock = cache()->lock("lifecycle-archive-recovery-platform-{$run->platform_id}", 7200);
        if (! $lock->get()) {
            $run->forceFill([
                'status' => LifecycleArchiveRecoveryRun::STATUS_FAILED,
                'notes' => 'Another archive recovery run is already in progress for this market.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        try {
            $recovery->execute($run);
        } catch (\Throwable $exception) {
            Log::error('Lifecycle archive recovery run failed', [
                'run_id' => (int) $run->id,
                'error' => $exception->getMessage(),
            ]);

            $run->forceFill([
                'status' => LifecycleArchiveRecoveryRun::STATUS_FAILED,
                'notes' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();
        } finally {
            $lock->release();
        }
    }
}
