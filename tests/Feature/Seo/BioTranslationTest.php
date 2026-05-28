<?php

namespace Tests\Feature\Seo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifies the on-demand English-peek translation endpoint:
 *   - Translates non-English bios via the LLM
 *   - Returns the source untouched when the bio is already in English
 *   - Caches the result so toggling the peek view doesn't re-bill the LLM
 *   - Falls back gracefully when no providers are available
 */
class BioTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.seo_engine.enabled'         => true,
            'services.seo_engine.providers'       => ['deepseek'],
            'services.seo_engine.deepseek.api_key' => 'sk-test',
            'services.seo_engine.deepseek.model'   => 'deepseek-chat',
        ]);
    }

    public function test_translates_french_bio_to_english(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'I am Camille, I love listening, precision and generosity.']]],
                'usage'   => ['prompt_tokens' => 80, 'completion_tokens' => 30],
            ], 200),
        ]);

        $response = $this->postJson('/api/crm/seo/translate-bio', [
            'bio_html'      => '<p>Je suis Camille, j\'aime l\'écoute et la précision.</p>',
            'from_language' => 'fr',
        ]);

        $response->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonStructure(['translation_html', 'provider_used', 'cached']);

        $this->assertStringContainsString('Camille', $response->json('translation_html'));
    }

    public function test_repeat_request_serves_from_cache_without_calling_llm(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Hello, I am Q.']]],
                'usage'   => ['prompt_tokens' => 40, 'completion_tokens' => 15],
            ], 200),
        ]);

        $payload = [
            'bio_html'      => '<p>Hola, soy Q de Mozambique.</p>',
            'from_language' => 'pt',
        ];

        $first = $this->postJson('/api/crm/seo/translate-bio', $payload)->assertOk();
        $this->assertFalse($first->json('cached'));

        $second = $this->postJson('/api/crm/seo/translate-bio', $payload)->assertOk();
        $this->assertTrue($second->json('cached'));

        // Only the first call should have hit DeepSeek.
        Http::assertSentCount(1);
    }

    public function test_english_source_short_circuits_without_an_llm_call(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        Http::fake(); // any HTTP call would record here

        $response = $this->postJson('/api/crm/seo/translate-bio', [
            'bio_html'      => '<p>Already in English.</p>',
            'from_language' => 'en',
        ]);

        $response->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('translation_html', '<p>Already in English.</p>');

        Http::assertNothingSent();
    }

    public function test_invalid_language_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        $this->postJson('/api/crm/seo/translate-bio', [
            'bio_html'      => '<p>foo</p>',
            'from_language' => 'xx',
        ])->assertStatus(422);
    }

    public function test_disabled_engine_blocks_translation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));
        config(['services.seo_engine.enabled' => false]);

        $this->postJson('/api/crm/seo/translate-bio', [
            'bio_html'      => '<p>Je suis ici.</p>',
            'from_language' => 'fr',
        ])->assertStatus(403);
    }

    public function test_returns_friendly_message_when_all_providers_fail(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        // No providers configured → waterfall throws AllProvidersFailedException.
        config(['services.seo_engine.providers' => []]);

        $response = $this->postJson('/api/crm/seo/translate-bio', [
            'bio_html'      => '<p>Je suis ici.</p>',
            'from_language' => 'fr',
        ]);

        $response->assertOk()
            ->assertJsonPath('provider_used', 'unavailable');

        $this->assertStringContainsString('unavailable', strtolower($response->json('translation_html')));
    }
}
