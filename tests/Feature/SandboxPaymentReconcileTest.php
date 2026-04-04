<?php

namespace Tests\Feature;

use App\Models\BillingRoutingDecision;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\WalletSettingsService;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SandboxPaymentReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_reconcile_completes_pending_gateway_payments_without_live_side_effects(): void
    {
        ['platform' => $platform, 'client' => $client, 'user' => $user] = $this->seedCrmSandboxContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 2100,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-RECON-001',
            'transaction_reference' => 'WTU-SANDBOX-RECON-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => [],
            'payment_data' => [],
        ]);

        Sanctum::actingAs($user);

        Http::preventStrayRequests();
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

        $response = $this->postJson("/api/crm/payments/{$payment->id}/sandbox-reconcile", [
            'reason' => 'Verify hosted checkout sandbox result',
        ]);

        $response->assertOk()
            ->assertJsonPath('reconciled', true)
            ->assertJsonPath('already_reconciled', false)
            ->assertJsonPath('provider_snapshot.status', 'completed');

        $payment->refresh();
        $client->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertSame('completed', data_get($payment->payment_data, 'test_result'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'test_mode'));
        $this->assertSame('0.00', number_format((float) $client->wallet_balance, 2, '.', ''));
        $this->assertDatabaseMissing('wallet_transactions', [
            'payment_id' => $payment->id,
        ]);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'sandbox_reconcile',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'action' => CrmAuditAction::PAYMENT_SANDBOX_RECONCILE,
        ]);
    }

    public function test_sandbox_reconcile_marks_failed_provider_results(): void
    {
        ['platform' => $platform, 'client' => $client, 'user' => $user] = $this->seedCrmSandboxContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 1200,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-RECON-FAIL-001',
            'transaction_reference' => 'WTU-SANDBOX-RECON-FAIL-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => [],
            'payment_data' => [],
        ]);

        Sanctum::actingAs($user);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'failed',
                    'reference' => 'WTU-SANDBOX-RECON-FAIL-001',
                    'gateway_response' => 'Declined',
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/sandbox-reconcile");

        $response->assertOk()
            ->assertJsonPath('reconciled', true)
            ->assertJsonPath('provider_snapshot.status', 'failed');

        $payment->refresh();

        $this->assertSame('failed', $payment->status);
        $this->assertSame('failed', data_get($payment->payment_data, 'test_result'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'test_mode'));
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'sandbox_reconcile',
            'status' => 'failed',
        ]);
    }

    public function test_sandbox_reconcile_is_idempotent_for_already_reconciled_test_payments(): void
    {
        ['platform' => $platform, 'client' => $client, 'user' => $user] = $this->seedCrmSandboxContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 950,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-RECON-DONE-001',
            'transaction_reference' => 'WTU-SANDBOX-RECON-DONE-001',
            'status' => 'completed',
            'completed_at' => now(),
            'raw_payload' => [],
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
                'side_effects_skipped' => true,
                'verified_at' => now()->toIso8601String(),
            ],
        ]);

        Sanctum::actingAs($user);

        Http::fake();

        $response = $this->postJson("/api/crm/payments/{$payment->id}/sandbox-reconcile");

        $response->assertOk()
            ->assertJsonPath('already_reconciled', true)
            ->assertJsonPath('reconciled', false);

        Http::assertNothingSent();
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'sandbox_reconcile',
            'status' => 'completed',
        ]);
    }

    public function test_sandbox_reconcile_prefers_pinned_snapshot_for_alias_provider_and_environment(): void
    {
        ['platform' => $platform, 'client' => $client, 'user' => $user] = $this->seedCrmSandboxContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack_checkout',
            'provider_environment' => 'production',
            'amount' => 1800,
            'currency' => 'KES',
            'reference_number' => 'WTU-SANDBOX-ALIAS-001',
            'transaction_reference' => 'WTU-SANDBOX-ALIAS-001',
            'status' => 'pending',
            'completed_at' => null,
            'raw_payload' => [],
            'payment_data' => [],
        ]);

        BillingRoutingDecision::query()->create([
            'payment_id' => $payment->id,
            'market_id' => $platform->id,
            'billing_surface' => 'wallet_topup',
            'provider_type_key' => 'paystack',
            'execution_mode' => 'proxy',
            'environment' => 'sandbox',
            'decision_version' => 1,
            'surface_cutover_flag' => 'billing.shadow_read',
            'snapshot_json' => [
                'provider_family' => 'hosted_checkout',
            ],
            'decision_json' => [
                'source' => 'test',
            ],
            'immutable_until_terminal_state' => true,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'WTU-SANDBOX-ALIAS-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/sandbox-reconcile");

        $response->assertOk()
            ->assertJsonPath('reconciled', true)
            ->assertJsonPath('provider_snapshot.provider', 'paystack')
            ->assertJsonPath('provider_snapshot.provider_environment', 'sandbox');

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'sandbox_reconcile',
            'provider' => 'paystack',
            'status' => 'completed',
        ]);
    }

    private function seedCrmSandboxContext(): array
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Sandbox Reconcile Market',
            'country' => 'Kenya',
            'domain' => 'sandbox-reconcile.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://sandbox-reconcile.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-sync-user',
            'wp_api_password' => 'crm-sync-secret',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 409001,
            'wallet_balance' => 0,
            'wallet_currency' => 'KES',
        ]);

        $user = User::query()->create([
            'name' => 'Sandbox Admin',
            'email' => 'sandbox-admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $service = app(WalletSettingsService::class);
        $service->saveSystemConfig([
            'mode' => 'production',
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
        $service->savePlatformConfig($platform, [
            'enabled' => true,
            'mode_override' => 'production',
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
                    'consumer_key' => 'pesapal-sandbox-key',
                    'consumer_secret' => 'pesapal-sandbox-secret',
                    'ipn_id' => 'ipn-sandbox-001',
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
            'client' => $client->fresh(['platform']),
            'user' => $user->fresh(),
        ];
    }
}
