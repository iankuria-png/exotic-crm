<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\TourMarketSetting;
use App\Services\TourSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class TourSettingsControllerTest extends TestCase
{
    use InteractsWithKycFixtures;
    use RefreshDatabase;

    private const BASE = 'https://tour-sync.example.test/wp-json/exotic-crm-sync/v1';

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = $this->createPlatform(['country' => 'Kenya', 'currency_code' => 'KES', 'wp_api_url' => self::BASE, 'wp_api_user' => 'test-user', 'wp_api_password' => 'test-only']);
    }

    private function settings(): array
    {
        return app(TourSettingsService::class)->defaults();
    }

    private function endpoint(): string
    {
        return '/api/crm/tours/'.$this->platform->id.'/settings';
    }

    public function test_defaults_are_off_and_approved_kenya_catalogue_is_only_a_template(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        $data = $this->getJson($this->endpoint())->assertOk()->assertJsonPath('revision', 0)->assertJsonPath('settings.enabled', false)->assertJsonPath('settings.passes', [])->json();
        $passes = collect($data['starter_passes'])->keyBy('code');
        $this->assertSame(800000, $passes['dubai_30d']['amount_minor']);
        $this->assertSame(400000, $passes['dubai_14d']['amount_minor']);
        $this->assertSame(200000, $passes['dubai_7d']['amount_minor']);
        $this->assertSame(600000, $passes['europe_30d']['amount_minor']);
        $this->assertSame(300000, $passes['europe_14d']['amount_minor']);
        $this->assertSame(150000, $passes['europe_7d']['amount_minor']);
        $this->assertTrue($passes->every(fn ($pass) => $pass['enabled'] === false));
        $this->assertDatabaseCount('tour_market_settings', 0);
    }

    public function test_other_currencies_are_not_given_kenya_prices(): void
    {
        $this->platform->update(['country' => 'Uganda', 'currency_code' => 'UGX']);
        Sanctum::actingAs($this->createKycUser('admin'));
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('starter_passes', []);
    }

    public function test_scoped_subadmin_can_read_only_assigned_markets_and_cannot_save(): void
    {
        $other = $this->createPlatform();
        Sanctum::actingAs($this->createKycUser('sub_admin', [$this->platform->id]));
        $ids = collect($this->getJson('/api/crm/tours/markets')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($this->platform->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->getJson($this->endpoint())->assertOk();
        $this->getJson('/api/crm/tours/'.$other->id.'/settings')->assertForbidden();
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $this->settings()])->assertForbidden();
        $this->postJson('/api/crm/tours/'.$this->platform->id.'/publish', ['expected_revision' => 1])->assertForbidden();
    }

    public function test_sales_and_anonymous_users_cannot_access_controls(): void
    {
        $this->getJson($this->endpoint())->assertUnauthorized();
        Sanctum::actingAs($this->createKycUser('sales', [$this->platform->id]));
        $this->getJson($this->endpoint())->assertForbidden();
    }

    public function test_save_is_durable_revisioned_and_does_not_publish(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::preventStrayRequests();
        $settings = $this->settings();
        $settings['enabled'] = true;
        $settings['max_stops'] = 4;
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('publication_status', 'unpublished');
        $this->assertSame($settings, TourMarketSetting::first()->settings);
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $this->settings()])->assertConflict();
        $this->assertSame(1, TourMarketSetting::first()->revision);
        Http::assertNothingSent();
    }

    public function test_validation_rejects_unknown_controls_limits_and_wrong_currency(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        foreach ([['local_destination_depth' => 3], ['environment' => 'inherited'], ['max_stops' => 11], ['max_span_days' => 91], ['allow_any_remote_host' => true]] as $patch) {
            $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => array_replace($this->settings(), $patch)])->assertUnprocessable();
        }
        $settings = $this->settings();
        $settings['passes'] = [array_replace(app(TourSettingsService::class)->read($this->platform)['starter_passes'][0], ['currency' => 'USD'])];
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertUnprocessable()->assertJsonValidationErrors('settings.passes.0.currency');
    }

    public function test_catalogue_prices_duration_and_destination_groups_are_fully_editable(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        $destination = $this->createPlatform(['is_active' => true]);
        $settings = $this->settings();
        $settings['passes'] = [['code' => 'my_dubai', 'name' => 'Dubai fortnight', 'region' => 'gulf', 'currency' => 'KES', 'amount_minor' => 377755, 'days' => 12, 'market_limit' => 2, 'destination_platform_ids' => [$destination->id], 'enabled' => true]];
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertOk()->assertJsonPath('settings.passes.0.amount_minor', 377755)->assertJsonPath('settings.passes.0.days', 12)->assertJsonPath('settings.passes.0.destination_platform_ids', [$destination->id]);
    }

    public function test_enabled_pass_requires_destination_and_duplicates_are_rejected(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        $pass = app(TourSettingsService::class)->read($this->platform)['starter_passes'][0];
        $settings = $this->settings();
        $settings['passes'] = [array_replace($pass, ['enabled' => true])];
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertUnprocessable();
        $settings['passes'] = [$pass, $pass];
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertUnprocessable();
        $settings['passes'] = [array_replace($pass, ['destination_platform_ids' => [$this->platform->id]])];
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertUnprocessable();
    }

    public function test_receiver_controls_require_registered_public_origins_and_explicit_sources(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        $source = $this->createPlatform(['domain' => 'https://source.example.com']);
        $settings = $this->settings();
        $settings['receiver_enabled'] = true;
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertUnprocessable();
        $settings['receiver_sources'] = [['platform_id' => $source->id, 'origin' => 'https://source.example.com', 'media_origins' => ['https://source.example.com', 'https://cdn.example.com']]];
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertOk()->assertJsonPath('settings.receiver_enabled', true);
        foreach (['http://source.example.com', 'https://127.0.0.1', 'https://files.internal', 'https://cdn.example.com/uploads'] as $origin) {
            $settings['receiver_sources'][0]['media_origins'] = [$origin];
            $this->putJson($this->endpoint(), ['expected_revision' => 1, 'settings' => $settings])->assertUnprocessable();
        }
        $settings['receiver_sources'][0]['media_origins'] = ['https://cdn.example.com'];
        $settings['receiver_sources'][0]['origin'] = 'https://different.example.com';
        $this->putJson($this->endpoint(), ['expected_revision' => 1, 'settings' => $settings])->assertUnprocessable();
    }

    public function test_publication_reads_back_complete_settings_before_marking_verified(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        $settings = $this->settings();
        $settings['enabled'] = true;
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertOk();
        $seen = [];
        Http::fake(function (HttpRequest $request) use (&$seen, $settings) {
            $seen[] = $request->method();
            if ($request->method() === 'POST') {
                $this->assertSame(0, $request['expected_revision']);
                $this->assertSame(1, $request['revision']);
                $this->assertSame($settings, $request['settings']);
            }

            return Http::response(['revision' => count($seen) === 1 ? 0 : 1, 'settings' => $settings]);
        });
        $this->postJson('/api/crm/tours/'.$this->platform->id.'/publish', ['expected_revision' => 1])->assertOk()->assertJsonPath('publication_status', 'published')->assertJsonPath('synced_revision', 1);
        $this->assertSame(['GET', 'POST', 'GET'], $seen);
    }

    public function test_failed_or_mismatched_publication_preserves_saved_draft_and_never_claims_live(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        $settings = $this->settings();
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $settings])->assertOk();
        Http::fake(['*' => Http::response(['revision' => 0, 'settings' => $settings])]);
        $this->postJson('/api/crm/tours/'.$this->platform->id.'/publish', ['expected_revision' => 1])->assertStatus(502)->assertJsonPath('configuration.publication_status', 'failed');
        $row = TourMarketSetting::first();
        $this->assertNull($row->synced_revision);
        $this->assertSame($settings, $row->settings);
        Http::fake(['*' => Http::response(['message' => 'unavailable'], 503)]);
        $this->postJson('/api/crm/tours/'.$this->platform->id.'/publish', ['expected_revision' => 1])->assertStatus(502);
        $this->assertNull(TourMarketSetting::first()->synced_revision);
    }

    public function test_publication_of_stale_crm_revision_is_conflict_without_a_network_request(): void
    {
        Sanctum::actingAs($this->createKycUser('admin'));
        Http::preventStrayRequests();
        $this->putJson($this->endpoint(), ['expected_revision' => 0, 'settings' => $this->settings()])->assertOk();
        $this->postJson('/api/crm/tours/'.$this->platform->id.'/publish', ['expected_revision' => 2])->assertConflict();
        Http::assertNothingSent();
    }
}
