<?php

namespace Database\Factories;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Platform>
 */
class PlatformFactory extends Factory
{
    protected $model = Platform::class;

    public function definition(): array
    {
        $name = fake()->unique()->city() . ' Market';
        $slug = Str::slug($name) . '-' . fake()->unique()->lexify('????');

        return [
            'product_id' => null,
            'name' => $name,
            'domain' => $slug . '.test',
            'country' => fake()->country(),
            'is_active' => true,
            'db_host' => '127.0.0.1',
            'db_name' => 'wp_' . fake()->lexify('market????'),
            'db_user' => 'root',
            'db_pass' => 'secret',
            'db_prefix' => 'wp_',
            'wp_api_url' => 'https://' . $slug . '.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
            'phone_prefix' => '254',
            'timezone' => 'Africa/Nairobi',
            'currency_code' => 'KES',
            'sync_last_checked_at' => null,
            'sync_last_synced_at' => null,
            'sync_last_scope' => null,
            'sync_last_status' => 'unknown',
            'sync_last_error' => null,
            'sync_last_result' => null,
            'payment_link_providers' => null,
            'support_chat_url' => null,
            'wallet_settings' => null,
        ];
    }
}
