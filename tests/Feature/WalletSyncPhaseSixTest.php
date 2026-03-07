<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Services\WalletSettingsService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WalletSyncPhaseSixTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_sync_service_pushes_balance_payload_and_updates_client_sync_timestamp(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
        ]);

        $platform = Platform::factory()->create([
            'wallet_settings' => [
                'enabled' => true,
            ],
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9001,
            'wp_user_id' => 7001,
            'wallet_balance' => 1500,
            'wallet_currency' => 'KES',
        ]);

        WalletTransaction::factory()->create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'type' => 'credit',
            'currency_code' => 'KES',
            'amount' => 1500,
            'balance_after' => 1500,
            'reference_type' => 'wallet_topup',
            'reference_id' => 1,
            'description' => 'Wallet top-up via MPESA',
        ]);

        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/clients/9001/wallet-balance' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = app(WalletSyncService::class)->syncClientBalance($client);

        $this->assertSame('synced', $result['status']);
        $this->assertNotNull($client->fresh()->wallet_last_synced_at);
        $this->assertSame($platform->id, $result['payload']['platform_id']);
        $this->assertSame('1500.00', $result['payload']['balance']);
        $this->assertSame('KES', $result['payload']['currency']);
        $this->assertSame('sandbox', $result['payload']['mode']);
        $this->assertIsArray($result['payload']['transactions']);

        Http::assertSent(function (Request $request) use ($platform) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . '/clients/9001/wallet-balance'
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode($platform->wp_api_user . ':' . $platform->wp_api_password));
        });
    }

    public function test_wallet_sync_service_pushes_platform_config_with_anchor_client(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
        ]);

        $platform = Platform::factory()->create([
            'wallet_settings' => [
                'enabled' => true,
                'show_refresh_button' => true,
                'providers' => [
                    'pesapal' => [
                        'enabled' => false,
                        'min_amount' => '100.00',
                        'max_amount' => '150000.00',
                    ],
                    'paystack' => [
                        'enabled' => true,
                        'min_amount' => '100.00',
                        'max_amount' => '500000.00',
                    ],
                    'mpesa_stk' => [
                        'enabled' => true,
                        'min_amount' => '100.00',
                        'max_amount' => '150000.00',
                    ],
                ],
            ],
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9002,
            'wp_user_id' => 7002,
        ]);

        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/clients/9002/wallet-config' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = app(WalletSyncService::class)->syncPlatformConfig($platform, $client);

        $this->assertSame('synced', $result['status']);

        Http::assertSent(function (Request $request) use ($platform) {
            $config = $request['config'] ?? [];

            return $request->url() === rtrim($platform->wp_api_url, '/') . '/clients/9002/wallet-config'
                && $request['platform_id'] === $platform->id
                && $request['mode'] === 'sandbox'
                && ($config['market']['platform_id'] ?? null) === $platform->id
                && ($config['show_refresh_button'] ?? null) === true
                && isset($config['providers']['paystack']);
        });
    }

    public function test_wallet_service_credit_syncs_balance_after_commit(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
        ]);

        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9003,
            'wp_user_id' => 7003,
            'wallet_balance' => 0,
            'wallet_currency' => 'KES',
        ]);

        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/clients/9003/wallet-balance' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = app(WalletService::class)->credit($client, 500, [
            'reference_type' => 'wallet_topup',
            'reference_id' => 101,
            'description' => 'QA wallet credit',
            'idempotency_key' => 'wallet-sync-credit-001',
        ]);

        $this->assertFalse($result['replayed']);
        $this->assertSame('500.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
        $this->assertNotNull($client->fresh()->wallet_last_synced_at);

        Http::assertSent(function (Request $request) use ($platform) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . '/clients/9003/wallet-balance'
                && $request['balance'] === '500.00';
        });
    }

    public function test_wallet_sync_service_logs_failures_without_throwing(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9004,
            'wp_user_id' => 7004,
            'wallet_balance' => 250,
            'wallet_currency' => 'KES',
        ]);

        Log::spy();
        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/clients/9004/wallet-balance' => Http::response([
                'message' => 'Nope',
            ], 500),
        ]);

        $result = app(WalletSyncService::class)->syncClientBalance($client);

        $this->assertSame('failed', $result['status']);
        $this->assertNull($client->fresh()->wallet_last_synced_at);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($client, $platform) {
                return $message === 'Wallet balance sync to WordPress failed'
                    && $context['platform_id'] === $platform->id
                    && $context['client_id'] === $client->id
                    && $context['wp_post_id'] === (int) $client->wp_post_id;
            });
    }
}
