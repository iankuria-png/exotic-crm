<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\BillingGatewayService;
use App\Services\PaymentCompletionService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CanonicalPaymentStateIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_topup_payment_persists_completed_status_and_wallet_credit(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Canonical Billing',
            'currency_code' => 'KES',
            'wallet_settings' => [
                'currency_code' => 'KES',
                'ui' => [
                    'recent_transactions_limit' => 5,
                ],
            ],
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wallet_balance' => 400,
            'wallet_currency' => 'KES',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'amount' => 1600,
            'currency' => 'KES',
            'reference_number' => 'CANONICAL-WALLET-001',
            'transaction_reference' => 'CANONICAL-WALLET-001',
            'status' => 'pending',
        ]);

        $result = app(PaymentCompletionService::class)->completeTopupPayment($payment, [
            'status' => 'success',
            'reference' => 'CANONICAL-WALLET-001',
            'gateway_response' => 'Successful',
        ]);

        $this->assertTrue($result['credited']);

        $payment->refresh();
        $client->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertNotNull($payment->completed_at);
        $this->assertNotNull($payment->wallet_transaction_id);
        $this->assertSame('2000.00', number_format((float) $client->wallet_balance, 2, '.', ''));
    }

    public function test_paystack_webhook_completion_persists_completed_status(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Canonical Billing',
            'country' => 'Kenya',
            'domain' => 'escorts.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://escorts.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-sync-user',
            'wp_api_password' => 'crm-sync-secret',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 4401,
            'wp_user_id' => 8801,
            'wallet_balance' => 400,
            'wallet_currency' => 'KES',
        ]);

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
            'redirect_delay_seconds' => 2,
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
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'amount' => 1600,
            'currency' => 'KES',
            'reference_number' => 'CANONICAL-WEBHOOK-001',
            'transaction_reference' => 'CANONICAL-WEBHOOK-001',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'CANONICAL-WEBHOOK-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'CANONICAL-WEBHOOK-001',
            ],
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $rawBody, 'sk_live_wallet');
        $result = app(BillingGatewayService::class)->handlePaystackWebhook($rawBody, $payload, $signature);

        $payment->refresh();
        $client->refresh();

        $this->assertSame('completed', $result['status']);
        $this->assertSame('completed', $result['payment']->status);
        $this->assertSame('completed', $payment->status);
        $this->assertNotNull($payment->wallet_transaction_id);
        $this->assertSame('2000.00', number_format((float) $client->wallet_balance, 2, '.', ''));
    }
}
