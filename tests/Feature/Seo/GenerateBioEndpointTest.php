<?php

namespace Tests\Feature\Seo;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\Seo\LinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateBioEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_generate_bio_requires_a_bearer_token(): void
    {
        // Token-first contract: the /api SEO routes must reject requests that
        // arrive without a bearer token (e.g. a stale session cookie alone).
        // The CRM SPA must attach Authorization: Bearer for these calls.
        config(['services.seo_engine.enabled' => true]);

        $this->postJson('/api/crm/seo/generate-bio', [])
            ->assertUnauthorized();
        $this->getJson('/api/crm/seo/provider-options')
            ->assertUnauthorized();
        $this->postJson('/api/crm/seo/translate-bio', [])
            ->assertUnauthorized();
        $this->postJson('/api/crm/seo/feedback', [])
            ->assertUnauthorized();
    }

    public function test_sales_user_can_fetch_configured_provider_options_without_keys(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        config([
            'services.seo_engine.enabled' => true,
            'services.seo_engine.providers' => ['deepseek', 'gemini', 'claude', 'openai'],
            'services.seo_engine.openrouter.api_key' => 'sk-or-secret',
            'services.seo_engine.openrouter.model' => 'google/gemini-3.8-flash',
            'services.seo_engine.openrouter.fallback_models' => ['deepseek/deepseek-v3.2'],
            'services.seo_engine.deepseek.api_key' => 'sk-deepseek-secret',
            'services.seo_engine.deepseek.model' => 'deepseek-v4-pro',
            'services.seo_engine.deepseek.fallback_models' => ['deepseek-v4-flash'],
            'services.seo_engine.gemini.api_key' => '',
        ]);

        $response = $this->getJson('/api/crm/seo/provider-options');

        $response->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('providers.0.provider', 'deepseek')
            ->assertJsonPath('providers.1.provider', 'openrouter')
            ->assertJsonPath('options.0.provider', 'deepseek')
            ->assertJsonPath('options.0.model', 'deepseek-v4-pro');

        $this->assertStringNotContainsString('sk-or-secret', $response->getContent());
        $this->assertStringNotContainsString('sk-deepseek-secret', $response->getContent());
    }

    public function test_generate_bio_rejects_unconfigured_force_model(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        config([
            'services.seo_engine.enabled' => true,
            'services.seo_engine.platform_allowlist' => [],
            'services.seo_engine.providers' => ['gemini'],
            'services.seo_engine.gemini.api_key' => 'gemini-secret',
            'services.seo_engine.gemini.model' => 'gemini-2.5-flash',
        ]);

        $platform = Platform::factory()->create();

        $this->postJson('/api/crm/seo/generate-bio', [
            'platform_id' => $platform->id,
            'force_provider' => 'gemini',
            'force_model' => 'not-configured-model',
            'profile_snapshot' => [
                'name' => 'Nia',
                'city' => 'Mombasa',
            ],
            'save' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['force_model']);
    }

    public function test_crm_generate_bio_accepts_edit_profile_payload_shape(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        config([
            'services.seo_engine.enabled' => true,
            'services.seo_engine.providers' => [],
            'services.seo_engine.platform_allowlist' => [],
        ]);

        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Test-PM',
            'city' => 'Nairobi',
        ]);

        $catalog = \Mockery::mock(LinkCatalogService::class);
        $catalog->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $catalog);

        $response = $this->postJson('/api/crm/seo/generate-bio', [
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'profile_snapshot' => [
                'name' => 'Test-PM',
                'phone' => '254769912227',
                'gender' => '1',
                'ethnicity' => '3',
                'height' => '168',
                'build' => '4',
                'bio' => 'Hello, gentlemen! I’m Testing PM.',
                'availability' => ['1', '2'],
                'services' => ['GFE', ['ignored nested array']],
            ],
            'save' => false,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['bio_html', 'score', 'breakdown', 'provider_used'])
            ->assertJsonPath('provider_used', 'template_fallback');

        $this->assertStringNotContainsString('build type', $response->json('bio_html'));
        $this->assertStringNotContainsString('type 4', $response->json('bio_html'));
        $this->assertStringContainsString('curvy', strtolower($response->json('bio_html')));
    }
}
