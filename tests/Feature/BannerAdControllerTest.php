<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BannerAdControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_active_crm_roles_can_access_banner_ad_markets(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/settings' => Http::response([
                'shuffle_mode' => false,
            ]),
        ]);

        foreach (['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'] as $role) {
            Sanctum::actingAs($this->createUser($role, [$platform->id]));

            $this->getJson('/api/crm/banner-ads/markets')
                ->assertOk()
                ->assertJsonPath('data.0.id', $platform->id)
                ->assertJsonPath('data.0.banner_ads_ready', true);
        }
    }

    public function test_list_proxies_wordpress_campaigns_summary_and_settings(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();
        $user = $this->createUser('sales', [$platform->id]);

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns*' => Http::response([
                'items' => [
                    $this->campaignPayload([
                        'id' => 77,
                        'title' => 'Weekend Banner',
                        'format' => 'image',
                        'image_id' => 123,
                        'image_url' => 'https://kenya.example/uploads/banner.jpg',
                        'status' => 'active',
                        'impressions' => 1200,
                        'clicks' => 60,
                        'ctr' => 5,
                    ]),
                ],
                'total' => 1,
                'pages' => 1,
                'page' => 1,
            ]),
            'https://kenya.example/wp-json/exotic-campaigns/v1/analytics/summary' => Http::response([
                'summary' => [
                    'campaigns_total' => 1,
                    'campaigns_active' => 1,
                    'campaigns_scheduled' => 0,
                    'campaigns_paused' => 0,
                    'campaigns_expired' => 0,
                    'impressions_total' => 1200,
                    'clicks_total' => 60,
                    'avg_ctr' => 5,
                ],
            ]),
            'https://kenya.example/wp-json/exotic-campaigns/v1/settings' => Http::response([
                'shuffle_mode' => true,
            ]),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/crm/banner-ads?platform_id={$platform->id}&status=active")
            ->assertOk()
            ->assertJsonPath('items.0.platform_id', $platform->id)
            ->assertJsonPath('items.0.id', 77)
            ->assertJsonPath('items.0.image_url', 'https://kenya.example/uploads/banner.jpg')
            ->assertJsonPath('summary.banner_ads_active', 1)
            ->assertJsonPath('settings.shuffle_mode', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns?status=active&page=1&per_page=25'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('crm-user:secret')));
    }

    public function test_create_image_banner_ad_writes_to_wordpress_and_audits(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();
        $user = $this->createUser('field_sales', [$platform->id]);

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns' => Http::response($this->campaignPayload([
                'id' => 91,
                'title' => 'Image Launch',
                'format' => 'image',
                'image_id' => 444,
                'status' => 'scheduled',
                'start_date' => '2026-09-10 09:00:00',
            ]), 201),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/crm/banner-ads', [
            'platform_id' => $platform->id,
            'post_title' => 'Image Launch',
            '_campaign_format' => 'image',
            '_campaign_image_id' => 444,
            '_campaign_image_alt' => 'Launch creative',
            '_campaign_cta_text' => 'Book now',
            '_campaign_cta_url' => 'https://kenya.example/book',
            '_campaign_cta_visible' => true,
            '_campaign_status' => 'scheduled',
            '_campaign_priority' => 4,
            '_campaign_start_date' => '2026-09-10 09:00:00',
        ])
            ->assertCreated()
            ->assertJsonPath('item.id', 91)
            ->assertJsonPath('message', 'Banner ad created.');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns'
            && $request['_campaign_image_id'] === 444
            && $request['_campaign_status'] === 'scheduled');

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $user->id,
            'action' => CrmAuditAction::BANNER_AD_CREATE,
            'entity_type' => 'banner_ad',
            'entity_id' => 91,
        ]);
    }

    public function test_schedule_status_updates_dates_and_audits(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();
        $user = $this->createUser('marketing', [$platform->id]);

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns/44' => Http::sequence()
                ->push($this->campaignPayload(['id' => 44, 'status' => 'paused']))
                ->push($this->campaignPayload(['id' => 44, 'status' => 'scheduled', 'start_date' => '2026-09-11 10:00:00'])),
            'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns/44/status' => Http::response($this->campaignPayload([
                'id' => 44,
                'status' => 'scheduled',
                'start_date' => '2026-09-11 10:00:00',
                'end_date' => '2026-09-12 10:00:00',
            ])),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/crm/banner-ads/44/status', [
            'platform_id' => $platform->id,
            'status' => 'scheduled',
            'start_date' => '2026-09-11 10:00:00',
            'end_date' => '2026-09-12 10:00:00',
        ])
            ->assertOk()
            ->assertJsonPath('item.status', 'scheduled')
            ->assertJsonPath('item.start_date', '2026-09-11 10:00:00');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns/44/status'
            && $request['status'] === 'scheduled'
            && $request['start_date'] === '2026-09-11 10:00:00');

        $audit = AuditLog::query()->where('action', CrmAuditAction::BANNER_AD_SCHEDULE)->first();
        $this->assertNotNull($audit);
        $this->assertSame('paused', data_get($audit->before_state, 'status'));
        $this->assertSame('scheduled', data_get($audit->after_state, 'status'));
    }

    public function test_delete_is_permanent_wordpress_delete_and_audited(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();
        $user = $this->createUser('admin');

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns/88' => Http::sequence()
                ->push($this->campaignPayload(['id' => 88, 'title' => 'Old Ad']))
                ->push(['success' => true, 'deleted_id' => 88]),
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/crm/banner-ads/88', [
            'platform_id' => $platform->id,
            'reason' => 'Retired creative',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deleted_id', 88);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns/88');

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::BANNER_AD_DELETE,
            'entity_type' => 'banner_ad',
            'entity_id' => 88,
            'reason' => 'Retired creative',
        ]);
    }

    public function test_settings_update_toggles_shuffle_mode_and_audits(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();
        $user = $this->createUser('sales', [$platform->id]);

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/settings' => Http::sequence()
                ->push(['shuffle_mode' => false])
                ->push(['shuffle_mode' => true]),
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/crm/banner-ads/settings', [
            'platform_id' => $platform->id,
            'shuffle_mode' => true,
        ])
            ->assertOk()
            ->assertJsonPath('settings.shuffle_mode', true);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://kenya.example/wp-json/exotic-campaigns/v1/settings'
            && $request['shuffle_mode'] === true);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::BANNER_AD_SETTINGS_UPDATE,
            'entity_type' => 'banner_ad',
            'entity_id' => $platform->id,
        ]);
    }

    public function test_media_listing_proxies_wordpress_media_endpoint(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();
        $user = $this->createUser('marketing', [$platform->id]);

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/media*' => Http::response([
                'items' => [
                    [
                        'id' => 501,
                        'title' => 'Hero creative',
                        'url' => 'https://kenya.example/uploads/hero.jpg',
                        'thumbnail_url' => 'https://kenya.example/uploads/hero-150x150.jpg',
                        'alt_text' => 'Hero',
                        'mime_type' => 'image/jpeg',
                    ],
                ],
                'total' => 1,
                'pages' => 1,
                'page' => 1,
            ]),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/crm/banner-ads/media?platform_id={$platform->id}&search=hero")
            ->assertOk()
            ->assertJsonPath('items.0.id', 501)
            ->assertJsonPath('items.0.thumbnail_url', 'https://kenya.example/uploads/hero-150x150.jpg');
    }

    public function test_inaccessible_market_is_denied_before_wordpress_call(): void
    {
        Http::preventStrayRequests();

        $allowed = $this->createPlatform(['name' => 'Kenya', 'domain' => 'kenya.example']);
        $blocked = $this->createPlatform(['name' => 'Uganda', 'domain' => 'uganda.example']);
        $user = $this->createUser('sales', [$allowed->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/crm/banner-ads?platform_id={$blocked->id}")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_missing_wordpress_credentials_returns_readiness_and_request_error(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform([
            'wp_api_url' => null,
            'wp_api_user' => null,
            'wp_api_password' => null,
        ]);
        $user = $this->createUser('marketing', [$platform->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/banner-ads/markets')
            ->assertOk()
            ->assertJsonPath('data.0.banner_ads_ready', false)
            ->assertJsonPath('data.0.readiness_message', 'WordPress API credentials are missing for this market.');

        $this->getJson("/api/crm/banner-ads?platform_id={$platform->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'WordPress API credentials are missing for this market.');
    }

    public function test_wordpress_capability_failure_is_reported_as_remote_error(): void
    {
        Http::preventStrayRequests();

        $platform = $this->createPlatform();
        $user = $this->createUser('marketing', [$platform->id]);

        Http::fake([
            'https://kenya.example/wp-json/exotic-campaigns/v1/campaigns*' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/crm/banner-ads?platform_id={$platform->id}")
            ->assertStatus(502)
            ->assertJsonPath('message', 'WordPress API user cannot manage banner ads.')
            ->assertJsonPath('remote_status', 403);
    }

    private function createPlatform(array $overrides = []): Platform
    {
        return Platform::query()->create(array_merge([
            'name' => 'Kenya',
            'domain' => 'kenya.example',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://kenya.example/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ], $overrides));
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => strtolower($role).Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => $assignedMarketIds,
            'status' => 'active',
        ]);
    }

    private function campaignPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 44,
            'title' => 'Adult Spa',
            'format' => 'card',
            'badge_text' => 'Adult Fun',
            'description' => 'Premium placement',
            'icon_class' => 'fa fa-bullhorn',
            'color_primary' => '#AB1C2F',
            'color_secondary' => '',
            'image_id' => 0,
            'image_url' => null,
            'image_alt' => '',
            'cta_text' => 'View',
            'cta_url' => 'https://kenya.example/adult-spa',
            'cta_visible' => true,
            'status' => 'active',
            'priority' => 10,
            'start_date' => '',
            'end_date' => '',
            'impressions' => 0,
            'clicks' => 0,
            'ctr' => 0,
        ], $overrides);
    }
}
