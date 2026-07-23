<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Payment;
use App\Services\LifecycleSmsService;
use App\Services\LifecycleSmsSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled lifecycle SMS sweep: onboarding welcomes, reactivation win-backs,
 * and the recovery reconciler (catches payment.failed events whose queue job
 * was missed). Everything routes through LifecycleSmsService, so dedup, state
 * gates, quiet hours, and rate caps make repeated runs idempotent.
 */
class RunLifecycleSms extends Command
{
    protected $signature = 'crm:run-lifecycle-sms
        {--flow=all : onboarding, recovery, reactivation, or all}
        {--platform= : limit to one platform id}
        {--limit=200 : max targets per flow per market}
        {--dry-run : evaluate targets without sending}';

    protected $description = 'Run the per-market lifecycle SMS sweeps (onboarding, recovery reconcile, reactivation)';

    public function handle(LifecycleSmsService $service, LifecycleSmsSettingsService $settings): int
    {
        if (!$settings->globalEnabled()) {
            $this->info('Lifecycle SMS is globally disabled — nothing to do.');

            return self::SUCCESS;
        }

        $flowOption = strtolower(trim((string) $this->option('flow'))) ?: 'all';
        $flows = $flowOption === 'all'
            ? [LifecycleSmsService::FLOW_RECOVERY, LifecycleSmsService::FLOW_ONBOARDING, LifecycleSmsService::FLOW_REACTIVATION]
            : [$flowOption];
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $platformFilter = $this->option('platform') !== null ? (int) $this->option('platform') : null;

        $config = $settings->currentConfig();
        $platformIds = collect(array_keys($config['markets'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && (bool) ($config['markets'][(string) $id]['sms_enabled'] ?? false))
            ->when($platformFilter, fn ($ids) => $ids->filter(fn ($id) => $id === $platformFilter))
            ->values();

        if ($platformIds->isEmpty()) {
            $this->info('No markets have lifecycle SMS enabled.');

            return self::SUCCESS;
        }

        $summaryRows = [];

        foreach ($platformIds as $platformId) {
            $marketConfig = $settings->marketConfig($platformId);

            foreach ($flows as $flow) {
                if (!$settings->flowEnabled($platformId, $flow)) {
                    continue;
                }

                $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'targeted' => 0];
                $skipReasons = [];

                $handle = function (Client $client, array $options) use ($service, $flow, $dryRun, &$counts, &$skipReasons) {
                    $counts['targeted']++;
                    $result = $service->send($flow, $client, array_merge($options, [
                        'source' => 'automated',
                        'dry_run' => $dryRun,
                    ]));

                    $status = (string) ($result['status'] ?? 'skipped');
                    if ($status === 'sent' || $status === 'would_send') {
                        $counts['sent']++;
                    } elseif ($status === 'failed') {
                        $counts['failed']++;
                    } else {
                        $counts['skipped']++;
                        $reason = (string) ($result['skip_reason'] ?? 'unknown');
                        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
                    }
                };

                if ($flow === LifecycleSmsService::FLOW_RECOVERY) {
                    $service->recoveryTargets($platformId)->limit($limit)->get()
                        ->each(function (Payment $payment) use ($handle) {
                            if ($payment->client) {
                                $handle($payment->client, ['payment' => $payment]);
                            }
                        });
                } elseif ($flow === LifecycleSmsService::FLOW_ONBOARDING) {
                    $service->onboardingTargets($platformId)->limit($limit)->get()
                        ->each(fn (Client $client) => $handle($client, []));
                } elseif ($flow === LifecycleSmsService::FLOW_REACTIVATION) {
                    $windows = (array) ($marketConfig['reactivation']['windows_days'] ?? [7]);
                    $service->reactivationTargets($platformId, $windows)->limit($limit)->get()
                        ->each(function (Client $client) use ($handle, $service, $windows) {
                            $handle($client, [
                                'window_days' => $service->reactivationWindowFor($client, $windows) ?? 0,
                            ]);
                        });
                } else {
                    $this->warn("Unknown flow '{$flow}' — skipping.");
                    continue;
                }

                $summaryRows[] = [
                    'platform' => $platformId,
                    'flow' => $flow,
                    'targeted' => $counts['targeted'],
                    $dryRun ? 'would_send' : 'sent' => $counts['sent'],
                    'skipped' => $counts['skipped'],
                    'failed' => $counts['failed'],
                    'skip_reasons' => $skipReasons === []
                        ? '-'
                        : collect($skipReasons)->map(fn ($count, $reason) => "{$reason}:{$count}")->implode(', '),
                ];

                Log::info('Lifecycle SMS sweep completed', [
                    'platform_id' => $platformId,
                    'flow' => $flow,
                    'dry_run' => $dryRun,
                    'counts' => $counts,
                    'skip_reasons' => $skipReasons,
                ]);
            }
        }

        if ($summaryRows === []) {
            $this->info('No enabled flows for the selected markets.');

            return self::SUCCESS;
        }

        $this->table(array_keys($summaryRows[0]), array_map(fn ($row) => array_values($row), $summaryRows));

        return self::SUCCESS;
    }
}
