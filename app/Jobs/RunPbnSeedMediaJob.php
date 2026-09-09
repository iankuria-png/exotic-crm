<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Jobs\Concerns\Sheddable;
use App\Models\PbnSeedBatch;
use App\Services\Pbn\PbnSeedMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Copies a seed batch's media without anyone pressing a button.
 *
 * Same bounded-slice shape as the seed batch itself: process a handful, then
 * re-dispatch for the next handful. Media copy is a download and an upload per
 * profile, so a single job that drained a whole batch would sit on a heavy
 * worker for many minutes and risk redelivery.
 *
 * Termination is the part that matters. A media failure leaves the item in
 * media_pending WITH a failure reason, and the manual pass deliberately retries
 * those first — so a naive loop would retry the same broken profile for ever.
 * This runner therefore drains untried items first, then allows a small number
 * of spaced retry passes to absorb a transient network problem, and finally
 * stops and leaves the rest for the operator's Needs check list.
 */
class RunPbnSeedMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RunsOnHeavyQueue, Sheddable;

    public int $tries = 2;
    public int $timeout = 900;
    public array $backoff = [60];

    private const SLICE = 5;

    /** Above the 200-profile batch cap at SLICE per pass, so a healthy batch never hits it. */
    private const MAX_PASSES = 60;

    private const NEXT_PASS_DELAY_SECONDS = 20;
    private const RETRY_PASS_DELAY_SECONDS = 300;
    private const MAX_RETRY_PASSES = 2;

    public function __construct(
        public readonly int $batchId,
        public readonly int $pass = 1,
        public readonly int $retryPass = 0,
    ) {
        $this->routeToHeavyQueue();
    }

    public function shedCapability(): string
    {
        return 'pbn_seed';
    }

    public function handle(PbnSeedMediaService $mediaService): void
    {
        if ($this->shedIfDegraded()) {
            return;
        }

        $batch = PbnSeedBatch::query()->with('pbnSite')->find($this->batchId);
        if (!$batch || in_array($batch->status, [PbnSeedBatch::STATUS_CANCELLED, PbnSeedBatch::STATUS_REVERTED], true)) {
            return;
        }

        if ($this->pass > self::MAX_PASSES) {
            Log::warning('PBN seed media runner stopped at the pass ceiling.', [
                'batch_id' => $this->batchId,
                'passes' => $this->pass,
            ]);

            return;
        }

        if ($mediaService->pendingMediaCount($batch) < 1) {
            return;
        }

        // One media runner per destination site: the copy pass uploads through
        // that site's REST API, and two batches pushing at once would double the
        // load on a host we do not control.
        $lock = cache()->lock('pbn-seed-media-site-' . (int) $batch->pbn_site_id, 900);
        if (!$lock->get()) {
            self::dispatch($this->batchId, $this->pass, $this->retryPass)
                ->delay(now()->addSeconds(self::NEXT_PASS_DELAY_SECONDS));

            return;
        }

        try {
            $untried = $mediaService->untriedMediaCount($batch);
            $isRetryPass = $untried < 1;

            if ($isRetryPass && $this->retryPass >= self::MAX_RETRY_PASSES) {
                return;
            }

            $mediaService->processBatch($batch, self::SLICE, null, onlyUntried: !$isRetryPass);
        } catch (\Throwable $exception) {
            // Missing REST credentials and the like are configuration problems,
            // not transient ones. Stop rather than re-queue into a loop; the
            // batch drawer still offers the manual pass.
            Log::warning('PBN seed media runner stopped.', [
                'batch_id' => $this->batchId,
                'error' => $exception->getMessage(),
            ]);

            return;
        } finally {
            $lock->release();
        }

        $fresh = $batch->fresh();
        if (!$fresh || $mediaService->pendingMediaCount($fresh) < 1) {
            return;
        }

        $nextIsRetry = $mediaService->untriedMediaCount($fresh) < 1;
        $nextRetryPass = $nextIsRetry ? $this->retryPass + 1 : $this->retryPass;

        if ($nextIsRetry && $nextRetryPass > self::MAX_RETRY_PASSES) {
            return;
        }

        self::dispatch($this->batchId, $this->pass + 1, $nextRetryPass)
            ->delay(now()->addSeconds($nextIsRetry ? self::RETRY_PASS_DELAY_SECONDS : self::NEXT_PASS_DELAY_SECONDS));
    }
}
