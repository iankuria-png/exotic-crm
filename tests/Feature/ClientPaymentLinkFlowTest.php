<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingRule;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPaymentLinkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_subscribe_creates_awaiting_payment_deal_and_returns_link(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $price = $this->createPrice($product, 3200);
        $client = $this->createClient($platform, 9101);
        $user = $this->createUser($platform);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/payment-link", [
            'mode' => 'quick_subscribe',
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'reason' => 'Start premium checkout from client profile',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.status', 'awaiting_payment')
            ->assertJsonPath('payment.amount', 3200)
            ->assertJsonPath('payment.status', 'initiated')
            ->assertJsonPath('payment_url', 'https://market.example.test/billing/pay');

        $deal = Deal::query()->latest('id')->firstOrFail();

        $this->assertSame($client->id, $deal->client_id);
        $this->assertSame($product->id, $deal->product_id);
        $this->assertSame('awaiting_payment', $deal->status);
        $this->assertNotNull($deal->payment_id);
    }

    public function test_quick_subscribe_preserves_custom_price_duration_metadata(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $price = $this->createPrice($product, 900, '2_days', '2 Days', 2);
        $client = $this->createClient($platform, 9106);
        $user = $this->createUser($platform);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/payment-link", [
            'mode' => 'quick_subscribe',
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'reason' => 'Start custom two day checkout from client profile',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.status', 'awaiting_payment')
            ->assertJsonPath('payment.amount', 900);

        $deal = Deal::query()->latest('id')->firstOrFail();

        $this->assertSame($price->id, $deal->product_price_id);
        $this->assertSame('manual', $deal->duration);
        $this->assertSame(2, $deal->duration_days);
    }

    public function test_existing_awaiting_payment_deal_resends_same_payment_link(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $client = $this->createClient($platform, 9102);
        $user = $this->createUser($platform);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'phone' => $client->phone_normalized,
            'amount' => 3200,
            'currency' => 'KES',
            'status' => 'initiated',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'premium',
            'amount' => 3200,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'awaiting_payment',
            'payment_id' => $payment->id,
            'payment_reference' => $payment->transaction_reference,
            'assigned_to' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/payment-link", [
            'mode' => 'existing_deal',
            'deal_id' => $deal->id,
            'reason' => 'Resend existing checkout link',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.id', $deal->id)
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment_url', 'https://market.example.test/billing/pay');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'send_payment_link',
        ]);
    }

    public function test_existing_failed_payment_creates_replacement_payment_link(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $client = $this->createClient($platform, 9103);
        $user = $this->createUser($platform);
        $failedPayment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'phone' => $client->phone_normalized,
            'amount' => 3200,
            'currency' => 'KES',
            'status' => 'failed',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'premium',
            'amount' => 3200,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'awaiting_payment',
            'payment_id' => $failedPayment->id,
            'payment_reference' => $failedPayment->transaction_reference,
            'assigned_to' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/payment-link", [
            'mode' => 'existing_deal',
            'deal_id' => $deal->id,
            'reason' => 'Replace failed payment link',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('deal.id', $deal->id);

        $deal->refresh();

        $this->assertNotSame($failedPayment->id, $deal->payment_id);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', [
            'id' => $deal->payment_id,
            'deal_id' => $deal->id,
            'status' => 'initiated',
        ]);
    }

    public function test_sms_failure_still_returns_payment_url_for_manual_sharing(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform);
        $price = $this->createPrice($product, 3200);
        $client = $this->createClient($platform, 9104);
        $user = $this->createUser($platform);

        $this->mock(NotificationService::class, function ($mock): void {
            $mock->shouldReceive('sendSms')
                ->once()
                ->andReturn([
                    'success' => false,
                    'status' => 'failed',
                    'provider_response' => 'SMS gateway timeout',
                ]);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/payment-link", [
            'mode' => 'quick_subscribe',
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'reason' => 'Create link even if SMS provider is down',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('payment_url', 'https://market.example.test/billing/pay')
            ->assertJsonPath('sms_result.success', false)
            ->assertJsonPath('sms_result.status', 'failed');

        $deal = Deal::query()->latest('id')->firstOrFail();
        $payment = Payment::query()->findOrFail($deal->payment_id);

        $this->assertSame('awaiting_payment', $deal->status);
        $this->assertSame('initiated', $payment->status);
    }

    public function test_sales_quick_subscribe_ignores_requested_payment_link_provider_override(): void
    {
        $platform = $this->createPlatform();
        $platform->forceFill([
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
                        'environment' => 'production',
                    ],
                ],
            ],
        ])->save();

        $product = $this->createProduct($platform);
        $price = $this->createPrice($product, 3200);
        $client = $this->createClient($platform, 9105);
        $user = $this->createUser($platform);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/clients/{$client->id}/payment-link", [
            'mode' => 'quick_subscribe',
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'payment_link_provider' => 'paystack_checkout',
            'reason' => 'Start premium checkout from client profile',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('payment.provider_key', 'site_pay_page');

        $deal = Deal::query()->latest('id')->firstOrFail();
        $payment = Payment::query()->findOrFail($deal->payment_id);
        $audit = AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->latest('id')->firstOrFail();

        $this->assertSame('site_pay_page', data_get($payment->raw_payload, 'payment_link_provider'));
        $this->assertSame('paystack_checkout', data_get($payment->raw_payload, 'requested_payment_link_provider'));
        $this->assertFalse((bool) data_get($payment->raw_payload, 'provider_override_applied'));
        $this->assertTrue((bool) data_get($payment->raw_payload, 'provider_override_denied'));
        $this->assertSame('paystack_checkout', data_get($audit->before_state, 'requested_provider'));
        $this->assertTrue((bool) data_get($audit->after_state, 'provider_override_denied'));
        $this->assertFalse((bool) data_get($audit->after_state, 'provider_override_applied'));
    }

    public function test_client_detail_payload_exposes_projected_subscription_link_providers_when_legacy_config_is_missing(): void
    {
        $platform = $this->createPlatform();
        $platform->forceFill([
            'payment_link_providers' => null,
        ])->save();

        $profile = BillingProviderProfile::query()->create([
            'provider_type_key' => 'pawapay',
            'profile_name' => 'pawaPay Kenya Sandbox',
            'country_code' => 'KE',
            'market_id' => $platform->id,
            'merchant_scope_json' => ['scope' => 'market'],
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

        $client = $this->createClient($platform, 9106);
        $user = $this->createUser($platform);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/clients/{$client->id}");

        $response->assertOk()
            ->assertJsonPath('platform.payment_link_providers.active_provider', 'pawapay_checkout')
            ->assertJsonPath('platform.payment_link_providers.providers.pawapay_checkout.wallet_provider_key', 'pawapay')
            ->assertJsonPath('platform.payment_link_providers.providers.pawapay_checkout.billing_surface', 'subscription_link');
    }

    private function createPlatform(): Platform
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
                ],
            ],
        ]);
    }

    private function createProduct(Platform $platform): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Plan',
            'display_name' => 'Premium Plan',
            'slug' => 'premium-plan-' . $platform->id,
            'tier' => 'premium',
            'weekly_price' => 800,
            'biweekly_price' => 1600,
            'monthly_price' => 3200,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createPrice(
        Product $product,
        float $amount,
        string $durationKey = '1_month',
        string $durationLabel = '1 Month',
        int $durationDays = 30
    ): ProductPrice
    {
        return ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => $durationKey,
            'duration_label' => $durationLabel,
            'duration_days' => $durationDays,
            'price' => $amount,
            'currency' => 'KES',
            'is_active' => true,
            'sort_order' => 30,
        ]);
    }

    private function createClient(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'wp_user_id' => $wpPostId + 4000,
            'phone_normalized' => '254700' . str_pad((string) $wpPostId, 6, '0', STR_PAD_LEFT),
            'profile_status' => 'private',
        ]);
    }

    private function createUser(Platform $platform): User
    {
        return User::factory()->create([
            'name' => 'Sales ' . Str::random(6),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);
    }
}
