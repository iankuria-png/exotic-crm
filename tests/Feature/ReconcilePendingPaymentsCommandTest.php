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

class ReconcilePendingPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reconciles_completed_wallet_and_subscription_payments(): void
    {
        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ] = $this->seedBillingContext([
            'client_balance' => 400,
        ]);

        Http::fake(array_merge(
            $this->provisioningApiFakes($platform, $client, [
                'premium' => true,
                'premium_expire' => now()->addDays(30)->timestamp,
            ]),
            [
                'https://api.paystack.co/transaction/verify/*' => Http::response([
                    'status' => true,
                    'data' => [
                        'status' => 'success',
                        'reference' => 'WTU-RECON-001',
                        'gateway_response' => 'Successful',
                    ],
                ], 200),
                'https://pay.pesapal.com/v3/api/Auth/RequestToken' => Http::response([
                    'token' => 'pesapal-access-token',
                ], 200),
                'https://pay.pesapal.com/v3/api/Transactions/GetTransactionStatus*' => Http::response([
                    'status_code' => 1,
                    'payment_status_description' => 'Completed',
                ], 200),
            ]
        ));

        $walletPayment = $this->createStalePayment([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'amount' => 1200,
            'currency' => 'KES',
            'reference_number' => 'WTU-RECON-001',
            'transaction_reference' => 'WTU-RECON-001',
            'status' => 'pending',
        ]);

        $subscriptionPayment = $this->createStalePayment([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => $product->id,
            'purpose' => 'subscription',
            'source' => 'gateway',
            'provider_key' => 'pesapal',
            'provider_environment' => 'production',
            'amount' => 2400,
            'currency' => 'KES',
            'duration' => 'monthly',
            'reference_number' => 'SUB-RECON-001',
            'transaction_reference' => 'PESAPAL-TRACK-RECON-001',
            'status' => 'pending',
            'raw_payload' => [
                'method' => 'link',
            ],
        ]);

        $this->artisan('crm:reconcile-pending-payments', [
            '--delay-ms' => 0,
        ])->assertExitCode(0);

        $walletPayment->refresh();
        $subscriptionPayment->refresh();

        $this->assertSame('completed', $walletPayment->status);
        $this->assertSame('completed', $subscriptionPayment->status);
        $this->assertNotNull($subscriptionPayment->deal_id);
        $this->assertSame('1600.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
        $this->assertDatabaseHas('deals', [
            'id' => $subscriptionPayment->deal_id,
            'payment_id' => $subscriptionPayment->id,
            'client_id' => $client->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $walletPayment->id,
            'attempt_type' => 'reconciliation_check',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $subscriptionPayment->id,
            'attempt_type' => 'reconciliation_check',
            'status' => 'completed',
        ]);
    }

    public function test_command_marks_failed_results_and_keeps_pending_results_open(): void
    {
        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ] = $this->seedBillingContext([
            'client_balance' => 400,
        ]);

        Http::fake(array_merge(
            $this->provisioningApiFakes($platform, $client),
            [
                'https://api.paystack.co/transaction/verify/*' => Http::response([
                    'status' => true,
                    'data' => [
                        'status' => 'failed',
                        'reference' => 'SUB-FAIL-001',
                        'gateway_response' => 'Declined',
                    ],
                ], 200),
                'https://pay.pesapal.com/v3/api/Auth/RequestToken' => Http::response([
                    'token' => 'pesapal-access-token',
                ], 200),
                'https://pay.pesapal.com/v3/api/Transactions/GetTransactionStatus*' => Http::response([
                    'status_code' => 0,
                    'payment_status_description' => 'Pending',
                ], 200),
            ]
        ));

        $failedPayment = $this->createStalePayment([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => $product->id,
            'purpose' => 'subscription',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'amount' => 2400,
            'currency' => 'KES',
            'duration' => 'monthly',
            'reference_number' => 'SUB-FAIL-001',
            'transaction_reference' => 'SUB-FAIL-001',
            'status' => 'pending',
            'raw_payload' => [
                'method' => 'link',
            ],
        ]);

        $pendingPayment = $this->createStalePayment([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => $product->id,
            'purpose' => 'subscription',
            'source' => 'gateway',
            'provider_key' => 'pesapal',
            'provider_environment' => 'production',
            'amount' => 2400,
            'currency' => 'KES',
            'duration' => 'monthly',
            'reference_number' => 'SUB-PENDING-001',
            'transaction_reference' => 'PESAPAL-PENDING-001',
            'status' => 'pending',
            'raw_payload' => [
                'method' => 'link',
            ],
        ]);

        $this->artisan('crm:reconcile-pending-payments', [
            '--delay-ms' => 0,
        ])->assertExitCode(0);

        $failedPayment->refresh();
        $pendingPayment->refresh();

        $this->assertSame('failed', $failedPayment->status);
        $this->assertSame('pending', $pendingPayment->status);
        $this->assertNull($pendingPayment->deal_id);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $failedPayment->id,
            'attempt_type' => 'reconciliation_check',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $pendingPayment->id,
            'attempt_type' => 'reconciliation_check',
            'status' => 'pending',
        ]);
    }

    public function test_command_skips_sandbox_payments_unless_explicitly_included(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
        ] = $this->seedBillingContext([
            'client_balance' => 400,
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'WTU-SANDBOX-RECON-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $sandboxPayment = $this->createStalePayment([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 1200,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-RECON-001',
            'transaction_reference' => 'WTU-SANDBOX-RECON-001',
            'status' => 'pending',
        ]);

        $this->artisan('crm:reconcile-pending-payments', [
            '--delay-ms' => 0,
        ])->assertExitCode(0);

        $sandboxPayment->refresh();
        $this->assertSame('pending', $sandboxPayment->status);
        $this->assertSame('400.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
        $this->assertDatabaseMissing('payment_attempts', [
            'payment_id' => $sandboxPayment->id,
            'attempt_type' => 'reconciliation_check',
        ]);

        $this->artisan('crm:reconcile-pending-payments', [
            '--delay-ms' => 0,
            '--include-sandbox' => true,
        ])->assertExitCode(0);

        $sandboxPayment->refresh();
        $this->assertSame('completed', $sandboxPayment->status);
        $this->assertTrue((bool) data_get($sandboxPayment->payment_data, 'test_mode'));
        $this->assertSame('400.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $sandboxPayment->id,
            'attempt_type' => 'reconciliation_check',
            'status' => 'completed',
        ]);
    }

    private function seedBillingContext(array $overrides = []): array
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Reconciliation Market',
            'country' => 'Kenya',
            'domain' => 'reconciliation.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://reconciliation.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-sync-user',
            'wp_api_password' => 'crm-sync-secret',
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Escort',
            'display_name' => 'Premium Escort',
            'slug' => 'premium-escort',
            'tier' => 'premium',
            'weekly_price' => 600,
            'biweekly_price' => 1300,
            'monthly_price' => 2400,
            'currency' => 'KES',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 2400,
            'currency' => 'KES',
            'is_active' => true,
            'sort_order' => 30,
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 4401,
            'wp_user_id' => 8801,
            'name' => 'Jane Escort',
            'phone_normalized' => '254700000111',
            'email' => 'jane@example.test',
            'profile_status' => 'publish',
            'wallet_balance' => $overrides['client_balance'] ?? 1000,
            'wallet_currency' => 'KES',
        ]);

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
            'redirect_delay_seconds' => 2,
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
        ]);

        return [
            'platform' => $platform->fresh(),
            'product' => $product->fresh(),
            'client' => $client->fresh(['platform']),
        ];
    }

    private function createStalePayment(array $attributes): Payment
    {
        $payment = Payment::factory()->create(array_merge([
            'raw_payload' => [],
        ], $attributes));

        $payment->forceFill([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
            'completed_at' => null,
            'start_date' => null,
            'end_date' => null,
        ])->save();

        return $payment->fresh(['platform', 'client', 'product']);
    }

    private function provisioningApiFakes(Platform $platform, Client $client, array $profileOverrides = []): array
    {
        return [
            $platform->wp_api_url . '/clients/' . $client->wp_post_id . '/activate' => Http::response([
                'ok' => true,
                'post_id' => $client->wp_post_id,
            ], 200),
            $platform->wp_api_url . '/clients/' . $client->wp_post_id => Http::response(array_merge([
                'wp_post_id' => $client->wp_post_id,
                'wp_user_id' => $client->wp_user_id,
                'name' => $client->name,
                'phone' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'post_status' => 'publish',
                'premium' => false,
                'premium_expire' => null,
                'featured' => false,
                'featured_expire' => null,
                'escort_expire' => now()->addDays(30)->timestamp,
                'verified' => true,
                'last_online' => now()->timestamp,
                'main_image_url' => 'https://images.example.test/profile.jpg',
            ], $profileOverrides), 200),
        ];
    }
}
