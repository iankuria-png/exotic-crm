<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\User;
use App\Models\Product;
use App\Services\PaymentLinkService;
use App\Services\WalletSettingsService;
use App\Services\WalletPayloadService;
use App\Services\BillingModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BaselineDiagnosticsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_diagnostics_baseline_snapshot(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('paystack');

        app(PaymentLinkService::class)->sendLink($payment, [
            'channel' => 'sms',
            'actor_id' => $user->id,
            'reason' => 'Baseline snapshot',
            'notification_purpose' => 'payment_link',
        ]);

        $payment = $payment->fresh();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy']['opened_at'] = '2026-04-03T10:00:00Z';
        $paymentData['link_proxy']['initialized_at'] = '2026-04-03T10:01:00Z';
        $paymentData['link_proxy']['open_count'] = 1;
        $paymentData['link_proxy']['redirect_url'] = 'https://checkout.paystack.test/redirect';
        $paymentData['link_proxy']['provider_reference'] = 'PSTK-BASELINE-001';

        $payment->forceFill([
            'status' => 'pending',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-BASELINE-REF-001',
            'payment_data' => $paymentData,
        ])->saveQuietly();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk();
        $response->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.provider_key', 'paystack')
            ->assertJsonPath('payment.provider_environment', 'sandbox')
            ->assertJsonPath('payment.reference_number', 'CRM-BASELINE-REF-001');

        $data = $response->json();
        unset($data['payment']['id'], $data['payment']['client_id'], $data['payment']['platform_id'], $data['payment']['user_id']);
        
        file_put_contents(
            base_path('tests/Feature/Billing/snapshots/diagnostics_baseline.json'),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    public function test_capture_provider_status_check_baseline_snapshot(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('paystack');

        $payment = $payment->fresh();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy'] = [
            'mode' => 'proxy_hosted_checkout',
            'initialized_at' => '2026-04-03T10:01:00Z',
            'provider_reference' => 'PSTK-BASELINE-002',
        ];

        $payment->forceFill([
            'status' => 'pending',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-BASELINE-REF-002',
            'payment_data' => $paymentData,
        ])->save();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'gateway_response' => 'Approved',
                    'reference' => 'CRM-BASELINE-REF-002',
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/check-provider-status");

        $response->assertOk();
        $response->assertJsonPath('provider', 'paystack')
            ->assertJsonPath('provider_environment', 'sandbox')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', 'Approved');

        $data = $response->json();
        unset($data['payment_id']);

        file_put_contents(
            base_path('tests/Feature/Billing/snapshots/provider_status_check_baseline.json'),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    public function test_capture_wordpress_sync_payload_baselines(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProxyPayment('paystack');

        $context = app(BillingModeService::class)->walletContext($platform);
        $syncedAt = '2026-04-03T12:00:00Z';
        
        $configPayload = app(WalletPayloadService::class)->configSync($platform, $context, $syncedAt);
        
        $summary = [
            'balance' => '5000.00',
            'currency' => 'KES',
            'refreshed_at' => '2026-04-03T11:55:00Z',
            'last_topup' => [
                'amount' => 2000,
                'date' => '2026-04-01T09:30:00Z',
                'provider' => 'paystack',
            ],
            'transactions' => [
                [
                    'id' => 123,
                    'amount' => 2000,
                    'type' => 'credit',
                    'date' => '2026-04-01T09:30:00Z',
                ]
            ],
        ];
        $balancePayload = app(WalletPayloadService::class)->balanceSync($client, $summary, $context, $syncedAt);

        $this->assertSame('disabled', $configPayload['mode']);
        $this->assertSame('KES', data_get($configPayload, 'config.market.currency'));
        $this->assertSame('5000.00', $balancePayload['balance']);
        $this->assertSame('disabled', $balancePayload['mode']);

        unset($configPayload['platform_id'], $balancePayload['platform_id'], $balancePayload['wp_user_id'], $balancePayload['wp_post_id']);

        file_put_contents(
            base_path('tests/Feature/Billing/snapshots/wp_config_sync_payload_baseline.json'),
            json_encode($configPayload, JSON_PRETTY_PRINT)
        );
        file_put_contents(
            base_path('tests/Feature/Billing/snapshots/wp_balance_sync_payload_baseline.json'),
            json_encode($balancePayload, JSON_PRETTY_PRINT)
        );
    }

    public function test_capture_wallet_api_baseline_snapshot(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProxyPayment('paystack');
        ['bearer_key' => $bearerKey] = $this->enableWalletForBaseline($platform);

        $client->forceFill([
            'wallet_balance' => '1800.00',
            'wallet_currency' => 'KES',
        ])->saveQuietly();

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 1200,
            'status' => 'completed',
        ]);

        $client->walletTransactions()->create([
            'platform_id' => $platform->id,
            'type' => 'credit',
            'currency_code' => 'KES',
            'amount' => 1200,
            'balance_after' => 1800,
            'reference_type' => 'wallet_topup',
            'reference_id' => 1,
            'description' => 'Wallet top-up via PAYSTACK',
        ]);

        $response = $this->withHeaders(
            $this->walletHeaders($platform, $bearerKey, 'GET', '/api/wallet/balance')
        )->getJson("/api/wallet/balance?wp_user_id={$client->wp_user_id}&platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('balance', '1800.00')
            ->assertJsonPath('currency', 'KES')
            ->assertJsonPath('mode', 'sandbox')
            ->assertJsonPath('config.sandbox_badge', true)
            ->assertJsonPath('config.providers.mpesa_stk.transport', 'direct_provider');

        $data = $response->json();
        unset($data['client']['id'], $data['client']['wp_user_id'], $data['client']['wp_post_id'], $data['config']['platform_id']);

        file_put_contents(
            base_path('tests/Feature/Billing/snapshots/wallet_balance_api_baseline.json'),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    private function enableWalletForBaseline(Platform $platform): array
    {
        $service = app(WalletSettingsService::class);
        $service->saveSystemConfig([
            'mode' => 'sandbox',
            'default_currency' => 'KES',
            'billing_domains' => [
                'sandbox' => 'https://billing-sandbox.example.test',
                'production' => 'https://billing.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Exotic Sandbox Billing',
                    'description' => 'Sandbox wallet top-up',
                ],
                'production' => [
                    'business_name' => 'Exotic Billing',
                    'description' => 'Live wallet top-up',
                ],
            ],
            'wallet_refresh_rate_limit_seconds' => 20,
            'wallet_refresh_timeout_seconds' => 15,
            'topup_poll_interval_seconds' => 8,
        ]);
        $service->savePlatformConfig($platform, [
            'enabled' => true,
            'mode_override' => 'sandbox',
            'currency_code' => 'KES',
            'max_single_topup' => '50000.00',
            'max_wallet_balance' => '300000.00',
            'topup_presets' => ['500.00', '1000.00', '2500.00'],
            'allow_combined_topup_subscribe' => true,
            'show_refresh_button' => true,
            'recent_transactions_limit' => 10,
            'providers' => [
                'paystack' => [
                    'enabled' => true,
                    'min_amount' => '100.00',
                    'max_amount' => '500000.00',
                ],
                'pesapal' => [
                    'enabled' => true,
                    'min_amount' => '100.00',
                    'max_amount' => '150000.00',
                ],
                'mpesa_stk' => [
                    'enabled' => true,
                    'min_amount' => '100.00',
                    'max_amount' => '150000.00',
                ],
            ],
        ]);
        $service->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_wallet',
                    'secret_key' => 'sk_test_wallet',
                ],
                'production' => [
                    'public_key' => 'pk_live_wallet',
                    'secret_key' => 'sk_live_wallet',
                ],
            ],
            'pesapal' => [
                'sandbox' => [
                    'consumer_key' => 'pesapal-key',
                    'consumer_secret' => 'pesapal-secret',
                    'ipn_id' => 'ipn-test-001',
                ],
                'production' => [
                    'consumer_key' => 'pesapal-live-key',
                    'consumer_secret' => 'pesapal-live-secret',
                    'ipn_id' => 'ipn-live-001',
                ],
            ],
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'direct_provider',
                    'payment_service_base_url' => 'https://payments.example.test',
                    'organization_code' => '76',
                    'callback_base_url' => 'https://billing-sandbox.example.test',
                ],
            ],
        ]);

        return $service->rotateWpCredentials($platform, 'sandbox', 'both')['revealed'];
    }

    private function walletHeaders(
        Platform $platform,
        string $bearerKey,
        string $method,
        string $path
    ): array {
        return [
            'Authorization' => 'Bearer ' . $bearerKey,
            'X-Exotic-Platform-Id' => (string) $platform->id,
            'X-Exotic-Timestamp' => (string) now()->timestamp,
        ];
    }

    private function seedProxyPayment(string $provider, string $purpose = 'subscription'): array
    {
        config(['app.url' => 'https://crm.example.test']);

        $platform = Platform::factory()->create([
            'name' => 'Baseline Market',
            'country' => 'Kenya',
            'domain' => 'baseline-market.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://baseline-market.example.test/wp-json/exotic-crm-sync/v1',
            'payment_link_providers' => [
                'active_provider' => $provider . '_checkout',
                'providers' => [
                    $provider . '_checkout' => [
                        'label' => strtoupper($provider) . ' Checkout',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => $provider,
                        'environment' => 'sandbox',
                    ],
                ],
            ],
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 5511,
            'wp_user_id' => 7711,
            'name' => 'Baseline Client',
            'phone_normalized' => '254700000222',
            'email' => 'baseline-client@example.test',
            'profile_status' => 'publish',
        ]);

        $user = User::query()->create([
            'name' => 'Sales Baseline',
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'phone' => $client->phone_normalized,
            'amount' => 1500,
            'currency' => 'KES',
            'purpose' => $purpose,
            'status' => 'initiated',
            'provider_key' => $provider,
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-' . Str::upper(Str::random(10)),
            'payment_data' => null,
            'raw_payload' => [],
        ]);

        $walletSettings = app(WalletSettingsService::class);
        $walletSettings->saveSystemConfig([
            'mode' => 'disabled',
            'default_currency' => 'KES',
            'billing_domains' => [
                'sandbox' => 'https://billing-sandbox.example.test',
                'production' => 'https://billing.example.test',
            ],
        ]);

        $walletSettings->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_baseline',
                    'secret_key' => 'sk_test_baseline',
                ],
            ],
        ]);

        return [
            'platform' => $platform->fresh(),
            'client' => $client->fresh(),
            'payment' => $payment->fresh(['platform', 'client']),
            'user' => $user->fresh(),
        ];
    }
}
