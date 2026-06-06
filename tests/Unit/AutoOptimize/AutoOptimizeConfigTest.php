<?php

namespace Tests\Unit\AutoOptimize;

use App\Models\AutoOptimizePlan;
use App\Services\AutoOptimize\AutoOptimizeConfig;
use Tests\TestCase;

class AutoOptimizeConfigTest extends TestCase
{
    public function test_unset_keys_inherit_global_seo_engine_defaults(): void
    {
        config(['services.seo_engine.generation' => [
            'language' => 'fr',
            'tone' => 'playful',
            'min_words' => 70,
        ]]);
        config(['services.seo_engine.providers' => ['openai', 'claude']]);

        $plan = $this->makePlan([]); // no actions set

        $cfg = AutoOptimizeConfig::effective($plan);

        $this->assertSame('fr', $cfg['actions']['generation']['language']);
        $this->assertSame('playful', $cfg['actions']['generation']['tone']);
        $this->assertSame(70, $cfg['actions']['generation']['min_words']);
        $this->assertSame(['openai', 'claude'], $cfg['actions']['generation']['providers_order']);
    }

    public function test_per_market_generation_overrides_global(): void
    {
        config(['services.seo_engine.generation' => ['language' => 'en', 'tone' => 'plain']]);

        $plan = $this->makePlan([
            'actions' => [
                'optimize_bio' => true,
                'generation' => ['language' => 'sw', 'tone' => 'warm & friendly'],
            ],
        ]);

        $cfg = AutoOptimizeConfig::effective($plan);

        $this->assertSame('sw', $cfg['actions']['generation']['language']);
        $this->assertSame('warm & friendly', $cfg['actions']['generation']['tone']);
    }

    public function test_scorer_weights_null_inherits_global(): void
    {
        config(['services.seo_engine.scorer_weights' => [
            'word_count' => 30, 'links' => 20, 'completeness' => 30, 'media' => 20,
        ]]);

        $plan = $this->makePlan([
            'actions' => ['generation' => ['scorer_weights' => null]],
        ]);

        $cfg = AutoOptimizeConfig::effective($plan);

        $this->assertSame(30, $cfg['actions']['generation']['scorer_weights']['word_count']);
    }

    public function test_criteria_and_schedule_merged_from_defaults(): void
    {
        $plan = $this->makePlan([
            'criteria' => ['max_score' => 55],
        ]);

        $cfg = AutoOptimizeConfig::effective($plan);

        $this->assertSame(55, $cfg['criteria']['max_score']);
        // Other defaults preserved
        $this->assertSame(80, $cfg['criteria']['views_below_market_pct']);
        $this->assertSame(20, $cfg['schedule']['daily_limit']);
    }

    public function test_validate_scorer_weights_passes_for_null(): void
    {
        $this->assertNull(AutoOptimizeConfig::validateScorerWeights(null));
    }

    public function test_validate_scorer_weights_fails_missing_key(): void
    {
        $error = AutoOptimizeConfig::validateScorerWeights([
            'word_count' => 33, 'links' => 33, 'completeness' => 34,
            // media missing
        ]);
        $this->assertNotNull($error);
        $this->assertStringContainsString('four keys', $error);
    }

    public function test_validate_scorer_weights_fails_wrong_total(): void
    {
        $error = AutoOptimizeConfig::validateScorerWeights([
            'word_count' => 30, 'links' => 30, 'completeness' => 30, 'media' => 30,
        ]);
        $this->assertNotNull($error);
        $this->assertStringContainsString('total 100', $error);
    }

    public function test_validate_scorer_weights_passes_for_valid(): void
    {
        $this->assertNull(AutoOptimizeConfig::validateScorerWeights([
            'word_count' => 25, 'links' => 25, 'completeness' => 25, 'media' => 25,
        ]));
    }

    private function makePlan(array $attrs): AutoOptimizePlan
    {
        $plan = new AutoOptimizePlan();
        $plan->forceFill([
            'platform_id' => 1,
            'enabled' => false,
        ] + $attrs);
        return $plan;
    }
}
