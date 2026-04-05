<?php

namespace Tests\Feature;

use App\Models\BillingWalletRule;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\BillingProviderTransaction;
use App\Models\BillingRoutingDecision;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\KopokopoService;
use App\Services\Routing\ProviderRoutingDispatcher;
use App\Services\WalletCheckoutService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class WalletApiPhaseFiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_client_wallet_routes_return_summary_and_allow_topups_and_adjustments_with_pin(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedWalletContext([
            'client_balance' => 700,
        ]);
        $this->configureWalletPin();

        Sanctum::actingAs($this->createUser('admin'));

        $showResponse = $this->getJson("/api/crm/clients/{$client->id}/wallet");
        $showResponse->assertOk()
            ->assertJsonPath('wallet.balance', '700.00')
            ->assertJsonPath('wallet.currency', 'KES');

        $topupResponse = $this->postJson("/api/crm/clients/{$client->id}/wallet/topup", [
            'amount' => '250.00',
            'pin' => '1234',
            'reason' => 'Manual QA top-up',
        ]);

        $topupResponse->assertCreated()
            ->assertJsonPath('wallet.balance', '950.00')
            ->assertJsonPath('transaction.type', 'credit');

        $adjustmentResponse = $this->postJson("/api/crm/clients/{$client->id}/wallet/adjustment", [
            'type' => 'credit',
            'amount' => '50.00',
            'pin' => '1234',
            'reason' => 'Manual QA credit',
        ]);

        $adjustmentResponse->assertOk()
            ->assertJsonPath('wallet.balance', '1000.00')
            ->assertJsonPath('transaction.type', 'credit');

        $this->assertDatabaseHas('wallet_transactions', [
            'client_id' => $client->id,
            'type' => 'credit',
            'description' => 'CRM wallet credit adjustment',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'action' => 'client_wallet_adjustment',
        ]);
    }

    public function test_sales_client_wallet_routes_allow_adjustments_but_marketing_remains_read_only(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedWalletContext([
            'client_balance' => 700,
        ]);
        $this->configureWalletPin();

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $salesResponse = $this->postJson("/api/crm/clients/{$client->id}/wallet/adjustment", [
            'type' => 'credit',
            'amount' => '200.00',
            'pin' => '1234',
            'reason' => 'Sales support correction',
        ]);

        $salesResponse->assertOk()
            ->assertJsonPath('wallet.balance', '900.00')
            ->assertJsonPath('transaction.type', 'credit');

        $this->assertDatabaseHas('wallet_transactions', [
            'client_id' => $client->id,
            'type' => 'credit',
            'description' => 'CRM wallet credit adjustment',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'action' => 'client_wallet_adjustment',
        ]);

        Sanctum::actingAs($this->createUser('marketing', [$platform->id]));

        $marketingResponse = $this->postJson("/api/crm/clients/{$client->id}/wallet/adjustment", [
            'type' => 'credit',
            'amount' => '50.00',
            'pin' => '1234',
            'reason' => 'Should be rejected',
        ]);

        $marketingResponse->assertForbidden();
    }

    public function test_client_wallet_adjustment_requires_configured_pin(): void
    {
        ['client' => $client] = $this->seedWalletContext([
            'client_balance' => 700,
        ]);

        Sanctum::actingAs($this->createUser('admin'));

        $response = $this->postJson("/api/crm/clients/{$client->id}/wallet/adjustment", [
            'type' => 'credit',
            'amount' => '200.00',
            'pin' => '1234',
            'reason' => 'Attempt without configured PIN',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'wallet_pin_not_configured');
    }

    public function test_client_wallet_adjustment_rejects_invalid_pin(): void
    {
        ['client' => $client] = $this->seedWalletContext([
            'client_balance' => 700,
        ]);
        $this->configureWalletPin();

        Sanctum::actingAs($this->createUser('admin'));

        $response = $this->postJson("/api/crm/clients/{$client->id}/wallet/adjustment", [
            'type' => 'credit',
            'amount' => '200.00',
            'pin' => '9999',
            'reason' => 'Attempt with wrong PIN',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'wallet_pin_invalid');
    }

    public function test_wallet_balance_endpoint_returns_live_summary_for_authenticated_request(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
            'bearer_key' => $bearerKey,
        ] = $this->seedWalletContext([
            'client_balance' => 1800,
        ]);

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
            $this->walletHeaders($platform, $bearerKey, null, 'GET', '/api/wallet/balance')
        )->getJson('/api/wallet/balance?wp_user_id=' . $client->wp_user_id);

        $response->assertOk()
            ->assertJsonPath('balance', '1800.00')
            ->assertJsonPath('currency', 'KES')
            ->assertJsonPath('mode', 'sandbox')
            ->assertJsonPath('config.sandbox_badge', true)
            ->assertJsonPath('last_topup.type', 'credit');
    }

    public function test_crm_client_wallet_summary_uses_projected_runtime_currency_and_recent_transaction_limit(): void
    {
        config(['billing.shadow_read.enabled' => true]);

        [
            'platform' => $platform,
            'client' => $client,
        ] = $this->seedWalletContext([
            'client_balance' => 1800,
        ]);

        $platform->forceFill([
            'wallet_settings' => null,
        ])->save();

        BillingWalletRule::query()->create([
            'market_id' => $platform->id,
            'enabled' => true,
            'currency_code' => 'GHS',
            'topup_preset_json' => ['150.00', '300.00'],
            'limit_json' => [
                'max_single_topup' => '9000.00',
                'max_wallet_balance' => '120000.00',
            ],
            'auto_renew_json' => ['enabled' => true],
            'ui_json' => [
                'show_refresh_button' => false,
                'allow_combined_topup_subscribe' => false,
                'recent_transactions_limit' => 2,
            ],
        ]);

        foreach ([1200, 500, 100] as $index => $amount) {
            $client->walletTransactions()->create([
                'platform_id' => $platform->id,
                'type' => 'credit',
                'currency_code' => 'GHS',
                'amount' => $amount,
                'balance_after' => 1800 + $amount + $index,
                'reference_type' => 'wallet_topup',
                'reference_id' => $index + 1,
                'description' => 'Projected wallet credit #' . ($index + 1),
            ]);
        }

        Sanctum::actingAs($this->createUser('admin'));

        $response = $this->getJson("/api/crm/clients/{$client->id}/wallet");

        $response->assertOk()
            ->assertJsonPath('wallet.currency', 'GHS');

        $this->assertCount(2, $response->json('wallet.transactions'));
    }

    public function test_wallet_subscribe_is_idempotent_and_uses_shared_provisioning_path(): void
    {
        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
            'bearer_key' => $bearerKey,
            'hmac_secret' => $hmacSecret,
        ] = $this->seedWalletContext([
            'client_balance' => 5000,
        ]);

        $this->fakeProvisioningApis($platform, $client, [
            'premium' => true,
            'premium_expire' => now()->addDays(30)->timestamp,
        ]);

        $payload = [
            'wp_user_id' => $client->wp_user_id,
            'product_id' => $product->id,
            'duration' => '1_month',
        ];
        $idempotencyKey = 'wallet-subscribe-' . Str::uuid();
        $headers = $this->walletHeaders(
            $platform,
            $bearerKey,
            $hmacSecret,
            'POST',
            '/api/wallet/subscribe',
            $payload,
            $idempotencyKey
        );

        $first = $this->withHeaders($headers)->postJson('/api/wallet/subscribe', $payload);
        $first->assertOk()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('payment.status', 'completed')
            ->assertJsonPath('deal.status', 'active')
            ->assertJsonPath('wallet.balance', '2600.00');

        $second = $this->withHeaders($headers)->postJson('/api/wallet/subscribe', $payload);
        $second->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('wallet.balance', '2600.00');

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('payments', [
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'purpose' => 'subscription',
            'source' => 'wallet',
            'status' => 'completed',
        ]);

        $payment = Payment::query()
            ->where('platform_id', $platform->id)
            ->where('client_id', $client->id)
            ->where('product_id', $product->id)
            ->latest('id')
            ->first();

        $routingDecision = BillingRoutingDecision::query()
            ->where('payment_id', (int) $payment?->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($routingDecision);
        $this->assertSame('self_checkout', $routingDecision->billing_surface);
        $this->assertSame('wallet_balance', $routingDecision->provider_type_key);
        $this->assertSame('direct', $routingDecision->execution_mode);
        $this->assertSame('internal_ledger', data_get($routingDecision->snapshot_json, 'execution_family'));
        $this->assertSame('wp_wallet_subscribe', data_get($routingDecision->snapshot_json, 'wallet_policy.origin'));
    }

    public function test_wallet_auto_subscribe_pins_wallet_auto_renew_snapshot(): void
    {
        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ] = $this->seedWalletContext([
            'client_balance' => 5000,
        ]);

        $this->fakeProvisioningApis($platform, $client, [
            'premium' => true,
            'premium_expire' => now()->addDays(30)->timestamp,
        ]);

        $checkout = app(WalletCheckoutService::class)->payForSubscriptionFromWallet(
            $client,
            $product,
            '1_month',
            'wallet-auto-subscribe-' . Str::uuid(),
            [
                'environment' => 'production',
                'origin' => 'wallet_auto_subscribe',
                'topup_payment_id' => 321,
            ]
        );

        $routingDecision = BillingRoutingDecision::query()
            ->where('payment_id', (int) $checkout['payment']->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($routingDecision);
        $this->assertSame('wallet_auto_renew', $routingDecision->billing_surface);
        $this->assertSame('wallet_balance', $routingDecision->provider_type_key);
        $this->assertSame('direct', $routingDecision->execution_mode);
        $this->assertSame('internal_ledger', data_get($routingDecision->snapshot_json, 'execution_family'));
        $this->assertSame('wallet_auto_subscribe', data_get($routingDecision->snapshot_json, 'wallet_policy.origin'));
        $this->assertSame(321, data_get($routingDecision->snapshot_json, 'wallet_policy.topup_payment_id'));
    }

    public function test_billing_initiate_supports_paystack_and_rejects_cybersource(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
            'bearer_key' => $bearerKey,
            'hmac_secret' => $hmacSecret,
        ] = $this->seedWalletContext();

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.test/redirect',
                    'reference' => 'WTU-REF-001',
                    'access_code' => 'ACCESS-CODE-001',
                ],
            ], 200),
        ]);

        $payload = [
            'wp_user_id' => $client->wp_user_id,
            'provider' => 'paystack',
            'amount' => '1200.00',
        ];
        $headers = $this->walletHeaders(
            $platform,
            $bearerKey,
            $hmacSecret,
            'POST',
            '/api/billing/initiate',
            $payload,
            'topup-' . Str::uuid()
        );

        $response = $this->withHeaders(array_merge($headers, [
            'User-Agent' => 'WordPress/6.8; https://www.exoticnairobi.com',
            'X-Request-Id' => 'wp-wallet-topup-req-001',
            'X-Exotic-Origin' => 'https://www.exoticnairobi.com',
            'X-Exotic-Referer' => 'https://www.exoticnairobi.com/escort/jane/',
            'X-Exotic-User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
        ]))->postJson('/api/billing/initiate', $payload);
        $response->assertCreated()
            ->assertJsonPath('provider', 'paystack')
            ->assertJsonPath('action.type', 'redirect')
            ->assertJsonPath('action.url', 'https://checkout.paystack.test/redirect')
            ->assertJsonPath('payment.purpose', 'wallet_topup');

        $paymentId = (int) $response->json('payment.id');
        $attempt = PaymentAttempt::query()
            ->where('payment_id', $paymentId)
            ->where('attempt_type', 'hosted_checkout_init')
            ->firstOrFail();
        $this->assertSame('success', $attempt->status);
        $this->assertSame('paystack', $attempt->provider);
        $this->assertSame('browser', data_get($attempt->request_meta, 'context_type'));
        $this->assertSame('hosted_checkout', data_get($attempt->request_meta, 'channel'));
        $this->assertSame('wallet_topup', data_get($attempt->request_meta, 'billing_surface'));
        $this->assertSame('paystack', data_get($attempt->request_meta, 'requested_provider'));
        $this->assertSame('wp-wallet-topup-req-001', data_get($attempt->request_meta, 'request_id'));
        $this->assertSame($platform->id, data_get($attempt->request_meta, 'platform_id'));
        $this->assertSame($client->id, data_get($attempt->request_meta, 'client_id'));
        $this->assertSame('https://www.exoticnairobi.com', data_get($attempt->request_meta, 'origin_url'));
        $this->assertSame('https://www.exoticnairobi.com/escort/jane/', data_get($attempt->request_meta, 'referrer'));
        $this->assertSame('Safari', data_get($attempt->request_meta, 'user_agent_family'));
        $this->assertSame('mobile', data_get($attempt->request_meta, 'device_type'));
        $this->assertSame('https://checkout.paystack.test/redirect', data_get($attempt->response_meta, 'checkout_url'));

        $cybersourcePayload = [
            'wp_user_id' => $client->wp_user_id,
            'provider' => 'cybersource',
            'amount' => '1200.00',
        ];
        $cybersourceHeaders = $this->walletHeaders(
            $platform,
            $bearerKey,
            $hmacSecret,
            'POST',
            '/api/billing/initiate',
            $cybersourcePayload,
            'topup-' . Str::uuid()
        );

        $cybersource = $this->withHeaders($cybersourceHeaders)->postJson('/api/billing/initiate', $cybersourcePayload);
        $cybersource->assertStatus(422)
            ->assertJsonPath('error_code', 'provider_not_supported');
    }

    public function test_billing_initiate_routes_wallet_topups_through_provider_dispatcher(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
            'bearer_key' => $bearerKey,
            'hmac_secret' => $hmacSecret,
        ] = $this->seedWalletContext();

        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.test/redirect-dispatcher',
                    'reference' => 'WTU-REF-DISPATCHER',
                    'access_code' => 'ACCESS-CODE-DISPATCHER',
                ],
            ], 200),
        ]);

        $recordingDispatcher = new class(app(ProviderRoutingDispatcher::class)) extends ProviderRoutingDispatcher {
            public array $calls = [];

            public function __construct(private readonly ProviderRoutingDispatcher $inner)
            {
            }

            public function dispatch(Payment $payment, array $context, array $options = []): array
            {
                $this->calls[] = compact('payment', 'context', 'options');

                return $this->inner->dispatch($payment, $context, $options);
            }

            public function supports(string $providerKey): bool
            {
                return $this->inner->supports($providerKey);
            }

            public function registeredProviders(): array
            {
                return $this->inner->registeredProviders();
            }
        };
        $this->app->instance(ProviderRoutingDispatcher::class, $recordingDispatcher);

        $payload = [
            'wp_user_id' => $client->wp_user_id,
            'provider' => 'paystack',
            'amount' => '1200.00',
        ];
        $headers = $this->walletHeaders(
            $platform,
            $bearerKey,
            $hmacSecret,
            'POST',
            '/api/billing/initiate',
            $payload,
            'topup-dispatch-' . Str::uuid()
        );

        $response = $this->withHeaders($headers)->postJson('/api/billing/initiate', $payload);
        $response->assertCreated()
            ->assertJsonPath('provider', 'paystack')
            ->assertJsonPath('action.type', 'redirect')
            ->assertJsonPath('action.url', 'https://checkout.paystack.test/redirect-dispatcher');

        $this->assertCount(1, $recordingDispatcher->calls);
        $call = $recordingDispatcher->calls[0];
        $this->assertSame('wallet_topup', $call['payment']->purpose);
        $this->assertSame('paystack', $call['context']['provider_key'] ?? null);
        $this->assertSame('Wallet top-up', $call['options']['description'] ?? null);
        $this->assertSame($client->phone_normalized, $call['payment']->phone);

        $decision = BillingRoutingDecision::query()
            ->where('payment_id', (int) $response->json('payment.id'))
            ->latest('id')
            ->first();
        $this->assertNotNull($decision);
        $this->assertSame('wallet_funding', $decision->billing_surface);
        $this->assertSame('paystack', $decision->provider_type_key);
        $this->assertSame('direct', $decision->execution_mode);
        $this->assertSame('hosted_redirect', data_get($decision->snapshot_json, 'execution_family'));
        $this->assertSame('browser_completion', data_get($decision->snapshot_json, 'callback_contract.type'));
        $this->assertSame('1200.00', data_get($decision->snapshot_json, 'pricing.amount'));

        $providerTransaction = BillingProviderTransaction::query()
            ->where('payment_id', (int) $response->json('payment.id'))
            ->latest('id')
            ->first();
        $this->assertNotNull($providerTransaction);
        $this->assertSame('paystack', $providerTransaction->provider_type_key);
        $this->assertSame('pending', $providerTransaction->normalized_status);
        $this->assertSame('WTU-REF-DISPATCHER', $providerTransaction->provider_transaction_id);
        $this->assertSame(1, $providerTransaction->attempt_sequence);
        $this->assertSame('initial_initiation', data_get($providerTransaction->confirmation_state_json, 'reason_code'));
    }

    public function test_paystack_webhook_credits_wallet_once_after_verification_for_production_payments(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
        ] = $this->seedWalletContext([
            'client_balance' => 400,
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'amount' => 1600,
            'currency' => 'KES',
            'reference_number' => 'WTU-PAYSTACK-001',
            'transaction_reference' => 'WTU-PAYSTACK-001',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'WTU-PAYSTACK-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'WTU-PAYSTACK-001',
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $rawBody, 'sk_live_wallet');

        $response = $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $rawBody);

        $response->assertOk()
            ->assertJsonPath('status', 'completed');

        $second = $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $rawBody);

        $second->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'callback_update',
            'provider' => 'paystack_webhook',
            'status' => 'success',
        ]);
        $this->assertSame('2000.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_paystack_webhook_wallet_credit_prefers_pinned_snapshot_provider_metadata(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
        ] = $this->seedWalletContext([
            'client_balance' => 400,
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'paystack_checkout',
            'provider_environment' => 'sandbox',
            'amount' => 1600,
            'currency' => 'KES',
            'reference_number' => 'WTU-PAYSTACK-ALIAS-001',
            'transaction_reference' => 'WTU-PAYSTACK-ALIAS-001',
            'status' => 'pending',
        ]);

        BillingRoutingDecision::query()->create([
            'payment_id' => $payment->id,
            'market_id' => $platform->id,
            'billing_surface' => 'wallet_funding',
            'provider_type_key' => 'paystack',
            'execution_mode' => 'direct',
            'environment' => 'production',
            'decision_version' => 1,
            'surface_cutover_flag' => 'billing.shadow_read',
            'snapshot_json' => [
                'provider_key' => 'paystack_checkout',
                'provider_family' => 'hosted_checkout',
            ],
            'decision_json' => [
                'source' => 'test',
            ],
            'immutable_until_terminal_state' => true,
            'created_at' => now(),
        ]);

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'WTU-PAYSTACK-ALIAS-001',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'WTU-PAYSTACK-ALIAS-001',
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $rawBody, 'sk_live_wallet');

        $response = $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $rawBody);

        $response->assertOk()
            ->assertJsonPath('status', 'completed');

        $transaction = \App\Models\WalletTransaction::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('Wallet top-up via PAYSTACK', $transaction->description);
        $this->assertSame('paystack', data_get($transaction->metadata, 'provider'));
        $this->assertSame('production', data_get($transaction->metadata, 'provider_environment'));
    }

    public function test_paystack_webhook_subscription_provisioning_prefers_pinned_snapshot_environment(): void
    {
        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ] = $this->seedWalletContext([
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
                        'reference' => 'SUB-PAYSTACK-ALIAS-ENV-001',
                        'gateway_response' => 'Successful',
                    ],
                ], 200),
            ]
        ));

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => $product->id,
            'purpose' => 'subscription',
            'source' => 'gateway',
            'provider_key' => 'paystack_checkout',
            'provider_environment' => 'sandbox',
            'amount' => 2400,
            'currency' => 'KES',
            'duration' => 'monthly',
            'reference_number' => 'SUB-PAYSTACK-ALIAS-ENV-001',
            'transaction_reference' => 'SUB-PAYSTACK-ALIAS-ENV-001',
            'status' => 'pending',
            'raw_payload' => [
                'method' => 'link',
            ],
        ]);

        BillingRoutingDecision::query()->create([
            'payment_id' => $payment->id,
            'market_id' => $platform->id,
            'billing_surface' => 'subscription_link',
            'provider_type_key' => 'paystack',
            'execution_mode' => 'proxy',
            'environment' => 'production',
            'decision_version' => 1,
            'surface_cutover_flag' => 'billing.shadow_read',
            'snapshot_json' => [
                'provider_key' => 'paystack_checkout',
                'provider_family' => 'hosted_checkout',
            ],
            'decision_json' => [
                'source' => 'test',
            ],
            'immutable_until_terminal_state' => true,
            'created_at' => now(),
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'SUB-PAYSTACK-ALIAS-ENV-001',
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $rawBody, 'sk_live_wallet');

        $response = $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $rawBody);

        $response->assertOk()
            ->assertJsonPath('status', 'completed');

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertNotNull($payment->deal_id);
        $this->assertSame('400.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_paystack_webhook_completes_subscription_payments_without_wallet_credit_side_effects_for_production_payments(): void
    {
        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ] = $this->seedWalletContext([
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
                        'reference' => 'SUB-PAYSTACK-001',
                        'gateway_response' => 'Successful',
                    ],
                ], 200),
            ]
        ));

        $payment = Payment::factory()->create([
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
            'reference_number' => 'SUB-PAYSTACK-001',
            'transaction_reference' => 'SUB-PAYSTACK-001',
            'status' => 'pending',
            'raw_payload' => [
                'method' => 'link',
            ],
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'SUB-PAYSTACK-001',
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $rawBody, 'sk_live_wallet');

        $response = $this->call('POST', '/api/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $rawBody);

        $response->assertOk()
            ->assertJsonPath('status', 'completed');

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertNotNull($payment->completed_at);
        $this->assertNotNull($payment->deal_id);
        $this->assertDatabaseHas('deals', [
            'id' => $payment->deal_id,
            'payment_id' => $payment->id,
            'client_id' => $client->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame('400.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
    }

    public function test_pesapal_ipn_completes_subscription_payments_without_wallet_credit_side_effects_for_production_payments(): void
    {
        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ] = $this->seedWalletContext([
            'client_balance' => 400,
        ]);

        Http::fake(array_merge(
            $this->provisioningApiFakes($platform, $client, [
                'premium' => true,
                'premium_expire' => now()->addDays(30)->timestamp,
            ]),
            [
                'https://pay.pesapal.com/v3/api/Auth/RequestToken' => Http::response([
                    'token' => 'pesapal-access-token',
                ], 200),
                'https://pay.pesapal.com/v3/api/Transactions/GetTransactionStatus*' => Http::response([
                    'status_code' => 1,
                    'payment_status_description' => 'Completed',
                ], 200),
            ]
        ));

        $payment = Payment::factory()->create([
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
            'reference_number' => 'SUB-PESAPAL-001',
            'transaction_reference' => 'PESAPAL-TRACK-001',
            'status' => 'pending',
            'raw_payload' => [
                'method' => 'link',
            ],
        ]);

        $response = $this->getJson('/api/billing/pesapal/ipn?OrderMerchantReference=SUB-PESAPAL-001&OrderTrackingId=PESAPAL-TRACK-001');

        $response->assertOk()
            ->assertJsonPath('status', 'completed');

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertSame('PESAPAL-TRACK-001', $payment->transaction_reference);
        $this->assertNotNull($payment->deal_id);
        $this->assertDatabaseHas('deals', [
            'id' => $payment->deal_id,
            'payment_id' => $payment->id,
            'client_id' => $client->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'callback_update',
            'provider' => 'pesapal_ipn',
            'status' => 'success',
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame('400.00', number_format((float) $client->fresh()->wallet_balance, 2, '.', ''));
    }

    public function test_mpesa_direct_provider_initiation_and_retry_are_available(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
            'bearer_key' => $bearerKey,
            'hmac_secret' => $hmacSecret,
        ] = $this->seedWalletContext();

        $this->mock(KopokopoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('initiateStkPush')
                ->twice()
                ->andReturn([
                    'status' => 'success',
                    'location' => 'https://sandbox.kopokopo.test/incoming_payments/abc123',
                ]);
        });

        $payload = [
            'wp_user_id' => $client->wp_user_id,
            'provider' => 'mpesa_stk',
            'amount' => '900.00',
            'phone' => $client->phone_normalized,
        ];
        $headers = $this->walletHeaders(
            $platform,
            $bearerKey,
            $hmacSecret,
            'POST',
            '/api/billing/initiate',
            $payload,
            'mpesa-' . Str::uuid()
        );

        $initiate = $this->withHeaders($headers)->postJson('/api/billing/initiate', $payload);
        $initiate->assertCreated()
            ->assertJsonPath('provider', 'mpesa_stk')
            ->assertJsonPath('action.type', 'stk_pending')
            ->assertJsonPath('action.retry_available', true);

        $routingDecision = BillingRoutingDecision::query()
            ->where('payment_id', (int) $initiate->json('payment.id'))
            ->latest('id')
            ->first();
        $this->assertNotNull($routingDecision);
        $this->assertSame('wallet_funding', $routingDecision->billing_surface);
        $this->assertSame('mpesa_stk', $routingDecision->provider_type_key);
        $this->assertSame('direct', $routingDecision->execution_mode);
        $this->assertSame('mobile_collection', data_get($routingDecision->snapshot_json, 'execution_family'));
        $this->assertSame('webhook', data_get($routingDecision->snapshot_json, 'callback_contract.type'));

        $paymentId = (int) $initiate->json('payment.id');
        $initialAttempts = PaymentAttempt::query()
            ->where('payment_id', $paymentId)
            ->where('attempt_type', 'stk_initiate')
            ->get();
        $this->assertCount(1, $initialAttempts);
        $this->assertSame('success', $initialAttempts->first()->status);
        $this->assertSame('kopokopo_direct', $initialAttempts->first()->provider);
        $this->assertSame('wallet_topup_stk', data_get($initialAttempts->first()->request_meta, 'channel'));
        $this->travel(61)->seconds();

        $retryPayload = [
            'wp_user_id' => $client->wp_user_id,
            'payment_id' => $paymentId,
            'phone' => $client->phone_normalized,
        ];
        $retryHeaders = $this->walletHeaders(
            $platform,
            $bearerKey,
            $hmacSecret,
            'POST',
            '/api/billing/retry-stk',
            $retryPayload,
            'mpesa-retry-' . Str::uuid()
        );

        $retry = $this->withHeaders($retryHeaders)->postJson('/api/billing/retry-stk', $retryPayload);
        $retry->assertOk()
            ->assertJsonPath('action.type', 'stk_pending');

        $retryAttempts = PaymentAttempt::query()
            ->where('payment_id', $paymentId)
            ->where('attempt_type', 'stk_initiate')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $retryAttempts);
        $this->assertSame('success', $retryAttempts->last()->status);
        $this->assertSame('kopokopo_direct', $retryAttempts->last()->provider);

        $providerTransactions = BillingProviderTransaction::query()
            ->where('payment_id', $paymentId)
            ->where('provider_type_key', 'mpesa_stk')
            ->orderBy('attempt_sequence')
            ->get();
        $this->assertCount(2, $providerTransactions);
        $this->assertSame(1, $providerTransactions[0]->attempt_sequence);
        $this->assertSame(2, $providerTransactions[1]->attempt_sequence);
        $this->assertSame($providerTransactions[0]->attempt_group_key, $providerTransactions[1]->attempt_group_key);
        $this->assertSame($providerTransactions[0]->id, $providerTransactions[1]->retry_of_provider_transaction_id);
        $this->assertSame('manual_retry', data_get($providerTransactions[1]->confirmation_state_json, 'reason_code'));
    }

    public function test_kopokopo_provider_key_routes_through_direct_collection_bridge(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
            'bearer_key' => $bearerKey,
            'hmac_secret' => $hmacSecret,
        ] = $this->seedWalletContext();

        $this->mock(KopokopoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('initiateStkPush')
                ->once()
                ->andReturn([
                    'status' => 'success',
                    'location' => 'https://sandbox.kopokopo.test/incoming_payments/bridge-001',
                ]);
        });

        $payload = [
            'wp_user_id' => $client->wp_user_id,
            'provider' => 'kopokopo',
            'amount' => '900.00',
            'phone' => $client->phone_normalized,
        ];
        $headers = $this->walletHeaders(
            $platform,
            $bearerKey,
            $hmacSecret,
            'POST',
            '/api/billing/initiate',
            $payload,
            'kopokopo-' . Str::uuid()
        );

        $initiate = $this->withHeaders($headers)->postJson('/api/billing/initiate', $payload);
        $initiate->assertCreated()
            ->assertJsonPath('provider', 'kopokopo')
            ->assertJsonPath('action.type', 'stk_pending');

        $paymentId = (int) $initiate->json('payment.id');
        $routingDecision = BillingRoutingDecision::query()
            ->where('payment_id', $paymentId)
            ->latest('id')
            ->first();

        $this->assertNotNull($routingDecision);
        $this->assertSame('kopokopo', $routingDecision->provider_type_key);
        $this->assertSame('mobile_collection', data_get($routingDecision->snapshot_json, 'execution_family'));

        $attempt = PaymentAttempt::query()
            ->where('payment_id', $paymentId)
            ->where('attempt_type', 'stk_initiate')
            ->latest('id')
            ->first();

        $this->assertNotNull($attempt);
        $this->assertSame('kopokopo_direct', $attempt->provider);
        $this->assertSame('wallet_topup_stk', data_get($attempt->request_meta, 'channel'));
    }

    public function test_mpesa_callback_records_attempt(): void
    {
        [
            'platform' => $platform,
            'client' => $client,
        ] = $this->seedWalletContext([
            'client_balance' => 350,
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'source' => 'gateway',
            'provider_key' => 'mpesa_stk',
            'provider_environment' => 'sandbox',
            'amount' => 900,
            'currency' => 'KES',
            'reference_number' => 'WTU-MPESA-001',
            'transaction_reference' => 'WTU-MPESA-001',
            'status' => 'pending',
        ]);

        $this->mock(KopokopoService::class, function (MockInterface $mock) use ($payment, $platform, $client) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->with('{"topic":"buygoods_transaction_received"}', 'mock-signature')
                ->andReturn([
                    'status' => 'success',
                    'data' => [
                        'topic' => 'buygoods_transaction_received',
                        'resourceStatus' => 'Success',
                        'reference' => 'KPK-REF-001',
                        'metadata' => [
                            'payment_id' => $payment->id,
                            'platform_id' => $platform->id,
                            'client_id' => $client->id,
                        ],
                    ],
                ]);
        });

        $response = $this->call('POST', '/api/billing/mpesa/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KOPOKOPO_SIGNATURE' => 'mock-signature',
        ], '{"topic":"buygoods_transaction_received"}');

        $response->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'callback_update',
            'provider' => 'kopokopo_webhook',
            'status' => 'success',
        ]);
    }

    public function test_billing_complete_route_renders_completion_view_above_spa_catch_all(): void
    {
        ['platform' => $platform, 'client' => $client] = $this->seedWalletContext();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'wallet_topup',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'amount' => 1500,
            'currency' => 'KES',
            'reference_number' => 'WTU-COMPLETE-001',
            'transaction_uuid' => 'wallet-complete-uuid-001',
            'status' => 'pending',
        ]);

        $response = $this->get('/billing/complete?payment=' . $payment->transaction_uuid);

        $response->assertOk()
            ->assertSee('Sandbox payment result')
            ->assertSee('Back to CRM Payments')
            ->assertSee('Check Provider Status')
            ->assertSee('Open profile anyway')
            ->assertSee('No live activation or wallet credit was performed.')
            ->assertSee('Sandbox Billing')
            ->assertDontSee('Redirecting in 2 seconds')
            ->assertDontSee('CRM SPA');
    }

    private function seedWalletContext(array $overrides = []): array
    {
        config([
            'app.url' => 'https://crm.example.test',
            'services.kopokopo.base_url' => 'https://sandbox.kopokopo.test',
            'services.kopokopo.client_id' => 'kopokopo-client',
            'services.kopokopo.client_secret' => 'kopokopo-secret',
            'services.kopokopo.api_key' => 'kopokopo-api-key',
            'services.kopokopo.till_number' => 'K123456',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Kenya Market',
            'country' => 'Kenya',
            'domain' => 'escorts.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://escorts.example.test/wp-json/exotic-crm-sync/v1',
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

        $rotated = $service->rotateWpCredentials($platform, 'sandbox', 'both');

        return [
            'platform' => $platform->fresh(),
            'product' => $product->fresh(),
            'client' => $client->fresh(['platform']),
            'bearer_key' => (string) $rotated['revealed']['bearer_key'],
            'hmac_secret' => (string) $rotated['revealed']['hmac_secret'],
        ];
    }

    private function walletHeaders(
        Platform $platform,
        string $bearerKey,
        ?string $hmacSecret,
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null
    ): array {
        $timestamp = (string) now()->timestamp;
        $headers = [
            'Authorization' => 'Bearer ' . $bearerKey,
            'X-Exotic-Platform-Id' => (string) $platform->id,
            'X-Exotic-Timestamp' => $timestamp,
        ];

        if ($method !== 'GET' && $idempotencyKey !== null && $hmacSecret !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '';
            $signaturePayload = implode("\n", [
                $timestamp,
                strtoupper($method),
                $path,
                (string) $platform->id,
                $idempotencyKey,
                hash('sha256', $body),
            ]);

            $headers['X-Idempotency-Key'] = $idempotencyKey;
            $headers['X-Exotic-Signature'] = hash_hmac('sha256', $signaturePayload, $hmacSecret);
        }

        return $headers;
    }

    private function fakeProvisioningApis(Platform $platform, Client $client, array $profileOverrides = []): void
    {
        Http::fake($this->provisioningApiFakes($platform, $client, $profileOverrides));
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

    private function configureWalletPin(string $pin = '1234'): void
    {
        app(WalletSettingsService::class)->updateOperatorPin($pin);
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => strtolower($role) . '-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }
}
