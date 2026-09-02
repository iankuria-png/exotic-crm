<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\User;
use App\Models\Product;
use App\Services\PaymentLinkService;
use App\Services\WalletSettingsService;
use App\Services\WalletPayloadService;
use App\Services\BillingModeService;
use App\Models\BillingSubscriptionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the shape of the billing payloads WordPress and the CRM UI depend on.
 *
 * These used to be recorders: each test hit an endpoint and wrote the response
 * straight over its baseline, so a genuine regression in these payloads could
 * never fail the suite, and every run left the working tree dirty because the
 * payloads carry a wall-clock timestamp and a faker-generated provider reference.
 *
 * They now assert. The clock is frozen and the volatile references are pinned, so
 * a diff means the payload actually changed. When a change is intended, re-record
 * with:
 *
 *     UPDATE_BILLING_SNAPSHOTS=1 php artisan test --filter=BaselineDiagnosticsSnapshotTest
 *
 * and commit the updated baselines alongside the change that caused them.
 */
class BaselineDiagnosticsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frozen so every now()-derived field in these payloads is reproducible.
     * Matches the hardcoded instants the WordPress sync fixtures already use.
     */
    private const SNAPSHOT_NOW = '2026-04-03T12:00:00+00:00';

    /** Pinned so the faker-generated TXN-####?? reference stops churning the baselines. */
    private const SNAPSHOT_TRANSACTION_REFERENCE = 'TXN-BASELINE-0001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::SNAPSHOT_NOW));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * Keys whose value is unreproducible by design and must not be pinned.
     *
     * A link-proxy token hash is derived from a freshly minted single-use secret;
     * making it reproducible would defeat its purpose. Only its presence and shape
     * matter here, so it is redacted before comparison.
     */
    private const REDACTED_KEYS = ['token_hash'];

    /**
     * Normalise a payload so only its shape and its meaningful values are compared.
     *
     * Anything keyed `*_ms` is a measured wall-clock latency — it varies run to run
     * and on CI hardware, and pinning it would make the suite flaky rather than
     * strict. Nulls are preserved, because "no measurement" is itself meaningful.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function normaliseVolatile(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normaliseVolatile($value);

                continue;
            }

            if ($value === null) {
                continue;
            }

            if (in_array($key, self::REDACTED_KEYS, true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_string($key) && str_ends_with($key, '_ms')) {
                $payload[$key] = 0;
            }
        }

        return $payload;
    }

    /**
     * Compare a payload against its committed baseline.
     *
     * Encoding must stay byte-identical to how the baselines were written
     * (JSON_PRETTY_PRINT, escaped slashes, no trailing newline) so a re-record
     * produces no incidental diff.
     *
     * @param  array<mixed>  $payload
     */
    private function assertMatchesBaseline(string $name, array $payload): void
    {
        $path = base_path("tests/Feature/Billing/snapshots/{$name}.json");
        $encoded = json_encode($this->normaliseVolatile($payload), JSON_PRETTY_PRINT);

        if (filter_var(env('UPDATE_BILLING_SNAPSHOTS', false), FILTER_VALIDATE_BOOLEAN)) {
            file_put_contents($path, $encoded);
            $this->assertTrue(true, "Re-recorded {$name}.");

            return;
        }

        $this->assertFileExists(
            $path,
            "Missing baseline {$name}.json. Create it with UPDATE_BILLING_SNAPSHOTS=1."
        );

        $this->assertSame(
            file_get_contents($path),
            $encoded,
            "The {$name} payload no longer matches its baseline. WordPress and the CRM UI "
            ."read this shape, so treat a diff as a breaking change until proven otherwise. "
            ."If the change is intended, re-record with UPDATE_BILLING_SNAPSHOTS=1 and commit "
            ."the new baseline with it."
        );
    }

    public function test_payment_diagnostics_payload_matches_baseline(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('paystack');

        app(PaymentLinkService::class)->sendLink($payment, [
            'channel' => 'sms',
            'actor_id' => $user->id,
            'reason' => 'Baseline snapshot',
            'notification_purpose' => 'payment_link',
        ]);

        $payment = $payment->fresh();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy']['opened_at'] = '2026-04-03T10:00:00Z';
        $paymentData['link_proxy']['initialized_at'] = '2026-04-03T10:01:00Z';
        $paymentData['link_proxy']['open_count'] = 1;
        $paymentData['link_proxy']['redirect_url'] = 'https://checkout.paystack.test/redirect';
        $paymentData['link_proxy']['provider_reference'] = 'PSTK-BASELINE-001';

        $payment->forceFill([
            'status' => 'pending',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-BASELINE-REF-001',
            'payment_data' => $paymentData,
        ])->saveQuietly();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk();
        $response->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.provider_key', 'paystack')
            ->assertJsonPath('payment.provider_environment', 'sandbox')
            ->assertJsonPath('payment.reference_number', 'CRM-BASELINE-REF-001');

        $data = $response->json();
        unset($data['payment']['id'], $data['payment']['client_id'], $data['payment']['platform_id'], $data['payment']['user_id']);
        
        $this->assertMatchesBaseline('diagnostics_baseline', $data);
    }

    public function test_provider_status_check_payload_matches_baseline(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('paystack');

        $payment = $payment->fresh();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy'] = [
            'mode' => 'proxy_hosted_checkout',
            'initialized_at' => '2026-04-03T10:01:00Z',
            'provider_reference' => 'PSTK-BASELINE-002',
        ];

        $payment->forceFill([
            'status' => 'pending',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-BASELINE-REF-002',
            'payment_data' => $paymentData,
        ])->save();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'gateway_response' => 'Approved',
                    'reference' => 'CRM-BASELINE-REF-002',
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/check-provider-status");

        $response->assertOk();
        $response->assertJsonPath('provider', 'paystack')
            ->assertJsonPath('provider_environment', 'sandbox')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', 'Approved');

        $data = $response->json();
        unset($data['payment_id']);

        $this->assertMatchesBaseline('provider_status_check_baseline', $data);
    }

    public function test_wordpress_sync_payloads_match_baselines(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProxyPayment('paystack');

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'discount_json' => [
                'self_service_incentive' => [
                    'enabled' => true,
                    'percent' => 10,
                    'label' => 'Self-service special',
                    'starts_at' => now()->subHour()->toIso8601String(),
                    'expires_at' => now()->addDay()->toIso8601String(),
                    'sources' => ['wallet', 'self_checkout', 'manual_submission'],
                ],
            ],
        ]);

        $context = app(BillingModeService::class)->walletContext($platform);
        $syncedAt = '2026-04-03T12:00:00Z';
        
        $configPayload = app(WalletPayloadService::class)->configSync($platform, $context, $syncedAt);
        
        $summary = [
            'balance' => '5000.00',
            'currency' => 'KES',
            'refreshed_at' => '2026-04-03T11:55:00Z',
            'last_topup' => [
                'amount' => 2000,
                'date' => '2026-04-01T09:30:00Z',
                'provider' => 'paystack',
            ],
            'transactions' => [
                [
                    'id' => 123,
                    'amount' => 2000,
                    'type' => 'credit',
                    'date' => '2026-04-01T09:30:00Z',
                ]
            ],
        ];
        $balancePayload = app(WalletPayloadService::class)->balanceSync($client, $summary, $context, $syncedAt);

        $this->assertSame('disabled', $configPayload['mode']);
        $this->assertSame('KES', data_get($configPayload, 'config.market.currency'));
        $this->assertSame(10.0, (float) data_get($configPayload, 'config.self_service_incentive.percent'));
        $this->assertSame('Self-service special', data_get($configPayload, 'config.self_service_incentive.label'));
        $this->assertSame('5000.00', $balancePayload['balance']);
        $this->assertSame('disabled', $balancePayload['mode']);

        unset($configPayload['platform_id'], $balancePayload['platform_id'], $balancePayload['wp_user_id'], $balancePayload['wp_post_id']);

        $this->assertMatchesBaseline('wp_config_sync_payload_baseline', $configPayload);
        $this->assertMatchesBaseline('wp_balance_sync_payload_baseline', $balancePayload);
    }

    public function test_wallet_balance_api_payload_matches_baseline(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedProxyPayment('paystack');
        ['bearer_key' => $bearerKey] = $this->enableWalletForBaseline($platform);

        $client->forceFill([
            'wallet_balance' => '1800.00',
            'wallet_currency' => 'KES',
        ])->saveQuietly();

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 1200,
            'status' => 'completed',
        ]);

        $client->walletTransactions()->create([
            'platform_id' => $platform->id,
            'type' => 'credit',
            'currency_code' => 'KES',
            'amount' => 1200,
            'balance_after' => 1800,
            'reference_type' => 'wallet_topup',
            'reference_id' => 1,
            'description' => 'Wallet top-up via PAYSTACK',
        ]);

        $response = $this->withHeaders(
            $this->walletHeaders($platform, $bearerKey, 'GET', '/api/wallet/balance')
        )->getJson("/api/wallet/balance?wp_user_id={$client->wp_user_id}&platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('balance', '1800.00')
            ->assertJsonPath('currency', 'KES')
            ->assertJsonPath('mode', 'sandbox')
            ->assertJsonPath('config.sandbox_badge', true)
            ->assertJsonPath('config.providers.mpesa_stk.transport', 'direct_provider');

        $data = $response->json();
        unset($data['client']['id'], $data['client']['wp_user_id'], $data['client']['wp_post_id'], $data['config']['platform_id']);

        $this->assertMatchesBaseline('wallet_balance_api_baseline', $data);
    }

    private function enableWalletForBaseline(Platform $platform): array
    {
        $service = app(WalletSettingsService::class);
        $service->saveSystemConfig([
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
            'wallet_refresh_rate_limit_seconds' => 20,
            'wallet_refresh_timeout_seconds' => 15,
            'topup_poll_interval_seconds' => 8,
        ]);
        $service->savePlatformConfig($platform, [
            'enabled' => true,
            'mode_override' => 'sandbox',
            'currency_code' => 'KES',
            'max_single_topup' => '50000.00',
            'max_wallet_balance' => '300000.00',
            'topup_presets' => ['500.00', '1000.00', '2500.00'],
            'allow_combined_topup_subscribe' => true,
            'show_refresh_button' => true,
            'recent_transactions_limit' => 10,
            'providers' => [
                'paystack' => [
                    'enabled' => true,
                    'min_amount' => '100.00',
                    'max_amount' => '500000.00',
                ],
                'pesapal' => [
                    'enabled' => true,
                    'min_amount' => '100.00',
                    'max_amount' => '150000.00',
                ],
                'mpesa_stk' => [
                    'enabled' => true,
                    'min_amount' => '100.00',
                    'max_amount' => '150000.00',
                ],
            ],
        ]);
        $service->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_wallet',
                    'secret_key' => 'sk_test_wallet',
                ],
                'production' => [
                    'public_key' => 'pk_live_wallet',
                    'secret_key' => 'sk_live_wallet',
                ],
            ],
            'pesapal' => [
                'sandbox' => [
                    'consumer_key' => 'pesapal-key',
                    'consumer_secret' => 'pesapal-secret',
                    'ipn_id' => 'ipn-test-001',
                ],
                'production' => [
                    'consumer_key' => 'pesapal-live-key',
                    'consumer_secret' => 'pesapal-live-secret',
                    'ipn_id' => 'ipn-live-001',
                ],
            ],
            'mpesa_stk' => [
                'sandbox' => [
                    'transport' => 'direct_provider',
                    'payment_service_base_url' => 'https://payments.example.test',
                    'organization_code' => '76',
                    'callback_base_url' => 'https://billing-sandbox.example.test',
                ],
            ],
        ]);

        return $service->rotateWpCredentials($platform, 'sandbox', 'both')['revealed'];
    }

    private function walletHeaders(
        Platform $platform,
        string $bearerKey,
        string $method,
        string $path
    ): array {
        return [
            'Authorization' => 'Bearer ' . $bearerKey,
            'X-Exotic-Platform-Id' => (string) $platform->id,
            'X-Exotic-Timestamp' => (string) now()->timestamp,
        ];
    }

    private function seedProxyPayment(string $provider, string $purpose = 'subscription'): array
    {
        config(['app.url' => 'https://crm.example.test']);

        $platform = Platform::factory()->create([
            'name' => 'Baseline Market',
            'country' => 'Kenya',
            'domain' => 'baseline-market.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'db_name' => 'wp_baseline_market',
            'wp_api_url' => 'https://baseline-market.example.test/wp-json/exotic-crm-sync/v1',
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
            'wp_post_id' => 5511,
            'wp_user_id' => 7711,
            'name' => 'Baseline Client',
            'phone_normalized' => '254700000222',
            'email' => 'baseline-client@example.test',
            'city' => 'Baseline City',
            'main_image_url' => 'https://cdn.example.test/baseline-client.png',
            'profile_status' => 'publish',
        ]);

        $user = User::query()->create([
            'name' => 'Sales Baseline',
            'email' => 'sales-baseline@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        // The diagnostics payload embeds the whole product, so it cannot be left to
        // the factory's faker name and prices.
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Baseline Basic',
            'display_name' => 'Baseline Basic',
            'slug' => 'baseline-basic',
            'tier' => 'basic',
            'currency' => 'KES',
            'weekly_price' => 500.00,
            'biweekly_price' => 900.00,
            'monthly_price' => 1500.00,
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'phone' => $client->phone_normalized,
            'amount' => 1500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'transaction_uuid' => '00000000-0000-4000-8000-000000000001',
            'purpose' => $purpose,
            'status' => 'initiated',
            'provider_key' => $provider,
            'provider_environment' => 'sandbox',
            'transaction_reference' => self::SNAPSHOT_TRANSACTION_REFERENCE,
            'reference_number' => 'CRM-BASELINE-SEED-001',
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
        ]);

        $walletSettings->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_baseline',
                    'secret_key' => 'sk_test_baseline',
                ],
            ],
        ]);

        return [
            'platform' => $platform->fresh(),
            'client' => $client->fresh(),
            'payment' => $payment->fresh(['platform', 'client']),
            'user' => $user->fresh(),
        ];
    }
}
