<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingDecision;
use App\Models\BillingRoutingRule;
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

        $decision = BillingRoutingDecision::query()->where('payment_id', $payment->id)->latest('id')->first();
        $this->assertNotNull($decision);
        $this->assertSame('subscription_link', $decision->billing_surface);
        $this->assertSame('site_pay_page', $decision->provider_type_key);
        $this->assertSame('direct', $decision->execution_mode);
        $this->assertSame('subscription_link', data_get($decision->snapshot_json, 'execution_family'));
    }

    public function test_sales_deal_link_activation_ignores_requested_payment_link_provider(): void
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
        $audit = AuditLog::query()->where('entity_type', 'deal')->where('entity_id', $deal->id)->latest('id')->firstOrFail();

        $this->assertSame('site_pay_page', $payment->provider_key);
        $this->assertSame('site_pay_page', data_get($payment->raw_payload, 'payment_link_provider'));
        $this->assertSame('paystack_checkout', data_get($payment->raw_payload, 'requested_payment_link_provider'));
        $this->assertFalse((bool) data_get($payment->raw_payload, 'provider_override_applied'));
        $this->assertTrue((bool) data_get($payment->raw_payload, 'provider_override_denied'));
        $this->assertSame('site_pay_page', data_get($payment->raw_payload, 'resolved_provider'));
        $this->assertSame('site_pay_page', $attempt->provider);
        $this->assertSame('site_pay_page', data_get($attempt->request_meta, 'requested_provider'));
        $this->assertSame('paystack_checkout', data_get($audit->after_state, 'requested_payment_link_provider'));
        $this->assertFalse((bool) data_get($audit->after_state, 'payment_link_provider_override_applied'));
        $this->assertTrue((bool) data_get($audit->after_state, 'payment_link_provider_override_denied'));

        $decision = BillingRoutingDecision::query()->where('payment_id', $payment->id)->latest('id')->first();
        $this->assertNotNull($decision);
        $this->assertSame('subscription_link', $decision->billing_surface);
        $this->assertSame('site_pay_page', $decision->provider_type_key);
        $this->assertSame('direct', $decision->execution_mode);
        $this->assertSame('subscription_link', data_get($decision->snapshot_json, 'execution_family'));
    }

    public function test_admin_deal_link_activation_can_apply_payment_link_provider_override(): void
    {
        $platform = $this->createLinkPlatform();
        $product = $this->createProductForPlatform($platform);
        $client = $this->createClientForPlatform($platform, 9204);
        $user = $this->createAuthorizedUser('admin', [$platform->id]);
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
        $audit = AuditLog::query()->where('entity_type', 'deal')->where('entity_id', $deal->id)->latest('id')->firstOrFail();

        $this->assertSame('paystack_checkout', $payment->provider_key);
        $this->assertSame('paystack_checkout', data_get($payment->raw_payload, 'payment_link_provider'));
        $this->assertSame('paystack_checkout', data_get($payment->raw_payload, 'requested_payment_link_provider'));
        $this->assertTrue((bool) data_get($payment->raw_payload, 'provider_override_applied'));
        $this->assertFalse((bool) data_get($payment->raw_payload, 'provider_override_denied'));
        $this->assertSame('admin', data_get($payment->raw_payload, 'provider_override_actor_role'));
        $this->assertSame('paystack_checkout', data_get($audit->after_state, 'payment_link_provider'));
        $this->assertSame('paystack_checkout', data_get($audit->after_state, 'requested_payment_link_provider'));
        $this->assertTrue((bool) data_get($audit->after_state, 'payment_link_provider_override_applied'));
        $this->assertFalse((bool) data_get($audit->after_state, 'payment_link_provider_override_denied'));

        $decision = BillingRoutingDecision::query()->where('payment_id', $payment->id)->latest('id')->first();
        $this->assertNotNull($decision);
        $this->assertSame('proxy_hosted_checkout', $decision->billing_surface);
        $this->assertSame('paystack', $decision->provider_type_key);
        $this->assertSame('proxy', $decision->execution_mode);
        $this->assertSame('hosted_redirect', data_get($decision->snapshot_json, 'execution_family'));
    }

    public function test_deal_link_activation_uses_projected_payment_link_provider_when_shadow_read_is_enabled(): void
    {
        config(['billing.shadow_read.enabled' => true]);

        $platform = $this->createLinkPlatform();
        $platform->forceFill([
            'payment_link_providers' => null,
        ])->save();

        $this->createProjectedProxyHostedCheckoutForPlatform($platform, 'paystack', 'Projected Paystack Production', 'production');

        $product = $this->createProductForPlatform($platform);
        $client = $this->createClientForPlatform($platform, 9203);
        $user = $this->createAuthorizedUser('sales', [$platform->id]);
        $deal = $this->createPendingDeal($platform, $product, $client, $user);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Route payment through projected checkout config',
            'payment_method' => 'link',
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

        $decision = BillingRoutingDecision::query()->where('payment_id', $payment->id)->latest('id')->first();
        $this->assertNotNull($decision);
        $this->assertSame('proxy_hosted_checkout', $decision->billing_surface);
        $this->assertSame('paystack', $decision->provider_type_key);
        $this->assertSame('proxy', $decision->execution_mode);
        $this->assertSame('paystack_checkout', data_get($decision->snapshot_json, 'provider_key'));
    }

    public function test_deal_link_activation_uses_projected_subscription_link_provider_when_legacy_link_config_is_missing(): void
    {
        $platform = $this->createLinkPlatform();
        $platform->forceFill([
            'payment_link_providers' => null,
        ])->save();

        $this->createProjectedSubscriptionLinkForPlatform($platform, 'pawapay', 'pawaPay Kenya Sandbox', 'sandbox');

        $product = $this->createProductForPlatform($platform);
        $client = $this->createClientForPlatform($platform, 9205);
        $user = $this->createAuthorizedUser('sales', [$platform->id]);
        $deal = $this->createPendingDeal($platform, $product, $client, $user);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Route payment through pawaPay subscription link binding',
            'payment_method' => 'link',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.id', $deal->id);

        $payment = Deal::query()->findOrFail($deal->id)->payment()->firstOrFail();
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->latest('id')->firstOrFail();

        $this->assertSame('pawapay_checkout', $payment->provider_key);
        $this->assertSame('pawapay_checkout', data_get($payment->raw_payload, 'payment_link_provider'));
        $this->assertSame('pawapay_checkout', data_get($payment->raw_payload, 'resolved_provider'));
        $this->assertSame('pawapay_checkout', $attempt->provider);
        $this->assertSame('pawapay_checkout', data_get($attempt->request_meta, 'requested_provider'));
        $this->assertNotEmpty(data_get($payment->payment_data, 'link_proxy.token_hash'));
        $this->assertSame('pawapay', data_get($payment->payment_data, 'link_proxy.provider_key'));

        $decision = BillingRoutingDecision::query()->where('payment_id', $payment->id)->latest('id')->first();
        $this->assertNotNull($decision);
        $this->assertSame('subscription_link', $decision->billing_surface);
        $this->assertSame('pawapay', $decision->provider_type_key);
        $this->assertSame('direct', $decision->execution_mode);
        $this->assertSame('subscription_link', data_get($decision->snapshot_json, 'execution_family'));
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

    public function test_deal_manual_activation_applies_discount_with_valid_pin(): void
    {
        $platform = $this->createProvisioningPlatform();
        $product = $this->createProductForPlatform($platform, 'vip', 3200);
        $client = $this->createClientForPlatform($platform, 9303);
        $user = $this->createAuthorizedUser('admin');
        $deal = $this->createPendingDeal($platform, $product, $client, $user, [
            'plan_type' => 'vip',
            'amount' => 3200,
        ]);

        app(WalletSettingsService::class)->updateDiscountPin('4821', $user->id);
        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 25,
            ],
        ], $user->id);
        $this->fakeProvisioningApis($platform, $client);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Activate with approved retention discount',
            'payment_method' => 'manual',
            'payment_reference' => 'MPESA-9303',
            'discount_percentage' => 20,
            'discount_pin' => '4821',
            'duration_days' => 30,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'active');

        $deal->refresh();
        $payment = $deal->payment()->firstOrFail();

        $this->assertSame('active', $deal->status);
        $this->assertSame(2560.0, (float) $deal->amount);
        $this->assertSame(3200.0, (float) $deal->original_amount);
        $this->assertSame(20.0, (float) $deal->discount_percentage);
        $this->assertSame($user->id, (int) $deal->discount_approved_by);
        $this->assertSame('agent_manual', $deal->discount_source);
        $this->assertSame(2560.0, (float) $payment->amount);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'deal_discount',
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'actor_id' => $user->id,
        ]);
    }

    public function test_deal_manual_activation_preserves_exact_discount_payable_amount(): void
    {
        $platform = $this->createProvisioningPlatform();
        $product = $this->createProductForPlatform($platform, 'vip', 12000);
        $client = $this->createClientForPlatform($platform, 9313);
        $user = $this->createAuthorizedUser('admin');
        $deal = $this->createPendingDeal($platform, $product, $client, $user, [
            'plan_type' => 'vip',
            'amount' => 12000,
        ]);

        app(WalletSettingsService::class)->updateDiscountPin('4821', $user->id);
        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 25,
            ],
        ], $user->id);
        $this->fakeProvisioningApis($platform, $client);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Activate with exact payable discount',
            'payment_method' => 'manual',
            'payment_reference' => 'MPESA-9313',
            'discount_percentage' => 1.67,
            'discount_payable_amount' => 11800,
            'discount_pin' => '4821',
            'duration_days' => 30,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'active');

        $deal->refresh();
        $payment = $deal->payment()->firstOrFail();

        $this->assertSame(11800.0, (float) $deal->amount);
        $this->assertSame(12000.0, (float) $deal->original_amount);
        $this->assertSame(1.67, (float) $deal->discount_percentage);
        $this->assertSame(11800.0, (float) $payment->amount);
    }

    public function test_deal_activation_rejects_discount_above_market_max(): void
    {
        $platform = $this->createProvisioningPlatform();
        $product = $this->createProductForPlatform($platform, 'premium', 2400);
        $client = $this->createClientForPlatform($platform, 9304);
        $user = $this->createAuthorizedUser('admin');
        $deal = $this->createPendingDeal($platform, $product, $client, $user, [
            'plan_type' => 'premium',
            'amount' => 2400,
        ]);

        app(WalletSettingsService::class)->updateDiscountPin('4821', $user->id);
        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 10,
            ],
        ], $user->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'reason' => 'Attempt unsupported discount',
            'payment_method' => 'manual',
            'payment_reference' => 'MPESA-9304',
            'discount_percentage' => 15,
            'discount_pin' => '4821',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Discount exceeds the configured market maximum of 10%.');

        $deal->refresh();

        $this->assertSame('pending', $deal->status);
        $this->assertSame(2400.0, (float) $deal->amount);
        $this->assertNull($deal->payment_id);
    }

    public function test_deal_renew_uses_original_amount_as_discount_base(): void
    {
        $platform = $this->createLinkPlatform();
        $product = $this->createProductForPlatform($platform, 'vip', 3200);
        $client = $this->createClientForPlatform($platform, 9305);
        $user = $this->createAuthorizedUser('admin');
        $deal = $this->createPendingDeal($platform, $product, $client, $user, [
            'plan_type' => 'vip',
            'amount' => 2400,
            'original_amount' => 3200,
            'discount_percentage' => 25,
            'discount_approved_by' => $user->id,
            'status' => 'expired',
            'activated_at' => now()->subDays(60),
            'expires_at' => now()->subDay(),
        ]);

        app(WalletSettingsService::class)->updateDiscountPin('4821', $user->id);
        app(WalletSettingsService::class)->updateDiscountConfig([
            'max_percentage_by_platform' => [
                (string) $platform->id => 15,
            ],
        ], $user->id);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/renew", [
            'reason' => 'Renew with a fresh approved discount',
            'payment_method' => 'link',
            'additional_days' => 30,
            'payment_link_provider' => 'paystack_checkout',
            'discount_percentage' => 10,
            'discount_pin' => '4821',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.status', 'awaiting_payment');

        $newDeal = Deal::query()
            ->where('client_id', $client->id)
            ->where('id', '!=', $deal->id)
            ->latest('id')
            ->firstOrFail();
        $payment = $newDeal->payment()->firstOrFail();
        $deal->refresh();

        $this->assertSame('expired', $deal->status);
        $this->assertSame('awaiting_payment', $newDeal->status);
        $this->assertSame(2880.0, (float) $newDeal->amount);
        $this->assertSame(3200.0, (float) $newDeal->original_amount);
        $this->assertSame(10.0, (float) $newDeal->discount_percentage);
        $this->assertSame($user->id, (int) $newDeal->discount_approved_by);
        $this->assertSame('agent_manual', $newDeal->discount_source);
        $this->assertSame(2880.0, (float) $payment->amount);
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

    private function createProjectedProxyHostedCheckoutForPlatform(
        Platform $platform,
        string $providerTypeKey,
        string $profileName,
        string $environment
    ): void {
        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => $providerTypeKey,
            'profile_name' => $profileName,
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => $environment,
            'config_json' => [],
            'secrets_json' => [],
            'active' => true,
        ]);

        $binding = BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
            'billing_surface' => 'proxy_hosted_checkout',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => true,
            'execution_mode' => 'proxy',
            'priority' => 10,
            'fallback_group' => 'checkout',
            'restriction_json' => [],
        ]);

        BillingRoutingRule::query()->create([
            'market_id' => $platform->id,
            'billing_surface' => 'proxy_hosted_checkout',
            'primary_binding_id' => $binding->id,
            'fallback_strategy_json' => ['providers' => []],
            'risk_policy_json' => ['mode' => 'proxy_preferred'],
            'active' => true,
        ]);
    }

    private function createProjectedSubscriptionLinkForPlatform(
        Platform $platform,
        string $providerTypeKey,
        string $profileName,
        string $environment
    ): void {
        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => $providerTypeKey,
            'profile_name' => $profileName,
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
            'environment' => $environment,
            'config_json' => [
                'base_url' => 'https://api.sandbox.pawapay.io',
                'callback_base_url' => 'https://billing.example.test',
            ],
            'secrets_json' => [
                'api_key' => 'pawapay-sandbox-key',
            ],
            'active' => true,
        ]);

        $binding = BillingMarketProviderBinding::query()->create([
            'market_id' => $platform->id,
            'provider_profile_id' => $profile->id,
            'billing_surface' => 'subscription_link',
            'enabled' => true,
            'operator_enabled' => true,
            'self_service_enabled' => false,
            'execution_mode' => 'direct',
            'priority' => 10,
            'fallback_group' => 'subscription-link',
            'restriction_json' => [],
        ]);

        BillingRoutingRule::query()->create([
            'market_id' => $platform->id,
            'billing_surface' => 'subscription_link',
            'primary_binding_id' => $binding->id,
            'fallback_strategy_json' => ['providers' => []],
            'risk_policy_json' => ['mode' => 'direct'],
            'active' => true,
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
