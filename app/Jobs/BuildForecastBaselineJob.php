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

    public int $timeout = 90;

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

        Cache::put("forecast:baseline-status:{$this->jobToken}", [
            'state' => 'building',
            'poll_after_ms' => 2000,
            'progress' => ['phase' => 'collecting payments', 'markets_done' => 0, 'markets_total' => 0],
            'cache_key' => $context->cacheKey(),
        ], now()->addMinutes(20));

        $baseline = $baselineService->build($context);
        Cache::put($context->cacheKey(), $baseline, now()->addSeconds((int) config('forecast.cache_ttl_seconds')));
        Cache::put("forecast:baseline-status:{$this->jobToken}", [
            'state' => 'ready',
            'cache_key' => $context->cacheKey(),
        ], now()->addMinutes(20));
    }

    public function failed(): void
    {
        Cache::put("forecast:baseline-status:{$this->jobToken}", [
            'state' => 'failed',
            'message' => 'Baseline build failed - the window has been left uncached, retry or narrow it.',
        ], now()->addMinutes(20));
    }
}
