<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\PaymentLinkService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentLinkProxyRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_route_redirects_to_paystack_checkout_and_reuses_the_initialized_redirect(): void
    {
        ['payment' => $payment] = $this->seedProxyContext('paystack');
        $paymentUrl = $this->sendProxyLink($payment);
        $token = $this->extractToken($paymentUrl);

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.test/redirect',
                    'reference' => $payment->reference_number,
                    'access_code' => 'ACCESS-CODE-001',
                ],
            ], 200),
        ]);

        $first = $this->get('/api/payments/link/' . $token);
        $first->assertRedirect('https://checkout.paystack.test/redirect');

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('paystack', $payment->provider_key);
        $this->assertSame('sandbox', $payment->provider_environment);
        $this->assertNotNull(data_get($payment->payment_data, 'link_proxy.initialized_at'));
        $this->assertNotNull(data_get($payment->payment_data, 'link_proxy.opened_at'));
        $this->assertSame(1, (int) data_get($payment->payment_data, 'link_proxy.open_count'));
        $this->assertSame('https://checkout.paystack.test/redirect', data_get($payment->payment_data, 'link_proxy.redirect_url'));

        $second = $this->get('/api/payments/link/' . $token);
        $second->assertRedirect('https://checkout.paystack.test/redirect');

        $payment->refresh();
        $this->assertSame(2, (int) data_get($payment->payment_data, 'link_proxy.open_count'));
        Http::assertSentCount(1);
    }

    public function test_proxy_route_redirects_to_pesapal_checkout(): void
    {
        ['payment' => $payment] = $this->seedProxyContext('pesapal');
        $paymentUrl = $this->sendProxyLink($payment);
        $token = $this->extractToken($paymentUrl);

        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response([
                'token' => 'pesapal-access-token',
            ], 200),
            'https://cybqa.pesapal.com/pesapalv3/api/Transactions/SubmitOrderRequest' => Http::response([
                'redirect_url' => 'https://checkout.pesapal.test/redirect',
                'order_tracking_id' => 'PESAPAL-TRACK-001',
            ], 200),
        ]);

        $response = $this->get('/api/payments/link/' . $token);
        $response->assertRedirect('https://checkout.pesapal.test/redirect');

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('pesapal', $payment->provider_key);
        $this->assertSame('sandbox', $payment->provider_environment);
        $this->assertSame('PESAPAL-TRACK-001', data_get($payment->payment_data, 'link_proxy.provider_reference'));
    }

    public function test_proxy_route_returns_gone_for_expired_tokens(): void
    {
        ['payment' => $payment] = $this->seedProxyContext('paystack');
        $paymentUrl = $this->sendProxyLink($payment);
        $token = $this->extractToken($paymentUrl);

        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy']['token_expires_at'] = now()->subMinute()->toIso8601String();
        $payment->forceFill([
            'payment_data' => $paymentData,
        ])->save();

        $response = $this->get('/api/payments/link/' . $token);
        $response->assertStatus(410);
    }

    public function test_proxy_route_rejects_invalid_and_rotated_tokens(): void
    {
        ['payment' => $payment] = $this->seedProxyContext('paystack');
        $firstUrl = $this->sendProxyLink($payment);
        $firstToken = $this->extractToken($firstUrl);
        $firstHash = data_get($payment->fresh()->payment_data, 'link_proxy.token_hash');

        $secondUrl = $this->sendProxyLink($payment->fresh());
        $secondToken = $this->extractToken($secondUrl);
        $secondHash = data_get($payment->fresh()->payment_data, 'link_proxy.token_hash');

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertNotSame($firstHash, $secondHash);

        $this->get('/api/payments/link/not-a-real-token')->assertNotFound();
        $this->get('/api/payments/link/' . $firstToken)->assertNotFound();
    }

    private function seedProxyContext(string $provider): array
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Proxy Market',
            'country' => 'Kenya',
            'domain' => 'proxy-market.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
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
            'wp_post_id' => 4401,
            'wp_user_id' => 8801,
            'name' => 'Proxy Client',
            'phone_normalized' => '254700000111',
            'email' => 'proxy-client@example.test',
            'profile_status' => 'publish',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'phone' => $client->phone_normalized,
            'amount' => 1500,
            'currency' => 'KES',
            'purpose' => 'subscription',
            'status' => 'initiated',
            'provider_key' => null,
            'provider_environment' => null,
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
        $walletSettings->savePlatformProviderCredentials($platform, match ($provider) {
            'paystack' => [
                'paystack' => [
                    'sandbox' => [
                        'public_key' => 'pk_test_wallet',
                        'secret_key' => 'sk_test_wallet',
                    ],
                ],
            ],
            'pesapal' => [
                'pesapal' => [
                    'sandbox' => [
                        'consumer_key' => 'pesapal-key',
                        'consumer_secret' => 'pesapal-secret',
                        'ipn_id' => 'ipn-test-001',
                    ],
                ],
            ],
        });

        return [
            'platform' => $platform->fresh(),
            'client' => $client->fresh(),
            'payment' => $payment->fresh(['platform', 'client']),
        ];
    }

    private function sendProxyLink(Payment $payment): string
    {
        $result = app(PaymentLinkService::class)->sendLink($payment, [
            'channel' => 'sms',
            'reason' => 'Send proxy link for checkout',
            'notification_purpose' => 'payment_link',
        ]);

        $this->assertTrue((bool) ($result['success'] ?? false));

        return (string) $result['payment_url'];
    }

    private function extractToken(string $paymentUrl): string
    {
        $path = (string) parse_url($paymentUrl, PHP_URL_PATH);

        return (string) basename($path);
    }
}
