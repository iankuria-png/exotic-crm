<?php

namespace App\Console\Commands;

use App\Models\AutoOptimizeItem;
use App\Services\AutoOptimize\AutoOptimizeImpactService;
use App\Services\AutoOptimize\AutoOptimizeWriteLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MaintainAutoOptimize extends Command
{
    protected $signature = 'crm:maintain-auto-optimize';

    protected $description = 'Recheck impact, sweep stuck items, reclaim expired write reservations';

    public function __construct(
        private readonly AutoOptimizeImpactService $impactService,
        private readonly AutoOptimizeWriteLedger $ledger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $checked = $this->impactService->recheckDue();

        $swept = $this->sweepStuckItems();

        $reclaimed = $this->ledger->reclaimExpired();

        $this->info(sprintf(
            'Auto-optimize maintenance: %d impact checks, %d stuck items swept, %d reservations reclaimed.',
            $checked,
            $swept,
            $reclaimed,
        ));

        return self::SUCCESS;
    }

    private function sweepStuckItems(): int
    {
        // Reap items stuck in ANY non-terminal state beyond a TTL. Critically this
        // includes queued/building: if the worker stalls, those items pin
        // coverageCount at daily_limit forever and dueForRun never fires again, so
        // the engine only ever processes the first batch. Reaping frees coverage
        // AND active_client_key so selection can move on.
        //   - applying  : a real write was in flight → 20 min grace
        //   - building  : LLM/WP work → 30 min grace
        //   - queued    : never picked up (worker down) → 60 min grace
        $rules = [
            'applying' => 20,
            'building' => 30,
            'queued' => 60,
        ];

        $swept = 0;
        foreach ($rules as $status => $minutes) {
            $stuck = AutoOptimizeItem::query()
                ->where('status', $status)
                ->where('updated_at', '<', now()->subMinutes($minutes))
                ->get();

            foreach ($stuck as $item) {
                Log::warning('auto_optimize.sweep_stuck_item', ['item_id' => $item->id, 'status' => $status]);
                // Model save so the active_client_key hook clears it (frees coverage + re-selection).
                $item->forceFill([
                    'status' => 'failed',
                    'error_message' => "Swept by maintenance: stuck in {$status} for >{$minutes}m (worker stalled?)",
                ])->save();
                $swept++;
            }
        }

        return $swept;
    }
}
