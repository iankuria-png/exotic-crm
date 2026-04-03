<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SelfCheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_checkout_uses_the_wallet_provider_key_for_proxy_hosted_checkout_aliases(): void
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Ghana',
            'country' => 'Ghana',
            'domain' => 'ghana.example.test',
            'phone_prefix' => '233',
            'currency_code' => 'GHS',
            'payment_link_providers' => [
                'active_provider' => 'primary',
                'providers' => [
                    'primary' => [
                        'label' => 'Primary',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => 'paystack',
                        'environment' => 'production',
                        'self_checkout_fx_enabled' => true,
                        'self_checkout_fx_currency' => 'KES',
                        'self_checkout_fx_rate' => 11.25,
                    ],
                ],
            ],
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 4401,
            'wp_user_id' => 8801,
            'name' => 'Ghana Client',
            'phone_normalized' => '233700000111',
            'email' => 'ghana-client@example.test',
            'profile_status' => 'draft',
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VVIP',
            'display_name' => 'Vvip',
            'currency' => 'GHS',
            'monthly_price' => 1400,
            'biweekly_price' => 700,
            'weekly_price' => 350,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 1400,
            'currency' => 'GHS',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->seedWalletBillingContext($platform);

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.test/redirect',
                    'reference' => 'SUB-REFERENCE-001',
                    'access_code' => 'ACCESS-CODE-001',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/self-checkout', [
            'product_id' => $product->id,
            'platform_id' => $platform->id,
            'user_id' => $client->wp_user_id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => $client->phone_normalized,
            'email' => $client->email,
            'duration' => 'monthly',
        ], [
            'Origin' => 'https://www.exoticghana.com',
            'Referer' => 'https://www.exoticghana.com/escort/test-pm/',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Chrome/136.0.0.0 Safari/537.36',
            'X-Request-Id' => 'wp-self-checkout-req-001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('provider', 'paystack')
            ->assertJsonPath('provider_config_key', 'primary')
            ->assertJsonPath('checkout_url', 'https://checkout.paystack.test/redirect');

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('paystack', $payment->provider_key);
        $this->assertSame('production', $payment->provider_environment);
        $this->assertSame('primary', data_get($payment->payment_data, 'provider_config_key'));
        $this->assertSame('paystack', data_get($payment->payment_data, 'provider'));
        $this->assertSame('https://checkout.paystack.test/redirect', data_get($payment->payment_data, 'checkout_url'));
        $this->assertSame('1400.00', data_get($payment->payment_data, 'quoted_pricing.amount'));
        $this->assertSame('GHS', data_get($payment->payment_data, 'quoted_pricing.currency'));
        $this->assertSame('15750.00', data_get($payment->payment_data, 'charge_pricing.amount'));
        $this->assertSame('KES', data_get($payment->payment_data, 'charge_pricing.currency'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'fx_override.enabled'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'fx_override.applied'));
        $this->assertSame(11.25, data_get($payment->payment_data, 'fx_override.rate'));
        $this->assertSame('KES', data_get($payment->payment_data, 'fx_override.target_currency'));
        $this->assertSame(15750.0, (float) $payment->amount);
        $this->assertSame('KES', $payment->currency);

        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('hosted_checkout_init', $attempt->attempt_type);
        $this->assertSame('success', $attempt->status);
        $this->assertSame('paystack', $attempt->provider);
        $this->assertSame('browser', data_get($attempt->request_meta, 'context_type'));
        $this->assertSame('wp-self-checkout-req-001', data_get($attempt->request_meta, 'request_id'));
        $this->assertSame('https://www.exoticghana.com', data_get($attempt->request_meta, 'origin_url'));
        $this->assertSame('https://www.exoticghana.com/escort/test-pm/', data_get($attempt->request_meta, 'referrer'));
        $this->assertSame('hosted_checkout', data_get($attempt->request_meta, 'channel'));
        $this->assertSame('self_service_subscription', data_get($attempt->request_meta, 'billing_surface'));
        $this->assertSame($product->id, data_get($attempt->request_meta, 'product_id'));
        $this->assertSame($platform->id, data_get($attempt->request_meta, 'platform_id'));
        $this->assertSame('1_month', data_get($attempt->request_meta, 'duration'));
        $this->assertSame('https://checkout.paystack.test/redirect', data_get($attempt->response_meta, 'checkout_url'));
        $this->assertSame('SUB-REFERENCE-001', data_get($attempt->response_meta, 'provider_reference'));

        Http::assertSent(function ($request) use ($payment) {
            return $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request['currency'] === 'KES'
                && $request['amount'] === 1575000
                && $request['reference'] === $payment->reference_number;
        });
    }

    public function test_self_checkout_currently_rejects_non_card_proxy_wallet_provider_aliases(): void
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'domain' => 'kenya.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'payment_link_providers' => [
                'active_provider' => 'primary',
                'providers' => [
                    'primary' => [
                        'label' => 'Primary',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => 'mpesa_stk',
                        'environment' => 'production',
                    ],
                ],
            ],
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 5501,
            'wp_user_id' => 9901,
            'name' => 'Kenya Client',
            'phone_normalized' => '254700000111',
            'email' => 'kenya-client@example.test',
            'profile_status' => 'draft',
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium',
            'display_name' => 'Premium',
            'currency' => 'KES',
            'monthly_price' => 2400,
            'biweekly_price' => 1200,
            'weekly_price' => 600,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 2400,
            'currency' => 'KES',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->seedWalletBillingContext($platform);

        $response = $this->postJson('/api/self-checkout', [
            'product_id' => $product->id,
            'platform_id' => $platform->id,
            'user_id' => $client->wp_user_id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => $client->phone_normalized,
            'email' => $client->email,
            'duration' => 'monthly',
        ], [
            'Origin' => 'https://www.exotickenya.com',
            'Referer' => 'https://www.exotickenya.com/escort/test-pm/',
            'User-Agent' => 'Mozilla/5.0 Chrome/136.0.0.0 Safari/537.36',
            'X-Request-Id' => 'wp-self-checkout-req-unsupported-001',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The active provider does not support hosted card checkout.');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    private function seedWalletBillingContext(Platform $platform): void
    {
        $walletSettings = app(WalletSettingsService::class);
        $walletSettings->saveSystemConfig([
            'mode' => 'disabled',
            'default_currency' => 'GHS',
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

        $walletSettings->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'production' => [
                    'public_key' => 'pk_live_wallet',
                    'secret_key' => 'sk_live_wallet',
                ],
            ],
        ]);
    }
}
