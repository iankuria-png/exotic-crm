<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Models\SeoBioFeedback;
use App\Services\Seo\BioUniquenessAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BioUniquenessAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_overused_terms_and_respects_ignored_terms(): void
    {
        $platform = Platform::factory()->create();

        foreach (range(1, 4) as $index) {
            SeoBioFeedback::create([
                'platform_id' => $platform->id,
                'rating' => 1,
                'accepted' => true,
                'bio_html' => "<p>She brings an unhurried rhythm, warm teasing, and real company in Kigali. Her unhurried rhythm keeps things relaxed {$index}.</p>",
            ]);
        }

        $analyzer = app(BioUniquenessAnalyzer::class);
        $result = $analyzer->analyze(
            '<p>Her unhurried rhythm feels warm, unhurried, and relaxed.</p>',
            $platform->id,
            ['overuse_sensitivity' => 'medium']
        );

        $this->assertSame(4, $result['corpus_sample_size']);
        $this->assertGreaterThan(0, $result['overuse_score']);
        $this->assertNotEmpty($result['overuse_flags']);

        $ignored = $analyzer->analyze(
            '<p>Her unhurried rhythm feels warm, unhurried, and relaxed.</p>',
            $platform->id,
            ['ignored_overuse_terms' => ['unhurried', 'rhythm', 'warm', 'relaxed']]
        );

        $this->assertLessThan($result['overuse_score'], $ignored['overuse_score']);
    }

    public function test_flags_ai_slop_patterns_without_static_word_bans(): void
    {
        $platform = Platform::factory()->create();

        $result = app(BioUniquenessAnalyzer::class)->analyze(
            '<p>She is not only warm, witty, and magnetic, but also a vibrant testament to unforgettable company.</p>',
            $platform->id
        );

        $this->assertGreaterThan(0, $result['ai_slop_score']);
        $this->assertNotEmpty($result['ai_slop_flags']);
    }
}
