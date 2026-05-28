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

    public function test_french_mojibake_is_repaired(): void
    {
        // Build a deliberately mojibaked string the way some LLMs return it.
        // Bytes for "régulière" in UTF-8 are 0xC3 0xA9 0x67 0x75 0x6C 0x69 0xC3 0xA8 0x72 0x65.
        // If those bytes get read as Windows-1252 and re-encoded as UTF-8, we get the
        // "Ã©" / "Ã¨" sequences seen in the broken bio.
        $mojibake = "Je suis Isla, silhouette rÃ©guliÃ¨re. Ma spÃ©cialitÃ© c'est le jeu.";

        $platform = Platform::factory()->create();

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $mojibake]]],
                'usage'   => ['prompt_tokens' => 60, 'completion_tokens' => 25],
            ], 200),
        ]);

        $service = app(\App\Services\Seo\BioGenerationService::class);
        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Isla', 'city' => 'Le Plateau'],
        ]);

        $bio = $result['bio_html'];
        // After repair: accented characters present, no Ã© / Ã¨ artefacts.
        $this->assertStringContainsString('régulière', $bio);
        $this->assertStringContainsString('spécialité', $bio);
        $this->assertStringNotContainsString('Ã©', $bio);
        $this->assertStringNotContainsString('Ã¨', $bio);
    }

    public function test_portuguese_mojibake_is_repaired(): void
    {
        // "São Paulo", "ção" patterns
        $mojibake = "Eu sou Beatriz, da SÃ£o Paulo. AtenÃ§Ã£o personalizada e discriÃ§Ã£o garantida.";

        $platform = Platform::factory()->create();
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $mojibake]]],
                'usage'   => ['prompt_tokens' => 60, 'completion_tokens' => 25],
            ], 200),
        ]);

        $result = app(\App\Services\Seo\BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Beatriz', 'city' => 'São Paulo'],
        ]);

        $bio = $result['bio_html'];
        $this->assertStringContainsString('São', $bio);
        $this->assertStringContainsString('atenção', strtolower($bio));
        $this->assertStringNotContainsString('Ã£', $bio);
        $this->assertStringNotContainsString('Ã§', $bio);
    }

    public function test_proper_utf8_text_is_not_damaged_by_demojibake(): void
    {
        // Already-correct French; the demojibake heuristic must NOT touch this.
        $clean = "Je suis Camille. Ma spécialité est l'écoute, la précision et la générosité.";

        $platform = Platform::factory()->create();
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $clean]]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 22],
            ], 200),
        ]);

        $result = app(\App\Services\Seo\BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Camille', 'city' => 'Lyon'],
        ]);

        $bio = $result['bio_html'];
        $this->assertStringContainsString('spécialité', $bio);
        $this->assertStringContainsString('générosité', $bio);
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
