<?php

namespace App\Console\Commands;

use App\Services\ClientProfileMetricsService;
use App\Services\LifecycleSmsSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the per-client analytics snapshot (client_profile_metrics) that
 * powers dynamic lifecycle copy. ONE cached bulk call per market; a broken
 * market is isolated and never blocks the others.
 */
class SnapshotClientProfileMetrics extends Command
{
    protected $signature = 'crm:snapshot-profile-metrics
        {--platform= : limit to one platform id}
        {--all : snapshot every lifecycle-enabled market even without the flag}';

    protected $description = 'Snapshot per-client WP analytics for lifecycle SMS dynamic copy';

    public function handle(ClientProfileMetricsService $metricsService, LifecycleSmsSettingsService $settings): int
    {
        $platformFilter = $this->option('platform') !== null ? (int) $this->option('platform') : null;

        $config = $settings->currentConfig();
        $platformIds = collect(array_keys($config['markets'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && (bool) ($config['markets'][(string) $id]['sms_enabled'] ?? false))
            ->when($platformFilter, fn ($ids) => $ids->filter(fn ($id) => $id === $platformFilter))
            ->values();

        if ($platformFilter && $platformIds->isEmpty()) {
            // Explicit platform request wins even if the market flag is off.
            $platformIds = collect([$platformFilter]);
        }

        if ($platformIds->isEmpty()) {
            $this->info('No lifecycle-enabled markets to snapshot.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($platformIds as $platformId) {
            $result = $metricsService->snapshotPlatform($platformId);
            $rows[] = [
                'platform' => $platformId,
                'status' => $result['status'],
                'updated' => $result['updated'],
                'error' => isset($result['error']) ? mb_substr((string) $result['error'], 0, 80) : '-',
            ];
        }

        $this->table(['platform', 'status', 'updated', 'error'], array_map(fn ($row) => array_values($row), $rows));
        Log::info('Lifecycle metrics snapshot run finished', ['results' => $rows]);

        return self::SUCCESS;
    }
}
