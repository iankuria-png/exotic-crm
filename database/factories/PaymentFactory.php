<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'user_id' => fake()->numberBetween(1000, 999999),
            'product_id' => function (array $attributes) {
                return Product::factory()->create([
                    'platform_id' => $attributes['platform_id'],
                ])->id;
            },
            'platform_id' => Platform::factory(),
            'escort_post_id' => null,
            'deal_id' => null,
            'client_id' => null,
            'match_confidence' => null,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'phone' => '2547' . fake()->numerify('#######'),
            'amount' => fake()->randomFloat(2, 1000, 10000),
            'currency' => function (array $attributes) {
                return Platform::query()->whereKey($attributes['platform_id'])->value('currency_code') ?: 'KES';
            },
            'transaction_uuid' => (string) fake()->uuid(),
            'transaction_reference' => strtoupper(fake()->bothify('TXN-####??')),
            'reference_number' => strtoupper(fake()->bothify('REF-####??')),
            'status' => 'completed',
            'purpose' => 'subscription',
            'failure_reason' => null,
            'completed_at' => now(),
            'source' => 'gateway',
            'wallet_transaction_id' => null,
            'provider_key' => null,
            'provider_environment' => null,
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'test_reason' => null,
            'test_marked_at' => null,
            'test_marked_by' => null,
            'import_batch_id' => null,
            'import_legacy_hash' => null,
            'reconciliation_confidence' => 'low',
            'reconciliation_state' => 'open',
            'raw_payload' => ['factory' => true],
            'payment_data' => null,
            'duration' => fake()->randomElement(['weekly', 'biweekly', 'monthly']),
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ];
    }
}
