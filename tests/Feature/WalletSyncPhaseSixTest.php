<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use App\Services\WalletSettingsService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
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
                && isset($config['providers']['paystack'])
                && !isset($config['providers']['mpesa_stk']);
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

    public function test_wallet_sync_service_prefers_market_wallet_currency_over_stale_client_currency(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'production',
        ]);

        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
            'wallet_settings' => [
                'enabled' => true,
                'currency_code' => 'GHS',
            ],
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9010,
            'wp_user_id' => 7010,
            'wallet_balance' => 0,
            'wallet_currency' => 'KES',
        ]);

        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/clients/9010/wallet-balance' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = app(WalletSyncService::class)->syncClientBalance($client);

        $this->assertSame('synced', $result['status']);
        $this->assertSame('GHS', $result['payload']['currency']);
        $this->assertSame('production', $result['payload']['mode']);

        Http::assertSent(function (Request $request) use ($platform) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . '/clients/9010/wallet-balance'
                && $request['currency'] === 'GHS'
                && $request['mode'] === 'production';
        });
    }

    public function test_push_active_wp_credentials_generates_missing_credentials_and_persists_after_successful_push(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
        ]);

        $platform = Platform::factory()->create([
            'wallet_settings' => [
                'enabled' => true,
            ],
        ]);

        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/wallet-credentials' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = app(WalletSyncService::class)->pushActiveWpCredentials($platform);

        $this->assertSame('synced', $result['status']);
        $this->assertSame('generated_and_pushed', $result['credential_action']);

        $stored = IntegrationSetting::query()
            ->where('key', 'wallet_platform_credentials_' . $platform->id)
            ->firstOrFail();

        $this->assertNotEmpty(data_get($stored->value, 'wp_to_crm.sandbox.bearer_key_hash'));
        $this->assertNotEmpty(data_get($stored->value, 'wp_to_crm.sandbox.bearer_key_encrypted'));
        $this->assertNotEmpty(data_get($stored->value, 'wp_to_crm.sandbox.hmac_secret_encrypted'));

        Http::assertSent(function (Request $request) use ($platform) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . '/wallet-credentials'
                && $request['platform_id'] === $platform->id
                && !empty($request['bearer_key'])
                && !empty($request['hmac_secret']);
        });

        $this->assertSame(
            'synced',
            data_get($stored->value, 'wp_to_crm.sandbox.sync.last_status')
        );
        $this->assertSame(
            'generated_and_pushed',
            data_get($stored->value, 'wp_to_crm.sandbox.sync.last_credential_action')
        );
        $this->assertNotNull(data_get($stored->value, 'wp_to_crm.sandbox.sync.last_attempt_at'));
        $this->assertNotNull(data_get($stored->value, 'wp_to_crm.sandbox.sync.last_synced_at'));
    }

    public function test_rotate_active_wp_credentials_does_not_persist_when_wordpress_push_fails(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
        ]);

        $platform = Platform::factory()->create([
            'wallet_settings' => [
                'enabled' => true,
            ],
        ]);

        $seed = app(WalletSettingsService::class)->rotateWpCredentials($platform, 'sandbox', 'both');
        $storedBefore = IntegrationSetting::query()
            ->where('key', 'wallet_platform_credentials_' . $platform->id)
            ->firstOrFail();
        $previousHash = (string) data_get($storedBefore->value, 'wp_to_crm.sandbox.bearer_key_hash');

        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/wallet-credentials' => Http::response([
                'message' => 'Push failed',
            ], 500),
        ]);

        $result = app(WalletSyncService::class)->rotateWpCredentials($platform, 'sandbox', 'both');

        $this->assertSame('failed', data_get($result, 'wp_credentials_sync.status'));
        $this->assertSame('rotation_not_persisted', data_get($result, 'wp_credentials_sync.credential_action'));
        $this->assertNull(data_get($result, 'revealed'));

        $storedAfter = IntegrationSetting::query()
            ->where('key', 'wallet_platform_credentials_' . $platform->id)
            ->firstOrFail();
        $currentHash = (string) data_get($storedAfter->value, 'wp_to_crm.sandbox.bearer_key_hash');

        $this->assertSame($previousHash, $currentHash);
        $this->assertTrue(Hash::check((string) $seed['revealed']['bearer_key'], $currentHash));

        $this->assertSame(
            'failed',
            data_get($storedAfter->value, 'wp_to_crm.sandbox.sync.last_status')
        );
        $this->assertSame(
            'rotation_not_persisted',
            data_get($storedAfter->value, 'wp_to_crm.sandbox.sync.last_credential_action')
        );
        $this->assertStringContainsString(
            'Push failed',
            (string) data_get($storedAfter->value, 'wp_to_crm.sandbox.sync.last_error')
        );
    }

    public function test_rotate_inactive_environment_credentials_persists_delayed_rotation_state_without_pushing(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'production',
        ]);

        $platform = Platform::factory()->create([
            'wallet_settings' => [
                'enabled' => true,
            ],
        ]);

        Http::fake();

        $result = app(WalletSyncService::class)->rotateWpCredentials($platform, 'sandbox', 'both');

        $this->assertSame('skipped', data_get($result, 'wp_credentials_sync.status'));
        $this->assertSame('environment_not_active', data_get($result, 'wp_credentials_sync.reason'));
        $this->assertSame('rotation_persisted_pending_push', data_get($result, 'wp_credentials_sync.credential_action'));

        $stored = IntegrationSetting::query()
            ->where('key', 'wallet_platform_credentials_' . $platform->id)
            ->firstOrFail();

        $this->assertNotEmpty(data_get($stored->value, 'wp_to_crm.sandbox.bearer_key_hash'));
        $this->assertNotEmpty(data_get($stored->value, 'wp_to_crm.sandbox.hmac_secret_encrypted'));
        $this->assertSame(
            'skipped',
            data_get($stored->value, 'wp_to_crm.sandbox.sync.last_status')
        );
        $this->assertSame(
            'environment_not_active',
            data_get($stored->value, 'wp_to_crm.sandbox.sync.last_reason')
        );
        $this->assertSame(
            'rotation_persisted_pending_push',
            data_get($stored->value, 'wp_to_crm.sandbox.sync.last_credential_action')
        );

        Http::assertNothingSent();
    }
}
