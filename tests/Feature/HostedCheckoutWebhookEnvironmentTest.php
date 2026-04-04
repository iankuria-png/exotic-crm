<?php

namespace Tests\Feature;

use App\Models\BillingRoutingDecision;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostedCheckoutWebhookEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_paystack_webhook_uses_the_payment_environment_for_signature_and_verification(): void
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
            'amount' => 1500,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-VERIFY-001',
            'transaction_reference' => 'WTU-SANDBOX-VERIFY-001',
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
                    'reference' => 'WTU-SANDBOX-VERIFY-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'WTU-SANDBOX-VERIFY-001',
            ],
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawBody, 'sk_test_wallet');

        $response = $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Paystack-Signature' => $signature,
        ], $rawBody);

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

        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/transaction/verify/WTU-SANDBOX-VERIFY-001'
            && $request->hasHeader('Authorization', 'Bearer sk_test_wallet'));
    }

    public function test_pesapal_ipn_uses_the_payment_environment_for_sandbox_verification(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProductionBillingContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'pesapal',
            'provider_environment' => 'sandbox',
            'amount' => 1800,
            'currency' => 'KES',
            'reference_number' => 'WTU-PESAPAL-SANDBOX-001',
            'transaction_reference' => 'PESAPAL-TRACK-SANDBOX-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => [],
            'payment_data' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response([
                'token' => 'sandbox-token',
            ], 200),
            'https://cybqa.pesapal.com/pesapalv3/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1,
                'payment_status_description' => 'Completed',
            ], 200),
        ]);

        $response = $this->getJson('/api/billing/pesapal/ipn?OrderMerchantReference=WTU-PESAPAL-SANDBOX-001&OrderTrackingId=PESAPAL-TRACK-SANDBOX-001');

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

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://cybqa.pesapal.com/pesapalv3/api/Transactions/GetTransactionStatus')
            && $request->hasHeader('Authorization', 'Bearer sandbox-token'));
    }

    public function test_paystack_webhook_prefers_pinned_snapshot_for_alias_provider_and_environment(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProductionBillingContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack_checkout',
            'provider_environment' => 'production',
            'amount' => 1500,
            'currency' => 'KES',
            'reference_number' => 'WTU-SNAPSHOT-WEBHOOK-001',
            'transaction_reference' => 'WTU-SNAPSHOT-WEBHOOK-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => [],
            'payment_data' => [],
        ]);

        BillingRoutingDecision::query()->create([
            'payment_id' => (int) $payment->id,
            'market_id' => (int) $platform->id,
            'billing_surface' => 'proxy_hosted_checkout',
            'chosen_binding_id' => null,
            'provider_profile_id' => null,
            'provider_type_key' => 'paystack',
            'execution_mode' => 'proxy',
            'environment' => 'sandbox',
            'fallback_taken' => false,
            'decision_version' => 1,
            'shadow_diff_json' => null,
            'surface_cutover_flag' => null,
            'snapshot_json' => [
                'provider_key' => 'paystack_checkout',
                'provider_type_key' => 'paystack',
                'environment' => 'sandbox',
            ],
            'immutable_until_terminal_state' => true,
            'decision_json' => [
                'source' => 'payment_link_send',
            ],
            'created_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'WTU-SNAPSHOT-WEBHOOK-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'WTU-SNAPSHOT-WEBHOOK-001',
            ],
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawBody, 'sk_test_wallet');

        $response = $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Paystack-Signature' => $signature,
        ], $rawBody);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/transaction/verify/WTU-SNAPSHOT-WEBHOOK-001'
            && $request->hasHeader('Authorization', 'Bearer sk_test_wallet'));
    }

    private function seedProductionBillingContext(): array
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Webhook Environment Market',
            'country' => 'Kenya',
            'domain' => 'webhook-environment.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://webhook-environment.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-sync-user',
            'wp_api_password' => 'crm-sync-secret',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 209001,
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
