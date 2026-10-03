<?php

namespace App\Services\SendLove;

use App\Billing\Settlement\SettlementTolerancePolicy;
use App\Billing\Support\BillingProviderTransactionRecorder;
use App\Billing\Support\CanonicalPaymentStateReducer;
use App\Models\LoveGift;
use App\Models\Payment;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class FulfillmentService
{
    public function complete(Payment $payment, array $payload = [], array $options = []): array
    {
        return DB::transaction(function () use ($payment, $payload, $options) {
            $g = LoveGift::where('payment_id', $payment->id)->lockForUpdate()->firstOrFail();
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($g->status, ['sent', 'refunded'], true)) {
                return ['payment' => $p, 'credited' => false, 'replayed' => true];
            }
            $a = app(SettlementTolerancePolicy::class)->evaluate($p, $payload, $options);
            app(BillingProviderTransactionRecorder::class)->recordSettlement($p, $a, $payload);
            if ($a['settled_amount'] === null || (int) round($a['settled_amount'] * 100) < (int) round((float) $g->amount * 100) || $a['settled_settlement_currency'] !== $a['expected_settlement_currency']) {
                $g->update(['status' => 'review']);
                $p->update(['reconciliation_state' => 'manual_review', 'payment_data' => array_merge($p->payment_data ?? [], ['settlement_assessment' => $a])]);

                return ['payment' => $p, 'credited' => false, 'replayed' => false, 'review' => true];
            }
            $sandbox = $g->is_sandbox || $p->isSandboxTest() || $p->isClassifiedTest();
            $transaction = null;
            if (! $sandbox && (float) $g->creator_credit_amount > 0) {
                $transaction = app(WalletService::class)->credit($g->client, $g->currency, (float) $g->creator_credit_amount, ['payment' => $p, 'idempotency_key' => 'love:'.$g->public_id, 'reference_type' => 'love_received', 'reference_id' => $g->id, 'description' => 'Love received'])['transaction'];
            }
            $g->update(['status' => 'sent', 'sent_at' => now(), 'wallet_transaction_id' => $transaction?->id, 'is_sandbox' => $sandbox, 'provider_fee' => $a['fee_amount'] ?? 0]);
            $p->update(app(CanonicalPaymentStateReducer::class)->complete($p, ['sandbox_suppressed' => $sandbox, 'payment_data' => array_merge($p->payment_data ?? [], ['settlement_assessment' => $a, 'send_love_status' => 'sent'])]) + ['reconciliation_state' => 'resolved']);
            if (! $sandbox) {
                \App\Jobs\SendLoveNotice::dispatch($g->id)->afterCommit();
            }

            return ['payment' => $p->fresh(), 'credited' => ! $sandbox, 'replayed' => false];
        }, 3);
    }
}
