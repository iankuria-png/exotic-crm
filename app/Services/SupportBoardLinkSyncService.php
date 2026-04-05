<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SupportBoardLinkSyncService
{
    public const BULK_SYNC_CHUNK_SIZE = 100;

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
            $this->mergeClientOutcome(
                $result,
                $this->syncClient($client, $supportBoard)
            );

            if ($onProcessed) {
                $onProcessed();
            }
        }

        return $result;
    }

    public function nextClientForPlatform(Platform $platform, bool $refresh = false, int $afterId = 0): ?Client
    {
        return $this->platformClientQuery($platform, $refresh, $afterId)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Client>
     */
    public function nextClientChunkForPlatform(
        Platform $platform,
        bool $refresh = false,
        int $afterId = 0,
        int $limit = self::BULK_SYNC_CHUNK_SIZE
    ): Collection {
        return $this->platformClientQuery($platform, $refresh, $afterId)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function syncClient(Client $client, ?SupportBoardService $supportBoard = null): array
    {
        $supportBoard = $supportBoard ?: new SupportBoardService($client->platform);
        $beforeSbUserId = $client->sb_user_id ? (int) $client->sb_user_id : null;
        $beforeMatchedBy = $client->sb_matched_by ?: null;

        $result = [
            'client_id' => (int) $client->id,
            'client_name' => $client->name,
            'processed' => 1,
            'matched' => 0,
            'updated' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'error_detail' => null,
        ];

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
            $result['errors'] = 1;
            $result['error_detail'] = [
                'client_id' => (int) $client->id,
                'message' => $caughtException->getMessage(),
            ];
        }

        return $result;
    }

    /**
     * Bulk sync all clients for a platform using a single SB API call.
     * Falls back to per-client syncPlatform() if the bulk fetch fails.
     */
    public function syncPlatformBulk(Platform $platform, bool $refresh = false, ?callable $onProcessed = null): array
    {
        $result = [
            'platform_id' => (int) $platform->id,
            'platform_name' => $platform->name ?: $platform->domain ?: "Platform {$platform->id}",
            'refresh' => $refresh,
            'ran_at' => now()->toDateTimeString(),
            'candidates' => 0,
            'processed' => 0,
            'matched' => 0,
            'updated' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'errors_detail' => [],
        ];

        $result['candidates'] = $this->countClientsForPlatform($platform, $refresh);
        $cursor = 0;

        do {
            $chunk = $this->syncPlatformBulkChunk(
                $platform,
                $refresh,
                $cursor,
                self::BULK_SYNC_CHUNK_SIZE
            );

            $result['processed'] += (int) ($chunk['processed'] ?? 0);
            $result['matched'] += (int) ($chunk['matched'] ?? 0);
            $result['updated'] += (int) ($chunk['updated'] ?? 0);
            $result['cleared'] += (int) ($chunk['cleared'] ?? 0);
            $result['unchanged'] += (int) ($chunk['unchanged'] ?? 0);
            $result['errors'] += (int) ($chunk['errors'] ?? 0);

            foreach (array_values(is_array($chunk['errors_detail'] ?? null) ? $chunk['errors_detail'] : []) as $errorDetail) {
                if (is_array($errorDetail) && count($result['errors_detail']) < 25) {
                    $result['errors_detail'][] = $errorDetail;
                }
            }

            for ($processed = 0; $processed < (int) ($chunk['processed'] ?? 0); $processed++) {
                if ($onProcessed) {
                    $onProcessed();
                }
            }

            $cursor = (int) ($chunk['last_processed_client_id'] ?? 0);
            $hasMore = (bool) ($chunk['has_more'] ?? false);
        } while ($hasMore && $cursor > 0);

        return $result;
    }

    public function syncPlatformBulkChunk(
        Platform $platform,
        bool $refresh = false,
        int $afterId = 0,
        int $limit = self::BULK_SYNC_CHUNK_SIZE
    ): array {
        $supportBoard = new SupportBoardService($platform);

        if (!$supportBoard->isConfigured()) {
            throw new RuntimeException('Support Board is not configured for this market.');
        }

        $result = [
            'platform_id' => (int) $platform->id,
            'platform_name' => $platform->name ?: $platform->domain ?: "Platform {$platform->id}",
            'refresh' => $refresh,
            'ran_at' => now()->toDateTimeString(),
            'processed' => 0,
            'matched' => 0,
            'updated' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'errors_detail' => [],
            'last_processed_client_id' => null,
            'last_processed_client_name' => null,
            'has_more' => false,
        ];

        $clients = $this->nextClientChunkForPlatform($platform, $refresh, $afterId, $limit);

        if ($clients->isEmpty()) {
            return $result;
        }

        $bulkResults = $supportBoard->bulkResolveClients($clients);

        foreach ($clients as $client) {
            $outcome = $bulkResults[(int) $client->id] ?? [
                'client_id' => (int) $client->id,
                'client_name' => $client->name,
                'processed' => 1,
                'matched' => 0,
                'updated' => 0,
                'cleared' => 0,
                'unchanged' => 0,
                'errors' => 1,
                'error_detail' => [
                    'client_id' => (int) $client->id,
                    'message' => 'Support Board bulk resolver returned no outcome.',
                ],
            ];

            $this->mergeClientOutcome($result, $outcome);
        }

        $lastClient = $clients->last();
        $result['last_processed_client_id'] = $lastClient ? (int) $lastClient->id : null;
        $result['last_processed_client_name'] = $lastClient?->name;
        $result['has_more'] = $lastClient
            ? $this->nextClientForPlatform($platform, $refresh, (int) $lastClient->id) !== null
            : false;

        return $result;
    }

    private function platformClientQuery(Platform $platform, bool $refresh, int $afterId = 0): Builder
    {
        return Client::query()
            ->where('platform_id', (int) $platform->id)
            ->when($afterId > 0, fn (Builder $query) => $query->where('id', '>', $afterId))
            ->when(!$refresh, fn (Builder $query) => $query->whereNull('sb_user_id'));
    }

    private function mergeClientOutcome(array &$aggregate, array $outcome): void
    {
        $aggregate['processed'] += (int) ($outcome['processed'] ?? 0);
        $aggregate['matched'] += (int) ($outcome['matched'] ?? 0);
        $aggregate['updated'] += (int) ($outcome['updated'] ?? 0);
        $aggregate['cleared'] += (int) ($outcome['cleared'] ?? 0);
        $aggregate['unchanged'] += (int) ($outcome['unchanged'] ?? 0);
        $aggregate['errors'] += (int) ($outcome['errors'] ?? 0);

        $errorDetail = $outcome['error_detail'] ?? null;
        if (is_array($errorDetail) && count($aggregate['errors_detail']) < 25) {
            $aggregate['errors_detail'][] = $errorDetail;
        }
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
