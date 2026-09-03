<?php

namespace App\Jobs;

use App\Models\ClientSyncRun;
use App\Services\ClientSyncRunService;
use App\Services\ClientSyncService;
use App\Support\SyncSliceBudget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs ONE bounded slice of a client sync run, then re-queues itself if the
 * market still has pages left.
 *
 * This job used to loop until WordPress said there was nothing more to send,
 * with a 20-minute timeout. A single slow market could hold a queue worker for
 * that whole window; the scheduler's overlap mutex expires long before that, so
 * every couple of minutes another worker was started on top — until the cPanel
 * entry-process limit was hit and the site started returning 504s while static
 * files still served fine.
 *
 * A slice is short enough that `queue:work --max-time` is meaningful again, and
 * short enough to sit comfortably inside the queue connection's `retry_after`,
 * so a still-running job is never redelivered to a second worker.
 */
class RunClientSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A run that needs more slices than this is not making progress; failing it
     * is better than letting it re-queue itself forever.
     */
    private const MAX_SLICES_PER_RUN = 400;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(
        public readonly int $runId,
        public readonly int $perPage = 100,
    ) {
    }

    /**
     * Headroom on top of the slice budget.
     *
     * The budget is only checked between pages, so a slice can overrun by one
     * page. A single page is a WordPress request with a 60s timeout retried up
     * to three times with backoff — about 182s worst case — so the headroom has
     * to cover that, or the worker gets killed mid-page on a slow market.
     */
    private const PAGE_OVERRUN_HEADROOM_SECONDS = 240;

    /**
     * Kept just above the slice budget so a wedged HTTP call is still cut off,
     * while staying well inside the connection's `retry_after` — a job that
     * outruns `retry_after` is handed to a second worker while the first is
     * still going, and every redelivery is another PHP process.
     */
    public function timeout(): int
    {
        return SyncSliceBudget::fromConfig()->maxSeconds() + self::PAGE_OVERRUN_HEADROOM_SECONDS;
    }

    public function handle(ClientSyncRunService $clientSyncRunService): void
    {
        $run = ClientSyncRun::query()
            ->with('platform')
            ->find($this->runId);

        if (! $run || ! $run->platform || in_array($run->status, [
            ClientSyncRun::STATUS_COMPLETED,
            ClientSyncRun::STATUS_FAILED,
            ClientSyncRun::STATUS_STALE,
        ], true)) {
            return;
        }

        $lock = cache()->lock(
            sprintf('client-sync-platform-%d', (int) $run->platform_id),
            $this->timeout() + 60
        );

        if (! $lock->get()) {
            Log::warning('Client sync job skipped because another worker holds the platform lock.', [
                'run_id' => (int) $run->id,
                'platform_id' => (int) $run->platform_id,
            ]);

            return;
        }

        try {
            if ((int) $run->slices >= self::MAX_SLICES_PER_RUN) {
                $clientSyncRunService->markFailed(
                    $run,
                    sprintf(
                        'Client sync run exceeded %d slices without completing; aborting to protect the queue.',
                        self::MAX_SLICES_PER_RUN
                    )
                );

                return;
            }

            $run = $clientSyncRunService->markRunning($run);

            $result = (new ClientSyncService($run->platform))->runBulkSync(
                $run,
                $this->perPage,
                SyncSliceBudget::fromConfig()
            );

            if (! ($result['complete'] ?? true)) {
                $this->queueNextSlice($run->fresh() ?: $run);

                return;
            }

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

    /**
     * Re-queue the same run. The lock is released in the caller's `finally`, so
     * a short delay keeps the next slice from racing this slice's teardown.
     */
    private function queueNextSlice(ClientSyncRun $run): void
    {
        Log::info('Client sync run has more work; queueing the next slice.', [
            'run_id' => (int) $run->id,
            'platform_id' => (int) $run->platform_id,
            'phase' => (string) $run->phase,
            'slices' => (int) $run->slices,
            'processed_so_far' => (int) $run->processed,
        ]);

        self::dispatch($this->runId, $this->perPage)
            ->onQueue($run->mode === 'reconcile' ? 'sync-clients-reconcile' : 'sync-clients')
            ->delay(now()->addSeconds(5));
    }
}
