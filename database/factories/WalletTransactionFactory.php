<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Platform;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'platform_id' => function (array $attributes) {
                return Client::query()->whereKey($attributes['client_id'])->value('platform_id')
                    ?: Platform::factory()->create()->id;
            },
            'type' => 'credit',
            'currency_code' => function (array $attributes) {
                return Client::query()->whereKey($attributes['client_id'])->value('wallet_currency')
                    ?: Platform::query()->whereKey($attributes['platform_id'])->value('currency_code')
                    ?: 'KES';
            },
            'amount' => 1000,
            'balance_after' => 1000,
            'reference_type' => null,
            'reference_id' => null,
            'payment_id' => null,
            'deal_id' => null,
            'idempotency_key' => (string) fake()->uuid(),
            'description' => 'Factory wallet transaction',
            'performed_by' => null,
            'metadata' => ['factory' => true],
            'notification_channel' => null,
            'notification_sent_at' => null,
            'wp_synced_at' => null,
            'wp_sync_attempts' => 0,
        ];
    }
}
