<?php

namespace App\Console\Commands;

use App\Services\SupportBoardLinkSyncService;
use Illuminate\Console\Command;

class SyncSupportBoardUsers extends Command
{
    protected $signature = 'crm:sync-sb-users
        {--platform= : Restrict sync to a single platform ID}
        {--refresh : Re-validate all clients instead of only clients without an SB link}';

    protected $description = 'Resolve and persist Support Board user links for CRM clients.';

    public function handle(): int
    {
        $platformId = $this->option('platform') ? (int) $this->option('platform') : null;
        $refresh = (bool) $this->option('refresh');
        /** @var SupportBoardLinkSyncService $linkSyncService */
        $linkSyncService = app(SupportBoardLinkSyncService::class);

        $platforms = $linkSyncService->configuredPlatforms($platformId);

        if ($platforms->isEmpty()) {
            $this->error($platformId
                ? 'No configured Support Board integration found for the selected platform.'
                : 'No configured Support Board integrations found.');

            return self::FAILURE;
        }

        $totals = [
            'processed' => 0,
            'matched' => 0,
            'updated' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ];

        foreach ($platforms as $platform) {
            $platformLabel = $platform->name ?: $platform->domain ?: "Platform {$platform->id}";
            $clientCount = $linkSyncService->countClientsForPlatform($platform, $refresh);

            if ($clientCount === 0) {
                $this->info("Skipping {$platformLabel}: no clients to process.");
                continue;
            }

            $this->info(sprintf(
                'Processing %d client%s for %s (platform #%d)%s',
                $clientCount,
                $clientCount === 1 ? '' : 's',
                $platformLabel,
                $platform->id,
                $refresh ? ' with refresh mode enabled' : ''
            ));

            $progressBar = $this->output->createProgressBar($clientCount);
            $progressBar->start();

            $result = $linkSyncService->syncPlatform(
                $platform,
                $refresh,
                function () use ($progressBar): void {
                    $progressBar->advance();
                }
            );

            foreach (['processed', 'matched', 'updated', 'cleared', 'unchanged', 'errors'] as $key) {
                $totals[$key] += (int) ($result[$key] ?? 0);
            }

            $progressBar->finish();
            $this->newLine(2);

            foreach (($result['errors_detail'] ?? []) as $error) {
                $this->warn(sprintf(
                    'Client #%d failed on platform #%d: %s',
                    (int) ($error['client_id'] ?? 0),
                    $platform->id,
                    (string) ($error['message'] ?? 'Unknown error')
                ));
            }
        }

        $this->info(sprintf(
            'Support Board sync complete: %d processed, %d matched, %d updated, %d cleared, %d unchanged, %d errors.',
            $totals['processed'],
            $totals['matched'],
            $totals['updated'],
            $totals['cleared'],
            $totals['unchanged'],
            $totals['errors']
        ));

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
