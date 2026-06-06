<?php

namespace App\Console\Commands;

use App\Models\AutoOptimizePlan;
use App\Services\AutoOptimize\AutoOptimizeEngineService;
use Illuminate\Console\Command;

class RunAutoOptimizeEngine extends Command
{
    protected $signature = 'crm:run-auto-optimize
        {--plan= : Run a specific auto-optimize plan}
        {--platform=* : Limit to platform ids}
        {--force : Bypass dueForRun check}';

    protected $description = 'Run auto-optimize engine for due plans';

    public function __construct(
        private readonly AutoOptimizeEngineService $engineService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $planId = $this->option('plan') ? (int) $this->option('plan') : null;
        $platformIds = collect((array) $this->option('platform'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
        $force = (bool) $this->option('force');

        $query = AutoOptimizePlan::query()
            ->with('platform')
            ->when($planId, fn ($q) => $q->whereKey($planId), fn ($q) => $q->enabledActive())
            ->when($platformIds !== [], fn ($q) => $q->whereIn('platform_id', $platformIds))
            ->orderBy('id');

        $plans = $query->get();

        if ($plans->isEmpty()) {
            $this->warn('No auto-optimize plans matched the run criteria.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($plans as $plan) {
            try {
                if (!$force && !$this->engineService->dueForRun($plan)) {
                    $rows[] = [(int) $plan->id, $plan->name, 'skipped_not_due'];
                    continue;
                }

                $run = $this->engineService->runPlan($plan->fresh('platform'));
                $rows[] = [(int) $plan->id, $plan->name, (string) $run->status];
            } catch (\Throwable $exception) {
                $rows[] = [(int) $plan->id, $plan->name, 'failed'];
                $this->warn(sprintf('Plan #%d failed: %s', (int) $plan->id, $exception->getMessage()));
            }
        }

        $this->table(['Plan ID', 'Name', 'Result'], $rows);

        return self::SUCCESS;
    }
}
