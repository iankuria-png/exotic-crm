<?php

namespace App\Console\Commands;

use App\Jobs\RunClientSyncJob;
use App\Models\Platform;
use App\Services\ClientSyncRunService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class SyncClients extends Command
{
    protected $signature = 'crm:sync-clients
        {--platform= : Platform ID to sync (default: all active)}
        {--full : Run reconciliation/full sync instead of delta}
        {--per-page= : WordPress profiles per API request, capped at 100}
        {--max-platforms= : Maximum platforms to queue in this command run; 0 means all}
        {--stagger-seconds= : Seconds to delay between queued platform sync jobs}
        {--rotate : Rotate the limited platform window by the current hour}';

    protected $description = 'Sync client profiles from WordPress to the CRM clients table';

    public function handle(): int
    {
        /** @var ClientSyncRunService $clientSyncRunService */
        $clientSyncRunService = app(ClientSyncRunService::class);
        $platformId = $this->option('platform');
        $full = $this->option('full');
        $perPage = $this->boundedIntegerOption('per-page', (int) config('services.client_sync.per_page', 100), 1, 100);
        $maxPlatforms = $this->boundedIntegerOption('max-platforms', 0, 0, 1000);
        $staggerSeconds = $this->boundedIntegerOption('stagger-seconds', 0, 0, 3600);
        $rotate = (bool) $this->option('rotate');

        $platforms = ($platformId
            ? Platform::where('id', $platformId)->whereNotNull('wp_api_url')->orderBy('id')->get()
            : Platform::where('is_active', true)->whereNotNull('wp_api_url')->orderBy('id')->get())
            ->filter(fn (Platform $platform) => $this->platformHasWpCredentials($platform))
            ->values();

        if ($platforms->isEmpty()) {
            $this->error('No platforms found with WP API configured.');

            return 1;
        }

        if (! $platformId && $maxPlatforms > 0) {
            $originalCount = $platforms->count();
            $platforms = $this->applyPlatformWindow($platforms, $maxPlatforms, $rotate);
            $this->info(sprintf(
                'Platform window: queueing %d of %d configured markets%s.',
                $platforms->count(),
                $originalCount,
                $rotate ? ' (rotated)' : ''
            ));
        }

        $successCount = 0;
        $failureCount = 0;
        $queuedIndex = 0;

        foreach ($platforms as $platform) {
            $this->info("Queueing sync: {$platform->name} (ID: {$platform->id})");

            try {
                $started = $clientSyncRunService->startAutomatedRun(
                    $platform,
                    $full ? 'reconcile' : 'delta',
                    $full ? 'Scheduled nightly client reconciliation' : 'Scheduled delta client sync'
                );

                if (! $started['reused']) {
                    $dispatch = RunClientSyncJob::dispatch((int) $started['run']->id, $perPage)
                        ->onQueue($full ? 'sync-clients-reconcile' : 'sync-clients');

                    if ($staggerSeconds > 0 && $queuedIndex > 0) {
                        $dispatch->delay(now()->addSeconds($staggerSeconds * $queuedIndex));
                    }

                    $this->info(sprintf(
                        '  Queued run #%d (%s, per_page=%d%s).',
                        $started['run']->id,
                        $full ? 'reconcile' : 'delta',
                        $perPage,
                        $staggerSeconds > 0 && $queuedIndex > 0
                            ? sprintf(', delay=%ds', $staggerSeconds * $queuedIndex)
                            : ''
                    ));
                    unset($dispatch);
                    $queuedIndex++;
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

    private function boundedIntegerOption(string $name, int $default, int $min, int $max): int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return max($min, min($max, $default));
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param  Collection<int, Platform>  $platforms
     * @return Collection<int, Platform>
     */
    private function applyPlatformWindow(Collection $platforms, int $maxPlatforms, bool $rotate): Collection
    {
        $count = $platforms->count();

        if ($count === 0 || $maxPlatforms <= 0 || $maxPlatforms >= $count) {
            return $platforms->values();
        }

        $offset = 0;
        if ($rotate) {
            $hourSlot = intdiv(now()->timestamp, 3600);
            $offset = (int) (($hourSlot * $maxPlatforms) % $count);
        }

        return $platforms
            ->slice($offset)
            ->concat($platforms->slice(0, $offset))
            ->take($maxPlatforms)
            ->values();
    }
}
