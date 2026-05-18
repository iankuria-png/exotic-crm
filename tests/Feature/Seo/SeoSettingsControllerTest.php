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
            ->assertJsonPath('config.providers.claude.has_key', false)
            ->assertJsonPath('config.providers.gemini.has_key', false);

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
            ->assertJsonPath('config.providers.gemini.api_key', '__keep__');

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
                'deepseek' => ['api_key' => '__keep__', 'model' => 'deepseek-chat'],
            ],
        ])->assertOk();

        $stored = IntegrationSetting::where('key', 'seo_engine')->first()->value;
        $this->assertTrue($stored['enabled']);
        $this->assertSame([1, 2], $stored['platform_allowlist']);
        $this->assertSame('my-gemini-key', $stored['providers']['gemini']['api_key']);
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
        $this->assertSame('gemini-1.5-pro', $stored['providers']['gemini']['model']);
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
