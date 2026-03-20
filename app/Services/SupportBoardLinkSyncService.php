<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class SupportBoardLinkSyncService
{
    public function configuredPlatforms(?int $platformId = null): Collection
    {
        return Platform::query()
            ->when($platformId, fn (Builder $query) => $query->where('id', $platformId))
            ->orderBy('id')
            ->get()
            ->filter(fn (Platform $platform) => (new SupportBoardService($platform))->isConfigured())
            ->values();
    }

    public function countClientsForPlatform(Platform $platform, bool $refresh = false): int
    {
        return $this->platformClientQuery($platform, $refresh)->count();
    }

    public function syncPlatform(Platform $platform, bool $refresh = false, ?callable $onProcessed = null): array
    {
        $supportBoard = new SupportBoardService($platform);

        if (!$supportBoard->isConfigured()) {
            throw new RuntimeException('Support Board is not configured for this market.');
        }

        $result = [
            'platform_id' => (int) $platform->id,
            'platform_name' => $platform->name ?: $platform->domain ?: "Platform {$platform->id}",
            'refresh' => $refresh,
            'ran_at' => now()->toDateTimeString(),
            'candidates' => $this->countClientsForPlatform($platform, $refresh),
            'processed' => 0,
            'matched' => 0,
            'updated' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'errors_detail' => [],
        ];

        foreach ($this->platformClientQuery($platform, $refresh)->lazyById(100, 'id') as $client) {
            $beforeSbUserId = $client->sb_user_id ? (int) $client->sb_user_id : null;
            $beforeMatchedBy = $client->sb_matched_by ?: null;
            $exception = null;

            try {
                SupportBoardService::clearResolveCache($client);
                $supportBoard->resolveClient($client);
                $client->refresh();

                $afterSbUserId = $client->sb_user_id ? (int) $client->sb_user_id : null;
                $afterMatchedBy = $client->sb_matched_by ?: null;

                $this->recordOutcome(
                    $result,
                    $beforeSbUserId,
                    $beforeMatchedBy,
                    $afterSbUserId,
                    $afterMatchedBy
                );
            } catch (Throwable $caughtException) {
                $exception = $caughtException;
                $result['errors']++;

                if (count($result['errors_detail']) < 25) {
                    $result['errors_detail'][] = [
                        'client_id' => (int) $client->id,
                        'message' => $caughtException->getMessage(),
                    ];
                }
            }

            $result['processed']++;

            if ($onProcessed) {
                $onProcessed();
            }

            usleep(100000);
        }

        return $result;
    }

    private function platformClientQuery(Platform $platform, bool $refresh): Builder
    {
        return Client::query()
            ->where('platform_id', (int) $platform->id)
            ->when(!$refresh, fn (Builder $query) => $query->whereNull('sb_user_id'));
    }

    private function recordOutcome(
        array &$result,
        ?int $beforeSbUserId,
        ?string $beforeMatchedBy,
        ?int $afterSbUserId,
        ?string $afterMatchedBy
    ): void {
        if ($afterSbUserId && !$beforeSbUserId) {
            $result['matched']++;
            return;
        }

        if (!$afterSbUserId && $beforeSbUserId) {
            $result['cleared']++;
            return;
        }

        if ($afterSbUserId !== $beforeSbUserId || $afterMatchedBy !== $beforeMatchedBy) {
            $result['updated']++;
            return;
        }

        $result['unchanged']++;
    }
}
