<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContactUnlockPricingRule;
use App\Models\Platform;
use App\Models\User;
use App\Services\BillingModeService;
use App\Services\FeatureSettingsService;
use App\Support\ClientLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ContactUnlockReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_surfaces_wordpress_not_configured_proxy_failure(): void
    {
        $platform = $this->contactUnlockPlatform();
        $this->enableContactUnlockFor($platform);
        $this->createRestrictedClient($platform, 75732);
        $this->fakeProviderRuntime();

        Http::fake([
            'https://www.exoticuganda.test/wp-json/exotic-crm-sync/v1/contact-unlock/config*' => Http::response([
                'code' => 'not_configured',
                'message' => 'Contact unlock is not configured on this site.',
                'data' => ['status' => 503],
            ], 503),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->postJson('/api/crm/settings/billing/contact-unlock/readiness', [
            'platform_id' => $platform->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('summary.blocked', 1)
            ->assertJsonPath('markets.0.status', 'blocked')
            ->assertJsonPath('markets.0.checks.4.key', 'wp_contact_unlock_proxy')
            ->assertJsonPath('markets.0.checks.4.status', 'fail')
            ->assertJsonPath('markets.0.checks.4.http_status', 503)
            ->assertJsonPath('markets.0.checks.4.hint', 'Check EXOTIC_CRM_BASE_URL, EXOTIC_CRM_SYNC_SHARED_KEY, and the WP platform id option on this market site.');
    }

    public function test_readiness_passes_when_wordpress_proxy_returns_enabled_config(): void
    {
        $platform = $this->contactUnlockPlatform();
        $this->enableContactUnlockFor($platform);
        $this->createRestrictedClient($platform, 75732);
        $this->fakeProviderRuntime();

        Http::fake([
            'https://www.exoticuganda.test/wp-json/exotic-crm-sync/v1/contact-unlock/config*' => Http::response([
                'enabled' => true,
                'market' => ['id' => $platform->id, 'name' => 'Uganda', 'currency' => 'UGX'],
                'profile' => ['wp_post_id' => 75732, 'restricted' => true],
                'pricing_rules' => [['id' => 1]],
                'providers' => [['key' => 'pawapay']],
            ], 200),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->postJson('/api/crm/settings/billing/contact-unlock/readiness', [
            'platform_id' => $platform->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('summary.ready', 1)
            ->assertJsonPath('markets.0.status', 'ready')
            ->assertJsonPath('markets.0.checks.4.status', 'pass')
            ->assertJsonPath('markets.0.checks.4.http_status', 200);
    }

    private function contactUnlockPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Uganda',
            'country' => 'Uganda',
            'domain' => 'https://www.exoticuganda.test',
            'currency_code' => 'UGX',
            'phone_prefix' => '256',
            'wp_api_url' => 'https://www.exoticuganda.test/wp-json/exotic-crm-sync/v1',
        ]);
    }

    private function enableContactUnlockFor(Platform $platform): void
    {
        app(FeatureSettingsService::class)->set('contact_unlock.enabled', true);
        app(FeatureSettingsService::class)->set('contact_unlock.market_ids', [(int) $platform->id]);
        app(FeatureSettingsService::class)->set('contact_unlock.sandbox_only', false);

        ContactUnlockPricingRule::query()->create([
            'platform_id' => (int) $platform->id,
            'scope' => ContactUnlockPricingRule::SCOPE_SINGLE_PROFILE,
            'label' => 'Unlock this profile',
            'currency' => 'UGX',
            'amount' => 5500,
            'duration_days' => 1,
            'is_active' => true,
        ]);
    }

    private function createRestrictedClient(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => (int) $platform->id,
            'wp_post_id' => $wpPostId,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'wp_profile_permalink' => 'https://www.exoticuganda.test/escort/mary/',
        ]);
    }

    private function fakeProviderRuntime(): void
    {
        $mock = Mockery::mock(BillingModeService::class);
        $mock->shouldReceive('providerContext')->andReturn([
            'environment' => 'production',
            'provider_resolved_from' => 'provider_profile',
        ]);

        app()->instance(BillingModeService::class, $mock);
    }
}
