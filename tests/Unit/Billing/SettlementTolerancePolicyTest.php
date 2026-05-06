<?php

namespace Tests\Unit\Billing;

use App\Billing\Settlement\SettlementTolerancePolicy;
use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementTolerancePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_paystack_minor_units_and_accepts_exact_settlement(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 1200,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
            ],
        ]);

        $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, [
            'amount' => 120000,
            'currency' => 'KES',
            'fees' => 1500,
        ]);

        $this->assertSame('accepted_exact', $assessment['disposition']);
        $this->assertSame('settled_exact', $assessment['settlement_status']);
        $this->assertSame(1200.0, $assessment['settled_amount']);
        $this->assertSame('KES', $assessment['settled_currency']);
        $this->assertSame(15.0, $assessment['fee_amount']);
        $this->assertFalse($assessment['review_required']);
    }

    public function test_it_marks_underpaid_settlement_for_review(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 1200,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
            ],
        ]);

        $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, [
            'amount' => 110000,
            'currency' => 'KES',
        ]);

        $this->assertSame('underpaid_review_required', $assessment['disposition']);
        $this->assertSame('underpaid', $assessment['settlement_status']);
        $this->assertSame('hold_for_review', $assessment['completion_policy']);
        $this->assertTrue($assessment['review_required']);
        $this->assertSame(-100.0, $assessment['variance_amount']);
    }

    public function test_it_marks_overpaid_settlement_for_review_without_holding_completion(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 1200,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
            ],
        ]);

        $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, [
            'amount' => 135000,
            'currency' => 'KES',
        ]);

        $this->assertSame('overpaid_review_required', $assessment['disposition']);
        $this->assertSame('overpaid', $assessment['settlement_status']);
        $this->assertSame('allow_completion', $assessment['completion_policy']);
        $this->assertTrue($assessment['review_required']);
        $this->assertSame(150.0, $assessment['variance_amount']);
    }

    public function test_it_holds_currency_mismatch_for_review(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 1200,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
            ],
        ]);

        $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, [
            'amount' => 120000,
            'currency' => 'USD',
        ]);

        $this->assertSame('currency_mismatch_review_required', $assessment['disposition']);
        $this->assertSame('currency_mismatch', $assessment['settlement_status']);
        $this->assertSame('hold_for_review', $assessment['completion_policy']);
        $this->assertTrue($assessment['review_required']);
        $this->assertNull($assessment['variance_amount']);
    }

    public function test_it_accepts_cfa_to_xof_settlement_when_market_context_resolves_to_benin(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 22000,
            'currency' => 'CFA',
            'provider_key' => 'pawapay',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '22000.00',
                    'currency' => 'CFA',
                ],
            ],
        ]);
        $payment->platform->forceFill([
            'name' => 'Benin',
            'country' => 'Benin',
            'currency_code' => 'CFA',
        ])->save();

        $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, [
            'amount' => '22000.00',
            'currency' => 'XOF',
            'country' => 'BEN',
        ]);

        $this->assertSame('accepted_exact', $assessment['disposition']);
        $this->assertSame('settled_exact', $assessment['settlement_status']);
        $this->assertSame('allow_completion', $assessment['completion_policy']);
        $this->assertSame('CFA', $assessment['expected_currency']);
        $this->assertSame('XOF', $assessment['expected_settlement_currency']);
        $this->assertSame('XOF', $assessment['settled_currency']);
        $this->assertSame('XOF', $assessment['settled_settlement_currency']);
        $this->assertSame(0.0, $assessment['variance_amount']);
        $this->assertFalse($assessment['review_required']);
    }

    public function test_it_uses_locked_fx_metadata_from_the_pinned_routing_decision(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 15750,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'quoted_pricing' => [
                    'amount' => '1400.00',
                    'currency' => 'GHS',
                ],
                'charge_pricing' => [
                    'amount' => '15750.00',
                    'currency' => 'KES',
                ],
            ],
        ]);

        BillingRoutingDecision::query()->create([
            'payment_id' => $payment->id,
            'market_id' => $payment->platform_id,
            'billing_surface' => 'self_checkout',
            'provider_type_key' => 'paystack',
            'execution_mode' => 'proxy',
            'environment' => 'production',
            'decision_version' => 1,
            'surface_cutover_flag' => 'billing.shadow_read',
            'snapshot_json' => [
                'fx_quote' => [
                    'mode' => 'fixed_override',
                    'quote_locked' => true,
                    'fx_override' => [
                        'enabled' => true,
                        'applied' => true,
                        'rate' => 11.25,
                        'target_currency' => 'KES',
                    ],
                ],
            ],
            'decision_json' => [
                'source' => 'test',
            ],
            'immutable_until_terminal_state' => true,
            'created_at' => now(),
        ]);

        $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, []);

        $this->assertSame(11.25, $assessment['fx_rate']);
        $this->assertSame('self_checkout_override', $assessment['fx_source']);
        $this->assertNotNull($assessment['fx_locked_at']);
        $this->assertSame(1400.0, $assessment['quoted_amount']);
        $this->assertSame('GHS', $assessment['quoted_currency']);
        $this->assertSame(15750.0, $assessment['expected_amount']);
        $this->assertSame('KES', $assessment['expected_currency']);
    }
}
