<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Platform;
use App\Services\ClientSyncService;

class SyncClients extends Command
{
    protected $signature = 'crm:sync-clients
        {--platform= : Platform ID to sync (default: all active)}
        {--full : Run full sync instead of delta}';

    protected $description = 'Sync client profiles from WordPress to the CRM clients table';

    public function handle(): int
    {
        $platformId = $this->option('platform');
        $full = $this->option('full');

        $platforms = $platformId
            ? Platform::where('id', $platformId)->whereNotNull('wp_api_url')->get()
            : Platform::where('is_active', true)->whereNotNull('wp_api_url')->get();

        if ($platforms->isEmpty()) {
            $this->error('No platforms found with WP API configured.');
            return 1;
        }

        foreach ($platforms as $platform) {
            $this->info("Syncing: {$platform->name} (ID: {$platform->id})");

            try {
                $syncService = new ClientSyncService($platform);
                $result = $full ? $syncService->fullSync() : $syncService->deltaSync();

                $this->info("  Created: {$result['created']}");
                $this->info("  Updated: {$result['updated']}");
                $this->info("  Total:   {$result['total']}");
            } catch (\Exception $e) {
                $this->error("  Failed: {$e->getMessage()}");
                return 1;
            }
        }

        $this->newLine();
        $this->info('Sync completed successfully.');
        return 0;
    }
}
