<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Platform;
use App\Services\SupportBoardService;
use Illuminate\Console\Command;
use Throwable;

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

        $platforms = Platform::query()
            ->when($platformId, fn ($query) => $query->where('id', $platformId))
            ->orderBy('id')
            ->get()
            ->filter(fn (Platform $platform) => (new SupportBoardService($platform))->isConfigured())
            ->values();

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
            $service = new SupportBoardService($platform);

            $clients = Client::query()
                ->where('platform_id', $platform->id)
                ->when(!$refresh, fn ($query) => $query->whereNull('sb_user_id'))
                ->orderBy('id')
                ->get();

            if ($clients->isEmpty()) {
                $this->info("Skipping {$platformLabel}: no clients to process.");
                continue;
            }

            $this->info(sprintf(
                'Processing %d client%s for %s (platform #%d)%s',
                $clients->count(),
                $clients->count() === 1 ? '' : 's',
                $platformLabel,
                $platform->id,
                $refresh ? ' with refresh mode enabled' : ''
            ));

            $progressBar = $this->output->createProgressBar($clients->count());
            $progressBar->start();

            foreach ($clients as $index => $client) {
                $beforeSbUserId = $client->sb_user_id ? (int) $client->sb_user_id : null;
                $beforeMatchedBy = $client->sb_matched_by ?: null;

                try {
                    SupportBoardService::clearResolveCache($client);
                    $service->resolveClient($client);
                    $client->refresh();

                    $afterSbUserId = $client->sb_user_id ? (int) $client->sb_user_id : null;
                    $afterMatchedBy = $client->sb_matched_by ?: null;

                    if ($afterSbUserId && !$beforeSbUserId) {
                        $totals['matched']++;
                    } elseif (!$afterSbUserId && $beforeSbUserId) {
                        $totals['cleared']++;
                    } elseif ($afterSbUserId !== $beforeSbUserId || $afterMatchedBy !== $beforeMatchedBy) {
                        $totals['updated']++;
                    } else {
                        $totals['unchanged']++;
                    }
                } catch (Throwable $exception) {
                    $totals['errors']++;
                    $progressBar->clear();
                    $this->warn(sprintf(
                        'Client #%d failed on platform #%d: %s',
                        $client->id,
                        $platform->id,
                        $exception->getMessage()
                    ));
                    $progressBar->display();
                }

                $totals['processed']++;
                $progressBar->advance();

                if ($index < ($clients->count() - 1)) {
                    usleep(100000);
                }
            }

            $progressBar->finish();
            $this->newLine(2);
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
