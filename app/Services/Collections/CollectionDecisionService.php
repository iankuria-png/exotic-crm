<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class CollectionDecisionService
{
    public function evaluate(int $receiptId): CollectionReceipt
    {
        $receipt = CollectionReceipt::findOrFail($receiptId);

        // Selection is a suggestion until the approval transaction rechecks everything.
        return DB::transaction(function () use ($receipt) {
            CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
            $r = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
            if (\Illuminate\Support\Facades\Schema::hasTable('collection_sales_sessions')
                && DB::table('collection_sales_sessions')->where('receipt_id', $r->id)->where('state', '!=', 'draft')->exists()) {
                return $r; // This receipt's manual-sale owner governs dispatch and recovery.
            }
            if (CollectionAllocation::where('receipt_id', $r->id)->lockForUpdate()->first() !== null) {
                return $r; // Enrichment never silently moves or re-grants an existing allocation.
            }
            $candidates = $this->candidates($r);
            $snapshot = ['rule_version' => config('collection.rule_version'), 'intents' => $candidates, 'reasons' => [], 'policy_version' => data_get($r->account->policy, 'version')];
            try {
                app(CollectionGate::class)->assertFunds($r);
                if (! $r->payer_fingerprint && $r->enrichment_deadline->isFuture()) {
                    $r->update(['candidate_snapshot' => $snapshot, 'decision_state' => 'collecting']);
                    app(CollectionIngestService::class)->outbox('decide', (string) $r->id, 'enrich:'.$r->id, $r->enrichment_deadline);

                    return $r;
                }
                // Retain identity evidence even when purchase or rollout checks hold activation.
                $match = app(CollectionMatchService::class)->automatic($r);
                $snapshot['matching'] = $match;
                if (! $candidates && data_get($r->account->policy, 'purchase_matching') === 'exact_catalogue_or_reviewed') {
                    $matching = $match;
                    if (! $matching['selected_client_id']) {
                        throw new CollectionException('client_match_below_threshold_or_conflicting');
                    }
                    app(CollectionAutomationEpoch::class)->assertReceipt($r);
                    $platforms = [(int) \App\Models\Client::findOrFail($matching['selected_client_id'])->platform_id];
                    if (! app(CollectionAutomationPolicy::class)->independentSubsidiary($r->account) && ($secondary = config('collection.subsidiary_platforms.'.$platforms[0]))) {
                        $platforms[] = (int) $secondary;
                    }
                    app(CollectionGate::class)->assertWrites($r->account, $platforms, true);
                    app(CollectionCataloguePurchaseMatch::class)->select($r, $matching['selected_client_id']);
                    $candidates = $this->candidates($r);
                    $snapshot['intents'] = $candidates;
                }
                $eligible = array_values(array_filter($candidates, fn ($c) => $c['automatic_eligible']));
                if (count($eligible) !== 1 || count($candidates) > 1) {
                    throw new CollectionException(count($candidates) > 1 ? 'multiple_purchase_candidates' : 'reviewed_purchase_authority_required');
                }
                if ($match['selected_client_id'] !== (int) $eligible[0]['client_id']) {
                    throw new CollectionException('client_match_below_threshold_or_conflicting');
                }
                $snapshot['reasons'][] = 'reviewed_payer_and_purchase_authority';
                $r->update(['candidate_snapshot' => $snapshot, 'decision_state' => 'ready_auto']);
                // Approval only reserves durable work here; no WordPress call inside the transaction.
                app(CollectionApprovalService::class)->reserve($r->id, $eligible[0]['intent_id'], $r->version, null, true);
            } catch (CollectionException $e) {
                $snapshot['reasons'][] = $e->reason;
                $r->update(['candidate_snapshot' => $snapshot, 'decision_state' => $r->historical_only ? 'historical' : 'needs_review']);
            }

            return $r->fresh();
        }, 3);
    }

    public function candidates(CollectionReceipt $receipt): array
    {
        $intents = CollectionPurchaseIntent::where('account_id', $receipt->account_id)->whereNull('revoked_at')->where('valid_until', '>', now())
            ->whereColumn('used_count', '<', 'max_renewals')->get();
        $rows = [];
        foreach ($intents as $intent) {
            $p = $intent->purchase_snapshot;
            $link = $intent->payer_link_id ? \App\Models\Collections\CollectionPayerLink::find($intent->payer_link_id) : null;
            $reference = $intent->provider_reference && $intent->provider_reference === $receipt->reference_norm;
            $payer = $receipt->payer_fingerprint && $link && hash_equals($link->payer_fingerprint, $receipt->payer_fingerprint);
            if (! $reference && ! $payer) {
                continue;
            }
            $reasons = [];
            $eligible = false;
            try {
                app(CollectionPurchaseService::class)->validate($intent, $receipt, true);
                $eligible = true;
                $reasons[] = $intent->authority === 'standing_agreement' ? 'reviewed_standing_renewal' : 'reviewed_current_quote';
            } catch (CollectionException $e) {
                $reasons[] = $e->reason;
            }
            $rows[] = ['intent_id' => $intent->id, 'intent_version' => $intent->version, 'client_id' => $intent->client_id, 'platform_id' => $intent->platform_id,
                'tier' => $p['tier'], 'duration_days' => $p['duration_days'], 'amount_minor' => $p['amount_minor'], 'currency' => $p['currency'],
                'automatic_eligible' => $eligible, 'reasons' => $reasons];
        }

        return $rows;
    }

    /** All positive matching roots are inspected before a new cash row is considered. */
    public function existingPayment(CollectionReceipt $receipt, CollectionPurchaseIntent $intent): ?Payment
    {
        $platformIds = DB::table('collection_account_platforms')->where('account_id', $receipt->account_id)->pluck('platform_id')->all();
        $references = array_unique(array_merge([$receipt->reference_norm], DB::table('collection_receipt_aliases')->where('account_id', $receipt->account_id)->where('receipt_id', $receipt->id)->pluck('reference')->all()));
        $payments = Payment::with(['manualSubmission', 'deal'])->whereIn('platform_id', $platformIds)->where('amount', '>', 0)
            ->where(function ($q) use ($references) {
                foreach ($references as $ref) {
                    $q->orWhere('transaction_reference', 'like', '%'.$ref.'%')->orWhere('reference_number', 'like', '%'.$ref.'%')->orWhere('reference_root', 'like', '%'.$ref.'%');
                }
            })->get()->filter(function ($p) use ($references) {
                foreach ([$p->transaction_reference, $p->reference_number, $p->reference_root] as $field) {
                    foreach ($references as $ref) {
                        if (preg_match('/(?<![A-Z0-9])'.preg_quote($ref, '/').'(?![A-Z0-9])/i', (string) $field)) {
                            return true;
                        }
                    }
                }

                return false;
            });
        if ($intent->payment_id && ! $payments->contains('id', $intent->payment_id)) {
            $payments->push(Payment::with(['manualSubmission', 'deal'])->findOrFail($intent->payment_id));
        }
        if ($payments->count() > 1) {
            throw new CollectionException('multiple_existing_cash_claims');
        }
        $payment = $payments->first();
        if (! $payment) {
            return null;
        }
        if ($payment->manual_payment_bundle_id || $payment->reference_sequence || $payment->provider_environment === 'sandbox' || $payment->record_classification === 'test'
            || data_get($payment->payment_data, 'test_mode') || in_array($payment->status, ['reversed', 'failed', 'cancelled']) || $payment->isVisitorPayment()
            || ! in_array($payment->purpose, [null, 'subscription'])
            || (int) $payment->platform_id !== (int) $intent->platform_id || (int) $payment->client_id !== (int) $intent->client_id
            || app(TzMpesaParser::class)->minor((string) $payment->amount) !== $receipt->principal_minor || $payment->currency !== $receipt->currency) {
            throw new CollectionException('existing_cash_claim_requires_review');
        }
        $ownedAccount = data_get($payment->payment_data, 'collection.account_id');
        $bindings = DB::table('collection_account_platforms')->where('account_id', $receipt->account_id)->where('platform_id', $payment->platform_id)->get();
        $destinationMatches = $payment->manualSubmission && $bindings->contains(fn ($b) => json_decode($b->destination_snapshot, true) === $payment->manualSubmission->destination_snapshot_json);
        if ($ownedAccount !== $receipt->account_id && ! $destinationMatches) {
            throw new CollectionException('legacy_destination_unverified');
        }

        return $payment;
    }
}
