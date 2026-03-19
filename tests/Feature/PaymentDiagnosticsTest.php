<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\PaymentLinkService;
use App\Services\WalletSettingsService;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_endpoint_includes_proxy_lifecycle_timeline_and_recommendations(): void
    {
        ['payment' => $payment, 'platform' => $platform, 'user' => $user] = $this->seedProxyPayment('paystack');

        app(PaymentLinkService::class)->sendLink($payment, [
            'channel' => 'sms',
            'actor_id' => $user->id,
            'reason' => 'Send proxy link for diagnostics',
            'notification_purpose' => 'payment_link',
        ]);

        $payment = $payment->fresh();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy']['opened_at'] = now()->subMinutes(95)->toIso8601String();
        $paymentData['link_proxy']['initialized_at'] = now()->subMinutes(94)->toIso8601String();
        $paymentData['link_proxy']['open_count'] = 2;
        $paymentData['link_proxy']['redirect_url'] = 'https://checkout.paystack.test/redirect';
        $paymentData['link_proxy']['provider_reference'] = 'PSTK-LIVE-001';

        $payment->forceFill([
            'status' => 'pending',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subMinutes(30),
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-DIAG-REF-001',
            'payment_data' => $paymentData,
        ])->saveQuietly();

        TimelineEvent::query()->create([
            'platform_id' => $platform->id,
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'event_type' => 'payment_link_opened',
            'actor_id' => $user->id,
            'content' => [
                'message' => 'Customer opened proxy payment link',
                'open_count' => 2,
            ],
            'created_at' => now()->subMinutes(93),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk()
            ->assertJsonPath('link_proxy.token_status', 'active')
            ->assertJsonPath('link_proxy.session_status', 'checkout_initialized')
            ->assertJsonPath('link_proxy.provider_reference', 'PSTK-LIVE-001')
            ->assertJsonPath('failure.stage', 'provider_checkout_pending')
            ->assertJsonPath('audit_trail.0.action', CrmAuditAction::PAYMENT_SEND_LINK)
            ->assertJsonPath('timeline.0.event_type', 'payment_link_opened');

        $recommendationKeys = collect($response->json('recommendations'))->pluck('key')->all();
        $this->assertContains('sandbox_reconcile', $recommendationKeys);
        $this->assertNotContains('create_subscription', $recommendationKeys);
    }

    public function test_provider_status_check_endpoint_returns_live_snapshot_without_mutating_payment(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('paystack');

        app(PaymentLinkService::class)->sendLink($payment, [
            'channel' => 'sms',
            'actor_id' => $user->id,
            'reason' => 'Prepare proxy session for provider check',
            'notification_purpose' => 'payment_link',
        ]);

        $payment = $payment->fresh();
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy']['initialized_at'] = now()->subMinutes(45)->toIso8601String();
        $paymentData['link_proxy']['provider_reference'] = 'PSTK-LIVE-002';

        $payment->forceFill([
            'status' => 'pending',
            'provider_key' => 'paystack',
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-DIAG-REF-002',
            'payment_data' => $paymentData,
        ])->save();

        Http::fake([
            'https://api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'gateway_response' => 'Approved',
                    'reference' => 'CRM-DIAG-REF-002',
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/check-provider-status");

        $response->assertOk()
            ->assertJsonPath('payment_id', $payment->id)
            ->assertJsonPath('provider', 'paystack')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', 'Approved');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_type' => 'provider_status_check',
            'provider' => 'paystack',
            'status' => 'completed',
        ]);
    }

    public function test_diagnostics_endpoint_blocks_live_subscription_recommendations_for_sandbox_completed_payments(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('paystack');

        $payment->forceFill([
            'status' => 'completed',
            'reconciliation_confidence' => 'high',
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
                'side_effects_skipped' => true,
                'verified_at' => now()->toIso8601String(),
            ],
        ])->save();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk()
            ->assertJsonPath('payment.payment_data.test_mode', true)
            ->assertJsonPath('payment.payment_data.side_effects_skipped', true);

        $recommendationKeys = collect($response->json('recommendations'))->pluck('key')->all();
        $this->assertContains('manual_review', $recommendationKeys);
        $this->assertNotContains('create_subscription', $recommendationKeys);
    }

    public function test_stk_initiate_attempt_populates_diagnostics(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('mpesa_stk');

        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_type' => 'stk_initiate',
            'provider' => 'django_stk',
            'status' => 'success',
            'latency_ms' => 1365,
            'request_meta' => [
                'context_type' => 'browser',
                'origin_url' => 'https://www.exoticnairobi.com',
                'referrer' => 'https://www.exoticnairobi.com/escort/ada',
                'user_agent_family' => 'Chrome',
                'device_type' => 'desktop',
                'ip_hash' => 'hash-browser-1',
            ],
            'response_meta' => [
                'transport' => 'django_proxy',
            ],
        ]);
        $attempt->forceFill([
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ])->saveQuietly();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk()
            ->assertJsonPath('performance.attempt_count', 1)
            ->assertJsonPath('performance.avg_latency_ms', 1365)
            ->assertJsonPath('browser_meta.context_type', 'browser')
            ->assertJsonPath('browser_meta.origin_url', 'https://www.exoticnairobi.com')
            ->assertJsonPath('browser_meta.referrer', 'https://www.exoticnairobi.com/escort/ada')
            ->assertJsonPath('browser_meta.user_agent_family', 'Chrome')
            ->assertJsonPath('attempts.0.attempt_type', 'stk_initiate');
    }

    public function test_browser_meta_prefers_initiation_over_callback(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('mpesa_stk');

        $initiation = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_type' => 'stk_initiate',
            'provider' => 'django_stk',
            'status' => 'success',
            'latency_ms' => 980,
            'request_meta' => [
                'context_type' => 'browser',
                'origin_url' => 'https://www.exoticnairobi.com',
                'referrer' => 'https://www.exoticnairobi.com/escort/grace',
                'user_agent_family' => 'Chrome',
                'device_type' => 'mobile',
                'ip_hash' => 'hash-browser-2',
            ],
        ]);
        $initiation->forceFill([
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ])->saveQuietly();

        $callback = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_type' => 'callback_update',
            'provider' => 'kopokopo_webhook',
            'status' => 'success',
            'request_meta' => [
                'context_type' => 'server',
                'origin_url' => 'https://internal-callback.example.test',
                'referrer' => 'https://internal-callback.example.test/webhook',
                'user_agent_family' => 'Other',
                'device_type' => 'desktop',
                'ip_hash' => 'hash-server-1',
            ],
        ]);
        $callback->forceFill([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ])->saveQuietly();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk()
            ->assertJsonPath('attempts.0.attempt_type', 'callback_update')
            ->assertJsonPath('browser_meta.context_type', 'browser')
            ->assertJsonPath('browser_meta.origin_url', 'https://www.exoticnairobi.com')
            ->assertJsonPath('browser_meta.referrer', 'https://www.exoticnairobi.com/escort/grace')
            ->assertJsonPath('browser_meta.device_type', 'mobile');
    }

    public function test_mpesa_reversal_populates_failure_diagnostics(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->seedProxyPayment('mpesa_stk');

        $payment->forceFill([
            'status' => 'reversed',
            'failure_reason' => 'Payment reversed by provider',
        ])->save();

        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_type' => 'callback_update',
            'provider' => 'kopokopo_webhook',
            'status' => 'failed',
            'error_code' => 'reversed',
            'error_message' => 'Payment reversed by provider',
            'response_meta' => [
                'resourceStatus' => 'Reversed',
            ],
        ]);
        $attempt->forceFill([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ])->saveQuietly();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/payments/{$payment->id}/diagnostics");

        $response->assertOk()
            ->assertJsonPath('failure.stage', 'callback_processing')
            ->assertJsonPath('failure.reason', 'Payment reversed by provider')
            ->assertJsonPath('failure.error_code', 'reversed');
    }

    private function seedProxyPayment(string $provider): array
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Diagnostics Market',
            'country' => 'Kenya',
            'domain' => 'diagnostics-market.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://diagnostics-market.example.test/wp-json/exotic-crm-sync/v1',
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
            'name' => 'Diagnostics Client',
            'phone_normalized' => '254700000222',
            'email' => 'diagnostics-client@example.test',
            'profile_status' => 'publish',
        ]);

        $user = User::query()->create([
            'name' => 'Sales ' . Str::random(5),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'phone' => $client->phone_normalized,
            'amount' => 1500,
            'currency' => 'KES',
            'purpose' => 'subscription',
            'status' => 'initiated',
            'provider_key' => $provider,
            'provider_environment' => 'sandbox',
            'reference_number' => 'CRM-' . Str::upper(Str::random(10)),
            'completed_at' => null,
            'start_date' => null,
            'end_date' => null,
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
        $walletSettings->savePlatformProviderCredentials($platform, [
            'paystack' => [
                'sandbox' => [
                    'public_key' => 'pk_test_wallet',
                    'secret_key' => 'sk_test_wallet',
                ],
            ],
            'pesapal' => [
                'sandbox' => [
                    'consumer_key' => 'pesapal-key',
                    'consumer_secret' => 'pesapal-secret',
                    'ipn_id' => 'ipn-test-001',
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
