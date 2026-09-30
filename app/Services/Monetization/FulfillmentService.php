<?php

namespace App\Services\Monetization;

use App\Billing\Settlement\SettlementTolerancePolicy;
use App\Billing\Support\BillingProviderTransactionRecorder;
use App\Models\Payment;
use App\Models\VisitorContentPurchase;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class FulfillmentService
{
    public function complete(Payment $payment, array $payload = [], array $options = []): array
    {
        return DB::transaction(function () use ($payment, $payload, $options) {
            $purchase = VisitorContentPurchase::where('payment_id', $payment->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($purchase->status, ['active', 'refunded', 'revoked'], true)) {
                return ['payment' => $payment, 'credited' => false, 'replayed' => true];
            }
            $assessment = app(SettlementTolerancePolicy::class)->evaluate($payment, $payload, $options);
            app(BillingProviderTransactionRecorder::class)->recordSettlement($payment, $assessment, $payload);
            $settled = $assessment['settled_amount'];
            $valid = $settled !== null && (int) round($settled * 100) >= (int) round((float) $purchase->gross_amount * 100) && $assessment['settled_settlement_currency'] === $assessment['expected_settlement_currency'];
            $deliverable = collect($purchase->entitlement_snapshot_json)->every(fn ($a) => \App\Models\PremiumContentAsset::where('platform_id', $purchase->platform_id)->where('public_id', $a['public_id'])->where('content_fingerprint', $a['content_fingerprint'])->whereIn('status', ['ready', 'public'])->exists());
            if (! $valid || ! $deliverable) {
                $purchase->update(['status' => 'review']);
                $payment->update(['reconciliation_state' => 'manual_review', 'payment_data' => array_merge($payment->payment_data ?? [], ['settlement_assessment' => $assessment])]);

                return ['payment' => $payment, 'credited' => false, 'replayed' => false, 'review' => true];
            }
            $sandbox = $purchase->is_sandbox || $payment->isSandboxTest() || $payment->isClassifiedTest();
            $credits = [];
            // Every earning escort is credited in this transaction; a partial credit cannot commit.
            $allocations = $sandbox && ! $purchase->client_id && ! $purchase->allocations()->exists() ? collect() : app(PurchaseAllocationService::class)->forFulfilment($purchase);
            $offer = $purchase->offer;
            $collection = $offer?->isMultiCreator();
            foreach ($allocations as $allocation) {
                if ($sandbox) {
                    $allocation->update(['status' => 'suppressed']);

                    continue;
                }
                $client = $allocation->client;
                abort_unless($client, 409, 'The creator account needs a settlement review.');
                $amount = $allocation->amount();
                try {
                    $result = app(WalletService::class)->credit($client, $purchase->currency, (float) $amount, ['payment' => $payment, 'idempotency_key' => $allocation->idempotency_key, 'reference_type' => 'premium_content_sale', 'reference_id' => $purchase->id, 'description' => $collection ? 'Exotic collection share · '.$offer->title : 'Private content sale']);
                    $credit = $result['transaction'];
                } catch (QueryException $e) {
                    $credit = WalletTransaction::where('client_id', $client->id)->where('currency_code', $purchase->currency)->where('idempotency_key', $allocation->idempotency_key)->first();
                    if (! $credit) {
                        throw $e;
                    }
                }
                $allocation->update(['status' => 'credited', 'wallet_transaction_id' => $credit->id]);
                $credits[] = [$allocation, $credit];
            }
            $creditedMinor = collect($credits)->sum(fn ($pair) => $pair[0]->amount_minor);
            abort_if(! $sandbox && $creditedMinor !== (int) round((float) $purchase->gross_amount * 100), 409, 'Creator allocations do not reconcile to the purchase amount.');
            $purchase->update(['status' => 'active', 'purchased_at' => now(), 'is_sandbox' => $sandbox, 'wallet_transaction_id' => $credits[0][1]->id ?? null, 'creator_credit_amount' => $sandbox ? '0.00' : $purchase->gross_amount, 'provider_fee' => $assessment['fee_amount'] ?? 0]);
            $state = app(\App\Billing\Support\CanonicalPaymentStateReducer::class)->complete($payment, ['sandbox_suppressed' => $sandbox, 'payment_data' => array_merge($payment->payment_data ?? [], ['settlement_assessment' => $assessment, 'premium_content_status' => 'active'])]);
            $payment->update($state + ['reconciliation_state' => 'resolved']);
            foreach ($allocations as $allocation) {
                $share = $allocation->amount();
                $reason = $sandbox ? 'Sandbox purchase completed. No wallet credit.' : ($collection ? 'Your share of '.$offer->title.' added '.$purchase->currency.' '.$share.' to your Exotic credit.' : 'A private content sale added '.$purchase->currency.' '.$share.' to your Exotic credit.');
                $notice = \App\Models\PremiumContentEvent::create(['platform_id' => $purchase->platform_id, 'client_id' => $allocation->client_id, 'purchase_id' => $purchase->id, 'kind' => 'sale_notice', 'reason' => $reason, 'metadata_json' => ['is_sandbox' => $sandbox, 'would_credit' => $share, 'collection' => $collection ? $offer->title : null, 'share_denominator' => $allocation->share_denominator]]);
                if (! $sandbox) {
                    \App\Jobs\SendMonetizationNotice::dispatch($notice->id)->afterCommit();
                }
            }

            return ['payment' => $payment->fresh(), 'credited' => ! $sandbox, 'replayed' => false];
        }, 3);
    }
}
