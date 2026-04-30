<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientWalletBalance;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\PaymentCompletionService;
use App\Services\SubscriptionProvisioningService;
use App\Services\DealPaymentService;
use App\Services\WalletCheckoutService;
use App\Services\WalletService;
use App\Services\WalletSettingsService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class MultiCurrencyWalletSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_service_keeps_currency_ledgers_isolated_and_replays_per_client_currency_key(): void
    {
        $platform = $this->makeDrcPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wallet_balance' => 0,
            'wallet_currency' => 'CDF',
            'wp_post_id' => 0,
        ]);

        $service = app(WalletService::class);

        $cdfCredit = $service->credit($client, 'CDF', 1000, [
            'idempotency_key' => 'shared-topup-key',
            'reference_type' => 'wallet_topup',
            'reference_id' => 10,
        ]);
        $usdCredit = $service->credit($client, 'USD', 10, [
            'idempotency_key' => 'shared-topup-key',
            'reference_type' => 'wallet_topup',
            'reference_id' => 11,
        ]);
        $usdReplay = $service->credit($client, 'USD', 10, [
            'idempotency_key' => 'shared-topup-key',
            'reference_type' => 'wallet_topup',
            'reference_id' => 11,
        ]);

        $this->assertFalse($cdfCredit['replayed']);
        $this->assertFalse($usdCredit['replayed']);
        $this->assertTrue($usdReplay['replayed']);
        $this->assertSame(2, ClientWalletBalance::query()->where('client_id', $client->id)->count());
        $this->assertSame('1000.00', ClientWalletBalance::query()->where('client_id', $client->id)->where('currency', 'CDF')->value('balance'));
        $this->assertSame('10.00', ClientWalletBalance::query()->where('client_id', $client->id)->where('currency', 'USD')->value('balance'));
        $this->assertSame('1000.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
        $this->assertSame('CDF', $client->fresh()->wallet_currency);

        $summary = $service->summary($client->fresh(['platform']));
        $this->assertSame([
            ['currency' => 'CDF', 'balance' => '1000.00'],
            ['currency' => 'USD', 'balance' => '10.00'],
        ], $summary['balances']);
    }

    public function test_multi_currency_pricing_requires_explicit_currency_and_never_falls_back(): void
    {
        $platform = $this->makeDrcPlatform();
        $product = $this->makeDrcProduct($platform, includeUsdPrice: false);

        $service = app(WalletCheckoutService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('currency required for multi-currency market');
        $service->resolveSubscriptionPricing($product, 'monthly');
    }

    public function test_multi_currency_pricing_fails_clearly_for_missing_usd_but_single_currency_mode_keeps_cdf_default(): void
    {
        $service = app(WalletCheckoutService::class);

        $multiCurrencyPlatform = $this->makeDrcPlatform();
        $multiCurrencyProduct = $this->makeDrcProduct($multiCurrencyPlatform, includeUsdPrice: false);

        try {
            $service->resolveSubscriptionPricing($multiCurrencyProduct, 'monthly', 'USD');
            $this->fail('Expected missing USD pricing to throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('USD price not configured for this plan/duration', $exception->getMessage());
        }

        $singleCurrencyPlatform = $this->makeDrcPlatform(enabled: false);
        $singleCurrencyProduct = $this->makeDrcProduct($singleCurrencyPlatform, includeUsdPrice: false);
        $pricing = $service->resolveSubscriptionPricing($singleCurrencyProduct, 'monthly');

        $this->assertSame('CDF', $pricing['currency']);
        $this->assertSame('140000.00', $pricing['amount']);
    }

    public function test_wallet_checkout_uses_selected_currency_and_reports_currency_specific_shortfalls(): void
    {
        $platform = $this->makeDrcPlatform();
        $product = $this->makeDrcProduct($platform, includeUsdPrice: true);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wallet_balance' => 0,
            'wallet_currency' => 'CDF',
            'wp_post_id' => 0,
        ]);

        app(WalletService::class)->credit($client, 'USD', 50, [
            'idempotency_key' => 'seed-usd-wallet',
            'reference_type' => 'wallet_topup',
            'reference_id' => 21,
        ]);

        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'currency' => 'USD',
            'duration' => 'monthly',
            'status' => 'pending',
        ]);

        $this->mock(SubscriptionProvisioningService::class, function ($mock) use ($deal) {
            $mock->shouldReceive('provisionCompletedPayment')->once()->andReturn($deal);
        });

        $checkout = app(WalletCheckoutService::class)->payForSubscriptionFromWallet(
            $client,
            $product,
            'monthly',
            'wallet-usd-subscription-1',
            ['currency' => 'USD']
        );

        $this->assertSame('USD', $checkout['payment']->currency);
        $this->assertSame('0.00', ClientWalletBalance::query()->where('client_id', $client->id)->where('currency', 'USD')->value('balance'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Top up your CDF wallet');
        app(WalletCheckoutService::class)->payForSubscriptionFromWallet(
            $client->fresh(['platform']),
            $product,
            'monthly',
            'wallet-cdf-subscription-1',
            ['currency' => 'CDF']
        );
    }

    public function test_payment_completion_credits_the_payment_currency_wallet_row(): void
    {
        $platform = $this->makeDrcPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wallet_balance' => 0,
            'wallet_currency' => 'CDF',
            'wp_post_id' => 0,
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => 'CDF',
        ]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'amount' => 25,
            'currency' => 'USD',
            'status' => 'initiated',
            'purpose' => 'wallet_topup',
            'completed_at' => null,
            'wallet_transaction_id' => null,
            'provider_key' => 'pawapay',
            'provider_environment' => 'production',
            'source' => 'gateway',
            'payment_data' => [],
        ]);

        $result = app(PaymentCompletionService::class)->completeTopupPayment($payment, ['provider' => 'pawapay'], []);

        $this->assertTrue($result['credited']);
        $this->assertSame('25.00', ClientWalletBalance::query()->where('client_id', $client->id)->where('currency', 'USD')->value('balance'));
        $this->assertSame('0.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
    }

    public function test_multi_currency_renewals_require_an_explicit_deal_currency(): void
    {
        $platform = $this->makeDrcPlatform();
        $product = $this->makeDrcProduct($platform, includeUsdPrice: true);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'currency' => '',
            'duration' => 'monthly',
            'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deal currency is required for multi-currency renewal.');

        app(WalletCheckoutService::class)->resolveDealRenewalPricing($deal);
    }

    public function test_multi_currency_deal_creation_requires_an_explicit_price_selection(): void
    {
        $platform = $this->makeDrcPlatform();
        $product = $this->makeDrcProduct($platform, includeUsdPrice: true);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9101,
            'wallet_currency' => 'CDF',
        ]);

        try {
            app(DealPaymentService::class)->createPendingDealFromCatalog(
                $client,
                (int) $product->id,
                null,
                'monthly',
                1,
                null
            );
            $this->fail('Expected multi-currency deal creation without price selection to throw.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Select an explicit pricing option for multi-currency deals.',
                $exception->errors()['product_price_id'][0] ?? null
            );
        }
    }

    public function test_multi_currency_deal_updates_require_an_explicit_price_selection(): void
    {
        $platform = $this->makeDrcPlatform();
        $product = $this->makeDrcProduct($platform, includeUsdPrice: true);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9102,
            'wallet_currency' => 'CDF',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'currency' => 'USD',
            'duration' => 'monthly',
            'amount' => 50,
            'status' => 'pending',
        ]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/crm/deals/{$deal->id}", [
            'product_id' => $product->id,
            'product_price_id' => null,
            'duration' => 'monthly',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['product_price_id']);
    }

    public function test_single_currency_mode_hides_stale_non_effective_balance_rows(): void
    {
        $platform = $this->makeDrcPlatform(enabled: false);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9103,
            'wallet_balance' => 1000,
            'wallet_currency' => 'CDF',
        ]);

        ClientWalletBalance::query()->create([
            'client_id' => $client->id,
            'currency' => 'CDF',
            'balance' => '1000.00',
        ]);
        ClientWalletBalance::query()->create([
            'client_id' => $client->id,
            'currency' => 'USD',
            'balance' => '25.00',
        ]);

        $summary = app(WalletService::class)->summary($client->fresh(['platform']));

        $this->assertSame([
            ['currency' => 'CDF', 'balance' => '1000.00'],
        ], $summary['balances']);
    }

    public function test_wallet_sync_payload_includes_balances_array_and_primary_mirror(): void
    {
        app(WalletSettingsService::class)->saveSystemConfig([
            'mode' => 'sandbox',
        ]);

        $platform = $this->makeDrcPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9988,
            'wp_user_id' => 8877,
            'wallet_balance' => 0,
            'wallet_currency' => 'CDF',
        ]);

        app(WalletService::class)->credit($client, 'CDF', 140000, [
            'idempotency_key' => 'sync-cdf-credit',
            'reference_type' => 'wallet_topup',
            'reference_id' => 31,
        ]);
        app(WalletService::class)->credit($client, 'USD', 50, [
            'idempotency_key' => 'sync-usd-credit',
            'reference_type' => 'wallet_topup',
            'reference_id' => 32,
        ]);

        Http::fake([
            'https://*.test/wp-json/exotic-crm-sync/v1/clients/9988/wallet-balance' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $result = app(WalletSyncService::class)->syncClientBalance($client->fresh(['platform']));

        $this->assertSame('synced', $result['status']);
        $this->assertSame('140000.00', $result['payload']['balance']);
        $this->assertSame('CDF', $result['payload']['currency']);
        $this->assertSame([
            ['currency' => 'CDF', 'balance' => '140000.00'],
            ['currency' => 'USD', 'balance' => '50.00'],
        ], $result['payload']['balances']);

        Http::assertSent(function (Request $request) {
            return $request['currency'] === 'CDF'
                && $request['balance'] === '140000.00'
                && $request['balances'] === [
                    ['currency' => 'CDF', 'balance' => '140000.00'],
                    ['currency' => 'USD', 'balance' => '50.00'],
                ];
        });
    }

    private function makeDrcPlatform(bool $enabled = true): Platform
    {
        return Platform::factory()->create([
            'country' => 'DRC',
            'currency_code' => 'CDF',
            'supported_currencies' => ['CDF', 'USD'],
            'multi_currency_wallet_enabled' => $enabled,
            'wallet_settings' => [
                'enabled' => true,
                'currency_code' => 'CDF',
            ],
        ]);
    }

    private function makeDrcProduct(Platform $platform, bool $includeUsdPrice = true): Product
    {
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VIP',
            'display_name' => 'VIP',
            'slug' => 'vip',
            'tier' => 'vip',
            'currency' => 'CDF',
            'monthly_price' => 140000,
            'biweekly_price' => 70000,
            'weekly_price' => 35000,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 140000,
            'currency' => 'CDF',
            'is_active' => true,
        ]);

        if ($includeUsdPrice) {
            ProductPrice::factory()->create([
                'product_id' => $product->id,
                'duration_key' => '1_month',
                'duration_label' => '1 Month',
                'duration_days' => 30,
                'price' => 50,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        }

        return $product->fresh(['activePrices', 'platform']);
    }
}
