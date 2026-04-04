<?php

namespace Tests\Unit\Billing;

use App\Billing\Support\CanonicalPaymentStateReducer;
use App\Models\Payment;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalPaymentStateReducerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_wallet_funding_completion_metadata(): void
    {
        $reducer = app(CanonicalPaymentStateReducer::class);
        $payment = $this->makePayment([
            'purpose' => 'wallet_topup',
            'status' => 'pending',
        ]);

        $projection = $reducer->complete($payment, [
            'payment_data' => ['existing' => true],
            'wallet_funding_status' => 'credited',
            'transition' => 'wallet_credit_succeeded',
        ]);

        $this->assertSame('completed', $projection['status']);
        $this->assertNull($projection['failure_reason']);
        $this->assertSame('completed', data_get($projection, 'payment_data.canonical_state.payment_intent_status'));
        $this->assertSame('credited', data_get($projection, 'payment_data.canonical_state.wallet_funding_status'));
        $this->assertSame('wallet_credit_succeeded', data_get($projection, 'payment_data.canonical_state.transition'));
        $this->assertTrue((bool) data_get($projection, 'payment_data.existing'));
    }

    public function test_it_projects_sandbox_subscription_completion_metadata(): void
    {
        $reducer = app(CanonicalPaymentStateReducer::class);
        $payment = $this->makePayment([
            'purpose' => 'subscription',
            'status' => 'pending',
        ]);

        $projection = $reducer->complete($payment, [
            'provisioning_status' => 'suppressed_sandbox',
            'sandbox_suppressed' => true,
        ]);

        $this->assertSame('completed', $projection['status']);
        $this->assertSame('suppressed_sandbox', data_get($projection, 'payment_data.canonical_state.provisioning_status'));
        $this->assertTrue((bool) data_get($projection, 'payment_data.canonical_state.sandbox_suppressed'));
    }

    public function test_it_projects_failure_metadata_for_wallet_funding(): void
    {
        $reducer = app(CanonicalPaymentStateReducer::class);
        $payment = $this->makePayment([
            'purpose' => 'wallet_topup',
            'status' => 'pending',
        ]);

        $projection = $reducer->fail($payment, 'Declined by provider', [
            'transition' => 'reconciliation_provider_failed',
        ]);

        $this->assertSame('failed', $projection['status']);
        $this->assertSame('Declined by provider', $projection['failure_reason']);
        $this->assertSame('failed', data_get($projection, 'payment_data.canonical_state.payment_intent_status'));
        $this->assertSame('provider_failed', data_get($projection, 'payment_data.canonical_state.wallet_funding_status'));
        $this->assertSame('reconciliation_provider_failed', data_get($projection, 'payment_data.canonical_state.transition'));
    }

    private function makePayment(array $overrides = []): Payment
    {
        $platform = Platform::factory()->create();

        return Payment::factory()->make(array_merge([
            'platform_id' => $platform->id,
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'purpose' => 'wallet_topup',
            'status' => 'pending',
            'payment_data' => [],
        ], $overrides));
    }
}
