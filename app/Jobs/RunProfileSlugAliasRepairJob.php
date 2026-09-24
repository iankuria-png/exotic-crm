<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Models\ProfileSlugAliasRepairRun;
use App\Services\ProfileSlugAliasRepairService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drives a profile URL repair (or its restore) one slice per job, so a large
 * market never holds a worker for long and progress is saved between slices.
 */
class RunProfileSlugAliasRepairJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [60];

    public function __construct(public readonly int $runId)
    {
        $this->routeToHeavyQueue();
    }

    public function handle(ProfileSlugAliasRepairService $repair): void
    {
        $run = ProfileSlugAliasRepairRun::query()->find($this->runId);
        if (! $run || ! $run->isActive()) {
            return;
        }

        $lock = cache()->lock("profile-slug-alias-repair-platform-{$run->platform_id}", $this->timeout + 60);
        if (! $lock->get()) {
            self::dispatch($this->runId)->delay(now()->addSeconds(15));

            return;
        }

        $restoring = $run->status === ProfileSlugAliasRepairRun::STATUS_RESTORING;
        $hasMore = false;

        try {
            $hasMore = $restoring
                ? $repair->restoreSlice($run)
                : $repair->processSlice($run);
        } catch (\Throwable $exception) {
            Log::error('Profile URL repair run failed.', [
                'run_id' => (int) $run->id,
                'restoring' => $restoring,
                'error' => $exception->getMessage(),
            ]);

            // A failed restore stays restorable: its backup and the count of
            // rows already put back are kept, and restoring again resumes.
            $run->forceFill([
                'status' => ProfileSlugAliasRepairRun::STATUS_FAILED,
                'notes' => mb_substr(($restoring ? 'Restore stopped: ' : '').$exception->getMessage(), 0, 1800),
                'finished_at' => $run->finished_at ?? now(),
            ])->save();
        } finally {
            $lock->release();
        }

        // Queue the next slice only after the lock is free: on the sync queue
        // (local, tests) it runs immediately and must be able to take the lock.
        if ($hasMore) {
            self::dispatch($this->runId)->delay(now()->addSeconds(3));
        }
    }
}
