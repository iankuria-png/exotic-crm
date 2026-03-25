<?php

namespace App\Console\Commands;

use App\Services\TeamActivityService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ComputeDailyStatsCommand extends Command
{
    protected $signature = 'crm:compute-daily-stats
        {--date= : Day to compute in YYYY-MM-DD format. Defaults to yesterday.}';

    protected $description = 'Compute daily Team activity rollups from audit logs and deals.';

    public function handle(TeamActivityService $teamActivityService): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->subDay()->startOfDay();

        $rows = $teamActivityService->computeDailyStats($date);

        $this->info(sprintf(
            'Computed Team daily stats for %s (%d row(s)).',
            $date->toDateString(),
            $rows
        ));

        return self::SUCCESS;
    }
}
