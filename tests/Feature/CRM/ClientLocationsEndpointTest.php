<?php

namespace Tests\Feature\CRM;

use App\Models\CityGeocode;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientLocationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_city_aggregates_with_performance(): void
    {
        $platform = $this->makePlatform();
        $user = $this->makeUser('marketing', [$platform->id]);

        Client::factory()->create([
            'platform_id' => $platform->id,
            'city' => 'Nairobi',
            'verified' => true,
            'wp_post_id' => 101,
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'city' => 'Mombasa',
            'profile_status' => 'private',
            'wp_post_id' => 202,
        ]);

        CityGeocode::query()->create([
            'platform_id' => $platform->id,
            'canonical_key' => 'nairobi',
            'display_city' => 'Nairobi',
            'latitude' => -1.2863890,
            'longitude' => 36.8172230,
            'status' => 'resolved',
        ]);

        Http::fake([
            $this->bulkPattern($platform) => Http::response([
                'page' => 1,
                'total_pages' => 1,
                'market_averages' => ['views' => 60, 'contact_rate' => 15.5],
                'profiles' => [
                    [
                        'wp_post_id' => 101,
                        'views' => 80,
                        'unique' => 50,
                        'contact_rate' => 20,
                        'contacts' => ['phone' => 2, 'whatsapp' => 6, 'viber' => 1],
                        'engagement' => 88,
                    ],
                    [
                        'wp_post_id' => 202,
                        'views' => 20,
                        'unique' => 10,
                        'contact_rate' => 5,
                        'contacts' => ['phone' => 0, 'whatsapp' => 1, 'viber' => 0],
                        'engagement' => 35,
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('analytics_status', 'ok')
            ->assertJsonPath('locations.0.display_city', 'Mombasa')
            ->assertJsonPath('locations.1.display_city', 'Nairobi')
            ->assertJsonPath('locations.1.channels.whatsapp', 6)
            ->assertJsonPath('locations.1.top_channel', 'whatsapp')
            ->assertJsonPath('locations.1.performance.band', 'strong')
            ->assertJsonPath('ungeocoded.0', 'Mombasa')
            ->assertJsonPath('totals.located_client_count', 2)
            ->assertJsonPath('totals.mapped_client_count', 1)
            ->assertJsonPath('totals.views', 100);
    }

    public function test_canonical_key_merges_case_and_whitespace(): void
    {
        $platform = $this->makePlatform();

        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 101]);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'nairobi', 'wp_post_id' => 102]);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => ' Nairobi ', 'wp_post_id' => 103]);

        Http::fake([
            $this->bulkPattern($platform) => Http::response([
                'page' => 1,
                'total_pages' => 1,
                'market_averages' => ['views' => 20, 'contact_rate' => 10],
                'profiles' => [
                    ['wp_post_id' => 101, 'views' => 10, 'unique' => 6, 'contact_rate' => 10, 'contacts' => ['phone' => 1, 'whatsapp' => 0, 'viber' => 0]],
                    ['wp_post_id' => 102, 'views' => 10, 'unique' => 5, 'contact_rate' => 20, 'contacts' => ['phone' => 0, 'whatsapp' => 1, 'viber' => 0]],
                    ['wp_post_id' => 103, 'views' => 10, 'unique' => 5, 'contact_rate' => 10, 'contacts' => ['phone' => 0, 'whatsapp' => 0, 'viber' => 0]],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeUser('marketing', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.canonical_key', 'nairobi')
            ->assertJsonPath('locations.0.client_count', 3);
    }

    public function test_degrades_when_wordpress_unavailable(): void
    {
        $platform = $this->makePlatform();

        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 101]);

        Http::fake([
            $this->bulkPattern($platform) => Http::response(['message' => 'boom'], 500),
        ]);

        Sanctum::actingAs($this->makeUser('marketing', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('analytics_status', 'unavailable')
            ->assertJsonPath('locations.0.engagement', null)
            ->assertJsonPath('locations.0.performance.band', 'unavailable')
            ->assertJsonPath('totals.located_client_count', 1)
            ->assertJsonPath('totals.views', null);
    }

    public function test_partial_when_old_wp_payload(): void
    {
        $platform = $this->makePlatform();

        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 101]);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 102]);

        Http::fake([
            $this->bulkPattern($platform) => Http::response([
                'page' => 1,
                'total_pages' => 1,
                'market_averages' => ['views' => 25, 'contact_rate' => 14.5],
                'profiles' => [
                    ['wp_post_id' => 101, 'views' => 40, 'contact_rate' => 10, 'engagement' => 70],
                    ['wp_post_id' => 102, 'views' => 20, 'contact_rate' => 30, 'engagement' => 50],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeUser('marketing', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('analytics_status', 'partial')
            ->assertJsonPath('locations.0.channels', null)
            ->assertJsonPath('locations.0.top_channel', null)
            ->assertJsonPath('locations.0.engagement.profile_unique_visits', null)
            ->assertJsonPath('locations.0.engagement.views', 60)
            ->assertJsonPath('locations.0.engagement.contact_rate', 16.67);

        $this->assertNotNull($response->json('locations.0.performance.index'));
    }

    public function test_paginates_beyond_500_profiles(): void
    {
        $platform = $this->makePlatform();

        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 101]);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 102]);

        Http::fake(function (ClientRequest $request) use ($platform) {
            if (!str_starts_with($request->url(), $this->bulkBaseUrl($platform))) {
                return Http::response([], 404);
            }

            $page = (int) $request['page'];

            return Http::response([
                'page' => $page,
                'total_pages' => 2,
                'market_averages' => ['views' => 20, 'contact_rate' => 12],
                'profiles' => $page === 1
                    ? [['wp_post_id' => 101, 'views' => 10, 'unique' => 5, 'contact_rate' => 10, 'contacts' => ['phone' => 1, 'whatsapp' => 0, 'viber' => 0]]]
                    : [['wp_post_id' => 102, 'views' => 30, 'unique' => 15, 'contact_rate' => 20, 'contacts' => ['phone' => 0, 'whatsapp' => 2, 'viber' => 0]]],
            ], 200);
        });

        Sanctum::actingAs($this->makeUser('marketing', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('locations.0.published_count', 2)
            ->assertJsonPath('locations.0.engagement.views', 40);
    }

    public function test_top_channel_null_on_zero_and_tie(): void
    {
        $platform = $this->makePlatform();

        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 101]);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Mombasa', 'wp_post_id' => 202]);

        Http::fake([
            $this->bulkPattern($platform) => Http::response([
                'page' => 1,
                'total_pages' => 1,
                'market_averages' => ['views' => 10, 'contact_rate' => 5],
                'profiles' => [
                    ['wp_post_id' => 101, 'views' => 10, 'unique' => 4, 'contact_rate' => 0, 'contacts' => ['phone' => 0, 'whatsapp' => 0, 'viber' => 0]],
                    ['wp_post_id' => 202, 'views' => 10, 'unique' => 4, 'contact_rate' => 20, 'contacts' => ['phone' => 1, 'whatsapp' => 1, 'viber' => 0]],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeUser('marketing', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('locations.1.top_channel', null)
            ->assertJsonPath('locations.0.top_channel', null);
    }

    public function test_skips_missing_wp_post_id(): void
    {
        $platform = $this->makePlatform();

        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 101]);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi', 'wp_post_id' => 0]);

        Http::fake([
            $this->bulkPattern($platform) => Http::response([
                'page' => 1,
                'total_pages' => 1,
                'market_averages' => ['views' => 10, 'contact_rate' => 10],
                'profiles' => [
                    ['wp_post_id' => 101, 'views' => 10, 'unique' => 4, 'contact_rate' => 10, 'contacts' => ['phone' => 1, 'whatsapp' => 0, 'viber' => 0]],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeUser('marketing', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('locations.0.client_count', 2)
            ->assertJsonPath('locations.0.published_count', 1)
            ->assertJsonPath('locations.0.engagement.views', 10);
    }

    public function test_authorization_and_single_market_fallback(): void
    {
        $platformA = $this->makePlatform('Kenya');
        $platformB = $this->makePlatform('Uganda');

        Client::factory()->create(['platform_id' => $platformA->id, 'city' => 'Nairobi', 'wp_post_id' => 101]);

        Http::fake([
            $this->bulkPattern($platformA) => Http::response([
                'page' => 1,
                'total_pages' => 1,
                'market_averages' => ['views' => 10, 'contact_rate' => 10],
                'profiles' => [
                    ['wp_post_id' => 101, 'views' => 10, 'unique' => 4, 'contact_rate' => 10, 'contacts' => ['phone' => 1, 'whatsapp' => 0, 'viber' => 0]],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeUser('marketing', [$platformA->id]));

        $fallbackResponse = $this->getJson('/api/crm/clients/locations');
        $fallbackResponse->assertOk()->assertJsonPath('platform_id', $platformA->id);

        $blockedResponse = $this->getJson("/api/crm/clients/locations?platform_id={$platformB->id}");
        $blockedResponse->assertForbidden();
    }

    public function test_route_resolves_before_client_binding(): void
    {
        $platform = $this->makePlatform();
        Sanctum::actingAs($this->makeUser('marketing', [$platform->id]));
        Http::fake();

        $response = $this->getJson("/api/crm/clients/locations?platform_id={$platform->id}");

        $response->assertStatus(200);
        $this->assertIsArray($response->json('locations'));
    }

    private function makePlatform(string $country = 'Kenya'): Platform
    {
        return Platform::factory()->create([
            'country' => $country,
            'wp_api_url' => 'https://' . strtolower($country) . '.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function makeUser(string $role, array $assignedMarketIds): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }

    private function bulkPattern(Platform $platform): string
    {
        return rtrim((string) $platform->wp_api_url, '/') . '/analytics/bulk*';
    }

    private function bulkBaseUrl(Platform $platform): string
    {
        return rtrim((string) $platform->wp_api_url, '/') . '/analytics/bulk';
    }
}
