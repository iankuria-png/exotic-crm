<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies that the configured language ends up in the system prompt
 * sent to the LLM, so the model writes the bio in the requested language.
 */
class BioLanguageTest extends TestCase
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
                'min_words' => 55,
                'max_words' => 95,
                'max_characters' => 750,
                'max_services' => 5,
                'include_location' => true,
                'include_services' => true,
                'include_contact' => false,
                'contact_channel' => 'none',
                'custom_prompt' => '',
                'language' => 'en',
            ],
        ]);

        $stub = \Mockery::mock(LinkCatalogService::class);
        $stub->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $stub);
    }

    public function test_english_is_the_implicit_default(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'An English bio.']]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 25],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'L', 'city' => 'N'],
        ]);

        $this->assertStringContainsString('English', $captured['messages'][0]['content']);
    }

    public function test_french_directive_is_sent_when_language_is_fr(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'Une biographie en français.']]],
                'usage'   => ['prompt_tokens' => 60, 'completion_tokens' => 30],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Camille', 'city' => 'Dakar'],
            'generation_options' => ['language' => 'fr'],
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('French', $systemPrompt);
        $this->assertStringContainsString('français', $systemPrompt);
    }

    public function test_portuguese_directive_is_sent_when_language_is_pt(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'Uma biografia em português.']]],
                'usage'   => ['prompt_tokens' => 55, 'completion_tokens' => 28],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Beatriz', 'city' => 'Maputo'],
            'generation_options' => ['language' => 'pt'],
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('Portuguese', $systemPrompt);
        $this->assertStringContainsString('português', $systemPrompt);
    }

    public function test_swahili_directive_includes_no_slang_guidance(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'Maelezo mafupi kwa Kiswahili.']]],
                'usage'   => ['prompt_tokens' => 60, 'completion_tokens' => 35],
            ], 200);
        });

        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Amina', 'city' => 'Mombasa'],
            'generation_options' => ['language' => 'sw'],
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('Swahili', $systemPrompt);
        $this->assertStringContainsString('Kiswahili', $systemPrompt);
        $this->assertStringContainsString('Sheng', $systemPrompt);
    }

    public function test_unknown_language_falls_back_to_english(): void
    {
        $platform = Platform::factory()->create();
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            return Http::response([
                'choices' => [['message' => ['content' => 'A bio.']]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 25],
            ], 200);
        });

        // 'zz' is not in SUPPORTED_LANGUAGES; service should fall back to 'en'.
        app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'X', 'city' => 'Y'],
            'generation_options' => ['language' => 'zz'],
        ]);

        $systemPrompt = $captured['messages'][0]['content'] ?? '';
        $this->assertStringContainsString('English', $systemPrompt);
        $this->assertStringNotContainsString('Sheng', $systemPrompt);
    }
}
