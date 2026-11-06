<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;

/** Reconciliation records evidence; it never moves money or adds a second Payment. */
class CollectionSettlementService
{
    public function alias(CollectionReceipt $receipt, User $actor, string $reference, string $evidence): void
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        DB::transaction(function () use ($receipt, $actor, $reference, $evidence) {
            CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
            $reference = trim($reference);
            if ($reference === '' || strlen($reference) > 128 || trim($evidence) === '') {
                throw new CollectionException('reviewed_alias_evidence_required', 422);
            }
            $old = DB::table('collection_receipt_aliases')->where('account_id', $receipt->account_id)->where('reference', $reference)->lockForUpdate()->first();
            if ($old && (int) $old->receipt_id !== (int) $receipt->id) {
                throw new CollectionException('reference_alias_already_bound');
            }
            if (! $old) {
                DB::table('collection_receipt_aliases')->insert(['account_id' => $receipt->account_id, 'receipt_id' => $receipt->id, 'reference' => $reference,
                    'evidence_reference' => $evidence, 'reviewed_by' => $actor->id, 'created_at' => now()]);
                app(CollectionPurchaseService::class)->audit($receipt, $actor->id, 'receipt_alias_reviewed', ['reference' => $reference, 'evidence_reference' => $evidence]);
            }
        });
    }

    public function statement(CollectionAccount $account, User $actor, array $row): CollectionReceipt
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);

        return DB::transaction(function () use ($account, $actor, $row) {
            CollectionAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $reference = strtoupper(trim($row['reference']));
            $r = CollectionReceipt::where('account_id', $account->id)->where('reference_norm', $reference)->lockForUpdate()->first();
            if (! $r) {
                $r = CollectionReceipt::create(['account_id' => $account->id, 'reference_raw' => $row['reference'], 'reference_norm' => $reference,
                    'principal_minor' => $row['amount_minor'], 'currency' => $row['currency'], 'provider_at' => $row['provider_at'], 'provider_latest_at' => $row['provider_at'],
                    'enrichment_deadline' => now(), 'historical_only' => true, 'decision_state' => 'historical', 'evidence_level' => 'statement', 'funds_state' => 'statement_verified']);
            }
            $key = 'statement:'.$account->id.':'.$reference;
            $prior = DB::table('collection_settlement_entries')->where('dedupe_key', $key)->first();
            if ($prior) {
                if ((int) $prior->amount_minor !== (int) $row['amount_minor'] || $prior->currency !== $row['currency']) {
                    throw new CollectionException('statement_identity_conflict');
                }

                return $r;
            }
            if ((int) $row['amount_minor'] <= 0 || $row['currency'] !== $account->currency || $r->principal_minor !== (int) $row['amount_minor'] || $r->currency !== $row['currency']) {
                $r->update(['conflicts' => array_values(array_unique(array_merge($r->conflicts ?? [], ['statement_principal_or_currency_conflict']))), 'decision_state' => 'needs_review', 'version' => $r->version + 1]);
                app(CollectionPurchaseService::class)->audit($r, $actor->id, 'statement_discrepancy', ['artifact_sha256' => $row['artifact_sha256']]);

                return $r;
            }
            $this->entry($account, $r->id, $row['currency'], 'statement_credit', $row['amount_minor'], $row['evidence_reference'], $row['artifact_sha256'], $key, $actor->id, $row['provider_at']);
            if (! $r->conflicts && ! in_array($r->funds_state, ['disputed', 'reversed'])) {
                $r->update(['evidence_level' => 'statement', 'funds_state' => 'statement_verified', 'version' => $r->version + 1]);
                app(CollectionExposureService::class)->release($r, 'verification');
            }
            app(CollectionPurchaseService::class)->audit($r, $actor->id, 'statement_credit_verified', ['artifact_sha256' => $row['artifact_sha256']], $key);

            return $r->fresh();
        }, 3);
    }

    public function adjustment(CollectionAccount $account, User $actor, array $row): void
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);
        if (! in_array($row['kind'], ['opening', 'fee', 'reversal', 'remittance'], true) || $row['amount_minor'] < 0 || $row['currency'] !== $account->currency) {
            throw new CollectionException('invalid_settlement_adjustment', 422);
        }
        // Evidence must identify the provider fee/reversal or independently verified company credit.
        DB::transaction(function () use ($account, $actor, $row) {
            CollectionAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $receipt = ! empty($row['receipt_id']) ? CollectionReceipt::where('account_id', $account->id)->lockForUpdate()->findOrFail($row['receipt_id']) : null;
            if ($row['kind'] === 'reversal' && (! $receipt || $receipt->principal_minor !== (int) $row['amount_minor'])) {
                throw new CollectionException('reversal_principal_evidence_required', 422);
            }
            $key = $row['kind'] === 'reversal' ? 'reversal:'.$account->id.':'.$receipt->id : 'adjustment:'.$account->id.':'.$row['idempotency_key'];
            $this->entry($account, $row['receipt_id'] ?? null, $row['currency'], $row['kind'], $row['amount_minor'], $row['evidence_reference'],
                $row['artifact_sha256'], $key, $actor->id, $row['posted_at']);
            if ($row['kind'] === 'reversal') {
                $account->update(['suspended_at' => now(), 'suspension_reason' => 'finance_reversal_recorded']);
                $receipt->update(['funds_state' => 'reversed', 'decision_state' => 'needs_review', 'version' => $receipt->version + 1]);
                app(CollectionPurchaseService::class)->audit($receipt, $actor->id, 'finance_reversal_recorded', ['evidence_reference' => $row['evidence_reference']], $key);
            }
        }, 3);
    }

    public function summary(CollectionAccount $account): array
    {
        $receipts = CollectionReceipt::where('account_id', $account->id);
        $entries = DB::table('collection_settlement_entries')->where('account_id', $account->id)->where('currency', $account->currency)
            ->selectRaw('kind, SUM(amount_minor) as amount')->groupBy('kind')->pluck('amount', 'kind');
        $gross = (int) (clone $receipts)->where('currency', $account->currency)->sum('principal_minor');

        return ['currency' => $account->currency, 'gross_received_minor' => $gross, 'statement_gross_minor' => (int) ($entries['statement_credit'] ?? 0),
            'opening_minor' => (int) ($entries['opening'] ?? 0), 'fees_minor' => (int) ($entries['fee'] ?? 0), 'reversals_minor' => (int) ($entries['reversal'] ?? 0),
            'remitted_minor' => (int) ($entries['remittance'] ?? 0),
            'closing_due_minor' => (int) ($entries['opening'] ?? 0) + $gross - (int) ($entries['fee'] ?? 0) - (int) ($entries['reversal'] ?? 0) - (int) ($entries['remittance'] ?? 0),
            'phone_without_statement' => (clone $receipts)->where('evidence_level', 'phone')->count(),
            'statement_without_phone' => (clone $receipts)->where('evidence_level', 'statement')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('collection_events')->whereColumn('collection_events.receipt_id', 'collection_receipts.id'))->count(),
            'unallocated' => (clone $receipts)->whereDoesntHave('allocation')->count(), 'conflicted_or_reversed' => (clone $receipts)->where(fn ($q) => $q->whereIn('funds_state', ['reversed', 'disputed'])->orWhere('decision_state', 'needs_review'))->count()];
    }

    private function entry(CollectionAccount $a, ?int $receipt, string $currency, string $kind, int $amount, string $evidence, string $hash, string $key, ?int $actor, $at): void
    {
        $existing = DB::table('collection_settlement_entries')->where('dedupe_key', $key)
            ->orWhere(fn ($q) => $q->where('account_id', $a->id)->where('kind', $kind)->where('evidence_reference', $evidence))->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->amount_minor !== $amount || $existing->currency !== $currency || $existing->kind !== $kind || $existing->evidence_reference !== $evidence || $existing->artifact_sha256 !== $hash) {
                throw new CollectionException('settlement_idempotency_conflict');
            }

            return;
        }
        DB::table('collection_settlement_entries')->insert(['account_id' => $a->id, 'receipt_id' => $receipt, 'currency' => $currency, 'kind' => $kind,
            'amount_minor' => $amount, 'evidence_reference' => $evidence, 'artifact_sha256' => $hash, 'dedupe_key' => $key, 'reviewed_by' => $actor, 'posted_at' => $at, 'created_at' => now()]);
    }
}
