<?php

namespace App\Console\Commands;

use App\Services\ClientRetentionInsightService;
use Illuminate\Console\Command;

class RefreshRetentionInsights extends Command
{
    protected $signature = 'crm:refresh-retention-insights
        {--platform_id=* : Restrict the refresh to one or more platform IDs}
        {--daily-only : Only refresh the daily churn snapshot summary}';

    protected $description = 'Refresh per-client retention insights and daily churn snapshots.';

    public function handle(ClientRetentionInsightService $service): int
    {
        $platformIds = collect((array) $this->option('platform_id'))
            ->filter(static fn ($value) => (int) $value > 0)
            ->map(static fn ($value) => (int) $value)
            ->values()
            ->all();

        if (!$this->option('daily-only')) {
            $this->info('Refreshing client retention insights...');
            $service->refreshAll($platformIds ?: null);
        }

        $this->info('Recording daily retention snapshots...');
        $service->recordDailyMetricSnapshots(null, $platformIds ?: null);

        $this->info('Retention refresh complete.');

        return self::SUCCESS;
    }
}
