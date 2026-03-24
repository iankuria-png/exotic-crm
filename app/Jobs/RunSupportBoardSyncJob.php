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

    public int $tries = 1;
    public int $timeout = 300;

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

        // Use bulk path for fresh runs (no cursor yet) — resolves all clients in 1 API call.
        if (!$run->last_processed_client_id) {
            try {
                $result = $supportBoardLinkSyncService->syncPlatformBulk(
                    $run->platform,
                    $run->isRefresh(),
                    function () use ($run, $supportBoardSyncRunService): void {
                        $run->increment('processed');
                    }
                );

                // Record aggregate outcome on the run.
                $run->update([
                    'candidates' => (int) ($result['candidates'] ?? 0),
                    'processed' => (int) ($result['processed'] ?? 0),
                    'matched' => (int) ($result['matched'] ?? 0),
                    'updated' => (int) ($result['updated'] ?? 0),
                    'cleared' => (int) ($result['cleared'] ?? 0),
                    'unchanged' => (int) ($result['unchanged'] ?? 0),
                    'errors' => (int) ($result['errors'] ?? 0),
                ]);

                $supportBoardSyncRunService->markCompleted($run);
                return;
            } catch (\Throwable $exception) {
                Log::warning('Bulk SB sync job failed, falling back to per-client chain.', [
                    'run_id' => $run->id,
                    'platform_id' => $run->platform_id,
                    'error' => $exception->getMessage(),
                ]);
                // Fall through to per-client chain below.
            }
        }

        // Per-client chain: processes one client then re-dispatches.
        $client = $supportBoardLinkSyncService->nextClientForPlatform(
            $run->platform,
            $run->isRefresh(),
            (int) ($run->last_processed_client_id ?? 0)
        );

        if (!$client) {
            $supportBoardSyncRunService->markCompleted($run);
            return;
        }

        try {
            $outcome = $supportBoardLinkSyncService->syncClient($client);
            $run = $supportBoardSyncRunService->recordClientOutcome($run, $outcome);
        } catch (\Throwable $exception) {
            $supportBoardSyncRunService->markFailed($run, $exception);
            return;
        }

        $nextClient = $supportBoardLinkSyncService->nextClientForPlatform(
            $run->platform,
            $run->isRefresh(),
            (int) ($run->last_processed_client_id ?? 0)
        );

        if ($nextClient) {
            self::dispatch($run->id);
            return;
        }

        $supportBoardSyncRunService->markCompleted($run);
    }
}
