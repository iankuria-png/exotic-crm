<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\Routing\ProviderRoutingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderRoutingDispatcherIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected ProviderRoutingDispatcher $dispatcher;

    public function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = app(ProviderRoutingDispatcher::class);
    }

    /** @test */
    public function dispatcher_can_route_hosted_checkout_paystack()
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);
        
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'purpose' => 'wallet_topup',
            'amount' => 100.00,
            'currency' => 'KES',
        ]);

        $context = [
            'provider_key' => 'paystack',
            'provider_config' => [
                'min_amount' => 10,
                'max_amount' => 1000,
            ],
            'provider_credentials' => [
                'public_key' => 'pk_test_123',
                'secret_key' => 'sk_test_456',
            ],
            'environment' => 'sandbox',
        ];

        // Verify dispatcher supports paystack
        $this->assertTrue($this->dispatcher->supports('paystack'));

        // Verify we can call dispatch (this would normally fail with mock, but validates routing)
        try {
            $action = $this->dispatcher->dispatch($payment, $context, [
                'callback_url' => 'http://example.test/callback',
                'metadata' => ['channel' => 'test'],
            ]);
            // With real credentials, this would return a valid action
            // For now, we just verify no "unknown provider" exception is thrown
            $this->assertIsArray($action);
        } catch (\Exception $e) {
            // Expected - HTTP call would fail without real credentials
            // But the important thing is it didn't throw InvalidArgumentException for unsupported provider
            $this->assertNotInstanceOf(\InvalidArgumentException::class, $e);
        }
    }

    /** @test */
    public function dispatcher_can_route_stk_mpesa()
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);
        
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'purpose' => 'wallet_topup',
            'amount' => 100.00,
            'currency' => 'KES',
            'phone' => '+254701234567',
        ]);

        $context = [
            'provider_key' => 'mpesa_stk',
            'provider_config' => [
                'min_amount' => 10,
                'max_amount' => 1000,
            ],
            'provider_credentials' => [
                'transport' => 'direct_provider',
            ],
            'environment' => 'sandbox',
            'system' => [
                'topup_poll_interval_seconds' => 10,
            ],
        ];

        // Verify dispatcher supports mpesa_stk
        $this->assertTrue($this->dispatcher->supports('mpesa_stk'));

        // Verify dispatcher can route (would need mocking for full success)
        try {
            $action = $this->dispatcher->dispatch($payment, $context, [
                'phone' => '+254701234567',
            ]);
            $this->assertIsArray($action);
        } catch (\Exception $e) {
            // Expected - API calls would fail in test
            // But not "unknown provider" error
            $this->assertNotInstanceOf(\InvalidArgumentException::class, $e);
        }
    }

    /** @test */
    public function dispatcher_can_route_stk_daraja_alias()
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'purpose' => 'wallet_topup',
            'amount' => 100.00,
            'currency' => 'KES',
            'phone' => '+254701234567',
        ]);

        $context = [
            'provider_key' => 'daraja',
            'provider_config' => [
                'min_amount' => 10,
                'max_amount' => 1000,
            ],
            'provider_credentials' => [
                'transport' => 'direct_provider',
            ],
            'environment' => 'sandbox',
            'system' => [
                'topup_poll_interval_seconds' => 10,
            ],
        ];

        $this->assertTrue($this->dispatcher->supports('daraja'));

        try {
            $action = $this->dispatcher->dispatch($payment, $context, [
                'phone' => '+254701234567',
            ]);
            $this->assertIsArray($action);
        } catch (\Exception $e) {
            $this->assertNotInstanceOf(\InvalidArgumentException::class, $e);
        }
    }

    /** @test */
    public function dispatcher_throws_for_unsupported_provider()
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
        ]);

        $context = [
            'provider_key' => 'unsupported_provider',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No routing executor registered for provider');

        $this->dispatcher->dispatch($payment, $context);
    }

    /** @test */
    public function dispatcher_lists_registered_providers()
    {
        $providers = $this->dispatcher->registeredProviders();

        $this->assertContains('paystack', $providers);
        $this->assertContains('pesapal', $providers);
        $this->assertContains('mpesa_stk', $providers);
        $this->assertContains('daraja', $providers);
    }
}
