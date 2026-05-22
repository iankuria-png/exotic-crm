<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies that the bio generator strips emoji/decorative Unicode that
 * earlier slipped through to WP and rendered as ? or � on the site.
 *
 * Uses Http::fake to short-circuit the DeepSeek adapter with a payload
 * that includes the kinds of characters we've seen the LLM produce.
 */
class BioSanitizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force deepseek as the only configured provider.
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
            ],
        ]);

        $stub = \Mockery::mock(LinkCatalogService::class);
        $stub->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $stub);
    }

    public function test_emojis_are_stripped_from_output(): void
    {
        $platform = Platform::factory()->create();

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Anna ✨ is a wonderful person 🌹 based in Nairobi 🔥. She loves what she does 💯."]],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
            ], 200),
        ]);

        $service = app(BioGenerationService::class);
        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Anna', 'city' => 'Nairobi'],
        ]);

        $bio = $result['bio_html'];
        $this->assertStringNotContainsString('✨', $bio);
        $this->assertStringNotContainsString('🌹', $bio);
        $this->assertStringNotContainsString('🔥', $bio);
        $this->assertStringNotContainsString('💯', $bio);
        // The actual prose words should still survive.
        $this->assertStringContainsString('Anna', $bio);
        $this->assertStringContainsString('Nairobi', $bio);
    }

    public function test_curly_quotes_and_em_dashes_are_normalized(): void
    {
        $platform = Platform::factory()->create();

        // U+2018, U+2019, U+201C, U+201D, U+2013, U+2014, U+2026
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Zara\u{2019}s vibe is \u{201C}calm energy\u{201D}\u{2014}quiet, focused\u{2026}direct."]],
                ],
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20],
            ], 200),
        ]);

        $service = app(BioGenerationService::class);
        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Zara', 'city' => 'Mombasa'],
        ]);

        $bio = $result['bio_html'];
        $this->assertStringNotContainsString("\u{2019}", $bio); // right single quote
        $this->assertStringNotContainsString("\u{201C}", $bio); // left double quote
        $this->assertStringNotContainsString("\u{201D}", $bio); // right double quote
        $this->assertStringNotContainsString("\u{2014}", $bio); // em-dash
        $this->assertStringNotContainsString("\u{2026}", $bio); // ellipsis

        // Substitutes should be present (after htmlspecialchars; quotes become &quot;/&#039;).
        $this->assertTrue(
            str_contains($bio, "&quot;calm energy&quot;")
                || str_contains($bio, '"calm energy"'),
            'Curly double quotes should be replaced by plain ASCII quotes.'
        );
    }

    public function test_zero_width_joiners_and_variation_selectors_are_removed(): void
    {
        $platform = Platform::factory()->create();

        // Skin-tone-modifier emoji sequence: 👍🏽 = 👍 + U+1F3FD
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Reliable\u{200D}and easy\u{FE0F} to book."]],
                ],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 12],
            ], 200),
        ]);

        $service = app(BioGenerationService::class);
        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Q', 'city' => 'Kisumu'],
        ]);

        $this->assertStringNotContainsString("\u{200D}", $result['bio_html']);
        $this->assertStringNotContainsString("\u{FE0F}", $result['bio_html']);
    }
}
