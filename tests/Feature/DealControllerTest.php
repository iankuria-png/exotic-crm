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
}
