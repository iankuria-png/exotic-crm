<?php

namespace App\Console\Commands;

use App\Models\AutoPushPlan;
use App\Services\AutoPush\AutoPushEngineService;
use Illuminate\Console\Command;

class RunAutoPushEngine extends Command
{
    protected $signature = 'crm:run-auto-push
        {--plan= : Run a specific auto-push plan}
        {--platform=* : Limit to platform ids}';

    protected $description = 'Generate and schedule auto-push campaigns for due plans';

    public function __construct(
        private readonly AutoPushEngineService $engineService,
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

        $plans = AutoPushPlan::query()
            ->with('platform')
            ->when($planId, fn ($query) => $query->whereKey($planId), fn ($query) => $query->enabledActive())
            ->when($platformIds !== [], fn ($query) => $query->whereIn('platform_id', $platformIds))
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty()) {
            $this->warn('No auto-push plans matched the run criteria.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($plans as $plan) {
            try {
                if (!$this->engineService->dueForRun($plan)) {
                    $rows[] = [(int) $plan->id, $plan->name, 'skipped_not_due'];
                    continue;
                }

                $run = $this->engineService->runPlan($plan);
                $rows[] = [(int) $plan->id, $plan->name, (string) $run->status];
            } catch (\Throwable $exception) {
                $rows[] = [(int) $plan->id, $plan->name, 'failed'];
                $this->warn(sprintf('Plan #%d failed: %s', (int) $plan->id, $exception->getMessage()));
            }
        }

        $this->table(['Plan', 'Name', 'Result'], $rows);

        return self::SUCCESS;
    }
}
