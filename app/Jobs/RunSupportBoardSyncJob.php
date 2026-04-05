<?php

namespace App\Jobs;

use App\Models\SupportBoardSyncRun;
use App\Services\SupportBoardLinkSyncService;
use App\Services\SupportBoardSyncRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSupportBoardSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $runId,
    ) {
    }

    public function handle(
        SupportBoardSyncRunService $supportBoardSyncRunService,
        SupportBoardLinkSyncService $supportBoardLinkSyncService
    ): void {
        $run = SupportBoardSyncRun::query()
            ->with('platform:id,name,support_board_api_url,support_board_token,phone_prefix')
            ->find($this->runId);

        if (!$run || !$run->platform || in_array($run->status, [
            SupportBoardSyncRun::STATUS_COMPLETED,
            SupportBoardSyncRun::STATUS_FAILED,
        ], true)) {
            return;
        }

        $run = $supportBoardSyncRunService->markRunning($run);

        try {
            $this->processRun($run, $supportBoardSyncRunService, $supportBoardLinkSyncService);
        } catch (\Throwable $exception) {
            Log::error('SB sync job unhandled exception.', [
                'run_id' => $run->id,
                'platform_id' => $run->platform_id,
                'error' => $exception->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // On final attempt, mark the run as failed so it doesn't stay stuck in "running".
            if ($this->attempts() >= $this->tries) {
                try {
                    $supportBoardSyncRunService->markFailed($run, $exception);
                } catch (\Throwable $e) {
                    Log::error('Failed to mark SB sync run as failed.', [
                        'run_id' => $run->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            throw $exception; // Let Laravel retry or mark job as failed.
        }
    }

    private function processRun(
        SupportBoardSyncRun $run,
        SupportBoardSyncRunService $supportBoardSyncRunService,
        SupportBoardLinkSyncService $supportBoardLinkSyncService
    ): void {
        $chunk = $supportBoardLinkSyncService->syncPlatformBulkChunk(
            $run->platform,
            $run->isRefresh(),
            (int) ($run->last_processed_client_id ?? 0),
            SupportBoardLinkSyncService::BULK_SYNC_CHUNK_SIZE
        );

        if ((int) ($chunk['processed'] ?? 0) > 0) {
            $run = $supportBoardSyncRunService->recordChunkOutcome($run, $chunk);
        }

        if ((bool) ($chunk['has_more'] ?? false)) {
            self::dispatch($run->id);
            return;
        }

        if ((int) ($run->candidates ?? 0) === 0 || (int) ($chunk['processed'] ?? 0) === 0 || (int) ($run->processed ?? 0) > 0) {
            $supportBoardSyncRunService->markCompleted($run);
            return;
        }
    }

    /**
     * Handle a job that has permanently failed (all retries exhausted).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SB sync job permanently failed.', [
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }
}
