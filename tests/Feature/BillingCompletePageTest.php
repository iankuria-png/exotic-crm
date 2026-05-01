<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCompletePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_wallet_topup_renders_client_actions_and_preserves_return_context(): void
    {
        ['payment' => $payment] = $this->seedBillingCompletePayment([
            'payment_data' => [
                'wp_return_url' => 'https://marketplace.example.test/escort/jane/?foo=bar&wallet_refresh=1&wallet_payment_status=pending&wallet_payment_id=999#booking',
            ],
        ]);

        $response = $this->get('/billing/complete?payment=' . $payment->transaction_uuid);

        $expectedRedirectUrl = 'https://marketplace.example.test/escort/jane/?foo=bar&wallet_refresh=1&wallet_payment_status=failed&wallet_payment_id=' . $payment->id . '#booking';
        $expectedRetryUrl = 'https://marketplace.example.test/escort/jane/?foo=bar#booking';

        $response->assertOk()
            ->assertSee('Retry payment')
            ->assertSee('Back home')
            ->assertSee('Contact admin')
            ->assertSee('Return to profile')
            ->assertDontSee('Back to CRM Payments')
            ->assertDontSee('Check Provider Status');

        $content = $response->getContent();
        $this->assertStringContainsString(e($expectedRedirectUrl), $content);
        $this->assertStringContainsString(e($expectedRetryUrl), $content);
        $this->assertStringContainsString('href="https://marketplace.example.test/"', $content);
        $this->assertStringContainsString('href="https://marketplace.example.test/chat"', $content);
        $this->assertStringNotContainsString('https://billing.example.test', $content);
    }

    public function test_private_wallet_topup_returns_to_marketplace_home_with_wallet_params(): void
    {
        ['payment' => $payment] = $this->seedBillingCompletePayment([
            'profile_status' => 'private',
            'payment_data' => [
                'wp_return_url' => 'https://marketplace.example.test/escort/private-profile/?step=checkout',
            ],
        ]);

        $response = $this->get('/billing/complete?payment=' . $payment->transaction_uuid);

        $expectedHomeRedirect = 'https://marketplace.example.test/?wallet_refresh=1&wallet_payment_status=failed&wallet_payment_id=' . $payment->id;

        $response->assertOk()
            ->assertSee('Return to profile')
            ->assertSee('Retry payment')
            ->assertSee('Back home')
            ->assertSee('Contact admin')
            ->assertDontSee('private-profile')
            ->assertDontSee('Back to CRM Payments')
            ->assertDontSee('Check Provider Status');

        $content = $response->getContent();
        $this->assertStringContainsString(e($expectedHomeRedirect), $content);
        $this->assertStringContainsString('href="https://marketplace.example.test/"', $content);
    }

    public function test_draft_and_pending_wallet_topups_return_to_marketplace_home(): void
    {
        foreach (['draft', 'pending'] as $profileStatus) {
            ['payment' => $payment] = $this->seedBillingCompletePayment([
                'profile_status' => $profileStatus,
                'payment_data' => [
                    'wp_return_url' => 'https://marketplace.example.test/escort/' . $profileStatus . '-profile/',
                ],
            ]);

            $response = $this->get('/billing/complete?payment=' . $payment->transaction_uuid);

            $response->assertOk()
                ->assertDontSee($profileStatus . '-profile');

            $this->assertStringContainsString(
                e('https://marketplace.example.test/?wallet_refresh=1&wallet_payment_status=failed&wallet_payment_id=' . $payment->id),
                $response->getContent()
            );
        }
    }

    public function test_contact_admin_prefers_platform_support_chat_url(): void
    {
        ['payment' => $payment] = $this->seedBillingCompletePayment([
            'platform' => [
                'support_chat_url' => 'https://support.example.test/conversation',
            ],
        ]);

        $response = $this->get('/billing/complete?payment=' . $payment->transaction_uuid);

        $response->assertOk()
            ->assertSee('Contact admin');

        $content = $response->getContent();
        $this->assertStringContainsString('href="https://support.example.test/conversation"', $content);
        $this->assertStringNotContainsString('href="https://marketplace.example.test/chat"', $content);
    }

    public function test_unresolved_payment_omits_platform_dependent_client_actions(): void
    {
        $response = $this->get('/billing/complete?payment=missing-payment');

        $response->assertOk()
            ->assertSee('Payment return not found')
            ->assertDontSee('Retry payment')
            ->assertDontSee('Back home')
            ->assertDontSee('Contact admin')
            ->assertDontSee('Back to CRM Payments')
            ->assertDontSee('Check Provider Status');
    }

    private function seedBillingCompletePayment(array $overrides = []): array
    {
        config([
            'app.url' => 'https://crm.example.test',
        ]);

        app(WalletSettingsService::class)->saveSystemConfig([
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

        $platform = Platform::factory()->create(array_merge([
            'name' => 'Marketplace Test',
            'domain' => fake()->unique()->lexify('fallback-market-????') . '.example.test',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://marketplace.example.test/wp-json/exotic-crm-sync/v1',
            'support_chat_url' => null,
        ], $overrides['platform'] ?? []));

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 10072,
            'wp_user_id' => 8801,
            'name' => 'Jane Escort',
            'phone_normalized' => '254700000111',
            'email' => 'jane@example.test',
            'profile_status' => $overrides['profile_status'] ?? 'publish',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'phone' => $client->phone_normalized,
            'amount' => 1500,
            'currency' => 'KES',
            'purpose' => 'wallet_topup',
            'status' => 'failed',
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'reference_number' => 'WTU-COMPLETE-FAIL',
            'transaction_uuid' => fake()->uuid(),
            'payment_data' => $overrides['payment_data'] ?? null,
            'raw_payload' => [],
        ]);

        return [
            'platform' => $platform->fresh(),
            'client' => $client->fresh(),
            'payment' => $payment->fresh(['platform', 'client']),
        ];
    }
}
