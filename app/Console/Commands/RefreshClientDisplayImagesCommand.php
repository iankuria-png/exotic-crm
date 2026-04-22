<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Platform;
use App\Services\ClientProfileImageService;
use Illuminate\Console\Command;
use Throwable;

class RefreshClientDisplayImagesCommand extends Command
{
    protected $signature = 'crm:refresh-client-display-images
        {--platform= : Platform ID to refresh}
        {--only-missing : Only refresh clients without a cached display image}
        {--limit= : Maximum number of clients to inspect}
        {--chunk=50 : Number of clients to process per chunk}
        {--sleep=0 : Seconds to sleep between WordPress media requests}
        {--dry-run : Report changes without writing them}
        {--no-verify : Do not HEAD-check media URLs before choosing a display image}';

    protected $description = 'Refresh cached client display images from WordPress profile media';

    public function handle(ClientProfileImageService $profileImageService): int
    {
        $chunkSize = max(1, min(250, (int) $this->option('chunk')));
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $sleepSeconds = max(0.0, (float) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');
        $verifyReachable = !(bool) $this->option('no-verify');

        $query = Client::query()
            ->where('wp_post_id', '>', 0)
            ->orderBy('id');

        if ($this->option('platform')) {
            $platformId = (int) $this->option('platform');
            if (!Platform::query()->whereKey($platformId)->exists()) {
                $this->error("Platform {$platformId} was not found.");
                return self::FAILURE;
            }

            $query->where('platform_id', $platformId);
        }

        if ($this->option('only-missing')) {
            $query->where(function ($builder) {
                $builder->whereNull('display_image_url')
                    ->orWhere('display_image_url', '');
            });
        }

        $seen = 0;
        $updated = 0;
        $cleared = 0;
        $failed = 0;

        $query->chunkById($chunkSize, function ($clients) use (
            $profileImageService,
            $limit,
            $sleepSeconds,
            $dryRun,
            $verifyReachable,
            &$seen,
            &$updated,
            &$cleared,
            &$failed
        ) {
            foreach ($clients as $client) {
                if ($limit !== null && $seen >= $limit) {
                    return false;
                }

                $seen++;

                try {
                    if ($dryRun) {
                        $selection = $profileImageService->selectDisplayImage(
                            \App\Services\WpSyncService::forPlatform((int) $client->platform_id)
                                ->getClientMedia((int) $client->wp_post_id),
                            $verifyReachable
                        );
                    } else {
                        $selection = $profileImageService->refreshClient($client, verifyReachable: $verifyReachable);
                    }

                    if ($selection) {
                        $updated++;
                        $this->line(sprintf(
                            '%s client #%d -> %s (%s)',
                            $dryRun ? 'Would update' : 'Updated',
                            (int) $client->id,
                            $selection['url'],
                            $selection['source']
                        ));
                    } else {
                        $cleared++;
                        $this->line(sprintf(
                            '%s client #%d -> no usable image',
                            $dryRun ? 'Would clear' : 'Cleared',
                            (int) $client->id
                        ));
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    $this->warn(sprintf(
                        'Failed client #%d: %s',
                        (int) $client->id,
                        $exception->getMessage()
                    ));
                }

                if ($sleepSeconds > 0) {
                    usleep((int) round($sleepSeconds * 1_000_000));
                }
            }

            return true;
        });

        $this->newLine();
        $this->info(sprintf(
            'Display image refresh complete. Seen: %d. Images: %d. Empty: %d. Failed: %d.%s',
            $seen,
            $updated,
            $cleared,
            $failed,
            $dryRun ? ' Dry run only.' : ''
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
