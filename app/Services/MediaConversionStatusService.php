<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Status feed for background video conversions.
 *
 * Cache-backed on purpose: a conversion is short-lived operational state, not
 * a record worth a table and a production migration. Mirrors how push-campaign
 * upload batches report progress.
 */
class MediaConversionStatusService
{
    private const TTL_HOURS = 6;

    private const INDEX_LIMIT = 40;

    public const ACTIVE_STATUSES = ['queued', 'converting', 'uploading'];

    public function cacheKey(string $conversionId): string
    {
        return 'media_conversion:' . $conversionId;
    }

    private function indexKey(int $clientId): string
    {
        return 'media_conversion:client:' . $clientId;
    }

    public function get(string $conversionId): ?array
    {
        $payload = Cache::get($this->cacheKey($conversionId));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function put(string $conversionId, array $payload): array
    {
        $merged = array_merge($this->get($conversionId) ?? [], $payload, [
            'conversion_id' => $conversionId,
            'updated_at' => now()->toIso8601String(),
        ]);

        Cache::put($this->cacheKey($conversionId), $merged, now()->addHours(self::TTL_HOURS));

        if (! empty($merged['client_id'])) {
            $this->touchIndex((int) $merged['client_id'], $conversionId);
        }

        return $merged;
    }

    /**
     * Conversions for one client, newest first, with dead entries pruned.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forClient(int $clientId): array
    {
        $ids = Cache::get($this->indexKey($clientId));
        if (! is_array($ids)) {
            return [];
        }

        $conversions = [];
        foreach ($ids as $conversionId) {
            $payload = $this->get((string) $conversionId);
            if ($payload !== null) {
                $conversions[] = $payload;
            }
        }

        return $conversions;
    }

    private function touchIndex(int $clientId, string $conversionId): void
    {
        $ids = Cache::get($this->indexKey($clientId));
        $ids = is_array($ids) ? $ids : [];

        $ids = array_values(array_unique(array_merge([$conversionId], $ids)));
        if (count($ids) > self::INDEX_LIMIT) {
            $ids = array_slice($ids, 0, self::INDEX_LIMIT);
        }

        Cache::put($this->indexKey($clientId), $ids, now()->addHours(self::TTL_HOURS * 2));
    }
}
