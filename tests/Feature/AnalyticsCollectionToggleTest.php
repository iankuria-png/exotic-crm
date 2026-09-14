<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsCollectionToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_markets_collect_analytics_by_default(): void
    {
        $this->assertTrue($this->createPlatform()->fresh()->analyticsCollectionEnabled());
    }

    public function test_switching_off_pushes_to_wordpress_and_keeps_what_it_reported(): void
    {
        $platform = $this->createPlatform();
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/analytics-collection" => Http::response([
            'ok' => true,
            'enabled' => false,
            'guard_installed' => true,
            'guard_flag_present' => true,
            'guard_flag_synced' => true,
            'final_rollup_scheduled_at' => '2026-09-14T10:00:00+00:00',
        ], 200)]);
        Sanctum::actingAs($this->createAdminUser());

        $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'analytics_collection_enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('platform.analytics_collection_enabled', false)
            ->assertJsonPath('platform.analytics_collection.supported', true)
            ->assertJsonPath('platform.analytics_collection.wp_enabled', false)
            ->assertJsonPath('platform.analytics_collection.guard_installed', true)
            ->assertJsonMissingPath('analytics_collection_push_warning');

        Http::assertSent(fn ($request) => $request->url() === "{$base}/analytics-collection"
            && (string) $request['enabled'] === '0');
        $this->assertFalse($platform->fresh()->analyticsCollectionEnabled());
    }

    public function test_saving_an_untouched_default_market_does_not_call_wordpress(): void
    {
        $platform = $this->createPlatform();
        Http::fake();
        Sanctum::actingAs($this->createAdminUser());

        $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'analytics_collection_enabled' => true,
            'country' => 'Tanzania',
        ])
            ->assertOk()
            ->assertJsonMissingPath('analytics_collection_push_warning');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/analytics-collection'));
    }

    public function test_a_plugin_without_the_route_still_saves_with_a_warning(): void
    {
        $platform = $this->createPlatform();
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/analytics-collection" => Http::response([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
            'data' => ['status' => 404],
        ], 404)]);
        Sanctum::actingAs($this->createAdminUser());

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'analytics_collection_enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('platform.analytics_collection_enabled', false)
            ->assertJsonPath('platform.analytics_collection.supported', false);

        $this->assertStringContainsString('1.3.8', (string) $response->json('analytics_collection_push_warning'));
        $this->assertFalse($platform->fresh()->analyticsCollectionEnabled());
    }

    public function test_an_unwritable_guard_flag_is_reported(): void
    {
        $platform = $this->createPlatform();
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/analytics-collection" => Http::response([
            'ok' => true,
            'enabled' => false,
            'guard_installed' => true,
            'guard_flag_present' => false,
            'guard_flag_synced' => false,
        ], 200)]);
        Sanctum::actingAs($this->createAdminUser());

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'analytics_collection_enabled' => false,
        ])->assertOk();

        $this->assertStringContainsString('exotic-crm-sync-flags', (string) $response->json('analytics_collection_push_warning'));
    }

    public function test_switching_back_on_pushes_enabled(): void
    {
        $platform = $this->createPlatform(['analytics_collection_enabled' => false]);
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/analytics-collection" => Http::response([
            'ok' => true,
            'enabled' => true,
            'guard_installed' => true,
            'guard_flag_present' => false,
            'guard_flag_synced' => true,
        ], 200)]);
        Sanctum::actingAs($this->createAdminUser());

        $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'analytics_collection_enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('platform.analytics_collection_enabled', true)
            ->assertJsonPath('platform.analytics_collection.wp_enabled', true);

        Http::assertSent(fn ($request) => $request->url() === "{$base}/analytics-collection"
            && (string) $request['enabled'] === '1');
    }

    private function createPlatform(array $overrides = []): Platform
    {
        return Platform::query()->create(array_merge([
            'name' => 'Tanzania Market',
            'domain' => 'tz-'.Str::random(6).'.example.test',
            'country' => 'Tanzania',
            'timezone' => 'Africa/Dar_es_Salaam',
            'phone_prefix' => '255',
            'currency_code' => 'TZS',
            'is_active' => true,
            'wp_api_url' => 'https://tz.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ], $overrides));
    }

    private function createAdminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin Tester',
            'email' => 'admin-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }
}
