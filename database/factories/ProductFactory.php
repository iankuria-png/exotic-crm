<?php

namespace Database\Factories;

use App\Models\Platform;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Basic', 'Premium', 'VIP']) . ' ' . fake()->unique()->numerify('###');

        return [
            'platform_id' => Platform::factory(),
            'name' => $name,
            'display_name' => $name,
            'slug' => Str::slug($name),
            'tier' => 'custom',
            'weekly_price' => fake()->randomFloat(2, 500, 2500),
            'biweekly_price' => fake()->randomFloat(2, 1000, 5000),
            'monthly_price' => fake()->randomFloat(2, 2000, 10000),
            'currency' => function (array $attributes) {
                return Platform::query()->whereKey($attributes['platform_id'])->value('currency_code') ?: 'KES';
            },
            'is_active' => true,
            'is_public' => true,
            'is_archived' => false,
            'sort_order' => 0,
        ];
    }
}
