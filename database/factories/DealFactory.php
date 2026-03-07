<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'client_id' => function (array $attributes) {
                return Client::factory()->create([
                    'platform_id' => $attributes['platform_id'],
                ])->id;
            },
            'lead_id' => null,
            'payment_id' => null,
            'product_id' => function (array $attributes) {
                return Product::factory()->create([
                    'platform_id' => $attributes['platform_id'],
                ])->id;
            },
            'plan_type' => fake()->randomElement(['basic', 'premium', 'vip']),
            'amount' => fake()->randomFloat(2, 1000, 10000),
            'currency' => function (array $attributes) {
                return Platform::query()->whereKey($attributes['platform_id'])->value('currency_code') ?: 'KES';
            },
            'duration' => fake()->randomElement(['weekly', 'biweekly', 'monthly']),
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addMonth(),
            'assigned_to' => null,
            'is_free_trial' => false,
            'free_trial_approved_by' => null,
            'payment_reference' => null,
            'renewal_reminders_paused' => false,
            'renewal_paused_until' => null,
            'renewal_pause_reason' => null,
            'origin' => 'manual',
        ];
    }
}
