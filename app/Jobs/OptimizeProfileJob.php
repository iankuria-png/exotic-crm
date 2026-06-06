<?php

namespace App\Jobs;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizeRun;
use App\Services\AutoOptimize\AutoOptimizeAlertService;
use App\Services\AutoOptimize\AutoOptimizeBuilder;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OptimizeProfileJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120; // 2 min max per LLM generation

    public function __construct(public int $itemId)
    {
        $this->onQueue('auto_optimize');
    }

    public function backoff(): array
    {
        return [30, 60, 120]; // exponential-ish back-off
    }

    public function middleware(): array
    {
        $item = AutoOptimizeItem::find($this->itemId);
        $platformId = $item?->platform_id ?? 0;

        // Shared per-platform lock so OptimizeProfileJob and ApplyAutoOptimizeItemJob
        // serialize against each other (->shared() is required — default key includes job class)
        return [
            (new WithoutOverlapping("auto_optimize:platform:{$platformId}"))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    public function handle(AutoOptimizeBuilder $builder, AutoOptimizeAlertService $alertService): void
    {
        // If batch was cancelled, skip
        if ($this->batch()?->cancelled()) {
            return;
        }

        $item = AutoOptimizeItem::with(['plan', 'client', 'plan.platform'])->find($this->itemId);

        if (!$item || !in_array($item->status, ['queued', 'building'], true)) {
            // Already processed (e.g. by a previous attempt) — skip
            return;
        }

        try {
            $builder->buildItem($item);
            $this->incrementRunCounters($item->auto_optimize_run_id, 'jobs_completed');
        } catch (\Throwable $e) {
            Log::error('OptimizeProfileJob failed', ['item_id' => $this->itemId, 'error' => $e->getMessage()]);

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
