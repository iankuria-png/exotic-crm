<?php

namespace App\Console\Commands;

use App\Jobs\RunClientSyncJob;
use App\Models\Platform;
use App\Services\ClientSyncRunService;
use Illuminate\Console\Command;
use Throwable;

class SyncClients extends Command
{
    protected $signature = 'crm:sync-clients
        {--platform= : Platform ID to sync (default: all active)}
        {--full : Run reconciliation/full sync instead of delta}';

    protected $description = 'Sync client profiles from WordPress to the CRM clients table';

    public function handle(): int
    {
        /** @var ClientSyncRunService $clientSyncRunService */
        $clientSyncRunService = app(ClientSyncRunService::class);
        $platformId = $this->option('platform');
        $full = $this->option('full');

        $platforms = ($platformId
            ? Platform::where('id', $platformId)->whereNotNull('wp_api_url')->get()
            : Platform::where('is_active', true)->whereNotNull('wp_api_url')->get())
            ->filter(fn (Platform $platform) => $this->platformHasWpCredentials($platform))
            ->values();

        if ($platforms->isEmpty()) {
            $this->error('No platforms found with WP API configured.');
            return 1;
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($platforms as $platform) {
            $this->info("Queueing sync: {$platform->name} (ID: {$platform->id})");

            try {
                $started = $clientSyncRunService->startAutomatedRun(
                    $platform,
                    $full ? 'reconcile' : 'delta',
                    $full ? 'Scheduled nightly client reconciliation' : 'Scheduled delta client sync'
                );

                if (!$started['reused']) {
                    RunClientSyncJob::dispatch((int) $started['run']->id, 100)
                        ->onQueue($full ? 'sync-clients-reconcile' : 'sync-clients');
                    $this->info(sprintf('  Queued run #%d (%s).', $started['run']->id, $full ? 'reconcile' : 'delta'));
                } else {
                    $this->warn(sprintf('  Reusing active run #%d.', $started['run']->id));
                }
                $successCount++;
            } catch (Throwable $e) {
                $this->error("  Failed to queue: {$e->getMessage()}");
                $failureCount++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Sync completed. Successful markets: %d. Failed markets: %d.',
            $successCount,
            $failureCount
        ));

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function platformHasWpCredentials(Platform $platform): bool
    {
        return filled($platform->wp_api_url)
            && filled($platform->wp_api_user)
            && filled($platform->wp_api_password);
    }
}
