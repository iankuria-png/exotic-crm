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

class RunSupportBoardSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

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
