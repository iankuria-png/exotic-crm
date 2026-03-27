<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(
            [$ghana->id, $ghana->id, $ghana->id],
            collect($response->json('products'))->pluck('platform_id')->all()
        );
        $this->assertSame(
            [1, 1, 1],
            collect($response->json('products'))->map(fn(array $product) => count($product['active_prices'] ?? []))->all()
        );
    }

    private function createProduct(
        Platform $platform,
        string $name,
        string $displayName,
        string $slug,
        int $sortOrder,
        bool $isActive = true
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
            'is_archived' => false,
            'sort_order' => $sortOrder,
        ]);
    }
}
