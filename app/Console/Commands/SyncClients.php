<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Services\ClientSyncService;
use Illuminate\Console\Command;
use Throwable;

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
            $this->info("Syncing: {$platform->name} (ID: {$platform->id})");

            try {
                $syncService = new ClientSyncService($platform);
                $result = $full ? $syncService->fullSync() : $syncService->deltaSync();
                $payload = $this->makeSyncPayload($full, $result);

                $platform->forceFill([
                    'sync_last_synced_at' => now(),
                    'sync_last_scope' => 'clients',
                    'sync_last_status' => 'success',
                    'sync_last_error' => null,
                    'sync_last_result' => $payload,
                ])->save();

                $this->info("  Created: {$result['created']}");
                $this->info("  Updated: {$result['updated']}");
                $this->info("  Total:   {$result['total']}");
                $successCount++;
            } catch (Throwable $e) {
                $platform->forceFill([
                    'sync_last_synced_at' => now(),
                    'sync_last_scope' => 'clients',
                    'sync_last_status' => 'error',
                    'sync_last_error' => mb_substr($e->getMessage(), 0, 500),
                    'sync_last_result' => $this->makeSyncPayload($full, null, $e->getMessage()),
                ])->save();

                $this->error("  Failed: {$e->getMessage()}");
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

    private function makeSyncPayload(bool $full, ?array $result, ?string $error = null): array
    {
        return array_filter([
            'scope' => 'clients',
            'mode' => $full ? 'full' : 'delta',
            'trigger' => 'scheduler',
            'ran_at' => now()->toDateTimeString(),
            'clients' => $result,
            'error' => $error,
        ], static fn ($value) => $value !== null);
    }
}
