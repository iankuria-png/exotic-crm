<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_stk_initiation_records_attempt_with_actor(): void
    {
        config([
            'services.django.base_url' => 'https://payments.exotic-ads.com/api/payments',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Deals Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'monthly_price' => 3200,
            'weekly_price' => 1200,
            'biweekly_price' => 2000,
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254700111333',
            'email' => 'deal-client@example.test',
            'name' => 'Deal Client',
        ]);
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'amount' => 3200,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'pending',
            'activated_at' => null,
            'expires_at' => null,
            'assigned_to' => $user->id,
        ]);

        $this->configureMpesaProxy($platform, $user->id, 'https://payments.exotic-ads.com/api/payments', '76');

        Http::fake([
            'https://payments.exotic-ads.com/api/payments/initiate/' => Http::response([
                'message' => 'Payment initiated',
                'payment_id' => 4455,
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders([
            'Origin' => 'https://crm.exoticnairobi.com',
            'Referer' => 'https://crm.exoticnairobi.com/deals',
            'User-Agent' => 'Mozilla/5.0 Chrome/123.0 Safari/537.36',
        ])->postJson("/api/crm/deals/{$deal->id}/activate", [
            'payment_method' => 'stk',
            'reason' => 'Activate after STK payment',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('payment.status', 'initiated');

        $attempt = PaymentAttempt::query()->latest('id')->firstOrFail();

        $this->assertSame('stk_initiate', $attempt->attempt_type);
        $this->assertSame('success', $attempt->status);
        $this->assertSame('django_stk', $attempt->provider);
        $this->assertSame($user->id, $attempt->created_by);
        $this->assertSame('browser', data_get($attempt->request_meta, 'context_type'));
    }

    public function test_deal_link_activation_defaults_to_market_active_provider(): void
    {
        $platform = $this->createLinkPlatform();
        $product = $this->createProductForPlatform($platform);
        $client = $this->createClientForPlatform($platform, 9201);
        $user = $this->createAuthorizedUser('sales', [$platform->id]);
        $deal = $this->createPendingDeal($platform, $product, $client, $user);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Send market-default payment link',
            'payment_method' => 'link',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.id', $deal->id);

        $payment = Deal::query()->findOrFail($deal->id)->payment()->firstOrFail();
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->latest('id')->firstOrFail();

        $this->assertSame('site_pay_page', $payment->provider_key);
        $this->assertSame('site_pay_page', data_get($payment->raw_payload, 'payment_link_provider'));
        $this->assertSame('site_pay_page', data_get($payment->raw_payload, 'resolved_provider'));
        $this->assertSame('site_pay_page', $attempt->provider);
        $this->assertSame('site_pay_page', data_get($attempt->request_meta, 'requested_provider'));
    }

    public function test_deal_link_activation_uses_selected_payment_link_provider(): void
    {
        $platform = $this->createLinkPlatform();
        $product = $this->createProductForPlatform($platform);
        $client = $this->createClientForPlatform($platform, 9202);
        $user = $this->createAuthorizedUser('sales', [$platform->id]);
        $deal = $this->createPendingDeal($platform, $product, $client, $user);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Route payment through Paystack checkout',
            'payment_method' => 'link',
            'payment_link_provider' => 'paystack_checkout',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.id', $deal->id);

        $payment = Deal::query()->findOrFail($deal->id)->payment()->firstOrFail();
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->latest('id')->firstOrFail();

        $this->assertSame('paystack_checkout', $payment->provider_key);
        $this->assertSame('paystack_checkout', data_get($payment->raw_payload, 'payment_link_provider'));
        $this->assertSame('paystack_checkout', data_get($payment->raw_payload, 'resolved_provider'));
        $this->assertSame('paystack_checkout', $attempt->provider);
        $this->assertSame('paystack_checkout', data_get($attempt->request_meta, 'requested_provider'));
    }

    public function test_deal_free_trial_activation_accepts_configured_pin_and_ignores_legacy_approved_by(): void
    {
        $platform = $this->createProvisioningPlatform();
        $product = $this->createProductForPlatform($platform, 'vip', 3200);
        $client = $this->createClientForPlatform($platform, 9301);
        $user = $this->createAuthorizedUser('admin');
        $deal = $this->createPendingDeal($platform, $product, $client, $user, [
            'plan_type' => 'vip',
            'amount' => 3200,
        ]);

        app(WalletSettingsService::class)->updateFreeTrialPin('4821', $user->id);
        $this->fakeProvisioningApis($platform, $client, [
            'premium' => true,
            'featured' => true,
            'premium_expire' => now()->addDays(14)->timestamp,
            'featured_expire' => now()->addDays(14)->timestamp,
            'escort_expire' => now()->addDays(14)->timestamp,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Redeem approved free trial',
            'payment_method' => 'free_trial',
            'free_trial_pin' => '4821',
            'approved_by' => 'Legacy approver field',
            'duration_days' => 14,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_free_trial', true);

        $deal->refresh();
        $payment = $deal->payment()->firstOrFail();
        $client->refresh();

        $this->assertSame('active', $deal->status);
        $this->assertTrue((bool) $deal->is_free_trial);
        $this->assertNull($deal->free_trial_approved_by);
        $this->assertSame('completed', $payment->status);
        $this->assertSame('pin', data_get($payment->raw_payload, 'approval_mode'));
        $this->assertSame('publish', $client->profile_status);
    }

    public function test_deal_free_trial_activation_rejects_invalid_pin(): void
    {
        $platform = $this->createProvisioningPlatform();
        $product = $this->createProductForPlatform($platform, 'premium', 2400);
        $client = $this->createClientForPlatform($platform, 9302);
        $user = $this->createAuthorizedUser('admin');
        $deal = $this->createPendingDeal($platform, $product, $client, $user, [
            'plan_type' => 'premium',
            'amount' => 2400,
        ]);

        app(WalletSettingsService::class)->updateFreeTrialPin('4821', $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Attempt free trial with wrong PIN',
            'payment_method' => 'free_trial',
            'free_trial_pin' => '1111',
            'duration_days' => 14,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Free-trial PIN is invalid.');

        $deal->refresh();

        $this->assertSame('pending', $deal->status);
        $this->assertNull($deal->payment_id);
        $this->assertDatabaseMissing('payments', [
            'deal_id' => $deal->id,
            'transaction_reference' => 'FREE-TRIAL-' . $deal->id,
        ]);
    }

    private function configureMpesaProxy(Platform $platform, int $userId, string $baseUrl, string $organizationCode): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
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
            'redirect_delay_seconds' => 3,
            'wallet_refresh_rate_limit_seconds' => 15,
            'wallet_refresh_timeout_seconds' => 15,
            'topup_poll_interval_seconds' => 10,
        ], $userId);

        app(WalletSettingsService::class)->savePlatformProviderCredentials($platform, [
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'django_proxy',
                    'payment_service_base_url' => $baseUrl,
                    'organization_code' => $organizationCode,
                ],
            ],
        ], $userId);
    }

    private function createLinkPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Link Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'payment_link_providers' => [
                'active_provider' => 'site_pay_page',
                'providers' => [
                    'site_pay_page' => [
                        'label' => 'Website pay page',
                        'mode' => 'static_url',
                        'enabled' => true,
                        'base_url' => 'https://market.example.test',
                        'path' => '/billing/pay',
                    ],
                    'paystack_checkout' => [
                        'label' => 'Paystack checkout',
                        'mode' => 'proxy_hosted_checkout',
                        'enabled' => true,
                        'wallet_provider_key' => 'paystack',
                        'environment' => 'sandbox',
                    ],
                ],
            ],
        ]);
    }

    private function createProvisioningPlatform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Provisioning Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createProductForPlatform(Platform $platform, string $tier = 'premium', float $monthlyPrice = 3200): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => ucfirst($tier) . ' Plan',
            'display_name' => ucfirst($tier) . ' Plan',
            'slug' => "{$tier}-plan-" . $platform->id,
            'tier' => $tier,
            'weekly_price' => round($monthlyPrice / 4, 2),
            'biweekly_price' => round($monthlyPrice / 2, 2),
            'monthly_price' => $monthlyPrice,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createClientForPlatform(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'wp_user_id' => $wpPostId + 4000,
            'phone_normalized' => '254700' . str_pad((string) $wpPostId, 6, '0', STR_PAD_LEFT),
            'profile_status' => 'private',
        ]);
    }

    private function createAuthorizedUser(string $role = 'admin', array $assignedMarketIds = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $role === 'admin' ? [] : $assignedMarketIds,
            'email' => $role . '-' . uniqid('', true) . '@example.test',
        ]);
    }

    private function createPendingDeal(
        Platform $platform,
        Product $product,
        Client $client,
        User $user,
        array $overrides = []
    ): Deal {
        return Deal::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => $product->tier,
            'amount' => $product->monthly_price,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'pending',
            'activated_at' => null,
            'expires_at' => null,
            'assigned_to' => $user->id,
        ], $overrides));
    }

    private function fakeProvisioningApis(Platform $platform, Client $client, array $profileOverrides = []): void
    {
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');
        $profilePayload = array_merge([
            'wp_post_id' => (int) $client->wp_post_id,
            'wp_user_id' => (int) $client->wp_user_id,
            'name' => (string) $client->name,
            'phone' => (string) $client->phone_normalized,
            'email' => (string) $client->email,
            'city' => (string) $client->city,
            'post_status' => 'publish',
            'premium' => false,
            'featured' => false,
            'verified' => false,
            'main_image_url' => (string) ($client->main_image_url ?? ''),
            'premium_expire' => null,
            'featured_expire' => null,
            'escort_expire' => now()->addDays(30)->timestamp,
            'last_online' => null,
        ], $profileOverrides);

        Http::fake([
            "{$baseUrl}/clients/{$client->wp_post_id}/activate" => Http::response([
                'success' => true,
                'crm_deal_id' => null,
            ], 200),
            "{$baseUrl}/clients/{$client->wp_post_id}" => Http::response($profilePayload, 200),
            '*' => Http::response([], 200),
        ]);
    }
}
