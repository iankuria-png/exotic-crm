<?php

namespace App\Console\Commands;

use App\Models\ForecastScenario;
use App\Services\Revenue\CollectedRevenueQuery;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ScoreForecastAccuracyCommand extends Command
{
    protected $signature = 'crm:score-forecast-accuracy {--chunk=100}';

    protected $description = 'Score elapsed forecast scenarios against actual collected revenue.';

    public function handle(CollectedRevenueQuery $revenueQuery): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $scored = 0;

        ForecastScenario::query()
            ->whereNotNull('horizon_ends_on')
            ->whereDate('horizon_ends_on', '<=', now()->toDateString())
            ->whereNull('scored_at')
            ->orderBy('id')
            ->chunkById($chunk, function ($scenarios) use ($revenueQuery, &$scored) {
                foreach ($scenarios as $scenario) {
                    $actual = $revenueQuery->total(
                        Carbon::parse($scenario->baseline_to)->copy()->addDay()->startOfDay(),
                        Carbon::parse($scenario->horizon_ends_on)->endOfDay(),
                        $scenario->platform_id ? (int) $scenario->platform_id : null,
                        (string) $scenario->reporting_currency
                    );
                    $actualTotal = (float) ($actual['normalized_total'] ?? 0);
                    $promised = (float) data_get($scenario->snapshot, 'scenario_total', data_get($scenario->snapshot, 'reached_monthly', 0));

                    $scenario->forceFill([
                        'actual_total' => round($actualTotal, 2),
                        'variance_percent' => $promised > 0 ? round((($actualTotal - $promised) / $promised) * 100, 2) : null,
                        'scored_at' => now(),
                    ])->save();
                    $scored++;
                }
            });

        $this->info("Scored {$scored} forecast scenario(s).");

        return self::SUCCESS;
    }
}
