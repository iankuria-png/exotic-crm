<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
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
        // Default range 60–90, +30/+50 → 90–140
        $this->assertStringContainsString('90-140', $systemPrompt);
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
}
