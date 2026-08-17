<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Models\SeoBioFeedback;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies that quick-action refinements actually mutate the prompt sent
 * to the LLM (longer/shorter range, prompt addenda).
 */
class BioRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.seo_engine.enabled' => true,
            'services.seo_engine.providers' => ['deepseek'],
            'services.seo_engine.deepseek.api_key' => 'sk-test',
            'services.seo_engine.deepseek.model' => 'deepseek-chat',
            'services.seo_engine.generation' => [
                'tone' => 'simple, direct, local classified profile copy',
                'temperament' => 'confident but not exaggerated',
                'min_words' => 60,
                'max_words' => 90,
                'max_characters' => 700,
                'max_services' => 5,
                'include_location' => true,
                'include_services' => true,
                'include_contact' => false,
                'contact_channel' => 'none',
                'custom_prompt' => '',
            ],
        ]);

        $stub = \Mockery::mock(LinkCatalogService::class);
        $stub->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $stub);
    }

    public function test_longer_refinement_increases_word_range_in_prompt(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'A longer draft.']]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 30],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'L', 'city' => 'N'],
            'refinements' => ['longer'],
        ]);

        $this->assertNotNull($captured);
        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        // Default range 60-90, +30/+50 would be 90-140, but 950 chars safely clamps max words to 131.
        $this->assertStringContainsString('90-131', $systemPrompt);
    }

    public function test_shorter_refinement_decreases_word_range_in_prompt(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'A tighter draft.']]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'L', 'city' => 'N'],
            'refinements' => ['shorter'],
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        // 60-20 = 40 (min), 90-30 = 60 (max)
        $this->assertStringContainsString('40-60', $systemPrompt);
    }

    public function test_less_generic_refinement_appends_instruction_to_prompt(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'Specific copy here.']]],
                'usage'   => ['prompt_tokens' => 40, 'completion_tokens' => 25],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'L', 'city' => 'N'],
            'refinements' => ['less_generic'],
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('stock copywriting phrases', $systemPrompt);
    }

    public function test_previous_bio_is_included_in_prompt_when_provided(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'Brand new angle.']]],
                'usage'   => ['prompt_tokens' => 60, 'completion_tokens' => 25],
            ], 200);
        });

        $previousBio = '<p>The original draft mentioned coffee and books.</p>';

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'L', 'city' => 'N'],
            'refinements' => ['different_angle'],
            'previous_bio' => $previousBio,
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('Previous draft', $systemPrompt);
        $this->assertStringContainsString('coffee and books', $systemPrompt);
    }

    public function test_unknown_refinements_are_silently_ignored(): void
    {
        $platform = Platform::factory()->create();

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Fine.']]],
                'usage'   => ['prompt_tokens' => 40, 'completion_tokens' => 10],
            ], 200),
        ]);

        // Should not throw — backend filters at validation, but service is
        // defensive too in case bypassed.
        $result = app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'L', 'city' => 'N'],
            'refinements' => ['this_is_not_a_real_preset'],
        ]);

        $this->assertArrayHasKey('bio_html', $result);
    }

    public function test_format_feedback_context_temperature_and_richer_snapshot_reach_provider_prompt(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'I keep things warm, witty, and specific in Kigali.']]],
                'usage'   => ['prompt_tokens' => 80, 'completion_tokens' => 30],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => [
                'name' => 'Gia',
                'city' => 'Kigali',
                'haircolor' => '1',
                'language1' => 'Swahili',
                'extraservices' => 'slow massage and teasing conversation',
                'occupation' => 'stylist',
                'rates_incall' => '100',
            ],
            'generation_options' => [
                'bio_format' => 'first_person_intro',
                'creativity' => 1.05,
            ],
            'feedback_context' => [
                'rating' => -1,
                'tag' => 'too_generic',
                'comment' => 'Make it less polished and more human.',
            ],
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        $userPrompt = $captured['messages'][1]['content'] ?? '';

        $this->assertSame(1.05, $captured['temperature']);
        $this->assertStringContainsString('Use first person', $systemPrompt);
        $this->assertStringContainsString('Immediate editor feedback', $systemPrompt);
        $this->assertStringContainsString('too_generic', $systemPrompt);
        $this->assertStringContainsString('Hair color: black', $userPrompt);
        $this->assertStringContainsString('Languages: Swahili', $userPrompt);
        $this->assertStringContainsString('Rate context', $userPrompt);
        $this->assertStringContainsString('Extra services', $userPrompt);
        $this->assertStringContainsString('Occupation: stylist', $userPrompt);
    }

    public function test_high_overuse_runs_one_rewrite_pass(): void
    {
        $platform = Platform::factory()->create();
        foreach (range(1, 3) as $index) {
            SeoBioFeedback::create([
                'platform_id' => $platform->id,
                'rating' => 1,
                'accepted' => true,
                'bio_html' => "<p>She brings an unhurried rhythm and warm teasing in Kigali {$index}. The unhurried rhythm feels easy.</p>",
            ]);
        }

        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request->data();

            $content = count($calls) === 1
                ? 'She brings an unhurried rhythm and warm teasing. Not only warm but also magnetic.'
                : 'I like slow conversation, direct eye contact, and a private mood that feels fresh.';

            return Http::response([
                'choices' => [['message' => ['content' => $content]]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 20],
            ], 200);
        });

        $result = app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'L', 'city' => 'Kigali'],
            'generation_options' => ['overuse_sensitivity' => 'high'],
        ]);

        $this->assertCount(2, $calls);
        $this->assertTrue($result['rewritten_for_uniqueness']);
        $this->assertStringContainsString('Rewrite instruction', $calls[1]['messages'][0]['content'] ?? '');
        $this->assertStringContainsString('Draft to rewrite', $calls[1]['messages'][1]['content'] ?? '');
        $this->assertStringContainsString('private mood', $result['bio_html']);
    }
}
