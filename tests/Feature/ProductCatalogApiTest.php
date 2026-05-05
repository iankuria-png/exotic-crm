<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_endpoint_returns_only_active_products_for_the_requested_platform(): void
    {
        $ghana = Platform::factory()->create([
            'name' => 'Ghana',
            'country' => 'Ghana',
            'currency_code' => 'GHS',
        ]);

        $kenya = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
        ]);

        $ghanaProducts = collect([
            $this->createProduct($ghana, 'VIP', 'Vip', 'vip', 10),
            $this->createProduct($ghana, 'PREMIUM', 'Premium', 'premium', 20),
            $this->createProduct($ghana, 'VVIP', 'Vvip', 'vvip', 30),
        ]);

        foreach ($ghanaProducts as $index => $product) {
            ProductPrice::factory()->create([
                'product_id' => $product->id,
                'duration_key' => '1_month',
                'duration_label' => '1 Month',
                'duration_days' => 30,
                'price' => 1000 + ($index * 200),
                'currency' => 'GHS',
                'is_active' => true,
                'sort_order' => 10,
            ]);
        }

        $crmOnlyGhana = $this->createProduct($ghana, 'VIP 2 DAY', 'Vip 2 Day', 'vip_2_day', 35, true, false);

        ProductPrice::factory()->create([
            'product_id' => $crmOnlyGhana->id,
            'duration_key' => '2_days',
            'duration_label' => '2 Days',
            'duration_days' => 2,
            'price' => 750,
            'currency' => 'GHS',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $inactiveGhana = $this->createProduct($ghana, 'LEGACY', 'Legacy', 'legacy', 40, false);

        ProductPrice::factory()->create([
            'product_id' => $inactiveGhana->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 500,
            'currency' => 'GHS',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $kenyaProduct = $this->createProduct($kenya, 'VIP KENYA', 'Vip Kenya', 'vip_kenya', 10);

        ProductPrice::factory()->create([
            'product_id' => $kenyaProduct->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 6000,
            'currency' => 'KES',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this->getJson('/api/products?platform_id=' . $ghana->id);

        $response->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonCount(3, 'products');

        $productIds = collect($response->json('products'))
            ->pluck('id')
            ->all();

        $this->assertSame($ghanaProducts->pluck('id')->all(), $productIds);
        $this->assertNotContains($crmOnlyGhana->id, $productIds);
        $this->assertSame(
            [$ghana->id, $ghana->id, $ghana->id],
            collect($response->json('products'))->pluck('platform_id')->all()
        );
        $this->assertSame(
            [1, 1, 1],
            collect($response->json('products'))->map(fn(array $product) => count($product['active_prices'] ?? []))->all()
        );
    }

    public function test_crm_products_endpoint_includes_crm_only_packages_for_authorized_users(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Rwanda',
            'country' => 'Rwanda',
            'currency_code' => 'RWF',
        ]);

        $publicProduct = $this->createProduct($platform, 'VIP', 'Vip', 'vip', 10);
        $crmOnlyProduct = $this->createProduct($platform, 'VIP 2 DAY', 'Vip 2 Day', 'vip_2_day', 20, true, false);

        foreach ([$publicProduct, $crmOnlyProduct] as $product) {
            ProductPrice::factory()->create([
                'product_id' => $product->id,
                'duration_key' => $product->is_public ? '1_month' : '2_days',
                'duration_label' => $product->is_public ? '1 Month' : '2 Days',
                'duration_days' => $product->is_public ? 30 : 2,
                'price' => $product->is_public ? 40500 : 9000,
                'currency' => 'RWF',
                'is_active' => true,
                'sort_order' => 10,
            ]);
        }

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        $response = $this->getJson('/api/crm/products?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonCount(2);

        $this->assertSame(
            [$publicProduct->id, $crmOnlyProduct->id],
            collect($response->json())->pluck('id')->all()
        );
    }

    public function test_public_self_checkout_rejects_crm_only_packages_by_id(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Uganda',
            'country' => 'Uganda',
            'currency_code' => 'UGX',
        ]);

        $crmOnlyProduct = $this->createProduct($platform, 'VIP 2 DAY', 'Vip 2 Day', 'vip_2_day', 10, true, false);
        $price = ProductPrice::factory()->create([
            'product_id' => $crmOnlyProduct->id,
            'duration_key' => '2_days',
            'duration_label' => '2 Days',
            'duration_days' => 2,
            'price' => 50000,
            'currency' => 'UGX',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this->postJson('/api/self-checkout', [
            'product_id' => $crmOnlyProduct->id,
            'product_price_id' => $price->id,
            'platform_id' => $platform->id,
            'user_id' => 12345,
            'first_name' => 'Jane',
            'last_name' => 'Client',
            'phone' => '0700000000',
            'duration' => '2_days',
            'currency' => 'UGX',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The selected package is not currently available.');
    }

    public function test_settings_package_catalog_saves_crm_only_custom_duration_package(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Senegal',
            'country' => 'Senegal',
            'currency_code' => 'XOF',
            'is_active' => false,
        ]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        $response = $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}/packages", [
            'reason' => 'Create CRM only two day VIP package',
            'packages' => [
                [
                    'name' => 'VIP 2 DAY',
                    'display_name' => 'Vip 2 Day',
                    'tier' => 'vip',
                    'sort_order' => 10,
                    'is_active' => true,
                    'is_public' => false,
                    'prices' => [
                        [
                            'duration_key' => '2_days',
                            'duration_label' => '2 Days',
                            'duration_days' => 2,
                            'price' => 25000,
                            'currency' => 'XOF',
                            'is_active' => true,
                            'sort_order' => 10,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('platform.packages.0.is_public', false)
            ->assertJsonPath('platform.packages.0.prices.0.duration_days', 2);

        $product = Product::query()->where('platform_id', $platform->id)->where('name', 'VIP 2 DAY')->firstOrFail();

        $this->assertFalse((bool) $product->is_public);
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id,
            'duration_key' => '2_days',
            'duration_days' => 2,
            'price' => 25000,
            'currency' => 'XOF',
            'is_active' => true,
        ]);
    }

    private function createProduct(
        Platform $platform,
        string $name,
        string $displayName,
        string $slug,
        int $sortOrder,
        bool $isActive = true,
        bool $isPublic = true
    ): Product {
        return Product::query()->create([
            'platform_id' => $platform->id,
            'name' => $name,
            'display_name' => $displayName,
            'slug' => $slug,
            'tier' => strtolower($slug),
            'weekly_price' => 250,
            'biweekly_price' => 500,
            'monthly_price' => 1000,
            'currency' => $platform->currency_code,
            'is_active' => $isActive,
            'is_public' => $isPublic,
            'is_archived' => false,
            'sort_order' => $sortOrder,
        ]);
    }
}
