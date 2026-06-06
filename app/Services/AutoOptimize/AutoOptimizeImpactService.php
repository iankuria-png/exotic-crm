<?php

namespace App\Services\AutoOptimize;

use App\Models\AutoOptimizeItem;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\Log;

/**
 * Re-checks analytics impact for applied items after the configured window.
 * Uses two EQUAL windows anchored on applied_at for a fair comparison.
 */
class AutoOptimizeImpactService
{
    public function __construct(
        private readonly WpSyncService $wpSync,
    ) {}

    public function recheckDue(): int
    {
        $checked = 0;

        // Find applied items whose impact window has elapsed and haven't been checked
        AutoOptimizeItem::query()
            ->where('status', 'applied')
            ->whereNotNull('applied_at')
            ->whereNull('impact_checked_at')
            ->chunkById(50, function ($items) use (&$checked) {
                foreach ($items as $item) {
                    $plan = $item->plan()->first();
                    if (!$plan) {
                        continue;
                    }

                    $cfg = AutoOptimizeConfig::effective($plan);
                    $recheckDays = (int) ($cfg['reliability']['impact_recheck_days'] ?? 7);
                    $appliedAt = $item->applied_at;

                    // Not yet due
                    if ($appliedAt->addDays($recheckDays)->isFuture()) {
                        continue;
                    }

                    try {
                        $this->checkItem($item, $recheckDays, $plan->platform_id);
                        $checked++;
                    } catch (\Throwable $e) {
                        Log::warning('auto_optimize.impact_check_failed', [
                            'item_id' => $item->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $checked;
    }

    private function checkItem(AutoOptimizeItem $item, int $windowDays, int $platformId): void
    {
        $appliedAt = $item->applied_at;
        $wpPostId = (int) ($item->client?->wp_post_id ?? 0);

        if ($wpPostId === 0) {
            return;
        }

        $wpSync = WpSyncService::forPlatform($platformId);

        // Pre-window: [applied_at - W, applied_at]
        $preFrom = $appliedAt->copy()->subDays($windowDays)->toDateString();
        $preTo = $appliedAt->toDateString();

        // Post-window: [applied_at, applied_at + W]
        $postFrom = $appliedAt->toDateString();
        $postTo = $appliedAt->copy()->addDays($windowDays)->toDateString();

        $preAnalytics = $wpSync->getAnalytics($wpPostId, $preFrom, $preTo);
        $postAnalytics = $wpSync->getAnalytics($wpPostId, $postFrom, $postTo);

        $preViews = (float) ($preAnalytics['views'] ?? $preAnalytics['view_count'] ?? 0);
        $postViews = (float) ($postAnalytics['views'] ?? $postAnalytics['view_count'] ?? 0);
        $preContact = (float) ($preAnalytics['contact_rate'] ?? 0);
        $postContact = (float) ($postAnalytics['contact_rate'] ?? 0);
        $preEngagement = (float) ($preAnalytics['engagement'] ?? 0);
        $postEngagement = (float) ($postAnalytics['engagement'] ?? 0);

        $viewsDelta = $preViews > 0 ? (($postViews - $preViews) / $preViews) * 100 : null;
        $contactDelta = $postContact - $preContact;
        $engagementDelta = $postEngagement - $preEngagement;
        $improved = ($viewsDelta !== null && $viewsDelta > 0) || $contactDelta > 0 || $engagementDelta > 0;

        $item->forceFill([
            'impact_before' => ['from' => $preFrom, 'to' => $preTo, 'views' => $preViews, 'contact_rate' => $preContact, 'engagement' => $preEngagement],
            'impact_after' => ['from' => $postFrom, 'to' => $postTo, 'views' => $postViews, 'contact_rate' => $postContact, 'engagement' => $postEngagement],
            'impact' => [
                'views_delta_pct' => $viewsDelta,
                'contact_rate_delta' => $contactDelta,
                'engagement_delta' => $engagementDelta,
                'improved' => $improved,
            ],
            'impact_checked_at' => now(),
        ])->save();
    }
}
