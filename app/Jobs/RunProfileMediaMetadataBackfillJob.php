<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Models\ProfileMediaMetadataBackfillRun;
use App\Services\ProfileMediaMetadataBackfillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunProfileMediaMetadataBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public array $backoff = [60];

    public function __construct(public readonly int $runId)
    {
        $this->routeToHeavyQueue();
    }

    public function handle(ProfileMediaMetadataBackfillService $backfill): void
    {
        $run = ProfileMediaMetadataBackfillRun::query()->find($this->runId);
        if (! $run || ! $run->isRunning()) {
            return;
        }

        $lock = cache()->lock("profile-media-metadata-backfill-platform-{$run->platform_id}", $this->timeout + 60);
        if (! $lock->get()) {
            self::dispatch($this->runId)->delay(now()->addSeconds(15));

            return;
        }

        try {
            if ($backfill->processSlice($run->fresh() ?: $run)) {
                self::dispatch($this->runId)->delay(now()->addSeconds(5));
            }
        } catch (\Throwable $exception) {
            Log::error('Profile media metadata backfill run failed.', [
                'run_id' => (int) $run->id,
                'error' => $exception->getMessage(),
            ]);

            $run->forceFill([
                'status' => ProfileMediaMetadataBackfillRun::STATUS_FAILED,
                'notes' => mb_substr($exception->getMessage(), 0, 1800),
                'finished_at' => now(),
            ])->save();
        } finally {
            $lock->release();
        }
    }
}
