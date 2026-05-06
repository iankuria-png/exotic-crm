<?php

namespace Tests\Feature\Billing;

use App\Models\BillingProviderTransaction;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Services\PaymentCompletionService;
use App\Services\SubscriptionProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementTolerancePolicyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_underpaid_wallet_topup_is_held_for_review_without_crediting_wallet(): void
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wallet_balance' => 400,
            'wallet_currency' => 'KES',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'status' => 'pending',
            'completed_at' => null,
            'amount' => 1200,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
                'quoted_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
            ],
        ]);

        $transaction = BillingProviderTransaction::query()->create([
            'payment_id' => $payment->id,
            'provider_type_key' => 'paystack',
            'normalized_status' => 'pending',
            'provider_transaction_id' => 'WALLET-TOPUP-001',
            'provider_invoice_id' => 'WALLET-TOPUP-001',
            'provider_status' => 'initiated',
            'requested_amount' => '1200.00',
            'requested_currency' => 'KES',
            'charge_amount' => '1200.00',
            'charge_currency' => 'KES',
            'attempt_group_key' => 'payment:' . $payment->id . ':provider:paystack',
            'attempt_sequence' => 1,
            'compatibility_reference' => 'WALLET-TOPUP-001',
            'state_version' => 1,
        ]);

        $result = app(PaymentCompletionService::class)->completeTopupPayment($payment, [
            'amount' => 110000,
            'currency' => 'KES',
            'fees' => 2500,
        ]);

        $payment->refresh();
        $transaction->refresh();
        $client->refresh();

        $this->assertFalse($result['credited']);
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->completed_at);
        $this->assertSame('underpaid', data_get($payment->payment_data, 'canonical_state.payment_intent_status'));
        $this->assertSame('underpaid', data_get($payment->payment_data, 'canonical_state.wallet_funding_status'));
        $this->assertSame('underpaid_review_required', data_get($payment->payment_data, 'settlement_assessment.disposition'));
        $this->assertSame('400.00', number_format((float) $client->wallet_balance, 2, '.', ''));
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame('underpaid', $transaction->settlement_status);
        $this->assertSame('1100.00', number_format((float) $transaction->settled_amount, 2, '.', ''));
        $this->assertTrue((bool) data_get($transaction->confirmation_state_json, 'settlement_assessment.review_required'));
    }

    public function test_underpaid_subscription_payment_is_held_for_review_without_provisioning(): void
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wallet_balance' => 400,
            'wallet_currency' => 'KES',
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => 'KES',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'subscription',
            'status' => 'pending',
            'completed_at' => null,
            'amount' => 2400,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '2400.00',
                    'currency' => 'KES',
                ],
                'quoted_pricing' => [
                    'amount' => '2400.00',
                    'currency' => 'KES',
                ],
                'duration_key' => 'monthly',
                'duration_days' => 30,
                'duration_label' => 'Monthly',
            ],
            'raw_payload' => [
                'method' => 'link',
            ],
        ]);

        $transaction = BillingProviderTransaction::query()->create([
            'payment_id' => $payment->id,
            'provider_type_key' => 'paystack',
            'normalized_status' => 'pending',
            'provider_transaction_id' => 'SUB-TOPUP-001',
            'provider_invoice_id' => 'SUB-TOPUP-001',
            'provider_status' => 'initiated',
            'requested_amount' => '2400.00',
            'requested_currency' => 'KES',
            'charge_amount' => '2400.00',
            'charge_currency' => 'KES',
            'attempt_group_key' => 'payment:' . $payment->id . ':provider:paystack',
            'attempt_sequence' => 1,
            'compatibility_reference' => 'SUB-TOPUP-001',
            'state_version' => 1,
        ]);

        $result = app(PaymentCompletionService::class)->completeSubscriptionPayment($payment, [
            'amount' => 200000,
            'currency' => 'KES',
            'fees' => 3000,
        ], [
            'client' => $client,
        ]);

        $payment->refresh();
        $transaction->refresh();

        $this->assertFalse($result['provisioned']);
        $this->assertNull($result['deal']);
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->deal_id);
        $this->assertNull($payment->completed_at);
        $this->assertSame('underpaid', data_get($payment->payment_data, 'canonical_state.payment_intent_status'));
        $this->assertSame('underpaid_review_required', data_get($payment->payment_data, 'canonical_state.provisioning_status'));
        $this->assertSame('underpaid_review_required', data_get($payment->payment_data, 'settlement_assessment.disposition'));
        $this->assertDatabaseCount('deals', 0);
        $this->assertSame('underpaid', $transaction->settlement_status);
        $this->assertSame('2000.00', number_format((float) $transaction->settled_amount, 2, '.', ''));
    }

    public function test_cfa_alias_to_xof_subscription_settlement_reaches_provisioning(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Benin',
            'country' => 'Benin',
            'currency_code' => 'CFA',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 930,
            'wp_post_id' => 4093,
            'phone_normalized' => '229162191426',
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VIP',
            'display_name' => 'Vip',
            'currency' => 'CFA',
            'biweekly_price' => 22000,
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'escort_post_id' => $client->wp_post_id,
            'purpose' => 'subscription',
            'source' => 'self_checkout',
            'status' => 'pending',
            'completed_at' => null,
            'amount' => 22000,
            'currency' => 'CFA',
            'provider_key' => 'pawapay',
            'provider_environment' => 'production',
            'duration' => 'biweekly',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '22000.00',
                    'currency' => 'CFA',
                ],
                'quoted_pricing' => [
                    'amount' => '22000.00',
                    'currency' => 'CFA',
                ],
                'duration_key' => '2_weeks',
                'duration_days' => 14,
                'duration_label' => '2 Weeks',
            ],
            'raw_payload' => [
                'method' => 'hosted_checkout',
            ],
        ]);

        $transaction = BillingProviderTransaction::query()->create([
            'payment_id' => $payment->id,
            'provider_type_key' => 'pawapay',
            'normalized_status' => 'pending',
            'provider_transaction_id' => '2ce8609c-0316-4ebf-8cca-6146077fa93c',
            'provider_status' => 'initiated',
            'requested_amount' => '22000.00',
            'requested_currency' => 'CFA',
            'charge_amount' => '22000.00',
            'charge_currency' => 'CFA',
            'attempt_group_key' => 'payment:' . $payment->id . ':provider:pawapay',
            'attempt_sequence' => 1,
            'compatibility_reference' => '2ce8609c-0316-4ebf-8cca-6146077fa93c',
            'state_version' => 1,
        ]);

        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'payment_id' => $payment->id,
            'amount' => 22000,
            'currency' => 'CFA',
            'duration' => 'biweekly',
            'duration_days' => 14,
            'status' => 'active',
        ]);

        $this->mock(SubscriptionProvisioningService::class, function ($mock) use ($deal) {
            $mock->shouldReceive('provisionCompletedPayment')->once()->andReturn($deal);
        });

        $result = app(PaymentCompletionService::class)->completeSubscriptionPayment($payment, [
            'depositId' => '2ce8609c-0316-4ebf-8cca-6146077fa93c',
            'status' => 'COMPLETED',
            'amount' => '22000.00',
            'currency' => 'XOF',
            'country' => 'BEN',
            'providerTransactionId' => '12029094146',
        ], [
            'client' => $client,
            'transaction_reference' => '2ce8609c-0316-4ebf-8cca-6146077fa93c',
        ]);

        $payment->refresh();
        $transaction->refresh();

        $this->assertTrue($result['provisioned']);
        $this->assertSame('completed', $payment->status);
        $this->assertNotNull($payment->completed_at);
        $this->assertSame('completed', data_get($payment->payment_data, 'canonical_state.provisioning_status'));
        $this->assertSame('accepted_exact', data_get($payment->payment_data, 'settlement_assessment.disposition'));
        $this->assertSame('CFA', data_get($payment->payment_data, 'settlement_assessment.expected_currency'));
        $this->assertSame('XOF', data_get($payment->payment_data, 'settlement_assessment.expected_settlement_currency'));
        $this->assertSame('XOF', data_get($payment->payment_data, 'settlement_assessment.settled_currency'));
        $this->assertSame('XOF', data_get($payment->payment_data, 'settlement_assessment.settled_settlement_currency'));
        $this->assertSame('settled_exact', $transaction->settlement_status);
        $this->assertSame('22000.00', number_format((float) $transaction->settled_amount, 2, '.', ''));
    }

    public function test_currency_mismatch_subscription_hold_uses_specific_provisioning_status(): void
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => 'KES',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'purpose' => 'subscription',
            'status' => 'pending',
            'completed_at' => null,
            'amount' => 2400,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '2400.00',
                    'currency' => 'KES',
                ],
                'duration_key' => 'monthly',
                'duration_days' => 30,
                'duration_label' => 'Monthly',
            ],
        ]);

        BillingProviderTransaction::query()->create([
            'payment_id' => $payment->id,
            'provider_type_key' => 'paystack',
            'normalized_status' => 'pending',
            'provider_transaction_id' => 'SUB-CURRENCY-MISMATCH-001',
            'provider_invoice_id' => 'SUB-CURRENCY-MISMATCH-001',
            'provider_status' => 'initiated',
            'requested_amount' => '2400.00',
            'requested_currency' => 'KES',
            'charge_amount' => '2400.00',
            'charge_currency' => 'KES',
            'attempt_group_key' => 'payment:' . $payment->id . ':provider:paystack',
            'attempt_sequence' => 1,
            'compatibility_reference' => 'SUB-CURRENCY-MISMATCH-001',
            'state_version' => 1,
        ]);

        $result = app(PaymentCompletionService::class)->completeSubscriptionPayment($payment, [
            'amount' => 240000,
            'currency' => 'USD',
        ], [
            'client' => $client,
        ]);

        $payment->refresh();

        $this->assertFalse($result['provisioned']);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('currency_mismatch', data_get($payment->payment_data, 'canonical_state.payment_intent_status'));
        $this->assertSame('currency_mismatch_review_required', data_get($payment->payment_data, 'canonical_state.provisioning_status'));
        $this->assertSame('currency_mismatch_review_required', data_get($payment->payment_data, 'settlement_assessment.disposition'));
    }

    public function test_overpaid_wallet_topup_credits_expected_value_and_marks_review(): void
    {
        $platform = Platform::factory()->create([
            'currency_code' => 'KES',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wallet_balance' => 400,
            'wallet_currency' => 'KES',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'user_id' => $client->wp_user_id,
            'product_id' => null,
            'purpose' => 'wallet_topup',
            'status' => 'pending',
            'completed_at' => null,
            'amount' => 1200,
            'currency' => 'KES',
            'provider_key' => 'paystack',
            'payment_data' => [
                'charge_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
                'quoted_pricing' => [
                    'amount' => '1200.00',
                    'currency' => 'KES',
                ],
            ],
        ]);

        $transaction = BillingProviderTransaction::query()->create([
            'payment_id' => $payment->id,
            'provider_type_key' => 'paystack',
            'normalized_status' => 'pending',
            'provider_transaction_id' => 'WALLET-TOPUP-OVERPAID-001',
            'provider_invoice_id' => 'WALLET-TOPUP-OVERPAID-001',
            'provider_status' => 'initiated',
            'requested_amount' => '1200.00',
            'requested_currency' => 'KES',
            'charge_amount' => '1200.00',
            'charge_currency' => 'KES',
            'attempt_group_key' => 'payment:' . $payment->id . ':provider:paystack',
            'attempt_sequence' => 1,
            'compatibility_reference' => 'WALLET-TOPUP-OVERPAID-001',
            'state_version' => 1,
        ]);

        $result = app(PaymentCompletionService::class)->completeTopupPayment($payment, [
            'amount' => 135000,
            'currency' => 'KES',
            'fees' => 2500,
        ]);

        $payment->refresh();
        $transaction->refresh();
        $client->refresh();

        $this->assertTrue($result['credited']);
        $this->assertSame('completed', $payment->status);
        $this->assertNotNull($payment->completed_at);
        $this->assertSame('completed', data_get($payment->payment_data, 'canonical_state.payment_intent_status'));
        $this->assertSame('credited', data_get($payment->payment_data, 'canonical_state.wallet_funding_status'));
        $this->assertSame('overpaid_review_required', data_get($payment->payment_data, 'settlement_assessment.disposition'));
        $this->assertTrue((bool) data_get($payment->payment_data, 'settlement_review_required'));
        $this->assertSame('1600.00', number_format((float) $client->wallet_balance, 2, '.', ''));
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame('overpaid', $transaction->settlement_status);
        $this->assertSame('1350.00', number_format((float) $transaction->settled_amount, 2, '.', ''));
        $this->assertTrue((bool) data_get($transaction->confirmation_state_json, 'settlement_assessment.review_required'));
    }
}
