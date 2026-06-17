<?php

namespace App\Services\AutoOptimize;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Selects underperforming clients eligible for optimization.
 * Makes exactly ONE analytics call per market (getAnalyticsBulk via AutoOptimizeMarketStats).
 * Zero per-client WP calls.
 */
class AutoOptimizeSelectionService
{
    public function __construct(
        private readonly AutoOptimizeMarketStats $marketStats,
    ) {}

    /**
     * @return Collection<Client>
     */
    public function selectForPlan(AutoOptimizePlan $plan): Collection
    {
        $cfg = AutoOptimizeConfig::effective($plan);
        $criteria = $cfg['criteria'];
        $schedule = $cfg['schedule'];
        $reliability = $cfg['reliability'];

        $windowDays = (int) ($criteria['eligibility_window_days'] ?? 30);
        $window = [
            'from' => now()->subDays($windowDays)->toDateString(),
            'to' => now()->toDateString(),
        ];

        $stats = $this->marketStats->forPlatform((int) $plan->platform_id, $window);

        $maxMarketSize = (int) config('auto_optimize.max_unoptimized_market_size', 500);
        if ($stats['sampleSize'] > $maxMarketSize) {
            $stats['perProfile'] = array_slice($stats['perProfile'], 0, $maxMarketSize, true);
        }

        if ($stats['sampleSize'] < (int) ($criteria['min_market_sample'] ?? 10)) {
            return collect();
        }

        $averages = $stats['averages'];
        $perProfile = $stats['perProfile'];

        // Clients currently in an active item (cross-run idempotency)
        $activeClientIds = AutoOptimizeItem::query()
            ->whereNotNull('active_client_key')
            ->where('platform_id', $plan->platform_id)
            ->pluck('client_id')
            ->flip(); // O(1) lookup

        // Clients optimized recently
        $excludeWithinDays = (int) ($reliability['exclude_optimized_within_days'] ?? 14);
        $recentlyOptimizedIds = AutoOptimizeItem::query()
            ->where('platform_id', $plan->platform_id)
            ->where('status', 'applied')
            ->where('applied_at', '>=', now()->subDays($excludeWithinDays))
            ->pluck('client_id')
            ->flip();

        // Clients skipped recently — without this, skip-prone profiles (bio gain
        // below threshold, too-similar, etc.) get re-selected, re-built and
        // re-skipped on EVERY run, churning the worker and LLM cost for nothing.
        $excludeSkippedDays = (int) ($reliability['exclude_skipped_within_days'] ?? 7);
        $recentlySkippedIds = AutoOptimizeItem::query()
            ->where('platform_id', $plan->platform_id)
            ->where('status', 'skipped')
            ->where('updated_at', '>=', now()->subDays($excludeSkippedDays))
            ->pluck('client_id')
            ->flip();

        $maxScore = (int) ($criteria['max_score'] ?? 60);
        $viewsPct = (float) ($criteria['views_below_market_pct'] ?? 80) / 100;
        $contactPct = (float) ($criteria['contact_rate_below_market_pct'] ?? 80) / 100;
        $engagementPct = (float) ($criteria['engagement_below_market_pct'] ?? 80) / 100;
        $requireBelow = (string) ($criteria['require_below'] ?? 'any');
        $onlyPublished = (bool) ($criteria['only_published'] ?? true);
        $dailyLimit = (int) ($schedule['daily_limit'] ?? 20);

        $candidates = collect();

        // Chunk CRM clients by wp_post_id intersection with analytics data
        $wpPostIds = array_keys($perProfile);

        if (empty($wpPostIds)) {
            return collect();
        }

        // Process in chunks to keep memory flat
        $chunkSize = 200;
        foreach (array_chunk($wpPostIds, $chunkSize) as $chunk) {
            $query = Client::query()
                ->whereIn('wp_post_id', $chunk)
                ->where('platform_id', $plan->platform_id)
                ->when($onlyPublished, fn ($q) => $q->where('profile_status', 'publish'));

            $query->chunkById(100, function ($clients) use (
                $perProfile, $averages,
                $activeClientIds, $recentlyOptimizedIds, $recentlySkippedIds,
                $maxScore, $viewsPct, $contactPct, $engagementPct,
                $requireBelow, &$candidates, $dailyLimit
            ) {
                foreach ($clients as $client) {
                    if ($candidates->count() >= $dailyLimit) {
                        return false; // stop chunking
                    }

                    if (isset($activeClientIds[$client->id])) {
                        continue;
                    }
                    if (isset($recentlyOptimizedIds[$client->id])) {
                        continue;
                    }
                    if (isset($recentlySkippedIds[$client->id])) {
                        continue;
                    }

                    $score = (int) ($client->seo_score ?? 0);
                    if ($score > $maxScore) {
                        continue;
                    }

                    $profileMetrics = $perProfile[(int) $client->wp_post_id] ?? null;
                    if ($profileMetrics === null) {
                        continue;
                    }

                    $belowViews = $averages['views'] > 0 && $profileMetrics['views'] < $averages['views'] * $viewsPct;
                    $belowContact = $averages['contact_rate'] > 0 && $profileMetrics['contact_rate'] < $averages['contact_rate'] * $contactPct;
                    $belowEngagement = $averages['engagement'] > 0 && $profileMetrics['engagement'] < $averages['engagement'] * $engagementPct;

                    $analyticsQualifies = $requireBelow === 'all'
                        ? $belowViews && $belowContact && $belowEngagement
                        : $belowViews || $belowContact || $belowEngagement;

                    if (!$analyticsQualifies) {
                        continue;
                    }

                    // Score worst-first for priority ordering
                    $candidates->push([
                        'client' => $client,
                        'score' => $score,
                        'metrics' => $profileMetrics,
                    ]);
                }
            });
        }

        return $candidates
            ->sortBy('score')
            ->take($dailyLimit)
            ->map(fn ($c) => $c['client'])
            ->values();
    }
}
