<?php

namespace App\Jobs;

use App\Services\Forecast\ForecastBaselineService;
use App\Services\Forecast\ForecastContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class BuildForecastBaselineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function __construct(
        private readonly array $context,
        private readonly string $jobToken
    ) {}

    public function handle(ForecastBaselineService $baselineService): void
    {
        $context = new ForecastContext(
            from: Carbon::parse($this->context['from'])->startOfDay(),
            to: Carbon::parse($this->context['to'])->endOfDay(),
            platformId: $this->context['platform_id'] ?? null,
            platformScope: $this->context['platform_id'] ?? ($this->context['accessible_platform_ids'] ?? null),
            accessiblePlatformIds: $this->context['accessible_platform_ids'] ?? null,
            currency: $this->context['currency'],
            days: (int) $this->context['days'],
            mode: $this->context['mode'] ?? 'project',
            horizonDays: (int) ($this->context['horizon_days'] ?? 90),
        );

        $startedAt = now();
        $durationKey = 'forecast:build-duration:'.($context->platformId ? 'market' : 'all');
        // Only ever an estimate learned from the last successful build of the same
        // shape. Absent on the first run, and the UI says "estimating" rather than
        // inventing a number.
        $estimate = Cache::get($durationKey);

        $publish = function (string $phase, int $done = 0, int $total = 0) use ($context, $startedAt, $estimate): void {
            Cache::put("forecast:baseline-status:{$this->jobToken}", [
                'state' => 'building',
                'poll_after_ms' => 1500,
                'started_at' => $startedAt->toIso8601String(),
                'estimated_seconds' => $estimate,
                'progress' => [
                    'phase' => $phase,
                    'markets_done' => $done,
                    'markets_total' => $total,
                ],
                'cache_key' => $context->cacheKey(),
            ], now()->addMinutes(20));
        };

        $publish('Starting');

        $baseline = $baselineService->build($context, $publish);

        Cache::put($context->cacheKey(), $baseline, now()->addSeconds((int) config('forecast.cache_ttl_seconds')));
        Cache::put($durationKey, max(1, (int) $startedAt->diffInSeconds(now())), now()->addDays(7));
        Cache::put("forecast:baseline-status:{$this->jobToken}", [
            'state' => 'ready',
            'cache_key' => $context->cacheKey(),
            'built_in_seconds' => max(1, (int) $startedAt->diffInSeconds(now())),
        ], now()->addMinutes(20));
    }

    public function failed(): void
    {
        Cache::put("forecast:baseline-status:{$this->jobToken}", [
            'state' => 'failed',
            'message' => 'The baseline build did not finish. Nothing was cached, so retrying is safe.',
            'failed_at' => now()->toIso8601String(),
        ], now()->addMinutes(20));
    }
}
