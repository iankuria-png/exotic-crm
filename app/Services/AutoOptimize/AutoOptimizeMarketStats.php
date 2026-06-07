<?php

namespace App\Services\AutoOptimize;

use App\Services\WpSyncService;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches per-platform analytics baselines once per market/window,
 * returning averages and a per-profile map keyed by wp_post_id.
 *
 * Selection never makes per-client WP calls; only this class touches getAnalyticsBulk.
 */
class AutoOptimizeMarketStats
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(private readonly WpSyncService $wpSync) {}

    /**
     * Returns:
     * {
     *   averages: {views: float, contact_rate: float, engagement: float},
     *   sampleSize: int,
     *   perProfile: {wp_post_id: {views, contact_rate, engagement}},
     * }
     */
    public function forPlatform(int $platformId, array $window = []): array
    {
        $from = $window['from'] ?? now()->subDays(30)->toDateString();
        $to = $window['to'] ?? now()->toDateString();
        $cacheKey = "auto_optimize_market_stats:{$platformId}:{$from}:{$to}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($platformId, $from, $to) {
            return $this->fetchAndAggregate($platformId, $from, $to);
        });
    }

    private function fetchAndAggregate(int $platformId, string $from, string $to): array
    {
        $perProfile = [];
        $lastResponse = null;
        $page = 1;
        $perPage = 200;

        while (true) {
            $response = $this->wpSync->getAnalyticsBulk([
                'from' => $from,
                'to' => $to,
                'page' => $page,
                'per_page' => $perPage,
            ]);
            $lastResponse = $response;

            $rows = $response['data'] ?? $response['profiles'] ?? (is_array($response) && !isset($response['data']) ? $response : []);

            // Handle flat array response (some WP plugin versions return a plain list)
            if (isset($response['profiles'])) {
                $rows = $response['profiles'];
            } elseif (isset($response['data'])) {
                $rows = $response['data'];
            } elseif (is_array($response) && array_is_list($response)) {
                $rows = $response;
            } else {
                $rows = [];
            }

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $postId = $row['wp_post_id'] ?? $row['post_id'] ?? $row['id'] ?? null;
                if (!$postId) {
                    continue;
                }
                $perProfile[(int) $postId] = [
                    'views' => (float) ($row['views'] ?? $row['view_count'] ?? 0),
                    'contact_rate' => (float) ($row['contact_rate'] ?? 0),
                    'engagement' => (float) ($row['engagement'] ?? $row['engagement_rate'] ?? 0),
                ];
            }

            // Stop if we got fewer rows than requested (last page)
            if (count($rows) < $perPage) {
                break;
            }

            $page++;
        }

        if (empty($perProfile)) {
            return [
                'averages' => ['views' => 0.0, 'contact_rate' => 0.0, 'engagement' => 0.0],
                'sampleSize' => 0,
                'perProfile' => [],
            ];
        }

        $serverAverages = $lastResponse['market_averages'] ?? null;
        if (is_array($serverAverages)) {
            $averages = [
                'views' => (float) ($serverAverages['views'] ?? 0),
                'contact_rate' => (float) ($serverAverages['contact_rate'] ?? 0),
                'engagement' => (float) ($serverAverages['engagement'] ?? 0),
            ];
        } else {
            $count = count($perProfile);
            $averages = [
                'views' => array_sum(array_column($perProfile, 'views')) / $count,
                'contact_rate' => array_sum(array_column($perProfile, 'contact_rate')) / $count,
                'engagement' => array_sum(array_column($perProfile, 'engagement')) / $count,
            ];
        }

        return [
            'averages' => $averages,
            'sampleSize' => count($perProfile),
            'perProfile' => $perProfile,
        ];
    }
}
