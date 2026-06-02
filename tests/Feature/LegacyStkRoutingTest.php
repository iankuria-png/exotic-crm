<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyStkRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_queue_retry_uses_market_proxy_settings_and_records_a_timestamped_audit_log(): void
    {
        config([
            'services.django.base_url' => 'https://legacy-default.example.test/api/payments',
        ]);

        $platform = $this->createPlatform('Kenya');
        $product = $this->createProduct($platform);
        $admin = $this->createUser('admin');
        $payment = $this->createPayment($platform, $product, [
            'status' => 'failed',
            'phone' => '254700111222',
            'amount' => 3000,
            'duration' => 'monthly',
        ]);

        $this->configureMpesaProxy($platform, $admin->id, 'https://payments.exotic-ads.com/api/payments', '99');

        Http::fake([
            'https://payments.exotic-ads.com/api/payments/initiate/' => Http::response([
                'message' => 'Payment initiated',
                'payment_id' => 999,
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/retry-stk", [
            'reason' => 'Retry STK from payment queue',
        ]);

        $response->assertOk()
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('message', 'STK push sent. Customer should complete the request on their phone.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://payments.exotic-ads.com/api/payments/initiate/'
                && data_get($request->data(), 'organization_code') === '99';
        });

        $audit = AuditLog::query()
            ->where('action', 'payment_retry_stk')
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($audit->created_at);
        $this->assertSame('pending', data_get($audit->after_state, 'after_status'));
        $this->assertSame('https://payments.exotic-ads.com/api/payments', data_get($audit->after_state, 'upstream_url'));
    }

    public function test_payment_queue_retry_surfaces_upstream_timeout_in_response_and_audit_log(): void
    {
        $platform = $this->createPlatform('Kenya');
        $product = $this->createProduct($platform);
        $admin = $this->createUser('admin');
        $payment = $this->createPayment($platform, $product, [
            'status' => 'failed',
            'phone' => '254700111222',
            'amount' => 3000,
            'duration' => 'monthly',
        ]);

        $this->configureMpesaProxy($platform, $admin->id, 'https://payments.exotic-ads.com/api/payments', '76');

        Http::fake([
            'https://payments.exotic-ads.com/api/payments/initiate/' => Http::response('Connection timed out', 522),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/retry-stk", [
            'reason' => 'Retry STK from payment queue',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Configured payment service timed out: https://payments.exotic-ads.com/api/payments');

        $audit = AuditLog::query()
            ->where('action', 'payment_retry_stk')
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($audit->created_at);
        $this->assertSame('failed', data_get($audit->after_state, 'after_status'));
        $this->assertSame(522, data_get($audit->after_state, 'http_status'));
        $this->assertSame('https://payments.exotic-ads.com/api/payments', data_get($audit->after_state, 'upstream_url'));
    }

    public function test_initiate_stk_payment_records_attempt_with_browser_context(): void
    {
        config([
            'services.django.base_url' => 'https://legacy-default.example.test/api/payments',
        ]);

        $platform = $this->createPlatform('Kenya');
        $product = $this->createProduct($platform);
        $admin = $this->createUser('admin');

        $this->configureMpesaProxy($platform, $admin->id, 'https://payments.exotic-ads.com/api/payments', '76');

        Http::fake([
            'https://payments.exotic-ads.com/api/payments/initiate/' => Http::response([
                'message' => 'Payment initiated',
                'payment_id' => 321,
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Origin' => 'https://www.exoticnairobi.com',
            'Referer' => 'https://www.exoticnairobi.com/escort/ada',
            'User-Agent' => 'Mozilla/5.0 Chrome/123.0 Safari/537.36',
        ])->postJson('/api/initiate-stk-payment', [
            'product_id' => $product->id,
            'platform_id' => $platform->id,
            'user_id' => 7788,
            'phone' => '254700111222',
            'duration' => 'monthly',
            'first_name' => 'Ada',
            'last_name' => 'Client',
            'email' => 'ada@example.test',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true);

        /** @var Payment $payment */
        $payment = Payment::query()->latest('id')->firstOrFail();
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->latest('id')->firstOrFail();

        $this->assertSame('stk_initiate', $attempt->attempt_type);
        $this->assertSame('success', $attempt->status);
        $this->assertSame('django_stk', $attempt->provider);
        $this->assertSame('browser', data_get($attempt->request_meta, 'context_type'));
        $this->assertSame('https://www.exoticnairobi.com', data_get($attempt->request_meta, 'origin_url'));
    }

    public function test_manual_stk_push_records_attempt(): void
    {
        config([
            'services.django.base_url' => 'https://legacy-default.example.test/api/payments',
        ]);

        $platform = $this->createPlatform('Kenya');
        $product = $this->createProduct($platform);
        $admin = $this->createUser('admin');

        $this->configureMpesaProxy($platform, $admin->id, 'https://payments.exotic-ads.com/api/payments', '76');

        Http::fake([
            'https://payments.exotic-ads.com/api/payments/initiate/' => Http::response([
                'message' => 'Payment initiated',
                'payment_id' => 654,
            ], 200),
        ]);

        $response = $this->withHeaders([
            'Origin' => 'https://www.exoticnairobi.com',
            'Referer' => 'https://www.exoticnairobi.com/escort/grace',
            'User-Agent' => 'Mozilla/5.0 Chrome/123.0 Safari/537.36',
        ])->postJson('/api/initiate-payment', [
            'product_id' => $product->id,
            'platform_id' => $platform->id,
            'user_id' => 8899,
            'phone' => '0700111222',
            'duration' => 'monthly',
            'first_name' => 'Grace',
            'last_name' => 'Client',
            'email' => 'grace@example.test',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('phone', '254700111222');

        /** @var Payment $payment */
        $payment = Payment::query()->latest('id')->firstOrFail();
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->latest('id')->firstOrFail();

        $this->assertSame('stk_initiate', $attempt->attempt_type);
        $this->assertSame('success', $attempt->status);
        $this->assertSame('django_stk', $attempt->provider);
        $this->assertSame('browser', data_get($attempt->request_meta, 'context_type'));
    }

    public function test_initiate_preflight_exception_records_failed_attempt(): void
    {
        config([
            'services.django.base_url' => '',
        ]);

        $platform = $this->createPlatform('Kenya');
        $product = $this->createProduct($platform);
        $admin = $this->createUser('admin');

        $this->configureMpesaProxy($platform, $admin->id, '', '76');

        $response = $this->withHeaders([
            'Origin' => 'https://www.exoticnairobi.com',
            'Referer' => 'https://www.exoticnairobi.com/escort/mary',
            'User-Agent' => 'Mozilla/5.0 Chrome/123.0 Safari/537.36',
        ])->postJson('/api/initiate-stk-payment', [
            'product_id' => $product->id,
            'platform_id' => $platform->id,
            'user_id' => 9900,
            'phone' => '254700111222',
            'duration' => 'monthly',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('status', false);

        /** @var Payment $payment */
        $payment = Payment::query()->latest('id')->firstOrFail();
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->latest('id')->firstOrFail();

        $this->assertSame('skipped', $attempt->status);
        $this->assertSame('no_recipients', $attempt->error_code);
        $this->assertSame('mpesa_stk', $attempt->provider);
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_retry_stk_prefers_projected_runtime_mode_over_legacy_system_mode(): void
    {
        config([
            'billing.shadow_read.enabled' => true,
            'services.django.base_url' => 'https://legacy-default.example.test/api/payments',
        ]);

        $platform = $this->createPlatform('Kenya');
        $product = $this->createProduct($platform);
        $admin = $this->createUser('admin');
        $payment = $this->createPayment($platform, $product, [
            'status' => 'failed',
            'phone' => '254700111222',
            'amount' => 3000,
            'duration' => 'monthly',
            'provider_environment' => null,
        ]);

        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'production',
            'default_currency' => 'KES',
        ], $admin->id);

        app(WalletSettingsService::class)->savePlatformProviderCredentials($platform, [
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'django_proxy',
                    'payment_service_base_url' => 'https://projected-sandbox.example.test/api/payments',
                    'organization_code' => '76',
                ],
            ],
        ], $admin->id);

        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'paystack',
            'profile_name' => 'Projected Wallet Sandbox',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => 'sandbox',
            'config_json' => [
                'public_key' => 'pk_projected_wallet',
            ],
            'secrets_json' => [
                'secret_key' => 'sk_projected_wallet',
            ],
            'active' => true,
        ]);

        BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
            'billing_surface' => 'wallet_funding',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => true,
            'execution_mode' => 'direct',
            'priority' => 10,
            'fallback_group' => 'legacy-wallet',
            'restriction_json' => [
                'min_amount' => '250.00',
                'max_amount' => '9000.00',
            ],
        ]);

        $runtimeWallet = app(WalletSettingsService::class)->runtimePlatformConfig($platform->fresh());
        $this->assertSame('sandbox', data_get($runtimeWallet, 'mode_override'));
        $this->assertSame('disabled', data_get($runtimeWallet, 'effective_mode'));

        Http::fake([
            'https://projected-sandbox.example.test/api/payments/initiate/' => Http::response([
                'message' => 'Payment initiated',
                'payment_id' => 4321,
            ], 200),
            'https://legacy-default.example.test/api/payments/initiate/' => Http::response([
                'message' => 'Should not be used',
            ], 500),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/retry-stk", [
            'reason' => 'Retry STK from projected runtime mode',
        ]);

        $response->assertOk()
            ->assertJsonPath('payment.status', 'pending');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://projected-sandbox.example.test/api/payments/initiate/'
                && data_get($request->data(), 'organization_code') === '76';
        });
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

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.example.test',
            'country' => $name,
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Escort',
            'slug' => 'premium-' . Str::lower(Str::random(6)),
            'monthly_price' => 3000,
            'weekly_price' => 1000,
            'biweekly_price' => 1800,
            'currency' => 'KES',
            'is_active' => true,
            'is_archived' => false,
        ]);
    }

    private function createPayment(Platform $platform, Product $product, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'user_id' => 1,
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'phone' => '254700000111',
            'amount' => 3000,
            'currency' => 'KES',
            'duration' => 'monthly',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => 'TX-' . Str::upper(Str::random(10)),
            'status' => 'initiated',
        ], $overrides));
    }

    private function createUser(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => strtolower($role) . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => [],
            'status' => 'active',
        ]);
    }
}
