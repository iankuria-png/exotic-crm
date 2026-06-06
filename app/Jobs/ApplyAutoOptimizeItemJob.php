<?php

namespace App\Jobs;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizeRun;
use App\Models\User;
use App\Services\AutoOptimize\AutoOptimizeAlertService;
use App\Services\AutoOptimize\AutoOptimizeApplyService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY job that writes to WordPress.
 * Used by:
 *  - Autopilot batch (chained after OptimizeProfileJob)
 *  - approve, approve-all, run-now (dispatched by controller)
 */
class ApplyAutoOptimizeItemJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 90;

    public function __construct(
        public int $itemId,
        public ?int $approverId = null, // null = system actor (autopilot)
    ) {
        $this->onQueue('auto_optimize');
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function middleware(): array
    {
        $item = AutoOptimizeItem::find($this->itemId);
        $platformId = $item?->platform_id ?? 0;

        // Same shared platform lock as OptimizeProfileJob
        return [
            (new WithoutOverlapping("auto_optimize:platform:{$platformId}"))
                ->shared()
                ->releaseAfter(60)
                ->expireAfter(120),
        ];
    }

    public function handle(AutoOptimizeApplyService $applyService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $item = AutoOptimizeItem::with(['plan', 'client', 'plan.platform'])->find($this->itemId);

        if (!$item) {
            return;
        }

        // No-op if the build step skipped/failed this item
        if (!in_array($item->status, ['pending'], true)) {
            return;
        }

        $approver = $this->approverId ? User::find($this->approverId) : null;

        try {
            $applyService->apply($item, $approver);
            $this->incrementRunCounters($item->auto_optimize_run_id, 'jobs_completed');

            if ($item->fresh()->status === 'applied') {
                $this->incrementRunCounters($item->auto_optimize_run_id, 'items_applied');
            }
        } catch (\Throwable $e) {
            Log::error('ApplyAutoOptimizeItemJob failed', ['item_id' => $this->itemId, 'error' => $e->getMessage()]);

            if ($this->attempts() >= $this->tries) {
                $item->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();
                $this->incrementRunCounters($item->auto_optimize_run_id, 'jobs_failed');
            }

            throw $e;
        }
    }

    private function incrementRunCounters(int $runId, string $field): void
    {
        AutoOptimizeRun::query()->where('id', $runId)->increment($field);
    }
}
