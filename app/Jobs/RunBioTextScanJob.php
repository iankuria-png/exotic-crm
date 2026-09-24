<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Models\BioTextScan;
use App\Services\BioTextRepairService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drives a bio text check, repair or restore one slice per job, so a large
 * market never holds a worker for long and progress is saved between slices.
 */
class RunBioTextScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [60];

    public function __construct(public readonly int $scanId)
    {
        $this->routeToHeavyQueue();
    }

    public function handle(BioTextRepairService $service): void
    {
        $scan = BioTextScan::query()->find($this->scanId);
        if (! $scan || ! $scan->isActive()) {
            return;
        }

        $lock = cache()->lock("bio-text-scan-platform-{$scan->platform_id}", $this->timeout + 60);
        if (! $lock->get()) {
            self::dispatch($this->scanId)->delay(now()->addSeconds(15));

            return;
        }

        $hasMore = false;

        try {
            $hasMore = match ($scan->status) {
                BioTextScan::STATUS_REPAIRING => $service->repairSlice($scan),
                BioTextScan::STATUS_RESTORING => $service->restoreSlice($scan),
                default => $service->scanSlice($scan),
            };
        } catch (\Throwable $exception) {
            Log::error('Bio text scan run failed.', [
                'scan_id' => (int) $scan->id,
                'status' => $scan->status,
                'error' => $exception->getMessage(),
            ]);

            // Findings keep their backups and queued state, so the run can be
            // started again and picks up where it stopped.
            $scan->forceFill([
                'status' => BioTextScan::STATUS_FAILED,
                'notes' => mb_substr($exception->getMessage(), 0, 1800),
                'finished_at' => now(),
            ])->save();
        } finally {
            $lock->release();
        }

        // Queue the next slice only after the lock is free: on the sync queue
        // (local, tests) it runs immediately and must be able to take the lock.
        if ($hasMore) {
            self::dispatch($this->scanId)->delay(now()->addSeconds(2));
        }
    }
}
