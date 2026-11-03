<?php

namespace App\Services\Tours;

use App\Billing\Settlement\SettlementTolerancePolicy;
use App\Billing\Support\BillingProviderTransactionRecorder;
use App\Billing\Support\CanonicalPaymentStateReducer;
use App\Models\Payment;
use App\Models\TourOrder;
use App\Models\TourPaymentAttempt;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class FulfillmentService
{
    public function wallet(TourOrder $order, TourPaymentAttempt $attempt): array
    {
        return DB::transaction(function () use ($order, $attempt) {
            $order = TourOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $attempt = TourPaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->environment === 'production' && $attempt->method === 'wallet', 403, 'Wallet payments are unavailable in Tours test mode.');
            if ($order->winning_attempt_id) {
                abort_unless((int) $order->winning_attempt_id === (int) $attempt->id, 409, 'This order was paid with another attempt.');

                return ['paid' => true, 'replayed' => true];
            }
            abort_unless($order->state !== 'cancelled', 409, 'This order was cancelled.');
            $result = app(WalletService::class)->debit($order->client, $order->currency, $order->amount_minor / Money::factor($order->currency), ['idempotency_key' => 'tour:'.$order->public_id, 'reference_type' => 'tour_pass', 'reference_id' => $order->id, 'description' => 'Tour Pass']);
            $ledger = $result['transaction'];
            abort_unless((int) $ledger->client_id === (int) $order->client_id && $ledger->type === 'debit' && $ledger->currency_code === $order->currency && (int) round($ledger->amount * Money::factor($order->currency)) === $order->amount_minor && $ledger->idempotency_key === 'tour:'.$order->public_id, 500, 'Wallet debit could not be verified.');
            $attempt->update(['wallet_transaction_id' => $ledger->id, 'state' => 'paid', 'action' => ['type' => 'paid']]);
            $this->win($order, $attempt);

            return ['paid' => true, 'replayed' => (bool) $result['replayed']];
        }, 3);
    }

    public function complete(Payment $payment, array $payload = [], array $options = []): array
    {
        return DB::transaction(function () use ($payment, $payload, $options) {
            $attempt = TourPaymentAttempt::where('payment_id', $payment->id)->firstOrFail();
            $order = TourOrder::whereKey($attempt->tour_order_id)->lockForUpdate()->firstOrFail();
            $attempt = TourPaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($attempt->state === 'paid' && (int) $order->winning_attempt_id === (int) $attempt->id) {
                return ['payment' => $payment, 'replayed' => true];
            }
            $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, $payload, $options);
            app(BillingProviderTransactionRecorder::class)->recordSettlement($payment, $assessment, $payload);
            if ($assessment['settled_amount'] === null || (int) round($assessment['settled_amount'] * Money::factor($order->currency)) !== $order->amount_minor || $assessment['settled_settlement_currency'] !== $assessment['expected_settlement_currency'] || $payment->currency !== $order->currency || $payment->provider_environment !== $order->environment) {
                $attempt->update(['state' => 'review', 'action' => ['type' => 'review', 'message' => 'Payment details need review.']]);
                $payment->update(['reconciliation_state' => 'manual_review']);

                return ['payment' => $payment, 'review' => true, 'replayed' => false];
            }
            if (($order->winning_attempt_id && (int) $order->winning_attempt_id !== (int) $attempt->id) || $order->state === 'cancelled') {
                $attempt->update(['state' => 'duplicate_settlement', 'action' => ['type' => 'review', 'message' => 'A further payment was received and needs refund review.']]);
                $payment->update(['reconciliation_state' => 'manual_review', 'payment_data' => array_merge($payment->payment_data ?? [], ['tour_duplicate_settlement' => true])]);

                return ['payment' => $payment, 'review' => true];
            }
            $sandbox = $order->environment !== 'production' || $payment->isSandboxTest() || $payment->isClassifiedTest();
            $payment->update(app(CanonicalPaymentStateReducer::class)->complete($payment, ['sandbox_suppressed' => $sandbox, 'payment_data' => array_merge($payment->payment_data ?? [], ['settlement_assessment' => $assessment, 'tour_order_reference' => $order->public_id])]) + ['reconciliation_state' => 'resolved']);
            $attempt->update(['state' => 'paid', 'action' => ['type' => 'paid']]);
            if ($sandbox) {
                $order->update(['state' => 'sandbox_paid', 'winning_attempt_id' => $attempt->id, 'paid_at' => now()]);
            } else {
                $this->win($order, $attempt);
            }

            return ['payment' => $payment->fresh(), 'paid' => ! $sandbox, 'sandbox' => $sandbox, 'replayed' => false];
        }, 3);
    }

    private function win(TourOrder $order, TourPaymentAttempt $attempt): void
    {
        $order->update(['state' => 'paid', 'winning_attempt_id' => $attempt->id, 'paid_at' => now(), 'sync_error' => null]);
        // Stop rows are the durable outbox; the scheduled sweep recovers a lost queue dispatch.
        $order->stops()->update(['state' => 'pending', 'next_attempt_at' => now()]);
        \App\Jobs\ReconcileTourOrder::dispatch($order->id)->afterCommit();
    }
}
