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
        // Items stuck in 'applying' beyond a reasonable timeout (20 min)
        $stuck = AutoOptimizeItem::query()
            ->where('status', 'applying')
            ->where('updated_at', '<', now()->subMinutes(20))
            ->get();

        $swept = 0;
        foreach ($stuck as $item) {
            Log::warning('auto_optimize.sweep_stuck_item', ['item_id' => $item->id]);
            // Must use model save so active_client_key hook fires
            $item->forceFill([
                'status' => 'failed',
                'error_message' => 'Swept by maintenance: stuck in applying status',
            ])->save();
            $swept++;
        }

        return $swept;
    }
}
