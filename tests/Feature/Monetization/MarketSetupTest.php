<?php

namespace Tests\Feature\Monetization;

use App\Models\ContentMonetizationSetting;
use App\Models\Platform;
use App\Models\PremiumContentEvent;
use App\Models\User;
use App\Services\BillingModeService;
use App\Services\Monetization\ReadinessService;
use App\Services\Monetization\SetupService;
use App\Services\Monetization\SyncService;
use App\Services\Monetization\WordPressDestination;
use App\Services\MonetizationSettingsService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketSetupTest extends TestCase
{
    use RefreshDatabase;

    private Platform $market;

    private ContentMonetizationSetting $settings;

    private User $admin;

    private bool $disabledProvider = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->market = Platform::factory()->create(['name' => 'Uganda', 'currency_code' => 'UGX', 'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture-admin', 'wp_api_password' => 'fixture-application-password']);
        $this->settings = app(MonetizationSettingsService::class)->forPlatform($this->market);
        $this->settings->update(['currency' => 'UGX', 'premium_access_environment' => 'production', 'checkout_policy_json' => ['allowed_providers' => ['pawapay'], 'device_slots' => 3, 'restore_per_hour' => 5]]);
        $this->settings->prices()->create(['duration_key' => '1_month', 'duration_label' => '1 Month', 'duration_days' => 30, 'currency' => 'UGX', 'price' => 500, 'subsidy_mode' => 'fixed', 'subsidy_value' => 100, 'is_active' => true]);
        app(MonetizationSettingsService::class)->system()->update(['enabled' => true]);
        $this->mock(WalletSettingsService::class, function ($m) {
            $m->shouldReceive('runtimeWalletCurrencyCode')->andReturn('UGX');
            $m->shouldReceive('runtimePlatformConfig')->andReturn(['effective_mode' => 'production', 'supported_currencies' => ['UGX']]);
            $m->shouldReceive('wpToCrmHmacSecret')->andReturn('fixture-hmac-only');
            $m->shouldReceive('wpToCrmCredentialPair')->andReturn(['bearer_key' => 'fixture-bearer-only', 'hmac_secret' => 'fixture-hmac-only']);
            $m->shouldReceive('wpCredentialSyncPayload')->andReturn(['platform_id' => $this->market->id, 'bearer_key' => 'fixture-bearer-only', 'hmac_secret' => 'fixture-hmac-only']);
        });
        $this->mock(BillingModeService::class, fn ($m) => $m->shouldReceive('providerContext')->andReturnUsing(function ($p, $provider) {
            if ($this->disabledProvider || $provider === 'kopokopo') {
                throw new \InvalidArgumentException('Selected provider is disabled for this market.');
            }

            return ['environment' => 'production'];
        }));
    }

    private function preflight(): array
    {
        return ['schema_version' => 1, 'capabilities' => ['guided_preflight_v1', 'protected_storage', 'range_streaming', 'previews', 'media_guards'], 'platform_id' => $this->market->id, 'rest_url' => $this->market->wp_api_url, 'home_url' => 'https://uganda.example.test/', 'plugin_version' => '1.3.20', 'deployment' => ['complete' => true, 'missing' => []], 'wallet_auth' => ['ready' => true], 'checked_at' => now()->toIso8601String(), 'server' => ['storage' => ['ready' => true], 'gd' => true, 'fileinfo' => true, 'video' => ['ready' => true], 'issues' => []]];
    }

    private function ready(): void
    {
        $this->settings->update(['preflight_json' => $this->preflight(), 'wp_revision' => $this->settings->config_revision, 'heartbeat_at' => now(), 'readiness_json' => ['ready' => true, 'checks' => array_fill_keys(['protected_storage', 'anonymous_denied', 'direct_denied', 'range', 'no_store', 'video_processing'], true)], 'setup_json' => ['checked_at' => now()->toIso8601String(), 'revision' => $this->settings->config_revision, 'configured_url' => $this->market->wp_api_url, 'environment' => 'production', 'sync' => ['status' => 'synced'], 'credentials' => ['status' => 'synced']]]);
    }

    private function input(): array
    {
        $s = $this->settings->fresh('prices');

        return ['reason' => 'Local market setup test', 'config_revision' => $s->config_revision, 'enabled' => false, 'rollout_mode' => 'off', 'premium_access_environment' => 'production', 'activation_kill_switch' => false, 'checkout_kill_switch' => false, 'prices' => $s->prices->toArray(), 'offer_policy' => $s->offer_policy_json, 'surface_policy' => $s->surface_policy_json, 'checkout_policy' => $s->checkout_policy_json, 'delivery_policy' => $s->delivery_policy_json, 'test_client_ids' => []];
    }

    public function test_successful_preflight_sync_and_delivery_survive_reload_without_enabling(): void
    {
        $this->mock(SyncService::class, function ($m) {
            $m->shouldReceive('send')->withArgs(fn ($s, $route) => $route === '/premium-content/preflight')->andReturn(['status' => 'synced', 'response' => $this->preflight()]);
            $m->shouldReceive('provision')->once()->andReturn(['status' => 'synced']);
            $m->shouldReceive('push')->once()->andReturnUsing(function ($s) {
                $s->update(['wp_revision' => $s->config_revision]);

                return ['status' => 'synced'];
            });
        });
        $this->mock(ReadinessService::class, fn ($m) => $m->shouldReceive('check')->once()->andReturn(['ready' => true, 'checks' => array_fill_keys(['protected_storage', 'anonymous_denied', 'direct_denied', 'range', 'no_store', 'video_processing'], true)]));
        $report = app(SetupService::class)->check($this->settings);
        $this->assertTrue($report['setup']['ready_to_enable']);
        $this->assertTrue(app(SetupService::class)->status($this->settings->fresh())['ready_to_enable']);
        $this->assertSame('off', $this->settings->fresh()->rollout_mode);
        $this->assertFalse($this->settings->fresh()->enabled);
        $this->assertStringNotContainsString('fixture-hmac-only', json_encode($report));
    }

    public function test_canonical_mismatch_is_explicit_and_repair_is_audited(): void
    {
        $preflight = $this->preflight();
        $preflight['rest_url'] = 'https://www.uganda.example.test/wp-json/exotic-crm-sync/v1';
        $this->settings->update(['preflight_json' => $preflight]);
        $status = app(SetupService::class)->status($this->settings);
        $this->assertFalse($status['gates'][0]['passed']);
        $this->assertStringContainsString('https://uganda.example.test', $status['gates'][0]['message']);
        $this->assertStringContainsString('https://www.uganda.example.test', $status['gates'][0]['message']);
        $this->mock(SyncService::class, fn ($m) => $m->shouldReceive('send')->andReturn(['status' => 'failed', 'message' => 'Fixture check pending']));
        app(SetupService::class)->repairCanonical($this->settings, 'Use reported canonical URL', $this->admin->id);
        $this->assertSame($preflight['rest_url'], $this->market->fresh()->wp_api_url);
        $this->assertDatabaseHas('premium_content_events', ['platform_id' => $this->market->id, 'kind' => 'setup_canonical_repaired']);
        $this->assertSame('off', $this->settings->fresh()->rollout_mode);
    }

    public function test_canonical_repair_rejects_a_different_site_without_a_write(): void
    {
        $preflight = $this->preflight();
        $preflight['rest_url'] = 'https://other.example.test/wp-json/exotic-crm-sync/v1';
        $this->settings->update(['preflight_json' => $preflight]);
        try {
            app(SetupService::class)->repairCanonical($this->settings, 'Invalid destination repair', $this->admin->id);
            $this->fail('Cross-site repair accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('connection', $e->errors());
        }
        $this->assertSame($this->market->wp_api_url, $this->market->fresh()->wp_api_url);
        Http::assertNothingSent();
    }

    public function test_public_dns_pinning_rejects_private_mapped_shared_and_mixed_addresses(): void
    {
        foreach ([['127.0.0.1'], ['::ffff:127.0.0.1'], ['100.64.0.1'], ['93.184.216.34', '10.0.0.1'], []] as $ips) {
            $guard = new class($ips) extends WordPressDestination
            {
                public function __construct(private array $ips) {}

                protected function addresses(string $host): array
                {
                    return $this->ips;
                }
            };
            try {
                $guard->options('https://public.example.com');
                $this->fail('Unsafe DNS accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('connection', $e->errors());
            }
        }
        $guard = new class extends WordPressDestination
        {
            protected function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $options = $guard->options('https://public.example.com');
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['public.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_disabled_provider_is_not_an_option_and_cannot_be_saved_even_when_off(): void
    {
        $this->disabledProvider = true;
        $providers = app(SetupService::class)->providers($this->settings);
        $this->assertSame([], $providers['available']);
        try {
            app(MonetizationSettingsService::class)->save($this->market, $this->input(), $this->admin->id);
            $this->fail('Disabled provider saved');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('checkout_policy.allowed_providers', $e->errors());
        }
        $this->assertSame(1, $this->settings->fresh()->config_revision);
    }

    public function test_an_off_market_can_save_a_draft_without_an_available_provider(): void
    {
        $input = $this->input();
        $input['checkout_policy']['allowed_providers'] = [];
        $saved = app(MonetizationSettingsService::class)->save($this->market, $input, $this->admin->id);
        $this->assertSame('off', $saved->rollout_mode);
        $this->assertSame([], $saved->checkout_policy_json['allowed_providers']);
        $this->assertFalse(app(SetupService::class)->status($saved)['ready_to_enable']);
    }

    public function test_all_required_gates_and_current_revision_are_enforced_for_activation(): void
    {
        $this->ready();
        $service = app(SetupService::class);
        $this->assertTrue($service->status($this->settings)['ready_to_enable']);
        $preflight = $this->preflight();
        foreach (['storage', 'gd', 'fileinfo', 'video', 'wallet', 'deployment', 'identity'] as $failure) {
            $p = $preflight;
            if ($failure === 'storage') {
                $p['server']['storage']['ready'] = false;
            }
            if ($failure === 'gd' || $failure === 'fileinfo') {
                $p['server'][$failure] = false;
            }
            if ($failure === 'video') {
                $p['server']['video']['ready'] = false;
            }
            if ($failure === 'wallet') {
                $p['wallet_auth']['ready'] = false;
            }
            if ($failure === 'deployment') {
                $p['deployment']['complete'] = false;
            }
            if ($failure === 'identity') {
                $p['platform_id']++;
            }
            $this->settings->update(['preflight_json' => $p]);
            $this->assertFalse($service->status($this->settings)['ready_to_enable'], $failure);
        }
        $this->ready();
        foreach (['protected_storage', 'anonymous_denied', 'direct_denied', 'range', 'no_store', 'video_processing'] as $key) {
            $report = $this->settings->readiness_json;
            $report['checks'][$key] = false;
            $this->settings->update(['readiness_json' => $report]);
            $this->assertFalse($service->status($this->settings)['ready_to_enable'], $key);
            $this->ready();
        }
        $this->settings->update(['config_revision' => 2]);
        try {
            $service->activate($this->settings, 2, 'Reject stale activation', $this->admin->id);
            $this->fail('Stale activation accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activation', $e->errors());
        }
        $this->assertSame('off', $this->settings->fresh()->rollout_mode);
        $this->assertDatabaseHas('premium_content_events', ['kind' => 'setup_activation_failed']);
    }

    public function test_stale_checks_and_a_disabled_production_provider_prevent_live(): void
    {
        $this->ready();
        $stored = $this->settings->setup_json;
        $stored['checked_at'] = now()->subMinutes(16)->toIso8601String();
        $this->settings->update(['setup_json' => $stored]);
        $this->assertFalse(app(SetupService::class)->status($this->settings)['ready_to_enable']);
        $this->ready();
        $this->disabledProvider = true;
        $this->assertFalse(app(SetupService::class)->status($this->settings)['ready_to_enable']);
    }

    public function test_general_settings_save_cannot_bypass_explicit_activation(): void
    {
        $this->ready();
        $input = $this->input();
        $input['enabled'] = true;
        $input['rollout_mode'] = 'live';
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/crm/settings/monetization/markets/'.$this->market->id, $input)->assertStatus(422)->assertJsonPath('message', 'Use Guided setup → Enable live market after completing the required checks.');
        $this->assertSame('off', $this->settings->fresh()->rollout_mode);
    }

    public function test_explicit_activation_waits_for_wordpress_confirmation_and_records_the_outcome(): void
    {
        $this->ready();
        $this->mock(SyncService::class, fn ($m) => $m->shouldReceive('push')->once()->andReturnUsing(function ($s) {
            $s->update(['wp_revision' => $s->config_revision]);

            return ['status' => 'synced'];
        }));
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/setup/activate', ['config_revision' => 1, 'reason' => 'Explicit local activation test']);
        $response->assertOk()->assertJsonPath('setup.is_live', true);
        $this->assertSame(2, $this->settings->fresh()->config_revision);
        $event = PremiumContentEvent::where('kind', 'setup_live_enabled')->firstOrFail();
        $this->assertFalse($event->metadata_json['payment_tested']);
    }

    public function test_lost_activation_response_retains_off_and_sends_compensating_revision(): void
    {
        $this->ready();
        $this->mock(SyncService::class, function ($m) {
            $m->shouldReceive('push')->once()->ordered()->andReturn(['status' => 'failed']);
            $m->shouldReceive('push')->once()->ordered()->withArgs(fn ($s) => $s->rollout_mode === 'off' && $s->config_revision === 3)->andReturn(['status' => 'synced']);
        });
        try {
            app(SetupService::class)->activate($this->settings, 1, 'Lost confirmation test', $this->admin->id);
            $this->fail('Unconfirmed activation succeeded');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('previous market mode is retained', $e->errors()['activation'][0]);
        }
        $this->assertSame('off', $this->settings->fresh()->rollout_mode);
        $this->assertFalse($this->settings->fresh()->enabled);
    }

    public function test_connect_aligns_environment_and_market_without_exposing_credentials(): void
    {
        $this->mock(SyncService::class, function ($m) {
            $m->shouldReceive('adminSend')->once()->withArgs(fn ($s, $route, $payload) => $route === '/wallet-credentials' && $payload['platform_id'] === $this->market->id && $payload['grant_secret'] === $this->settings->grant_secret)->andReturn(['status' => 'synced', 'response' => ['fixture_secret' => 'must-not-be-returned']]);
            $m->shouldReceive('send')->once()->andReturn(['status' => 'failed', 'message' => 'Server needs setup']);
        });
        $result = app(SetupService::class)->connect($this->settings, 'production', 'Align WordPress market auth', $this->admin->id);
        $this->assertSame('production', $this->settings->fresh()->premium_access_environment);
        $this->assertSame('off', $this->settings->fresh()->rollout_mode);
        $this->assertStringNotContainsString('fixture-bearer-only', json_encode($result));
        $this->assertStringNotContainsString('must-not-be-returned', json_encode($result));
        $this->assertDatabaseHas('premium_content_events', ['kind' => 'setup_connected']);
    }

    public function test_setup_actions_preserve_market_authorization(): void
    {
        foreach ([['role' => 'sales'], ['role' => 'sub_admin', 'assigned_market_ids' => []]] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->actingAs($user, 'sanctum')->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/setup/connect', ['reason' => 'Unauthorized local test', 'config_revision' => 1])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_readiness_origin_mismatch_names_the_repair_without_requesting_the_probe(): void
    {
        $url = 'https://www.uganda.example.test/wp-json/exotic-crm-sync/v1/premium-content/assets/fixture';
        $this->mock(SyncService::class, fn ($m) => $m->shouldReceive('send')->andReturn(['status' => 'synced', 'response' => ['asset_url' => $url, 'delivery_url' => $url, 'direct_url' => $url]]));
        $report = app(ReadinessService::class)->check($this->settings);
        $this->assertFalse($report['ready']);
        $this->assertSame('canonical_origin_mismatch', $report['code']);
        $this->assertStringContainsString('Use the WordPress address', $report['error']);
        Http::assertNothingSent();
    }

    public function test_signed_transport_only_follows_the_registered_www_alias(): void
    {
        $path = '/wp-json/exotic-crm-sync/v1/premium-content/preflight';
        $http = new \Illuminate\Http\Client\Factory;
        $http->fake(['https://uganda.example.test'.$path => Http::response('', 301, ['Location' => 'https://www.uganda.example.test'.$path]), 'https://www.uganda.example.test'.$path => Http::response($this->preflight())]);
        Http::swap($http);
        $this->assertSame('synced', app(SyncService::class)->send($this->settings, '/premium-content/preflight', [])['status']);
        Http::assertSentCount(2);
        $http = new \Illuminate\Http\Client\Factory;
        $http->fake(['https://uganda.example.test'.$path => Http::response('', 307, ['Location' => 'https://other.example.test'.$path])]);
        Http::swap($http);
        $this->assertSame('unsafe_wordpress_destination', app(SyncService::class)->send($this->settings, '/premium-content/preflight', [])['code']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'other.example.test'));
    }

    public function test_established_live_market_keeps_legacy_delivery_checks_during_plugin_upgrade(): void
    {
        $this->ready();
        $this->settings->update(['enabled' => true, 'rollout_mode' => 'live']);
        $this->mock(SyncService::class, function ($m) {
            $m->shouldReceive('send')->once()->andReturn(['status' => 'failed', 'code' => 'wordpress_http_404', 'message' => 'Plugin update needed']);
            $m->shouldReceive('provision')->once()->andReturn(['status' => 'synced']);
            $m->shouldReceive('push')->once()->andReturn(['status' => 'synced']);
        });
        $this->mock(ReadinessService::class, fn ($m) => $m->shouldReceive('check')->once()->andReturn($this->settings->readiness_json));
        $result = app(SetupService::class)->check($this->settings);
        $this->assertTrue($result['readiness']['ready']);
        $this->assertTrue($this->settings->fresh()->enabled);
        $this->assertFalse($result['setup']['ready_to_enable']);
    }

    public function test_release_check_detects_an_incomplete_application(): void
    {
        $this->mock(SetupService::class, fn ($m) => $m->shouldReceive('deployment')->andReturn(['complete' => false, 'missing' => ['app/Models/VisitorContentPurchase.php']]));
        $this->artisan('monetization:check-release')->expectsOutputToContain('VisitorContentPurchase.php')->assertExitCode(1);
    }

    public function test_probe_transport_exceptions_never_expose_a_delivery_grant(): void
    {
        $url = 'https://uganda.example.test/wp-json/exotic-crm-sync/v1/premium-content/assets/fixture';
        $this->mock(SyncService::class, fn ($m) => $m->shouldReceive('send')->andReturn(['status' => 'synced', 'response' => ['asset_url' => $url, 'delivery_url' => $url.'?grant=fixture-secret-grant', 'direct_url' => $url]]));
        $http = new \Illuminate\Http\Client\Factory;
        $http->fake(fn () => throw new \RuntimeException('Network failure for '.$url.'?grant=fixture-secret-grant'));
        Http::swap($http);
        $report = app(ReadinessService::class)->check($this->settings);
        $this->assertSame('probe_unreachable', $report['code']);
        $this->assertStringNotContainsString('fixture-secret-grant', json_encode($report));
    }

    public function test_preflight_retains_only_declared_non_secret_facts(): void
    {
        $preflight = $this->preflight();
        $preflight['server']['private_secret'] = 'fixture-sensitive-value';
        $preflight['server']['video']['arbitrary_files'] = ['fixture-sensitive-value'];
        $preflight['wallet_auth']['bearer'] = 'fixture-sensitive-value';
        $this->mock(SyncService::class, function ($m) use ($preflight) {
            $m->shouldReceive('send')->andReturn(['status' => 'synced', 'response' => $preflight]);
            $m->shouldReceive('provision')->andReturn(['status' => 'failed', 'message' => 'Fixture provisioning unavailable']);
        });
        $result = app(SetupService::class)->check($this->settings);
        $this->assertStringNotContainsString('fixture-sensitive-value', json_encode($result));
        $this->assertStringNotContainsString('fixture-sensitive-value', json_encode($this->settings->fresh()->preflight_json));
        $this->assertArrayNotHasKey('platform', $this->settings->toArray());
    }

    public function test_missing_preflight_capability_prevents_activation(): void
    {
        $this->ready();
        $preflight = $this->preflight();
        $preflight['capabilities'] = ['protected_storage'];
        $this->settings->update(['preflight_json' => $preflight]);
        $status = app(SetupService::class)->status($this->settings);
        $this->assertFalse($status['ready_to_enable']);
        $this->assertStringContainsString('Upload the complete sync plugin', $status['gates'][0]['message']);
    }

    public function test_setup_migration_round_trip_preserves_existing_market_configuration(): void
    {
        $migration = require database_path('migrations/2026_10_09_000001_add_monetization_setup_reports.php');
        $before = $this->settings->fresh()->only(['currency', 'enabled', 'rollout_mode', 'checkout_policy_json', 'config_revision']);
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('content_monetization_settings', 'setup_json'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('content_monetization_settings', ['setup_json', 'preflight_json']));
        $this->assertSame($before, $this->settings->fresh()->only(array_keys($before)));
    }
}
