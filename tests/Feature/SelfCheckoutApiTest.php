<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
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

        Http::assertSent(function ($request) use ($payment) {
            return $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request['currency'] === 'GHS'
                && $request['amount'] === 140000
                && $request['reference'] === $payment->reference_number;
        });
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
