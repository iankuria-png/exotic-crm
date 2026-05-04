<?php

namespace App\Jobs;

use App\Models\ClientSyncRun;
use App\Services\ClientSyncRunService;
use App\Services\ClientSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunClientSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 1200;
    public array $backoff = [15, 60];

    public function __construct(
        public readonly int $runId,
        public readonly int $perPage = 100,
    ) {
    }

    public function handle(
        ClientSyncRunService $clientSyncRunService
    ): void {
        $run = ClientSyncRun::query()
            ->with('platform')
            ->find($this->runId);

        if (!$run || !$run->platform || in_array($run->status, [
            ClientSyncRun::STATUS_COMPLETED,
            ClientSyncRun::STATUS_FAILED,
            ClientSyncRun::STATUS_STALE,
        ], true)) {
            return;
        }

        $lock = cache()->lock(sprintf('client-sync-platform-%d', (int) $run->platform_id), 1800);

        if (!$lock->get()) {
            Log::warning('Client sync job skipped because another worker holds the platform lock.', [
                'run_id' => (int) $run->id,
                'platform_id' => (int) $run->platform_id,
            ]);
            return;
        }

        try {
            $run = $clientSyncRunService->markRunning($run);
            $result = (new ClientSyncService($run->platform))->runBulkSync($run, $this->perPage);
            $completedRun = $clientSyncRunService->markCompleted($run, $result);

            if ((int) ($result['processed'] ?? 0) > 0 && $completedRun->started_at) {
                RefreshClientRetentionInsightsJob::dispatch(
                    (int) $completedRun->platform_id,
                    $completedRun->started_at->copy()->subMinute()->toDateTimeString()
                );
            }
        } catch (\Throwable $exception) {
            try {
                $clientSyncRunService->markFailed($run, $exception);
            } catch (\Throwable $markException) {
                Log::error('Unable to mark client sync run as failed.', [
                    'run_id' => (int) $run->id,
                    'error' => $markException->getMessage(),
                ]);
            }

            throw $exception;
        } finally {
            optional($lock)->release();
        }
    }
}
