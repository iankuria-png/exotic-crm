<?php

namespace Tests\Feature;

use App\Jobs\SendMarketDownAlertRecipientJob;
use App\Jobs\SendMarketDownAlertsJob;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\MarketHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_probe_persists_healthy_status_and_sends_shared_key_header(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        config([
            'services.exotic_crm_sync.shared_key' => 'shared-health-token',
            'services.exotic_crm_sync.shared_key_platform_ids' => (string) $platform->id,
        ]);

        $statsRequestSeen = false;
        $statsAuthHeaderSeen = false;
        $statsSharedKeyHeaderSeen = false;
        $seenUrls = [];
        Http::fake(function (Request $request) use (&$statsRequestSeen, &$statsAuthHeaderSeen, &$statsSharedKeyHeaderSeen, &$seenUrls) {
            $seenUrls[] = $request->url();

            if (str_contains($request->url(), '/stats')) {
                $statsRequestSeen = true;
                $statsAuthHeaderSeen = $request->hasHeader('Authorization', 'Basic '.base64_encode('crm-user:secret'));
                $statsSharedKeyHeaderSeen = $request->hasHeader('X-Exotic-CRM-Sync-Key', 'shared-health-token');

                return Http::response(['total' => 12], 200);
            }

            return Http::response('<html>ok</html>', 200);
        });

        $result = app(MarketHealthService::class)->checkAndStore($platform);
        $fresh = $result['platform'];

        $this->assertTrue(
            $statsRequestSeen,
            'Expected stats probe request. Saw: '.implode(', ', $seenUrls)
                .'; status='.($fresh->health_status ?? 'null')
                .'; error='.($fresh->health_error ?? 'null')
        );
        $this->assertTrue($statsAuthHeaderSeen);
        $this->assertTrue($statsSharedKeyHeaderSeen);
        $this->assertFalse($result['transitioned_down']);
        $this->assertSame(MarketHealthService::STATUS_HEALTHY, $fresh->health_status);
        $this->assertSame(0, (int) $fresh->health_consecutive_failures);
        $this->assertNull($fresh->health_error);
    }

    public function test_ceo_market_health_endpoint_uses_stored_health_and_live_profile_counts_without_probing(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Ghana',
            'country' => 'Ghana',
            'health_status' => MarketHealthService::STATUS_HEALTHY,
            'health_checked_at' => now()->subMinutes(4),
            'health_latency_ms' => 120,
            'sync_last_synced_at' => now()->subHour(),
        ]);
        Client::factory()->count(3)->create(['platform_id' => $platform->id]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => true,
        ]));

        Http::fake(function (): void {
            $this->fail('Dashboard market-health GET must not probe external URLs.');
        });

        $this->getJson('/api/crm/dashboard/ceo/market-health')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.healthy', 1)
            ->assertJsonPath('markets.0.id', $platform->id)
            ->assertJsonPath('markets.0.health_status', MarketHealthService::STATUS_HEALTHY)
            ->assertJsonPath('markets.0.profiles_total', 3);
    }

    public function test_command_persists_domain_down_and_dispatches_one_transition_alert(): void
    {
        Queue::fake();

        $platform = Platform::factory()->create([
            'name' => 'Uganda',
            'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0712345678',
            'notification_prefs' => [
                'market_down_sms' => [
                    'enabled' => true,
                    'market_ids' => null,
                ],
            ],
        ]);

        Http::fake(function (): void {
            throw new ConnectionException('Could not resolve host: uganda.example.test');
        });

        $this->artisan('crm:check-market-health')
            ->assertSuccessful();
        $this->artisan('crm:check-market-health')
            ->assertSuccessful();

        $platform->refresh();
        $this->assertSame(MarketHealthService::STATUS_DOMAIN_UNREACHABLE, $platform->health_status);
        $this->assertNotNull($platform->health_down_since_at);
        $this->assertNotNull($platform->health_last_down_notified_at);

        Queue::assertPushed(SendMarketDownAlertsJob::class, 1);
    }

    public function test_market_down_alert_coordinator_respects_opt_in_and_market_scope(): void
    {
        Queue::fake();

        $platform = Platform::factory()->create(['name' => 'Tanzania']);
        $otherPlatform = Platform::factory()->create(['name' => 'Rwanda']);

        User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0712345678',
            'notification_prefs' => [
                'market_down_sms' => [
                    'enabled' => true,
                    'market_ids' => null,
                ],
            ],
        ]);

        User::factory()->create([
            'role' => 'sub_admin',
            'status' => 'active',
            'phone' => '0712345679',
            'assigned_market_ids' => [$otherPlatform->id],
            'notification_prefs' => [
                'market_down_sms' => [
                    'enabled' => true,
                    'market_ids' => [$otherPlatform->id],
                ],
            ],
        ]);

        User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0712345680',
            'notification_prefs' => [
                'market_down_sms' => [
                    'enabled' => false,
                    'market_ids' => null,
                ],
            ],
        ]);

        (new SendMarketDownAlertsJob(
            (int) $platform->id,
            $platform->id.':2026-06-21T00:00:00+03:00',
            MarketHealthService::STATUS_SERVER_ERROR,
            'HTTP 500'
        ))->handle(app(MarketAuthorizationService::class));

        Queue::assertPushed(SendMarketDownAlertRecipientJob::class, 1);
    }

    public function test_settings_roles_persist_market_down_sms_preferences(): void
    {
        $platform = Platform::factory()->create(['name' => 'Nigeria']);
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        $createResponse = $this->postJson('/api/crm/settings/roles/users', [
            'name' => 'Ops Admin',
            'email' => 'ops-admin@example.test',
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0712345678',
            'assigned_market_ids' => [],
            'notification_prefs' => [
                'market_down_sms' => [
                    'enabled' => true,
                    'market_ids' => [$platform->id],
                ],
            ],
            'reason' => 'Enable market-down alerts',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('notification_prefs.market_down_sms.enabled', true)
            ->assertJsonPath('notification_prefs.market_down_sms.market_ids.0', $platform->id)
            ->assertJsonPath('market_down_sms_state', 'enabled');

        $userId = (int) $createResponse->json('id');
        $updateResponse = $this->patchJson("/api/crm/settings/roles/{$userId}", [
            'role' => 'sub_admin',
            'status' => 'active',
            'phone' => '0712345678',
            'assigned_market_ids' => [$platform->id],
            'notification_prefs' => [
                'market_down_sms' => [
                    'enabled' => false,
                    'market_ids' => null,
                ],
            ],
            'reason' => 'Disable market-down alerts',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('notification_prefs.market_down_sms.enabled', false)
            ->assertJsonPath('market_down_sms_state', 'disabled');
    }
}
