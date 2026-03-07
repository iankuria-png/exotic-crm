<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => fake()->randomFloat(2, 500, 10000),
            'currency' => function (array $attributes) {
                return Product::query()->whereKey($attributes['product_id'])->value('currency') ?: 'KES';
            },
            'is_active' => true,
            'sort_order' => 30,
        ];
    }
}
