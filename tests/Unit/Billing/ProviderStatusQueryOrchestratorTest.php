<?php

namespace Tests\Unit\Billing;

use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\BillingModeService;
use App\Services\HostedCheckoutService;
use App\Services\ProviderStatusQueryOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProviderStatusQueryOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_payments_ignore_late_failed_provider_signals(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $payment = $this->makePayment([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $decision = $orchestrator->decideMutation($payment, [
            'provider' => 'paystack',
            'provider_environment' => 'production',
            'provider_reference' => 'PSTK-STATUS-001',
            'status' => 'failed',
        ]);

        $this->assertSame('noop_terminal', $decision['decision']);
        $this->assertSame('completed', $decision['winning_status']);
        $this->assertTrue($decision['late_signal']);
    }

    public function test_failed_payments_can_recover_on_late_success_for_the_same_routed_contract(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $payment = $this->makePayment([
            'status' => 'failed',
            'failure_reason' => 'Timed out',
        ]);

        $decision = $orchestrator->decideMutation($payment, [
            'provider' => 'paystack',
            'provider_environment' => 'production',
            'provider_reference' => 'PSTK-STATUS-001',
            'status' => 'completed',
        ]);

        $this->assertSame('apply_completed', $decision['decision']);
        $this->assertSame('completed', $decision['winning_status']);
        $this->assertTrue($decision['late_signal']);
    }

    public function test_mismatched_provider_contracts_are_treated_as_superseded(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $payment = $this->makePayment([
            'provider_key' => 'paystack_checkout',
            'provider_environment' => 'sandbox',
            'reference_number' => 'PSTK-STATUS-001',
        ]);

        BillingRoutingDecision::query()->create([
            'payment_id' => $payment->id,
            'market_id' => $payment->platform_id,
            'billing_surface' => 'payment_link',
            'provider_type_key' => 'paystack',
            'execution_mode' => 'proxy',
            'environment' => 'sandbox',
            'decision_version' => 1,
            'snapshot_json' => [
                'provider_key' => 'paystack_checkout',
                'provider_type_key' => 'paystack',
                'environment' => 'sandbox',
            ],
            'decision_json' => [
                'source' => 'test',
            ],
            'immutable_until_terminal_state' => true,
            'created_at' => now(),
        ]);

        $decision = $orchestrator->decideMutation($payment->fresh('routingDecisions'), [
            'provider' => 'paystack',
            'provider_environment' => 'sandbox',
            'provider_reference' => 'WRONG-REFERENCE',
            'status' => 'completed',
        ]);

        $this->assertSame('noop_superseded', $decision['decision']);
        $this->assertTrue($decision['superseded']);
        $this->assertSame('pending', $decision['winning_status']);
    }

    private function makeOrchestrator(): ProviderStatusQueryOrchestrator
    {
        return new ProviderStatusQueryOrchestrator(
            Mockery::mock(BillingModeService::class),
            Mockery::mock(HostedCheckoutService::class)
        );
    }

    private function makePayment(array $overrides = []): Payment
    {
        $platform = Platform::factory()->create();

        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'reference_number' => 'PSTK-STATUS-001',
            'transaction_reference' => 'PSTK-STATUS-001',
            'status' => 'pending',
            'completed_at' => null,
            'wallet_transaction_id' => null,
        ], $overrides));
    }
}
