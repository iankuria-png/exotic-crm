<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientUrlSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();

        if (!Schema::hasTable('escort_live_urls')) {
            Schema::create('escort_live_urls', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id')->primary();
                $table->string('post_name')->nullable();
                $table->string('live_url')->nullable();
                $table->timestamp('last_synced')->nullable();
            });
        }
    }

    public function test_client_search_supports_exact_public_profile_url_in_selected_market(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $client = $this->createClient($ghana, [
            'wp_post_id' => 5517,
            'name' => 'Traceable Ghana Client',
        ]);

        DB::table('escort_live_urls')->insert([
            'post_id' => 5517,
            'post_name' => 'test-pm',
            'live_url' => 'https://www.exoticghana.com/escort/test-pm/',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https://www.exoticghana.com/escort/test-pm/'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_client_search_falls_back_to_slug_match_when_live_url_is_missing(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $client = $this->createClient($ghana, [
            'wp_post_id' => 7722,
            'name' => 'Venessa',
        ]);

        DB::table('escort_live_urls')->insert([
            'post_id' => 7722,
            'post_name' => 'venessa-5',
            'live_url' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https://www.exoticghana.com/escort/venessa-5/'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_client_search_supports_wordpress_query_style_profile_url(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $client = $this->createClient($ghana, [
            'wp_post_id' => 8801,
            'name' => 'Query Style Client',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https://www.exoticghana.com/?p=8801'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_client_search_resolves_exact_client_from_public_profile_shortlink_header(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $exactClient = $this->createClient($ghana, [
            'wp_post_id' => 127508,
            'name' => 'Olivia',
        ]);
        $this->createClient($ghana, [
            'wp_post_id' => 116269,
            'name' => 'Olivia',
        ]);

        Http::fake([
            'https://www.exoticghana.com/escort/olivia-7/' => Http::response('', 200, [
                'Link' => '<https://www.exoticghana.com/wp-json/>; rel="https://api.w.org/", <https://www.exoticghana.com/?p=127508>; rel=shortlink',
            ]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https://www.exoticghana.com/escort/olivia-7/'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $exactClient->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('search_resolution.mode', 'exact')
            ->assertJsonPath('search_resolution.source', 'head_shortlink')
            ->assertJsonPath('search_resolution.resolved_wp_post_id', 127508);

        Http::assertSent(fn ($request) => $request->method() === 'HEAD');
    }

    public function test_client_search_resolves_exact_client_from_public_profile_html_shortlink(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $exactClient = $this->createClient($ghana, [
            'wp_post_id' => 127509,
            'name' => 'Olivia',
        ]);
        $this->createClient($ghana, [
            'wp_post_id' => 116269,
            'name' => 'Olivia',
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'HEAD') {
                return Http::response('', 200);
            }

            return Http::response(
                '<html><head><link rel="shortlink" href="https://www.exoticghana.com/?p=127509"></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        });

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https://www.exoticghana.com/escort/olivia-8/'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $exactClient->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('search_resolution.mode', 'exact')
            ->assertJsonPath('search_resolution.source', 'html_shortlink')
            ->assertJsonPath('search_resolution.resolved_wp_post_id', 127509);
    }

    public function test_client_search_falls_back_to_slug_derived_name_match_when_wp_lookup_tables_miss(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $client = $this->createClient($ghana, [
            'wp_post_id' => 99017,
            'name' => 'Venessa',
        ]);

        Http::fake([
            'https://www.exoticghana.com/escort/venessa-5/' => Http::response('', 404),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https://www.exoticghana.com/escort/venessa-5/'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('search_resolution.mode', 'fallback')
            ->assertJsonPath('search_resolution.source', 'slug_fallback');
    }

    public function test_client_search_returns_empty_for_url_from_different_market_when_market_is_selected(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $kenya = $this->createPlatform('Kenya', 'https://www.exotickenya.com');
        $user = $this->createUser('sales', [$ghana->id, $kenya->id]);

        $this->createClient($ghana, [
            'wp_post_id' => 5517,
            'name' => 'Ghana Client',
        ]);

        $kenyaClient = $this->createClient($kenya, [
            'wp_post_id' => 6618,
            'name' => 'Kenya Client',
        ]);

        DB::table('escort_live_urls')->insert([
            'post_id' => 6618,
            'post_name' => 'test-ke',
            'live_url' => 'https://www.exotickenya.com/escort/test-ke/',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https://www.exotickenya.com/escort/test-ke/'));

        $response->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($kenyaClient->id);
    }

    public function test_client_search_without_market_filter_only_returns_url_matches_from_accessible_markets(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $kenya = $this->createPlatform('Kenya', 'https://www.exotickenya.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $ghanaClient = $this->createClient($ghana, [
            'wp_post_id' => 5517,
            'name' => 'Accessible Ghana Client',
        ]);
        $this->createClient($kenya, [
            'wp_post_id' => 6618,
            'name' => 'Out Of Scope Kenya Client',
        ]);

        DB::table('escort_live_urls')->insert([
            [
                'post_id' => 5517,
                'post_name' => 'test-gh',
                'live_url' => 'https://www.exoticghana.com/escort/test-gh/',
            ],
            [
                'post_id' => 6618,
                'post_name' => 'test-ke',
                'live_url' => 'https://www.exotickenya.com/escort/test-ke/',
            ],
        ]);

        Sanctum::actingAs($user);

        $ghanaResponse = $this->getJson('/api/crm/clients?search=' . urlencode('https://www.exoticghana.com/escort/test-gh/'));
        $kenyaResponse = $this->getJson('/api/crm/clients?search=' . urlencode('https://www.exotickenya.com/escort/test-ke/'));

        $ghanaResponse->assertOk()
            ->assertJsonPath('data.0.id', $ghanaClient->id)
            ->assertJsonCount(1, 'data');

        $kenyaResponse->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_client_search_falls_back_to_standard_search_for_malformed_url_input(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $client = $this->createClient($ghana, [
            'name' => 'https:///Client Match',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/clients?platform_id=' . $ghana->id . '&search=' . urlencode('https:///Client'));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_client_search_keeps_existing_numeric_identifier_search_behavior(): void
    {
        $ghana = $this->createPlatform('Ghana', 'https://www.exoticghana.com');
        $user = $this->createUser('sales', [$ghana->id]);
        $client = $this->createClient($ghana, [
            'wp_post_id' => 456700,
            'wp_user_id' => 76540,
            'name' => 'Traceable Client',
        ]);

        Sanctum::actingAs($user);

        $byCrmId = $this->getJson('/api/crm/clients?search=' . $client->id);
        $byWpPostId = $this->getJson('/api/crm/clients?search=456700');
        $byWpUserId = $this->getJson('/api/crm/clients?search=76540');

        $byCrmId->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data');
        $byWpPostId->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data');
        $byWpUserId->assertOk()
            ->assertJsonPath('data.0.id', $client->id)
            ->assertJsonCount(1, 'data');
    }

    private function createPlatform(string $name, string $domain): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => $domain,
            'country' => $name,
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => rtrim($domain, '/') . '/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createUser(string $role, array $platformIds): User
    {
        return User::query()->create([
            'name' => Str::title($role) . ' User',
            'email' => strtolower($role) . '-' . Str::random(6) . '@example.test',
            'password' => bcrypt('secret'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => json_encode($platformIds),
        ]);
    }

    private function createClient(Platform $platform, array $overrides = []): Client
    {
        return Client::query()->create(array_merge([
            'platform_id' => $platform->id,
            'wp_post_id' => random_int(1000, 999999),
            'name' => 'Client ' . Str::random(5),
            'phone_normalized' => '2547' . random_int(10000000, 99999999),
            'profile_status' => 'publish',
        ], $overrides));
    }
}
