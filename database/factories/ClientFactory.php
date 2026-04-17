<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'wp_post_id' => fake()->unique()->numberBetween(1000, 999999),
            'wp_user_id' => fake()->unique()->numberBetween(1000, 999999),
            'client_type' => 'escort',
            'name' => fake()->name(),
            'phone_normalized' => '2547' . fake()->unique()->numerify('#######'),
            'email' => fake()->safeEmail(),
            'city' => fake()->city(),
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'is_high_risk' => false,
            'risk_reason_code' => null,
            'risk_marked_at' => null,
            'risk_marked_by' => null,
            'premium' => false,
            'premium_expire' => null,
            'featured' => false,
            'featured_expire' => null,
            'escort_expire' => null,
            'verified' => false,
            'main_image_url' => fake()->imageUrl(640, 800),
            'wallet_balance' => 0,
            'wallet_currency' => function (array $attributes) {
                return Platform::query()->whereKey($attributes['platform_id'])->value('currency_code') ?: 'KES';
            },
            'wallet_last_synced_at' => null,
            'assigned_to' => null,
            'duplicate_of' => null,
            'last_online_at' => null,
            'last_synced_at' => now(),
        ];
    }
}
