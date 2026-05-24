<?php

namespace Tests\Feature\CRM;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesCustomPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_one_off_custom_deal(): void
    {
        [$platform, $product, $price, $client, $user] = $this->catalogFixture();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/deals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'base_product_price_id' => $price->id,
            'custom_amount' => 1000,
            'custom_duration_days' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount', '1000.00')
            ->assertJsonPath('duration', 'manual')
            ->assertJsonPath('duration_days', 5)
            ->assertJsonPath('product_price_id', null)
            ->assertJsonPath('base_product_price_id', $price->id);

        $deal = Deal::query()->firstOrFail();

        $this->assertSame($platform->id, $deal->platform_id);
        $this->assertSame($product->id, $deal->product_id);
        $this->assertNull($deal->original_amount);
        $this->assertNull($deal->discount_source);
    }

    public function test_it_activates_a_custom_deal_with_preserved_amount_and_duration(): void
    {
        [$platform, $product, $price, $client, $user] = $this->catalogFixture();
        $this->fakeProvisioningApis($platform, $client);
        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/crm/deals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'base_product_price_id' => $price->id,
            'custom_amount' => 1000,
            'custom_duration_days' => 5,
        ])->assertCreated();

        $dealId = $createResponse->json('id');

        $response = $this->postJson("/api/crm/deals/{$dealId}/activate", [
            'payment_method' => 'manual',
            'payment_reference' => 'ABC-CUSTOM-5D',
        ]);

	        $response->assertOk()
	            ->assertJsonPath('amount', '1000.00')
	            ->assertJsonPath('duration_days', 5);

        $deal = Deal::query()->findOrFail($dealId);

        $this->assertSame(1000.0, (float) $deal->amount);
        $this->assertSame(5, (int) $deal->duration_days);
        $this->assertSame(1000.0, (float) $deal->payment->amount);
        $this->assertNotNull($deal->activated_at);
        $this->assertTrue($deal->expires_at->isSameDay($deal->activated_at->copy()->addDays(5)));
    }

    public function test_it_creates_and_saves_a_new_sales_package(): void
    {
        [$platform, $product, $price, $client, $user] = $this->catalogFixture();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/deals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'base_product_price_id' => $price->id,
            'custom_amount' => 800,
            'custom_duration_days' => 3,
            'save_as_package' => true,
            'new_package_name' => 'Premium Mini 3d',
        ]);

        $response->assertCreated();

        $salesProduct = Product::query()
            ->where('platform_id', $platform->id)
            ->where('name', 'PREMIUM MINI 3D')
            ->firstOrFail();

        $this->assertSame('sales', $salesProduct->origin);
        $this->assertFalse((bool) $salesProduct->is_public);
        $this->assertSame($user->id, (int) $salesProduct->created_by_user_id);

        $this->assertDatabaseHas('product_prices', [
            'product_id' => $salesProduct->id,
            'duration_days' => 3,
            'price' => 800,
            'currency' => 'KES',
            'is_active' => true,
        ]);

        $deal = Deal::query()->latest('id')->firstOrFail();

        $this->assertSame($salesProduct->id, $deal->product_id);
        $this->assertSame($salesProduct->prices()->firstOrFail()->id, $deal->product_price_id);
    }

    public function test_it_rejects_duplicate_sales_package_name_even_when_archived(): void
    {
        [$platform, $product, $price, $client, $user] = $this->catalogFixture();
        Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'PREMIUM MINI 3D',
            'display_name' => 'Premium Mini 3d',
            'slug' => 'premium_mini_3d',
            'is_archived' => true,
            'is_active' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/deals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'base_product_price_id' => $price->id,
            'custom_amount' => 800,
            'custom_duration_days' => 3,
            'save_as_package' => true,
            'new_package_name' => 'Premium Mini 3d',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_package_name']);
    }

    public function test_it_requires_base_product_price_id_on_multi_currency_platform(): void
    {
        [$platform, $product, , $client, $user] = $this->catalogFixture([
            'supported_currencies' => ['KES', 'USD'],
            'multi_currency_wallet_enabled' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/deals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'custom_amount' => 1000,
            'custom_duration_days' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['base_product_price_id']);
    }

    public function test_it_keeps_sales_package_out_of_public_catalog(): void
    {
        [$platform, $product, $price, $client, $user] = $this->catalogFixture();
        Sanctum::actingAs($user);

        $this->postJson('/api/crm/deals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'base_product_price_id' => $price->id,
            'custom_amount' => 800,
            'custom_duration_days' => 3,
            'save_as_package' => true,
            'new_package_name' => 'Premium Mini 3d',
        ])->assertCreated();

        $publicResponse = $this->getJson('/api/products?platform_id=' . $platform->id);
        $publicResponse->assertOk();
        $this->assertFalse(collect($publicResponse->json('products'))->contains('name', 'PREMIUM MINI 3D'));

        $crmResponse = $this->getJson('/api/crm/products?platform_id=' . $platform->id);
        $crmResponse->assertOk();
        $this->assertTrue(collect($crmResponse->json())->contains('name', 'PREMIUM MINI 3D'));
    }

    public function test_it_preserves_sales_custom_pricing_when_activator_applies_no_discount(): void
    {
        [$platform, $product, $price, $client, $user] = $this->catalogFixture();
        $this->fakeProvisioningApis($platform, $client);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_price_id' => null,
            'base_product_price_id' => $price->id,
            'plan_type' => 'premium',
            'amount' => 1000,
            'currency' => 'KES',
            'duration' => 'manual',
            'duration_days' => 5,
            'status' => 'pending',
            'original_amount' => 1500,
            'discount_source' => 'sales_custom',
            'assigned_to' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'payment_method' => 'manual',
            'payment_reference' => 'ABC-SALES-CUSTOM',
        ])->assertOk();

        $deal->refresh();

        $this->assertSame(1000.0, (float) $deal->amount);
        $this->assertSame(1500.0, (float) $deal->original_amount);
        $this->assertSame('sales_custom', $deal->discount_source);
    }

    private function catalogFixture(array $platformOverrides = []): array
    {
        $platform = Platform::factory()->create(array_merge([
            'name' => 'Sales Custom Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://market.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ], $platformOverrides));
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'PREMIUM',
            'display_name' => 'Premium',
            'slug' => 'premium',
            'tier' => 'premium',
            'weekly_price' => 1500,
            'biweekly_price' => 2500,
            'monthly_price' => 4500,
            'currency' => 'KES',
            'is_active' => true,
            'is_public' => true,
            'is_archived' => false,
        ]);
        $price = ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_week',
            'duration_label' => '1 Week',
            'duration_days' => 7,
            'price' => 1500,
            'currency' => 'KES',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9001,
            'wp_user_id' => 19001,
            'phone_normalized' => '254700009001',
            'profile_status' => 'private',
        ]);
        $user = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        return [$platform, $product, $price, $client, $user];
    }

    private function fakeProvisioningApis(Platform $platform, Client $client): void
    {
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$baseUrl}/clients/{$client->wp_post_id}/activate" => Http::response([
                'success' => true,
                'crm_deal_id' => null,
            ], 200),
            "{$baseUrl}/clients/{$client->wp_post_id}" => Http::response([
                'wp_post_id' => (int) $client->wp_post_id,
                'wp_user_id' => (int) $client->wp_user_id,
                'name' => (string) $client->name,
                'phone' => (string) $client->phone_normalized,
                'email' => (string) $client->email,
                'city' => (string) $client->city,
                'post_status' => 'publish',
                'premium' => true,
                'featured' => false,
                'verified' => false,
                'premium_expire' => now()->addDays(5)->timestamp,
                'featured_expire' => null,
                'escort_expire' => now()->addDays(5)->timestamp,
                'last_online' => null,
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }
}
