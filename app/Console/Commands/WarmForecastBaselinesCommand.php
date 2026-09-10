<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Services\Forecast\ForecastBaselineService;
use App\Services\Forecast\ForecastContext;
use Illuminate\Console\Command;

class WarmForecastBaselinesCommand extends Command
{
    protected $signature = 'crm:warm-forecast-baselines {--currency=USD}';

    protected $description = 'Pre-build common CEO forecast baselines.';

    public function handle(ForecastBaselineService $baselineService): int
    {
        $currency = strtoupper((string) $this->option('currency'));
        $to = now()->endOfDay();
        $platforms = Platform::query()->orderBy('id')->limit(6)->get(['id']);
        $warmed = 0;

        foreach ([7, 30, 90] as $days) {
            $from = $to->copy()->subDays($days - 1)->startOfDay();
            $context = new ForecastContext($from, $to->copy(), null, null, null, $currency, $days);
            $baselineService->remember($context, $baselineService->build($context));
            $warmed++;

            foreach ($platforms as $platform) {
                $context = new ForecastContext($from, $to->copy(), (int) $platform->id, (int) $platform->id, null, $currency, $days);
                $baselineService->remember($context, $baselineService->build($context));
                $warmed++;
            }
        }

        $this->info("Warmed {$warmed} forecast baseline(s).");

        return self::SUCCESS;
    }
}
