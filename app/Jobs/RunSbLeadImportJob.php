<?php

namespace App\Jobs;

use App\Models\SbLeadImportRun;
use App\Services\SbLeadImportRunService;
use App\Services\SupportBoardLeadImportService;
use App\Services\SupportBoardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSbLeadImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public readonly int $runId,
    ) {
    }

    public function handle(
        SbLeadImportRunService $runService,
        SupportBoardLeadImportService $importService
    ): void {
        $run = SbLeadImportRun::query()
            ->with('platform:id,name,support_board_api_url,support_board_token,phone_prefix')
            ->find($this->runId);

        if (!$run || !$run->platform || in_array($run->status, [
            SbLeadImportRun::STATUS_COMPLETED,
            SbLeadImportRun::STATUS_FAILED,
        ], true)) {
            return;
        }

        $run = $runService->markRunning($run);

        $candidateUserIds = is_array($run->candidate_user_ids) ? $run->candidate_user_ids : [];
        $cursor = (int) $run->cursor_position;

        if ($cursor >= count($candidateUserIds)) {
            $runService->markCompleted($run);
            return;
        }

        $sbUserId = (int) $candidateUserIds[$cursor];
        $sbService = new SupportBoardService($run->platform);

        try {
            $result = $importService->processCandidate(
                $sbService,
                $run->platform,
                (int) $run->platform_id,
                (string) ($run->platform->phone_prefix ?: '254'),
                $sbUserId
            );

            $outcome = [
                'created' => $result === 'created' ? 1 : 0,
                'updated' => $result === 'updated' ? 1 : 0,
                'skipped_existing_client' => $result === 'skipped_existing_client' ? 1 : 0,
                'skipped_existing_lead' => $result === 'skipped_existing_lead' ? 1 : 0,
                'errors' => 0,
                'cursor_position' => $cursor + 1,
                'sb_user_id' => $sbUserId,
                'name' => $importService->getLastProcessedName(),
            ];

            $run = $runService->recordOutcome($run, $outcome);
        } catch (\Throwable $exception) {
            Log::warning('SB lead import: candidate processing failed', [
                'run_id' => $this->runId,
                'sb_user_id' => $sbUserId,
                'error' => $exception->getMessage(),
            ]);

            $run = $runService->recordOutcome($run, [
                'errors' => 1,
                'error_detail' => [
                    'sb_user_id' => $sbUserId,
                    'message' => $exception->getMessage(),
                ],
                'cursor_position' => $cursor + 1,
                'sb_user_id' => $sbUserId,
            ]);
        }

        // Check if more candidates remain
        $nextCursor = (int) $run->cursor_position;
        if ($nextCursor < count($candidateUserIds)) {
            self::dispatch($this->runId);
            return;
        }

        $runService->markCompleted($run);
    }
}
