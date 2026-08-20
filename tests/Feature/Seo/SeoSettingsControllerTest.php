<?php

namespace Tests\Feature\Seo;

use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeoSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_defaults_when_no_settings_stored(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->getJson('/api/crm/settings/seo-engine');

        $response->assertOk()
            ->assertJsonPath('config.enabled', false)
            ->assertJsonPath('config.generation.min_words', 75)
            ->assertJsonPath('config.generation.max_words', 115)
            ->assertJsonPath('config.generation.max_characters', 900)
            ->assertJsonPath('config.generation.bio_format', 'auto')
            ->assertJsonPath('config.generation.overuse_sensitivity', 'medium')
            ->assertJsonPath('config.generation.previous_bio_reference_min_uniqueness_score', 70)
            ->assertJsonPath('config.providers.claude.has_key', false)
            ->assertJsonPath('config.providers.gemini.has_key', false)
            ->assertJsonPath('config.providers.gemini.model', 'gemini-2.5-flash')
            ->assertJsonPath('config.providers.deepseek.model', 'deepseek-v4-pro')
            ->assertJsonPath('config.providers.deepseek.fallback_models.0', 'deepseek-v4-flash');

        $this->assertSame(['claude', 'openai', 'gemini', 'deepseek'], $response->json('available_providers'));
    }

    public function test_show_masks_api_keys(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        IntegrationSetting::create([
            'key' => 'seo_engine',
            'value' => [
                'enabled' => true,
                'providers' => [
                    'gemini' => ['api_key' => 'real-secret-key-xyz789', 'model' => 'gemini-1.5-flash'],
                ],
            ],
        ]);

        $response = $this->getJson('/api/crm/settings/seo-engine');

        $response->assertOk()
            ->assertJsonPath('config.providers.gemini.has_key', true)
            ->assertJsonPath('config.providers.gemini.api_key', '__keep__')
            ->assertJsonPath('config.providers.gemini.model', 'gemini-2.5-flash');

        // Real key never leaks in any field
        $this->assertStringNotContainsString('real-secret-key-xyz789', $response->getContent());
    }

    public function test_update_persists_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->patchJson('/api/crm/settings/seo-engine', [
            'enabled' => true,
            'platform_allowlist' => [1, 2],
            'providers_order' => ['gemini', 'claude', 'openai', 'deepseek'],
            'providers' => [
                'gemini' => ['api_key' => 'my-gemini-key', 'model' => 'gemini-1.5-flash'],
                'claude' => ['api_key' => '__keep__', 'model' => 'claude-3-5-sonnet-20241022'],
                'openai' => ['api_key' => '__keep__', 'model' => 'gpt-4o-mini'],
                'deepseek' => [
                    'api_key' => '__keep__',
                    'model' => 'deepseek-chat',
                    'fallback_models' => ['deepseek-v4-pro', 'deepseek-chat', 'deepseek-custom-creative'],
                ],
            ],
        ])->assertOk();

        $stored = IntegrationSetting::where('key', 'seo_engine')->first()->value;
        $this->assertTrue($stored['enabled']);
        $this->assertSame([1, 2], $stored['platform_allowlist']);
        $this->assertSame('my-gemini-key', $stored['providers']['gemini']['api_key']);
        $this->assertSame('gemini-2.5-flash', $stored['providers']['gemini']['model']);
        $this->assertSame('deepseek-chat', $stored['providers']['deepseek']['model']);
        $this->assertSame(['deepseek-v4-pro', 'deepseek-custom-creative'], $stored['providers']['deepseek']['fallback_models']);
    }

    public function test_update_persists_custom_prompt_and_provider_models_on_reload(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $customPrompt = 'Preserve the previous bio voice. No hyphens. Keep it direct, sexy, specific, and human.';

        $this->patchJson('/api/crm/settings/seo-engine', [
            'enabled' => true,
            'platform_allowlist' => [],
            'providers_order' => ['deepseek', 'gemini', 'claude', 'openai'],
            'providers' => [
                'deepseek' => ['api_key' => 'sk-deepseek-test', 'model' => 'deepseek-v4-pro', 'fallback_models' => ['deepseek-v4-flash']],
                'gemini' => ['api_key' => '__keep__', 'model' => 'gemini-2.5-flash'],
                'claude' => ['api_key' => '__keep__', 'model' => 'claude-3-5-sonnet-20241022'],
                'openai' => ['api_key' => '__keep__', 'model' => 'gpt-4o-mini'],
            ],
            'generation' => [
                'tone' => 'raw, direct, sexy, playful, human, profile voice',
                'custom_prompt' => $customPrompt,
                'min_words' => 65,
                'max_words' => 83,
                'max_characters' => 800,
                'previous_bio_reference_min_uniqueness_score' => 85,
            ],
        ])->assertOk()
            ->assertJsonPath('config.generation.custom_prompt', $customPrompt)
            ->assertJsonPath('config.generation.previous_bio_reference_min_uniqueness_score', 85)
            ->assertJsonPath('config.providers.deepseek.model', 'deepseek-v4-pro')
            ->assertJsonPath('config.providers.deepseek.fallback_models.0', 'deepseek-v4-flash');

        $this->getJson('/api/crm/settings/seo-engine')
            ->assertOk()
            ->assertJsonPath('config.generation.custom_prompt', $customPrompt)
            ->assertJsonPath('config.generation.previous_bio_reference_min_uniqueness_score', 85)
            ->assertJsonPath('config.providers.deepseek.model', 'deepseek-v4-pro')
            ->assertJsonPath('config.providers.deepseek.fallback_models.0', 'deepseek-v4-flash');
    }

    public function test_update_normalizes_overlapping_length_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->patchJson('/api/crm/settings/seo-engine', [
            'enabled' => true,
            'platform_allowlist' => [],
            'providers' => [],
            'generation' => [
                'min_words' => 300,
                'max_words' => 605,
                'max_characters' => 600,
            ],
        ])->assertOk();

        $stored = IntegrationSetting::where('key', 'seo_engine')->first()->value;

        $this->assertSame(60, $stored['generation']['min_words']);
        $this->assertSame(83, $stored['generation']['max_words']);
        $this->assertSame(600, $stored['generation']['max_characters']);
    }

    public function test_update_keep_sentinel_preserves_existing_key(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        IntegrationSetting::create([
            'key' => 'seo_engine',
            'value' => [
                'enabled' => false,
                'providers' => [
                    'gemini' => ['api_key' => 'original-key', 'model' => 'gemini-1.5-flash'],
                ],
            ],
        ]);

        $this->patchJson('/api/crm/settings/seo-engine', [
            'enabled' => true,
            'platform_allowlist' => [],
            'providers' => [
                'gemini' => ['api_key' => '__keep__', 'model' => 'gemini-1.5-pro'],
            ],
        ])->assertOk();

        $stored = IntegrationSetting::where('key', 'seo_engine')->first()->value;
        $this->assertSame('original-key', $stored['providers']['gemini']['api_key']);
        $this->assertSame('gemini-2.5-flash', $stored['providers']['gemini']['model']);
    }

    public function test_non_admin_cannot_update(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        $this->patchJson('/api/crm/settings/seo-engine', [
            'enabled' => true,
            'platform_allowlist' => [],
        ])->assertStatus(403);
    }

    public function test_sub_admin_can_view_but_not_update(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sub_admin', 'status' => 'active']));

        $this->getJson('/api/crm/settings/seo-engine')->assertOk();
        $this->patchJson('/api/crm/settings/seo-engine', [
            'enabled' => true,
            'platform_allowlist' => [],
        ])->assertStatus(403);
    }

    public function test_test_endpoint_returns_failure_when_provider_unconfigured(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        // No keys stored — gemini should fail isAvailable check
        config([
            'services.seo_engine.gemini.api_key' => '',
            'services.seo_engine.gemini.model' => '',
        ]);

        $response = $this->postJson('/api/crm/settings/seo-engine/test', ['provider' => 'gemini']);

        $response->assertOk()
            ->assertJsonPath('success', false);
    }
}
