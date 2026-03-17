<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Services\SubscriptionProvisioningService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class SandboxPaymentCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_wallet_topup_completion_marks_test_metadata_without_crediting_wallet(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProductionBillingContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 1250,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-COMPLETE-001',
            'transaction_reference' => 'WTU-SANDBOX-COMPLETE-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => [],
            'payment_data' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'WTU-SANDBOX-COMPLETE-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $response = $this->postSignedPaystackWebhook('WTU-SANDBOX-COMPLETE-001');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $payment->refresh();
        $client->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertSame('0.00', number_format((float) $client->wallet_balance, 2, '.', ''));
        $this->assertTrue((bool) data_get($payment->payment_data, 'test_mode'));
        $this->assertSame('completed', data_get($payment->payment_data, 'test_result'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'side_effects_skipped'));
        $this->assertNotEmpty(data_get($payment->payment_data, 'verified_at'));
        $this->assertDatabaseMissing('wallet_transactions', [
            'payment_id' => $payment->id,
        ]);
    }

    public function test_sandbox_subscription_completion_marks_test_metadata_without_creating_a_deal(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProductionBillingContext();

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VIP Sandbox',
            'display_name' => 'VIP Sandbox',
            'tier' => 'vip',
            'weekly_price' => 900,
            'biweekly_price' => 1800,
            'monthly_price' => 3600,
            'currency' => 'KES',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => $product->id,
            'purpose' => 'subscription',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 3600,
            'currency' => 'KES',
            'duration' => 'monthly',
            'reference_number' => 'SUB-SANDBOX-COMPLETE-001',
            'transaction_reference' => 'SUB-SANDBOX-COMPLETE-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => ['method' => 'link'],
            'payment_data' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'SUB-SANDBOX-COMPLETE-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $response = $this->postSignedPaystackWebhook('SUB-SANDBOX-COMPLETE-001');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $payment->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertNull($payment->deal_id);
        $this->assertTrue((bool) data_get($payment->payment_data, 'test_mode'));
        $this->assertSame('completed', data_get($payment->payment_data, 'test_result'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'side_effects_skipped'));
        $this->assertDatabaseMissing('deals', [
            'payment_id' => $payment->id,
        ]);
    }

    public function test_sandbox_failed_verification_marks_test_failure_metadata(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProductionBillingContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 990,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-FAIL-001',
            'transaction_reference' => 'WTU-SANDBOX-FAIL-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => [],
            'payment_data' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'failed',
                    'reference' => 'WTU-SANDBOX-FAIL-001',
                    'gateway_response' => 'Declined',
                ],
            ], 200),
        ]);

        $response = $this->postSignedPaystackWebhook('WTU-SANDBOX-FAIL-001');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $payment->refresh();
        $client->refresh();

        $this->assertSame('failed', $payment->status);
        $this->assertSame('0.00', number_format((float) $client->wallet_balance, 2, '.', ''));
        $this->assertTrue((bool) data_get($payment->payment_data, 'test_mode'));
        $this->assertSame('failed', data_get($payment->payment_data, 'test_result'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'side_effects_skipped'));
        $this->assertNotEmpty(data_get($payment->payment_data, 'verified_at'));
        $this->assertDatabaseMissing('wallet_transactions', [
            'payment_id' => $payment->id,
        ]);
    }

    public function test_sandbox_completed_payments_cannot_be_provisioned_into_live_subscriptions(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProductionBillingContext();

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Sandbox',
            'display_name' => 'Premium Sandbox',
            'tier' => 'premium',
            'weekly_price' => 700,
            'biweekly_price' => 1500,
            'monthly_price' => 3000,
            'currency' => 'KES',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => $product->id,
            'purpose' => 'subscription',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 3000,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'completed',
            'completed_at' => now(),
            'raw_payload' => ['method' => 'link'],
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
                'side_effects_skipped' => true,
                'verified_at' => now()->toIso8601String(),
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sandbox payments cannot provision live subscriptions.');

        app(SubscriptionProvisioningService::class)->provisionCompletedPayment($payment, [
            'client' => $client,
        ]);
    }

    private function postSignedPaystackWebhook(string $reference)
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
            ],
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawBody, 'sk_test_wallet');

        return $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Paystack-Signature' => $signature,
        ], $rawBody);
    }

    private function seedProductionBillingContext(): array
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Sandbox Completion Market',
            'country' => 'Kenya',
            'domain' => 'sandbox-completion.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://sandbox-completion.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-sync-user',
            'wp_api_password' => 'crm-sync-secret',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 309001,
            'wallet_balance' => 0,
            'wallet_currency' => 'KES',
        ]);

        $service = app(WalletSettingsService::class);
        $service->saveSystemConfig([
            'mode' => 'production',
            'default_currency' => 'KES',
            'billing_domains' => [
                'sandbox' => 'https://billing-sandbox.example.test',
                'production' => 'https://billing.example.test',
            ],
            'billing_branding' => [
                'sandbox' => [
                    'business_name' => 'Exotic Sandbox Billing',
                    'description' => 'Sandbox checkout',
                ],
                'production' => [
                    'business_name' => 'Exotic Billing',
                    'description' => 'Live checkout',
                ],
            ],
            'redirect_delay_seconds' => 2,
            'wallet_refresh_rate_limit_seconds' => 20,
            'wallet_refresh_timeout_seconds' => 15,
            'topup_poll_interval_seconds' => 8,
        ]);
        $service->savePlatformConfig($platform, [
            'enabled' => true,
            'mode_override' => 'production',
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
                    'consumer_key' => 'pesapal-sandbox-key',
                    'consumer_secret' => 'pesapal-sandbox-secret',
                    'ipn_id' => 'ipn-sandbox-001',
                ],
                'production' => [
                    'consumer_key' => 'pesapal-live-key',
                    'consumer_secret' => 'pesapal-live-secret',
                    'ipn_id' => 'ipn-live-001',
                ],
            ],
        ]);

        return [
            'platform' => $platform->fresh(),
            'client' => $client->fresh(['platform']),
        ];
    }
}
