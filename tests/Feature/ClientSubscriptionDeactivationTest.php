<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\RenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientSubscriptionDeactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_untracked_client_subscription_can_be_deactivated_via_client_endpoint(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 125447,
            'wp_user_id' => 32211,
            'name' => 'Blessing',
            'phone_normalized' => '233209582508',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'premium' => true,
            'featured' => true,
            'escort_expire' => now()->addDays(30)->timestamp,
        ]);
        $user = $this->createAuthorizedUser($platform);

        Http::fake([
            rtrim((string) $platform->wp_api_url, '/') . '/clients/125447/deactivate' => Http::response([
                'success' => true,
            ], 200),
            rtrim((string) $platform->wp_api_url, '/') . '/clients/125447' => Http::response([
                'wp_post_id' => 125447,
                'wp_user_id' => 32211,
                'name' => 'Blessing',
                'phone' => '+233209582508',
                'email' => 'blessing@example.test',
                'city' => 'East Legon',
                'post_status' => 'private',
                'premium' => false,
                'premium_expire' => null,
                'featured' => false,
                'featured_expire' => null,
                'escort_expire' => null,
                'verified' => false,
                'needs_payment' => true,
                'notactive' => false,
                'main_image_url' => '',
                'modified_at' => now()->toIso8601String(),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/deactivate-subscription", [
            'reason_code' => 'customer_request',
            'reason_notes' => 'Customer asked to stop the wp-admin subscription.',
        ]);

        $response->assertOk()
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('client.profile_status', 'private')
            ->assertJsonPath('client.needs_payment', true)
            ->assertJsonPath('client.notactive', false)
            ->assertJsonPath('client.premium', false)
            ->assertJsonPath('client.featured', false)
            ->assertJsonPath('client.escort_expire', null);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'profile_status' => 'private',
            'needs_payment' => 1,
            'notactive' => 0,
            'premium' => 0,
            'featured' => 0,
            'escort_expire' => null,
        ]);

        $event = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->where('event_type', 'profile_deactivated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('customer_request', data_get($event->content, 'reason_code'));
        $this->assertSame('client_wp_subscription', data_get($event->content, 'deactivation_scope'));

        $audit = AuditLog::query()
            ->where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('client_subscription_deactivate', $audit->action);
        $this->assertSame('private', data_get($audit->after_state, 'profile_status'));
        $this->assertTrue((bool) data_get($audit->after_state, 'needs_payment'));

        Http::assertSent(fn ($request) => $request->url() === rtrim((string) $platform->wp_api_url, '/') . '/clients/125447/deactivate');
        Http::assertSent(fn ($request) => $request->url() === rtrim((string) $platform->wp_api_url, '/') . '/clients/125447');
    }

    public function test_renewal_overview_marks_publish_plus_notactive_rows_as_conflicted_not_forever_untracked(): void
    {
        $platform = $this->createPlatform();

        $conflictedClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Blessing Conflict',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => true,
            'premium' => false,
            'featured' => false,
            'escort_expire' => null,
            'premium_expire' => null,
            'featured_expire' => null,
        ]);

        Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Real Forever Plan',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'premium' => false,
            'featured' => false,
            'escort_expire' => null,
            'premium_expire' => null,
            'featured_expire' => null,
        ]);

        $payload = app(RenewalService::class)->buildOverview([
            'platform_id' => $platform->id,
            'search' => 'Blessing Conflict',
            'bucket' => 'all',
            'include_untracked' => true,
        ], 20, null);

        $row = collect($payload['targets']->items())->firstWhere('client_id', $conflictedClient->id);

        $this->assertNotNull($row);
        $this->assertFalse((bool) ($row['is_untracked'] ?? false));
        $this->assertTrue((bool) ($row['has_wp_state_conflict'] ?? false));
        $this->assertTrue((bool) ($row['can_deactivate_without_deal'] ?? false));
        $this->assertSame('WP state conflict', $row['wp_profile_state_label']);
        $this->assertSame(0, (int) ($payload['summary']['untracked_active'] ?? -1));

        $fullPayload = app(RenewalService::class)->buildOverview([
            'platform_id' => $platform->id,
            'bucket' => 'all',
            'include_untracked' => true,
        ], 20, null);

        $this->assertSame(1, (int) ($fullPayload['summary']['untracked_active'] ?? 0));
    }

    public function test_renewal_overview_marks_legacy_rows_as_manually_deactivatable(): void
    {
        $platform = $this->createPlatform();

        $legacyClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 123985,
            'wp_user_id' => 31877,
            'name' => 'Juliana',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'premium' => false,
            'featured' => false,
            'escort_expire' => now()->subDays(5)->timestamp,
            'premium_expire' => null,
            'featured_expire' => null,
        ]);

        $payload = app(RenewalService::class)->buildOverview([
            'platform_id' => $platform->id,
            'search' => 'Juliana',
            'bucket' => 'all',
        ], 20, null);

        $row = collect($payload['targets']->items())->firstWhere('client_id', $legacyClient->id);

        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row['is_virtual'] ?? false));
        $this->assertSame('legacy', $row['origin_type'] ?? null);
        $this->assertSame('expired', $row['status'] ?? null);
        $this->assertTrue((bool) ($row['can_deactivate_without_deal'] ?? false));
        $this->assertSame('client', $row['deactivation_scope'] ?? null);
        $this->assertSame('Deactivate', $row['deactivation_label'] ?? null);
    }

    public function test_client_show_payload_exposes_no_deal_deactivation_metadata(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 123985,
            'wp_user_id' => 31877,
            'name' => 'Juliana',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'premium' => false,
            'featured' => false,
            'escort_expire' => now()->subDays(5)->timestamp,
        ]);
        $user = $this->createAuthorizedUser($platform);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/clients/{$client->id}");

        $response->assertOk()
            ->assertJsonPath('id', $client->id)
            ->assertJsonPath('can_deactivate_without_deal', true)
            ->assertJsonPath('deactivation_scope', 'client')
            ->assertJsonPath('deactivation_label', 'Deactivate')
            ->assertJsonPath('deactivation_disabled_reason', null);
    }

    public function test_legacy_client_subscription_can_be_deactivated_via_client_endpoint(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 123985,
            'wp_user_id' => 31877,
            'name' => 'Juliana',
            'phone_normalized' => '233508182807',
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'premium' => false,
            'featured' => false,
            'escort_expire' => now()->subDays(5)->timestamp,
        ]);
        $user = $this->createAuthorizedUser($platform);

        Http::fake([
            rtrim((string) $platform->wp_api_url, '/') . '/clients/123985/deactivate' => Http::response([
                'success' => true,
            ], 200),
            rtrim((string) $platform->wp_api_url, '/') . '/clients/123985' => Http::response([
                'wp_post_id' => 123985,
                'wp_user_id' => 31877,
                'name' => 'Juliana',
                'phone' => '+233508182807',
                'email' => 'juliana@example.test',
                'city' => 'East Legon',
                'post_status' => 'private',
                'premium' => false,
                'premium_expire' => null,
                'featured' => false,
                'featured_expire' => null,
                'escort_expire' => null,
                'verified' => false,
                'needs_payment' => true,
                'notactive' => false,
                'main_image_url' => '',
                'modified_at' => now()->toIso8601String(),
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/deactivate-subscription", [
            'reason_code' => 'other',
            'reason_notes' => 'Manual legacy cleanup from CRM.',
        ]);

        $response->assertOk()
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('client.profile_status', 'private')
            ->assertJsonPath('client.needs_payment', true)
            ->assertJsonPath('client.escort_expire', null);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'profile_status' => 'private',
            'needs_payment' => 1,
            'escort_expire' => null,
        ]);
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Client Deactivation Market',
            'domain' => 'client-deactivation-' . Str::random(6) . '.example.test',
            'country' => 'Ghana',
            'timezone' => 'Africa/Accra',
            'phone_prefix' => '233',
            'currency_code' => 'GHS',
            'is_active' => true,
            'wp_api_url' => 'https://client-deactivation.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createAuthorizedUser(Platform $platform): User
    {
        return User::query()->create([
            'name' => 'Client Deactivation Admin',
            'email' => 'client-deactivation-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }
}
