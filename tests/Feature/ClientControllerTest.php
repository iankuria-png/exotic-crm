<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_payload_contains_short_url_permalink_slug_and_canonical_expiry_context(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 6, 12, 0, 0, 'Africa/Nairobi'));

        try {
            $platform = Platform::factory()->create([
                'name' => 'Kenya',
                'domain' => 'kenya.example.test',
                'country' => 'Kenya',
                'phone_prefix' => '254',
                'currency_code' => 'KES',
                'timezone' => 'Africa/Nairobi',
                'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            ]);
            $product = Product::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'VIP Profile',
                'display_name' => 'VIP Profile',
                'slug' => 'vip-profile',
                'tier' => 'vip',
            ]);
            $client = Client::factory()->create([
                'platform_id' => $platform->id,
                'wp_post_id' => 10026,
                'wp_user_id' => 34647,
                'wp_profile_permalink' => 'https://kenya.example.test/escort/faithvideossquirtingnudes/',
                'wp_profile_slug' => 'faithvideossquirtingnudes',
                'escort_expire' => now()->addDays(20)->timestamp,
            ]);
            $dealExpiry = now()->addDays(7)->startOfSecond();

            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $client->id,
                'product_id' => $product->id,
                'plan_type' => 'vip',
                'status' => 'active',
                'expires_at' => $dealExpiry,
            ]);

            Sanctum::actingAs(User::factory()->create([
                'role' => 'admin',
                'status' => 'active',
                'assigned_market_ids' => [],
            ]));

            $response = $this->getJson("/api/crm/clients/{$client->id}");

            $response->assertOk()
                ->assertJsonPath('wp_profile_url', 'https://kenya.example.test/?p=10026')
                ->assertJsonPath('wp_profile_permalink', 'https://kenya.example.test/escort/faithvideossquirtingnudes/')
                ->assertJsonPath('wp_profile_slug', 'faithvideossquirtingnudes');

            $this->assertNotEmpty($response->json('active_deal.expires_at'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
