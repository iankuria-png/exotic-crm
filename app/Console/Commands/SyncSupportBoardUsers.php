<?php

namespace App\Console\Commands;

use App\Jobs\RunSupportBoardSyncJob;
use App\Services\SupportBoardLinkSyncService;
use App\Services\SupportBoardService;
use App\Services\SupportBoardSyncRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncSupportBoardUsers extends Command
{
    protected $signature = 'crm:sync-sb-users
        {--platform= : Restrict sync to a single platform ID}
        {--refresh : Re-validate all clients instead of only clients without an SB link}';

    protected $description = 'Resolve and persist Support Board user links for CRM clients.';

    public function handle(): int
    {
        $lock = Cache::lock('crm:sync-sb-users', 600);

        if (!$lock->get()) {
            $this->warn('Support Board sync is already running. Skipping duplicate invocation.');

            return self::SUCCESS;
        }

        $platformId = $this->option('platform') ? (int) $this->option('platform') : null;
        $refresh = (bool) $this->option('refresh');
        try {
            /** @var SupportBoardLinkSyncService $linkSyncService */
            $linkSyncService = app(SupportBoardLinkSyncService::class);
            /** @var SupportBoardSyncRunService $syncRunService */
            $syncRunService = app(SupportBoardSyncRunService::class);

            $platforms = $linkSyncService->configuredPlatforms($platformId);

            if ($platforms->isEmpty()) {
                $this->error($platformId
                    ? 'No configured Support Board integration found for the selected platform.'
                    : 'No configured Support Board integrations found.');

                return self::FAILURE;
            }

            $queue = $syncRunService->queueReadiness();
            if (!($queue['available'] ?? false)) {
                $this->error($queue['issues'][0] ?? 'Support Board background sync is not available.');

                return self::FAILURE;
            }

            $summary = [
                'queued' => 0,
                'reused' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];

            foreach ($platforms as $platform) {
                $platformLabel = $platform->name ?: $platform->domain ?: "Platform {$platform->id}";
                $clientCount = $linkSyncService->countClientsForPlatform($platform, $refresh);

                if (Cache::has(SupportBoardService::failureCacheKey((int) $platform->id))) {
                    $summary['skipped']++;
                    $message = sprintf(
                        'Skipping Support Board sync for %s (platform #%d): recent outage cached.',
                        $platformLabel,
                        (int) $platform->id
                    );

                    $this->warn($message);
                    Log::warning($message, [
                        'platform_id' => (int) $platform->id,
                    ]);
                    continue;
                }

                if ($clientCount === 0) {
                    $this->info("Skipping {$platformLabel}: no clients to queue.");
                    $summary['skipped']++;
                    continue;
                }

                try {
                    $started = $syncRunService->startAutomatedRun(
                        $platform,
                        $refresh,
                        'Scheduled Support Board link sync'
                    );
                    $run = $started['run'];

                    if ($started['reused']) {
                        $summary['reused']++;
                        $this->info(sprintf(
                            'Reusing active Support Board sync run #%d for %s (platform #%d).',
                            (int) $run->id,
                            $platformLabel,
                            $platform->id
                        ));
                        continue;
                    }

                    RunSupportBoardSyncJob::dispatch((int) $run->id);
                    $summary['queued']++;

                    $this->info(sprintf(
                        'Queued Support Board sync run #%d for %s (platform #%d, %d candidate%s)%s.',
                        (int) $run->id,
                        $platformLabel,
                        $platform->id,
                        $clientCount,
                        $clientCount === 1 ? '' : 's',
                        $refresh ? ' with refresh mode enabled' : ''
                    ));
                } catch (\Throwable $exception) {
                    $summary['failed']++;

                    Log::error('Failed to queue Support Board sync run.', [
                        'platform_id' => (int) $platform->id,
                        'error' => $exception->getMessage(),
                    ]);

                    $this->error(sprintf(
                        'Failed to queue Support Board sync for %s (platform #%d): %s',
                        $platformLabel,
                        (int) $platform->id,
                        $exception->getMessage()
                    ));
                }
            }

            $this->info(sprintf(
                'Support Board sync dispatch complete: %d queued, %d reused, %d skipped, %d failed to queue.',
                $summary['queued'],
                $summary['reused'],
                $summary['skipped'],
                $summary['failed']
            ));

            return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }
}
