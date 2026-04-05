<?php

namespace Tests\Unit\Billing;

use App\Billing\Providers\Pesapal\PesapalCompatibilityAdapter;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\HostedCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PesapalCompatibilityAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_initialize_delegates_to_hosted_checkout_service(): void
    {
        $payment = $this->makePayment();
        $context = ['environment' => 'production'];
        $options = ['description' => 'Wallet top-up'];

        $hostedCheckoutService = Mockery::mock(HostedCheckoutService::class);
        $hostedCheckoutService->shouldReceive('initializePesapal')
            ->once()
            ->with(Mockery::on(fn (Payment $resolved) => $resolved->is($payment)), $context, $options)
            ->andReturn(['type' => 'redirect', 'url' => 'https://checkout.example.test']);

        $adapter = new PesapalCompatibilityAdapter($hostedCheckoutService);

        $result = $adapter->initialize($payment, $context, $options);

        $this->assertSame('redirect', $result['type']);
    }

    public function test_verify_delegates_to_hosted_checkout_service(): void
    {
        $payment = $this->makePayment();
        $context = ['environment' => 'production'];

        $hostedCheckoutService = Mockery::mock(HostedCheckoutService::class);
        $hostedCheckoutService->shouldReceive('verifyPesapalTransaction')
            ->once()
            ->with(Mockery::on(fn (Payment $resolved) => $resolved->is($payment)), $context, 'TRACK-ABC')
            ->andReturn(['status' => 'completed']);

        $adapter = new PesapalCompatibilityAdapter($hostedCheckoutService);

        $result = $adapter->verify($payment, $context, 'TRACK-ABC');

        $this->assertSame('completed', $result['status']);
    }

    private function makePayment(): Payment
    {
        $platform = Platform::factory()->create();

        return Payment::factory()->create([
            'platform_id' => $platform->id,
            'provider_key' => 'pesapal',
            'provider_environment' => 'production',
            'reference_number' => 'PESAPAL-UNIT-001',
            'transaction_reference' => 'TRACK-ABC',
        ]);
    }
}
