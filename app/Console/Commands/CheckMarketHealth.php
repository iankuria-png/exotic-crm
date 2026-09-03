<?php

namespace App\Console\Commands;

use App\Jobs\SendMarketDownAlertsJob;
use App\Models\Platform;
use App\Services\MarketHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckMarketHealth extends Command
{
    protected $signature = 'crm:check-market-health
        {--platform= : Restrict to a single platform ID}
        {--max-seconds= : Wall-clock budget for this pass; 0 means no budget}';

    protected $description = 'Probe market domain and WordPress sync health, then alert on new outages.';

    /**
     * Probing is two sequential HTTP calls per market with a 5s timeout each.
     * Across ~55 markets a pass in which several sites are slow can run for
     * minutes, so the pass is budgeted: markets are probed least-recently-
     * checked first and the pass stops when the budget is spent. Every market
     * still gets covered, just across consecutive passes instead of one.
     */
    public function handle(MarketHealthService $marketHealthService): int
    {
        $platformId = $this->option('platform');
        $maxSeconds = $this->option('max-seconds') === null
            ? 0
            : max(0, (int) $this->option('max-seconds'));
        $startedAt = microtime(true);
        $logger = Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/crm_market_health.log'),
        ]);

        $platforms = Platform::query()
            ->when($platformId, fn ($query, $id) => $query->where('id', (int) $id))
            ->when(! $platformId, fn ($query) => $query->where('is_active', true))
            ->orderByRaw('health_checked_at IS NOT NULL, health_checked_at ASC')
            ->orderBy('name')
            ->get();

        if ($platforms->isEmpty()) {
            $this->warn('No active platforms found for market health checks.');

            return self::SUCCESS;
        }

        $checked = 0;
        $failed = 0;
        $alertsQueued = 0;
        $skipped = 0;

        foreach ($platforms as $platform) {
            if ($maxSeconds > 0 && (microtime(true) - $startedAt) >= $maxSeconds) {
                $skipped++;
                continue;
            }

            try {
                $result = $marketHealthService->checkAndStore($platform);
                /** @var Platform $fresh */
                $fresh = $result['platform'];
                $checked++;

                $logger->info('Market health checked.', [
                    'platform_id' => (int) $fresh->id,
                    'platform' => (string) $fresh->name,
                    'status' => (string) $fresh->health_status,
                    'latency_ms' => $fresh->health_latency_ms,
                    'error' => $fresh->health_error,
                ]);

                if (($result['transitioned_down'] ?? false) && $this->cooldownElapsed($fresh)) {
                    $eventKey = sprintf(
                        '%d:%s',
                        (int) $fresh->id,
                        optional($fresh->health_down_since_at)->toIso8601String() ?: optional($fresh->health_checked_at)->toIso8601String()
                    );

                    SendMarketDownAlertsJob::dispatch(
                        (int) $fresh->id,
                        $eventKey,
                        (string) $fresh->health_status,
                        (string) ($fresh->health_error ?: 'No error message captured.')
                    )->onQueue('alerts');

                    $fresh->forceFill([
                        'health_last_down_notified_at' => now(),
                    ])->save();

                    $alertsQueued++;
                    $logger->warning('Market-down alert queued.', [
                        'platform_id' => (int) $fresh->id,
                        'event_key' => $eventKey,
                        'status' => (string) $fresh->health_status,
                    ]);
                }

                $this->line(sprintf(
                    '%s: %s',
                    $fresh->name,
                    $fresh->health_status ?: MarketHealthService::STATUS_UNCONFIGURED
                ));
            } catch (Throwable $exception) {
                $failed++;
                $logger->error('Market health check failed.', [
                    'platform_id' => (int) $platform->id,
                    'platform' => (string) $platform->name,
                    'error' => $exception->getMessage(),
                ]);
                $this->error(sprintf('%s: %s', $platform->name, $exception->getMessage()));
            }
        }

        $this->info(sprintf(
            'Market health complete: %d checked, %d failed, %d alerts queued%s.',
            $checked,
            $failed,
            $alertsQueued,
            $skipped > 0
                ? sprintf(', %d deferred to the next pass (%ds budget spent)', $skipped, $maxSeconds)
                : ''
        ));

        if ($skipped > 0) {
            $logger->info('Market health pass hit its budget.', [
                'checked' => $checked,
                'deferred' => $skipped,
                'max_seconds' => $maxSeconds,
            ]);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function cooldownElapsed(Platform $platform): bool
    {
        return ! $platform->health_last_down_notified_at
            || $platform->health_last_down_notified_at->lt(now()->subMinutes(30));
    }
}
