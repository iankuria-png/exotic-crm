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
    protected $signature = 'crm:check-market-health {--platform= : Restrict to a single platform ID}';

    protected $description = 'Probe market domain and WordPress sync health, then alert on new outages.';

    public function handle(MarketHealthService $marketHealthService): int
    {
        $platformId = $this->option('platform');
        $logger = Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/crm_market_health.log'),
        ]);

        $platforms = Platform::query()
            ->when($platformId, fn ($query, $id) => $query->where('id', (int) $id))
            ->when(! $platformId, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        if ($platforms->isEmpty()) {
            $this->warn('No active platforms found for market health checks.');

            return self::SUCCESS;
        }

        $checked = 0;
        $failed = 0;
        $alertsQueued = 0;

        foreach ($platforms as $platform) {
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
            'Market health complete: %d checked, %d failed, %d alerts queued.',
            $checked,
            $failed,
            $alertsQueued
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function cooldownElapsed(Platform $platform): bool
    {
        return ! $platform->health_last_down_notified_at
            || $platform->health_last_down_notified_at->lt(now()->subMinutes(30));
    }
}
