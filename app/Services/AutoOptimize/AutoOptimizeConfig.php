<?php

namespace App\Services\AutoOptimize;

use App\Models\AutoOptimizePlan;
use App\Services\Seo\BioGenerationService;

/**
 * Returns the effective configuration for a plan, merging per-market values
 * over global SEO Engine defaults. All services must read config through this
 * resolver — no magic numbers in service code.
 */
class AutoOptimizeConfig
{
    public static function effective(AutoOptimizePlan $plan): array
    {
        $criteria = is_array($plan->criteria) ? $plan->criteria : [];
        $actions = is_array($plan->actions) ? $plan->actions : [];
        $schedule = is_array($plan->schedule) ? $plan->schedule : [];
        $reliability = is_array($plan->reliability) ? $plan->reliability : [];

        // Merge actions.generation with global SEO Engine defaults
        $actions['generation'] = self::effectiveGeneration(
            is_array($actions['generation'] ?? null) ? $actions['generation'] : []
        );

        return [
            'criteria' => array_merge(self::defaultCriteria(), $criteria),
            'actions' => array_merge(self::defaultActions(), $actions),
            'schedule' => array_merge(self::defaultSchedule(), $schedule),
            'reliability' => array_merge(self::defaultReliability(), $reliability),
        ];
    }

    /**
     * Sane starting values written to the DB when a market first creates a plan.
     * NOT used at runtime — only for UI pre-population.
     */
    public static function defaultPlanTemplate(): array
    {
        return [
            'criteria' => self::defaultCriteria(),
            'actions' => self::defaultActions(),
            'schedule' => self::defaultSchedule(),
            'reliability' => self::defaultReliability(),
        ];
    }

    /**
     * Validates scorer_weights when provided: must contain all four keys and total 100.
     * Returns a validation error string, or null if valid.
     */
    public static function validateScorerWeights(?array $weights): ?string
    {
        if ($weights === null) {
            return null; // null = inherit global, always valid
        }

        $required = ['word_count', 'links', 'completeness', 'media'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $weights)) {
                return "scorer_weights must contain all four keys: " . implode(', ', $required);
            }
        }

        $total = array_sum(array_map('intval', $weights));
        if ($total !== 100) {
            return "scorer_weights must total 100, got {$total}";
        }

        return null;
    }

    // -------------------------------------------------------------------------

    private static function effectiveGeneration(array $perMarket): array
    {
        // Global SEO Engine generation defaults
        $global = is_array(config('services.seo_engine.generation')) ? config('services.seo_engine.generation') : [];
        $globalProviders = config('services.seo_engine.providers', ['claude', 'openai', 'gemini', 'deepseek']);
        $globalWeights = config('services.seo_engine.scorer_weights', [
            'word_count' => 25, 'links' => 25, 'completeness' => 25, 'media' => 25,
        ]);

        // Per-market generation inherits from global; explicit null = inherit
        $generation = array_merge([
            'language' => $global['language'] ?? 'en',
            'respect_existing_language' => true,
            'tone' => $global['tone'] ?? 'simple, direct, local classified profile copy',
            'temperament' => $global['temperament'] ?? 'confident but not exaggerated',
            'min_words' => $global['min_words'] ?? 55,
            'max_words' => $global['max_words'] ?? 95,
            'max_characters' => $global['max_characters'] ?? 750,
            'max_services' => $global['max_services'] ?? 5,
            'include_location' => $global['include_location'] ?? true,
            'include_services' => $global['include_services'] ?? true,
            'include_contact' => $global['include_contact'] ?? true,
            'contact_channel' => $global['contact_channel'] ?? 'whatsapp',
            'custom_prompt' => $global['custom_prompt'] ?? '',
            'providers_order' => null,  // null = inherit global
            'scorer_weights' => null,   // null = inherit global
        ], array_filter($perMarket, fn ($v) => $v !== null));

        // Resolve null sentinels to actual global values for runtime use
        if ($generation['providers_order'] === null) {
            $generation['providers_order'] = $globalProviders;
        }
        if ($generation['scorer_weights'] === null) {
            $generation['scorer_weights'] = $globalWeights;
        }

        return $generation;
    }

    private static function defaultCriteria(): array
    {
        return [
            'max_score' => 60,
            'views_below_market_pct' => 80,
            'contact_rate_below_market_pct' => 80,
            'engagement_below_market_pct' => 80,
            'require_below' => 'any',
            'min_market_sample' => 10,
            'only_published' => true,
            'only_active' => true, // publish + paid + not deactivated
            'eligibility_window_days' => 30,
        ];
    }

    private static function defaultActions(): array
    {
        return [
            'optimize_bio' => true,
            'switch_main_image' => false, // off by default until P1 (media dimensions) ships
            'generation' => [],           // merged from global in effectiveGeneration()
            'image_quality' => [
                'min_width' => 800,
                'min_height' => 1000,
                'min_megapixel_gain' => 0.15,
                'require_dimensions' => true, // gate image-switch until P1 ships
            ],
        ];
    }

    private static function defaultSchedule(): array
    {
        return [
            'active_days' => [1, 2, 3, 4, 5, 6, 7],
            'window_start' => '02:00',
            'window_end' => '06:00',
            'daily_limit' => 20,
            'runway_threshold' => 0, // 0 = compute from daily_limit
        ];
    }

    private static function defaultReliability(): array
    {
        return [
            'exclude_optimized_within_days' => 14,
            'exclude_skipped_within_days' => 7,
            'impact_recheck_days' => 7,
            'min_score_gain' => 3,
            'min_image_gain' => 0.10,
            'max_writes_per_hour' => 60,
            'batch_size' => 20,
            'retry_attempts' => 3,
            'language_confidence' => 0.70,
            'similarity_lookback_days' => 30,
            'max_similarity_distance' => 6, // Hamming distance threshold (legacy)
            'no_op_similarity_pct' => 90,   // skip only if new bio ≥ this % similar to the CURRENT bio
        ];
    }
}
