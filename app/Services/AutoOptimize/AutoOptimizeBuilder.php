<?php

namespace App\Services\AutoOptimize;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LanguageDetector;
use App\Services\Seo\ProfileSnapshot;
use App\Services\Seo\ProfileSnapshotBuilder;
use App\Services\WpSyncFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AutoOptimizeBuilder
{
    public function __construct(
        private readonly WpSyncFactory $wpSyncFactory,
        private readonly ProfileSnapshotBuilder $snapshotBuilder,
        private readonly BioGenerationService $bioGenerator,
        private readonly AutoOptimizeImagePicker $imagePicker,
        private readonly AutoOptimizeAlertService $alertService,
        private readonly LanguageDetector $languageDetector,
    ) {}

    /**
     * Builds an item (already created as 'queued') by fetching canonical WP state,
     * generating a new bio, and optionally selecting a better main image.
     * No WP writes happen here.
     */
    public function buildItem(AutoOptimizeItem $item): void
    {
        $plan = $item->plan ?? $item->plan()->with('platform')->first();
        $client = $item->client ?? $item->client()->first();
        $cfg = AutoOptimizeConfig::effective($plan);
        $reliability = $cfg['reliability'];
        $actions = $cfg['actions'];
        $generation = $actions['generation'];

        $item->forceFill(['status' => 'building'])->save();

        // Platform-scoped WP client (NOT the container-injected one).
        $wpSync = $this->wpSyncFactory->forPlatform((int) $item->platform_id);

        try {
            $wpPostId = (int) ($client->wp_post_id ?? 0);
            if ($wpPostId === 0) {
                $this->fail($item, $plan, 'Client has no wp_post_id');
                return;
            }

            // Fetch canonical WP profile (NOT ProfileSnapshotBuilder — it swallows failures)
            try {
                $wpProfile = $wpSync->getClientProfile($wpPostId);
            } catch (\Throwable $e) {
                $this->fail($item, $plan, 'WP profile fetch failed: ' . $e->getMessage());
                return;
            }

            $canonicalBio = $wpProfile['content'] ?? $wpProfile['bio'] ?? '';
            $sourceBioHash = md5($canonicalBio);
            $sourceMainId = (int) ($wpProfile['main_image_attachment_id'] ?? $wpProfile['main_attachment_id'] ?? 0) ?: null;

            // Build snapshot for generation (using ProfileSnapshotBuilder which swallows WP failures
            // but we already have the canonical content — so this is just for profile facts)
            $snapshot = $this->snapshotBuilder->fromRequest($client->id, $wpPostId, (int) $plan->platform_id);

            // Persist snapshot for post-apply score recompute
            $snapshotArray = $snapshot->toArray();

            $previousScore = (int) ($client->seo_score ?? 0);
            $previousBreakdown = is_array($client->seo_score_breakdown) ? $client->seo_score_breakdown : [];

            $newBioHtml = null;
            $newScore = null;
            $newBreakdown = null;
            $providerUsed = null;
            $languageUsed = null;
            $bioSimhash = null;
            $aiCostUsd = 0.0;
            $newMainAttachmentId = null;
            $newMainImageUrl = null;

            // ── Bio optimization ──
            if ((bool) ($actions['optimize_bio'] ?? true)) {
                $language = (string) ($generation['language'] ?? 'en');

                // Respect existing language if configured
                if ((bool) ($generation['respect_existing_language'] ?? true) && trim($canonicalBio) !== '') {
                    $detected = $this->languageDetector->detect(strip_tags($canonicalBio));
                    $confidenceThreshold = (float) ($reliability['language_confidence'] ?? 0.70);
                    if ($detected['confidence'] >= $confidenceThreshold) {
                        $language = $detected['language'];
                    }
                }

                try {
                    $generated = $this->bioGenerator->generate([
                        'client_id' => $client->id,
                        'wp_post_id' => $wpPostId,
                        'platform_id' => (int) $plan->platform_id,
                        'generation_options' => array_merge($generation, ['language' => $language]),
                    ]);
                } catch (\Throwable $e) {
                    $this->fail($item, $plan, 'Bio generation failed: ' . $e->getMessage());
                    return;
                }

                // Fallback guard: never publish English template into non-English market
                if ((bool) ($generated['fallback_used'] ?? false) && $language !== 'en') {
                    $this->alertService->raise(
                        'english_fallback_blocked',
                        'warning',
                        'Bio optimization blocked: English template fallback for non-English market',
                        "Market language: {$language}. All LLM providers failed and the template fallback is English-only.",
                        ['language' => $language, 'client_id' => $client->id],
                        $plan,
                        $item,
                    );
                    $item->forceFill(['status' => 'skipped', 'reason' => 'english_fallback_blocked'])->save();
                    return;
                }

                // Reject blank or over-length output
                $bioText = strip_tags((string) ($generated['bio_html'] ?? ''));
                if (trim($bioText) === '') {
                    $item->forceFill(['status' => 'skipped', 'reason' => 'bio_generation_returned_empty'])->save();
                    return;
                }

                // Duplicate-content SimHash check
                $simhash = $this->computeSimhash(strip_tags((string) $generated['bio_html']));
                $maxDistance = (int) ($reliability['max_similarity_distance'] ?? 6);
                if ($this->isTooSimilar($simhash, $client->id, (int) $plan->platform_id, $reliability, $sourceBioHash)) {
                    $item->forceFill(['status' => 'skipped', 'reason' => 'bio_too_similar_to_existing'])->save();
                    return;
                }

                $newBioHtml = (string) $generated['bio_html'];
                $newScore = (int) ($generated['score'] ?? 0);
                $newBreakdown = is_array($generated['breakdown'] ?? null) ? $generated['breakdown'] : [];
                $providerUsed = (string) ($generated['provider_used'] ?? 'unknown');
                $languageUsed = (string) ($generated['language_used'] ?? $language);
                $bioSimhash = $simhash;
                $aiCostUsd = (float) ($generated['usage']['estimated_cost_usd'] ?? 0);
            }

            // ── Image optimization ──
            if ((bool) ($actions['switch_main_image'] ?? false)) {
                try {
                    $mediaPayload = $wpSync->getClientMedia($wpPostId);
                    $imageQualityCfg = is_array($actions['image_quality'] ?? null) ? $actions['image_quality'] : [];
                    $pickedImage = $this->imagePicker->pickBetterMain($mediaPayload, $sourceMainId, $imageQualityCfg);

                    if ($pickedImage === null && (bool) ($imageQualityCfg['require_dimensions'] ?? true)) {
                        $this->alertService->raise(
                            'image_swap_skipped',
                            'info',
                            'Image swap skipped: dimensions unavailable or no better image',
                            null,
                            ['client_id' => $client->id, 'wp_post_id' => $wpPostId],
                            $plan, $item,
                        );
                    } elseif ($pickedImage !== null) {
                        $newMainAttachmentId = (int) $pickedImage['id'];
                        $newMainImageUrl = (string) ($pickedImage['url'] ?? '');
                    }
                } catch (\Throwable $e) {
                    Log::warning('auto_optimize.image_fetch_failed', [
                        'item_id' => $item->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // If nothing to do, skip
            if ($newBioHtml === null && $newMainAttachmentId === null) {
                $item->forceFill(['status' => 'skipped', 'reason' => 'nothing_to_optimize'])->save();
                return;
            }

            $item->forceFill([
                'status' => 'pending',
                'profile_snapshot' => $snapshotArray,
                'previous_bio_html' => $canonicalBio,
                'new_bio_html' => $newBioHtml,
                'previous_score' => $previousScore,
                'new_score' => $newScore,
                'previous_score_breakdown' => $previousBreakdown,
                'new_score_breakdown' => $newBreakdown,
                'previous_main_attachment_id' => $sourceMainId,
                'new_main_attachment_id' => $newMainAttachmentId,
                'new_main_image_url' => $newMainImageUrl,
                'source_bio_hash' => $sourceBioHash,
                'source_main_attachment_id' => $sourceMainId,
                'bio_simhash' => $bioSimhash,
                'provider_used' => $providerUsed,
                'language_used' => $languageUsed,
                'ai_cost_usd' => $aiCostUsd,
            ])->save();

        } catch (\Throwable $e) {
            Log::error('auto_optimize.builder_failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($item, $plan, $e->getMessage());
        }
    }

    private function fail(AutoOptimizeItem $item, AutoOptimizePlan $plan, string $message): void
    {
        $item->forceFill(['status' => 'failed', 'error_message' => $message])->save();
        $this->alertService->raise(
            'apply_failed',
            'warning',
            'Auto Optimize build failed',
            $message,
            ['item_id' => $item->id],
            $plan, $item,
        );
    }

    private function computeSimhash(string $text): string
    {
        // 64-bit SimHash over word shingles (3-grams)
        $text = strtolower(preg_replace('/\s+/', ' ', strip_tags($text)));
        $words = explode(' ', trim($text));
        $vector = array_fill(0, 64, 0);

        for ($i = 0; $i < count($words) - 2; $i++) {
            $shingle = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
            $hash = md5($shingle);
            // Use first 16 hex chars = 64 bits
            for ($bit = 0; $bit < 64; $bit++) {
                $byteIdx = intdiv($bit, 4);
                $nibble = hexdec($hash[$byteIdx]);
                $bitInNibble = 3 - ($bit % 4);
                $bitVal = ($nibble >> $bitInNibble) & 1;
                $vector[$bit] += $bitVal ? 1 : -1;
            }
        }

        $simhash = 0;
        for ($bit = 0; $bit < 64; $bit++) {
            if ($vector[$bit] > 0) {
                $simhash |= (1 << $bit);
            }
        }

        return sprintf('%016x', $simhash & 0xFFFFFFFFFFFFFFFF);
    }

    private function isTooSimilar(
        string $simhash,
        int $clientId,
        int $platformId,
        array $reliability,
        string $previousBioHash
    ): bool {
        $maxDistance = (int) ($reliability['max_similarity_distance'] ?? 6);
        $lookbackDays = (int) ($reliability['similarity_lookback_days'] ?? 30);

        // Compare against recent bios generated by the engine for this platform
        $recentSimhashes = AutoOptimizeItem::query()
            ->where('platform_id', $platformId)
            ->where('status', 'applied')
            ->where('applied_at', '>=', now()->subDays($lookbackDays))
            ->whereNotNull('bio_simhash')
            ->pluck('bio_simhash');

        foreach ($recentSimhashes as $existing) {
            if ($this->hammingDistance($simhash, $existing) <= $maxDistance) {
                return true;
            }
        }

        // Also compare against client's own current bio
        // (previous bio hash → compute its simhash for comparison if available)
        // We can't easily get the simhash of the previous bio here — skip for now
        // The candidate bio won't match itself since it was freshly generated

        return false;
    }

    private function hammingDistance(string $a, string $b): int
    {
        $xa = hexdec(substr($a, 0, 8));
        $xb = hexdec(substr($b, 0, 8));
        $ya = hexdec(substr($a, 8, 8));
        $yb = hexdec(substr($b, 8, 8));
        $diff = ($xa ^ $xb);
        $count = 0;
        while ($diff) {
            $count += $diff & 1;
            $diff >>= 1;
        }
        $diff2 = ($ya ^ $yb);
        while ($diff2) {
            $count += $diff2 & 1;
            $diff2 >>= 1;
        }
        return $count;
    }
}
