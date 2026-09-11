<?php

namespace App\Services;

use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the dashboard's missed-chats figure out of the dashboard's request.
 *
 * The count used to be assembled inline in GET /api/crm/dashboard: for each
 * configured market, up to 50 sequential pages from Support Board, each page a
 * blocking HTTP call with a 20-second timeout and two connection attempts.
 * Twenty markets made that a theoretical thousand round trips on the CRM's
 * busiest endpoint, every load, for one number in one tile. What kept it
 * survivable was the failure breaker tripping on the first 502 per market and
 * short-circuiting the rest — which is to say, it was only fast while Support
 * Board was reliably *down*. A slow-but-up Support Board had nothing capping it.
 *
 * So the reading and the fetching are now separate jobs. A scheduled refresh
 * computes one figure per market and writes it to the cache; the dashboard sums
 * whatever is cached and never opens a socket. A market whose refresh failed
 * keeps serving its last known figure until the entry expires, because a stale
 * count is a better answer than a three-minute page load.
 */
class MissedChatsCountService
{
    private const CACHE_PREFIX = 'dashboard.missed_chats.';

    /**
     * Deliberately much longer than the refresh interval. The TTL is a staleness
     * bound, not a schedule: if a refresh is skipped or fails, the previous
     * figure should survive rather than blanking the tile.
     */
    private const CACHE_TTL_MINUTES = 90;

    public static function cacheKey(int $platformId): string
    {
        return self::CACHE_PREFIX.$platformId;
    }

    /**
     * The read side. Cache only — this must never make a network call, because
     * it runs inside the dashboard request.
     *
     * Returns null for "no figure available", which is what the tile already
     * renders as a dash, and 0 only when the caller is genuinely scoped to no
     * markets at all.
     */
    public function cachedTotal(?array $platformIds): ?int
    {
        if (! SupportBoardService::isEnabled()) {
            return null;
        }

        if (is_array($platformIds) && empty($platformIds)) {
            return 0;
        }

        $platforms = $this->configuredPlatformIds($platformIds);

        if ($platforms === []) {
            return null;
        }

        $total = 0;
        $hasAny = false;

        foreach ($platforms as $platformId) {
            $entry = Cache::get(self::cacheKey($platformId));

            if (! is_array($entry) || ! array_key_exists('count', $entry)) {
                continue;
            }

            $total += (int) $entry['count'];
            $hasAny = true;
        }

        return $hasAny ? $total : null;
    }

    /**
     * The write side, run from the scheduler. One market at a time so a single
     * unreachable market cannot cost the others their refresh.
     *
     * @return array{refreshed:int, failed:int, skipped:int}
     */
    public function refreshAll(): array
    {
        if (! SupportBoardService::isEnabled()) {
            return ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $refreshed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->configuredPlatforms() as $platform) {
            $service = new SupportBoardService($platform);

            if (! $service->isConfigured()) {
                $skipped++;

                continue;
            }

            try {
                $count = $this->countForPlatform($service);
            } catch (\Throwable $exception) {
                // The previous figure is deliberately left in place: a market
                // that failed one refresh should keep showing its last known
                // count rather than silently dropping out of the total.
                $failed++;
                Log::warning('Missed-chats refresh failed for market.', [
                    'platform_id' => $platform->id,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            Cache::put(
                self::cacheKey((int) $platform->id),
                ['count' => $count, 'computed_at' => Carbon::now()->toIso8601String()],
                Carbon::now()->addMinutes(self::CACHE_TTL_MINUTES)
            );

            $refreshed++;
        }

        return ['refreshed' => $refreshed, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Walks a market's open conversations. The page ceiling is a guard against
     * a pagination bug turning one refresh into an unbounded crawl, not a
     * expected limit — markets are not supposed to reach it.
     */
    private function countForPlatform(SupportBoardService $service): int
    {
        $maxPages = max(1, (int) config('crm.missed_chats.max_pages', 20));
        $count = 0;
        $page = 1;

        while ($page <= $maxPages) {
            $batch = $service->getAllConversations($page);

            if (empty($batch)) {
                break;
            }

            $count += collect($batch)
                ->filter(fn (array $conversation) => (int) ($conversation['status_code'] ?? 0) !== 4)
                ->count();

            $page++;
        }

        return $count;
    }

    /**
     * @return array<int, int>
     */
    private function configuredPlatformIds(?array $platformIds): array
    {
        return $this->configuredPlatformQuery($platformIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Platform>
     */
    private function configuredPlatforms()
    {
        return $this->configuredPlatformQuery(null)->get();
    }

    private function configuredPlatformQuery(?array $platformIds)
    {
        $query = Platform::query()
            ->whereNotNull('support_board_api_url')
            ->whereNotNull('support_board_token')
            ->orderBy('id');

        if (is_array($platformIds)) {
            $query->whereIn('id', $platformIds);
        }

        return $query;
    }
}
