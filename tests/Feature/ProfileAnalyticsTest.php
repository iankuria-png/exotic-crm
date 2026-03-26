<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_analytics_requires_auth(): void
    {
        $platform = $this->createPlatform('Kenya');
        $client = $this->createClient($platform);

        $response = $this->getJson("/api/crm/clients/{$client->id}/analytics");

        $response->assertUnauthorized();
    }

    public function test_profile_analytics_returns_data(): void
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('sales', [$platform->id]);
        $client = $this->createClient($platform, ['wp_post_id' => 16835]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            $baseUrl . '/analytics/16835*' => Http::response([
                'post_id' => 16835,
                'period' => ['from' => '2026-02-25', 'to' => '2026-03-26'],
                'totals' => [
                    'profile_view' => ['total' => 326, 'unique' => 248],
                    'whatsapp_click' => ['total' => 22, 'unique' => 20],
                ],
                'contact_actions_total' => 43,
                'contact_rate_percent' => 13.2,
                'avg_session_duration_sec' => 71,
                'placement_breakdown' => [],
                'daily' => [],
                'market_averages' => [
                    'views_per_profile' => 145,
                    'contacts_per_profile' => 18,
                    'contact_rate_percent' => 10.6,
                ],
            ]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/clients/{$client->id}/analytics?from=2026-02-25&to=2026-03-26");

        $response->assertOk()
            ->assertJsonPath('post_id', 16835)
            ->assertJsonPath('contact_actions_total', 43)
            ->assertJsonPath('contact_rate_percent', 13.2);

        Http::assertSent(function (ClientRequest $request) use ($baseUrl) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), $baseUrl . '/analytics/16835')
                && $request['from'] === '2026-02-25'
                && $request['to'] === '2026-03-26';
        });
    }

    public function test_profile_engagement_accessible_by_marketing(): void
    {
        $platform = $this->createPlatform('Kenya');
        $marketing = $this->createUser('marketing', [$platform->id]);
        $client = $this->createClient($platform, ['wp_post_id' => 16835]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            $baseUrl . '/analytics/rankings*' => Http::response($this->fakeRankingsPayload($client)),
        ]);

        Sanctum::actingAs($marketing);

        $response = $this->getJson("/api/crm/reports/profile-engagement?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('filters.platform_id', $platform->id)
            ->assertJsonPath('profiles.0.crm_client_id', $client->id);
    }

    public function test_profile_engagement_threads_platform_filter(): void
    {
        $platformA = $this->createPlatform('Kenya');
        $platformB = $this->createPlatform('Uganda');
        $user = $this->createUser('marketing', [$platformA->id, $platformB->id]);
        $client = $this->createClient($platformA, ['wp_post_id' => 16835]);
        $baseUrlA = rtrim((string) $platformA->wp_api_url, '/');
        $baseUrlB = rtrim((string) $platformB->wp_api_url, '/');

        Http::fake([
            $baseUrlA . '/analytics/rankings*' => Http::response($this->fakeRankingsPayload($client)),
            $baseUrlB . '/analytics/rankings*' => Http::response(['profiles' => []]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/reports/profile-engagement?platform_id={$platformA->id}&page=2&per_page=5&sort_by=contact_rate&order=asc&from=2026-02-25&to=2026-03-26");

        $response->assertOk()
            ->assertJsonPath('filters.platform_id', $platformA->id)
            ->assertJsonPath('profiles.0.crm_client_id', $client->id)
            ->assertJsonPath('profiles.0.subscription_tier', 'Premium');

        Http::assertSent(function (ClientRequest $request) use ($baseUrlA) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), $baseUrlA . '/analytics/rankings')
                && $request['page'] === '2'
                && $request['per_page'] === '5'
                && $request['sort_by'] === 'contact_rate'
                && $request['order'] === 'asc'
                && $request['from'] === '2026-02-25'
                && $request['to'] === '2026-03-26';
        });

        Http::assertNotSent(function (ClientRequest $request) use ($baseUrlB) {
            return str_starts_with($request->url(), $baseUrlB . '/analytics/rankings');
        });
    }

    private function fakeRankingsPayload(Client $client): array
    {
        return [
            'period' => ['from' => '2026-02-25', 'to' => '2026-03-26'],
            'compare_period' => ['from' => '2026-01-25', 'to' => '2026-02-24'],
            'page' => 1,
            'per_page' => 20,
            'total_profiles' => 1,
            'total_pages' => 1,
            'platform_totals' => [
                'profile_view' => ['total' => 326, 'unique' => 248, 'delta_total_percent' => 12.0, 'delta_unique_percent' => 8.0],
                'contact_actions' => ['total' => 43, 'unique' => 31, 'delta_total_percent' => 18.0, 'delta_unique_percent' => 11.0],
                'contact_rate_percent' => 13.2,
                'delta_contact_rate_pp' => 1.2,
            ],
            'market_averages' => [
                'views_per_profile' => 145,
                'contacts_per_profile' => 18,
                'contact_rate_percent' => 10.6,
            ],
            'platform_contact_mix' => [
                ['event_type' => 'whatsapp_click', 'label' => 'WhatsApp', 'total' => 22, 'share_percent' => 51.2],
                ['event_type' => 'phone_click', 'label' => 'Phone', 'total' => 16, 'share_percent' => 37.2],
                ['event_type' => 'viber_click', 'label' => 'Viber', 'total' => 5, 'share_percent' => 11.6],
            ],
            'profiles' => [
                [
                    'post_id' => (int) $client->wp_post_id,
                    'name' => $client->name,
                    'status' => 'publish',
                    'totals' => [
                        'profile_view' => ['total' => 326, 'unique' => 248],
                        'phone_click' => ['total' => 16, 'unique' => 12],
                        'whatsapp_click' => ['total' => 22, 'unique' => 18],
                        'viber_click' => ['total' => 5, 'unique' => 4],
                    ],
                    'contact_actions_total' => 43,
                    'contact_rate_percent' => 13.2,
                    'avg_session_duration_sec' => 71,
                    'engagement_score' => 88.4,
                ],
            ],
        ];
    }

    private function createUser(string $role = 'sales', array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' ' . Str::random(6),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.test',
            'country' => $name,
            'is_active' => true,
            'wp_api_url' => 'https://' . Str::slug($name) . '.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createClient(Platform $platform, array $overrides = []): Client
    {
        $assignedAgent = $this->createUser('sales', [$platform->id]);
        $product = Product::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium',
            'display_name' => 'Premium',
            'slug' => 'premium-' . Str::random(4),
            'tier' => 'premium',
            'monthly_price' => 2500,
            'biweekly_price' => 1500,
            'weekly_price' => 900,
            'currency' => 'KES',
            'is_active' => true,
        ]);

        $client = Client::query()->create(array_merge([
            'platform_id' => $platform->id,
            'wp_post_id' => random_int(1000, 999999),
            'name' => 'Client ' . Str::random(5),
            'phone_normalized' => '2547' . random_int(10000000, 99999999),
            'profile_status' => 'publish',
            'assigned_to' => $assignedAgent->id,
            'premium' => true,
        ], $overrides));

        Deal::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'premium',
            'amount' => 2500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'active',
            'assigned_to' => $assignedAgent->id,
        ]);

        return $client->fresh();
    }
}
