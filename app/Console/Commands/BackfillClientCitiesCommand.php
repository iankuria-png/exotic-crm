<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Platform;
use App\Services\WpSyncService;
use App\Support\CityNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BackfillClientCitiesCommand extends Command
{
    protected $signature = 'crm:backfill-client-cities
        {platform : Platform ID or exact name}
        {--apply : Persist updates. Without this flag the command is preview-only.}
        {--limit=50 : Limit how many numeric-city clients to inspect in this run.}';

    protected $description = 'Backfill numeric CRM city values from the WordPress client profile API';

    public function handle(): int
    {
        $platform = $this->resolvePlatform((string) $this->argument('platform'));
        if (!$platform) {
            $this->error('Platform not found.');
            return self::FAILURE;
        }

        if (!$this->platformHasWpCredentials($platform)) {
            $this->error('Platform is missing WordPress API credentials.');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        $clients = Client::query()
            ->where('platform_id', $platform->id)
            ->orderBy('id')
            ->get(['id', 'wp_post_id', 'name', 'city'])
            ->filter(function (Client $client) {
                return $client->city !== null && preg_match('/^\d+$/', (string) $client->city) === 1;
            })
            ->take($limit)
            ->values();

        if ($clients->isEmpty()) {
            $this->info('No numeric city rows found for this platform.');
            return self::SUCCESS;
        }

        $wpSync = new WpSyncService($platform);
        $preview = collect();
        $backupRows = [];
        $updated = 0;
        $errors = 0;

        foreach ($clients as $client) {
            if ((int) $client->wp_post_id <= 0) {
                $preview->push([
                    'client_id' => $client->id,
                    'wp_post_id' => $client->wp_post_id,
                    'name' => $client->name,
                    'crm_city' => $client->city,
                    'wp_city' => null,
                    'status' => 'missing_wp_post_id',
                ]);
                continue;
            }

            try {
                $payload = $wpSync->getClientProfile((int) $client->wp_post_id);
                $profile = $this->unwrapProfilePayload($payload);
                $resolvedCity = CityNormalizer::fromWpPayload($profile);

                $row = [
                    'client_id' => $client->id,
                    'wp_post_id' => $client->wp_post_id,
                    'name' => $client->name,
                    'crm_city' => $client->city,
                    'wp_city' => $resolvedCity,
                    'status' => $resolvedCity ? 'resolvable' : 'unresolved',
                ];

                $preview->push($row);

                if ($apply && $resolvedCity && $resolvedCity !== $client->city) {
                    $backupRows[] = [
                        'client_id' => $client->id,
                        'wp_post_id' => $client->wp_post_id,
                        'name' => $client->name,
                        'old_city' => $client->city,
                        'new_city' => $resolvedCity,
                        'backed_up_at' => now()->toIso8601String(),
                    ];

                    $client->forceFill(['city' => $resolvedCity])->save();
                    $updated++;
                }
            } catch (\Throwable $exception) {
                $errors++;
                $preview->push([
                    'client_id' => $client->id,
                    'wp_post_id' => $client->wp_post_id,
                    'name' => $client->name,
                    'crm_city' => $client->city,
                    'wp_city' => null,
                    'status' => 'error',
                    'error' => mb_substr($exception->getMessage(), 0, 180),
                ]);
            }
        }

        $resolvable = $preview->filter(fn (array $row) => ($row['status'] ?? null) === 'resolvable')->count();
        $summary = [
            'platform_id' => $platform->id,
            'platform_name' => $platform->name,
            'inspected' => $clients->count(),
            'resolvable' => $resolvable,
            'updated' => $updated,
            'errors' => $errors,
            'mode' => $apply ? 'apply' : 'dry-run',
        ];

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->table(
            ['client_id', 'wp_post_id', 'name', 'crm_city', 'wp_city', 'status'],
            $preview->take(20)->map(fn (array $row) => [
                $row['client_id'] ?? null,
                $row['wp_post_id'] ?? null,
                $row['name'] ?? null,
                $row['crm_city'] ?? null,
                $row['wp_city'] ?? null,
                $row['status'] ?? null,
            ])->all()
        );

        if ($apply && !empty($backupRows)) {
            $backupPath = storage_path(
                'app/backfills/client-city-backfill-platform-' . $platform->id . '-' . now()->format('Ymd_His') . '.json'
            );
            @mkdir(dirname($backupPath), 0777, true);
            file_put_contents($backupPath, json_encode([
                'platform_id' => $platform->id,
                'platform_name' => $platform->name,
                'generated_at' => now()->toIso8601String(),
                'rows' => $backupRows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->info('Backup written to: ' . $backupPath);
        }

        if (!$apply) {
            $this->comment('Dry run only. Re-run with --apply to persist updates.');
        }

        return self::SUCCESS;
    }

    private function resolvePlatform(string $input): ?Platform
    {
        if (ctype_digit($input)) {
            return Platform::query()->find((int) $input);
        }

        return Platform::query()->where('name', $input)->first();
    }

    private function platformHasWpCredentials(Platform $platform): bool
    {
        return filled($platform->wp_api_url)
            && filled($platform->wp_api_user)
            && filled($platform->wp_api_password);
    }

    private function unwrapProfilePayload(array $payload): array
    {
        $profile = $payload['client'] ?? $payload['data'] ?? $payload;

        return is_array($profile) ? $profile : [];
    }
}
