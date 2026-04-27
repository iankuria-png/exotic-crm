<?php

namespace Tests\Feature;

use App\Models\BillingSubscriptionRule;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\Client;
use App\Models\BillingRoutingDecision;
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
        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'activation_method_json' => ['methods' => ['manual', 'payment_link']],
            'renewal_method_json' => ['methods' => ['wallet_balance', 'payment_link'], 'wallet_auto_renew' => true],
            'free_trial_json' => ['enabled' => false],
            'discount_json' => [
                'enabled' => true,
                'self_service_incentive' => [
                    'enabled' => true,
                    'percent' => 10,
                    'label' => 'Weekend special',
                    'starts_at' => now()->subMinute()->toIso8601String(),
                    'expires_at' => now()->addDay()->toIso8601String(),
                    'sources' => ['wallet', 'self_checkout', 'manual_submission'],
                ],
            ],
            'expiry_policy_json' => ['grace_period_days' => 7],
        ]);

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
            ->assertJsonPath('checkout_url', 'https://checkout.paystack.test/redirect')
            ->assertJsonPath('pricing.original_amount', 1400)
            ->assertJsonPath('pricing.discount_percent', 10)
            ->assertJsonPath('pricing.discount_source', 'self_service_incentive')
            ->assertJsonPath('billing_method_policy.version', '2026-04-08')
            ->assertJsonPath('billing_method_policy.activation.methods', ['manual', 'payment_link'])
            ->assertJsonPath('billing_method_policy.renewal.methods', ['wallet_balance', 'payment_link']);

        $payment = Payment::query()->firstOrFail();
        $decision = BillingRoutingDecision::query()->where('payment_id', $payment->id)->latest('id')->first();

        $this->assertSame('paystack', $payment->provider_key);
        $this->assertSame('production', $payment->provider_environment);
        $this->assertSame('primary', data_get($payment->payment_data, 'provider_config_key'));
        $this->assertSame('paystack', data_get($payment->payment_data, 'provider'));
        $this->assertSame('https://checkout.paystack.test/redirect', data_get($payment->payment_data, 'checkout_url'));
        $this->assertSame('1260.00', data_get($payment->payment_data, 'quoted_pricing.amount'));
        $this->assertSame('GHS', data_get($payment->payment_data, 'quoted_pricing.currency'));
        $this->assertSame('14175.00', data_get($payment->payment_data, 'charge_pricing.amount'));
        $this->assertSame('KES', data_get($payment->payment_data, 'charge_pricing.currency'));
        $this->assertSame(1400.0, (float) data_get($payment->payment_data, 'self_service_incentive.original_amount'));
        $this->assertSame(10.0, (float) data_get($payment->payment_data, 'self_service_incentive.percent'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'fx_override.enabled'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'fx_override.applied'));
        $this->assertSame(11.25, data_get($payment->payment_data, 'fx_override.rate'));
        $this->assertSame('KES', data_get($payment->payment_data, 'fx_override.target_currency'));
        $this->assertSame(14175.0, (float) $payment->amount);
        $this->assertSame('KES', $payment->currency);
        $this->assertNotNull($decision);
        $this->assertSame('self_checkout', $decision->billing_surface);
        $this->assertSame('paystack', $decision->provider_type_key);
        $this->assertSame('proxy', $decision->execution_mode);
        $this->assertSame('hosted_redirect', data_get($decision->snapshot_json, 'execution_family'));
        $this->assertSame('fixed_override', data_get($decision->snapshot_json, 'fx_quote.mode'));
        $this->assertSame('primary', data_get($decision->snapshot_json, 'provider_config_key'));

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
                && $request['amount'] === 1417500
                && $request['reference'] === $payment->reference_number;
        });
    }

    public function test_self_checkout_normalizes_local_kenya_numbers_before_pawapay_initialization(): void
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
                        'wallet_provider_key' => 'pawapay',
                        'environment' => 'sandbox',
                    ],
                ],
            ],
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 6601,
            'wp_user_id' => 9911,
            'name' => 'Kenya Client',
            'phone_normalized' => '254748612016',
            'email' => 'kenya-pawapay@example.test',
            'profile_status' => 'draft',
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium',
            'display_name' => 'Premium',
            'currency' => 'KES',
            'monthly_price' => 3000,
            'biweekly_price' => 1500,
            'weekly_price' => 750,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 3000,
            'currency' => 'KES',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->seedWalletBillingContext($platform);

        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'pawapay',
            'profile_name' => 'pawaPay Kenya Sandbox',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'environment' => 'sandbox',
            'config_json' => [
                'base_url' => 'https://api.sandbox.pawapay.io',
                'callback_base_url' => 'https://billing.example.test',
            ],
            'secrets_json' => [
                'api_key' => 'pawapay-sandbox-key',
            ],
            'active' => true,
        ]);

        BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
            'billing_surface' => 'self_checkout',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => true,
            'execution_mode' => 'direct',
            'priority' => 1,
            'restriction_json' => [],
        ]);

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) {
                $payload = json_decode($request->body(), true);

                TestCase::assertArrayNotHasKey('phoneNumber', $payload);
                TestCase::assertSame('KEN', $payload['country'] ?? null);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://sandbox.paywith.pawapay.io/session/self-checkout-001',
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/self-checkout', [
            'product_id' => $product->id,
            'platform_id' => $platform->id,
            'user_id' => $client->wp_user_id,
            'first_name' => 'Zuri',
            'last_name' => 'User',
            'phone' => '0748612016',
            'email' => $client->email,
            'duration' => 'monthly',
        ], [
            'Origin' => 'https://www.exotickenya.com',
            'Referer' => 'https://www.exotickenya.com/escort/zuri-10/',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Chrome/136.0.0.0 Safari/537.36',
            'X-Request-Id' => 'wp-self-checkout-pawapay-req-001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('provider', 'pawapay')
            ->assertJsonPath('checkout_url', 'https://sandbox.paywith.pawapay.io/session/self-checkout-001');

        $payment = Payment::query()->firstOrFail();
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame('254748612016', $payment->phone);
        $this->assertSame('254748612016', data_get($payment->payment_data, 'customer.phone'));
        $this->assertSame('pawapay', $payment->provider_key);
        $this->assertSame('https://sandbox.paywith.pawapay.io/session/self-checkout-001', data_get($payment->payment_data, 'checkout_url'));
        $this->assertSame('success', $attempt->status);
        $this->assertSame('pawapay', $attempt->provider);

        Http::assertSentCount(1);
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
