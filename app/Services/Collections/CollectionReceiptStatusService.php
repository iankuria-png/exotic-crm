<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Advisory evidence. Never attaches money or changes subscriptions. */
class CollectionReceiptStatusService
{
    private function referenceQuery(string $table, string $alias)
    {
        return DB::table($table.' as '.$alias)->whereExists(function ($q) use ($alias) {
            $q->selectRaw('1')->from('collection_account_platforms as bound')
                ->whereColumn('bound.account_id', 'collection_receipts.account_id')
                ->whereColumn('bound.platform_id', $alias.'.platform_id');
        })->when($table === 'payments', fn ($q) => $q->where(fn ($q) => $q->whereNull($alias.'.resolution_code')->orWhere($alias.'.resolution_code', '!=', Payment::RESOLUTION_RECEIPT_DUPLICATE)))
            ->where(function ($q) use ($alias, $table) {
                foreach ($table === 'payments' ? ['reference_root', 'transaction_reference', 'reference_number'] : ['reference_root'] as $column) {
                    $q->orWhereColumn($alias.'.'.$column, 'collection_receipts.reference_norm')
                        ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('collection_receipt_aliases as alias_ref')
                            ->whereColumn('alias_ref.receipt_id', 'collection_receipts.id')
                            ->whereColumn('alias_ref.account_id', 'collection_receipts.account_id')
                            ->whereColumn('alias_ref.reference', $alias.'.'.$column));
                }
            });
    }

    public function withUsage(Builder $query): Builder
    {
        return $query->addSelect(['reference_matches_count' => $this->referenceQuery('payments', 'cash')->selectRaw('COUNT(*)'),
            'reference_bundles_count' => $this->referenceQuery('manual_payment_bundles', 'bundle')->selectRaw('COUNT(*)')]);
    }

    /**
     * Restrict a receipt query to one status tab. Reference collisions are resolved once for the candidate set with
     * index-friendly IN lookups, then applied as an id list: per-receipt correlated scans of `payments` (OR across
     * three reference columns plus aliases) cost a full table scan per receipt and timed out the workspace.
     */
    public function filter(Builder $query, string $state): void
    {
        $candidates = (clone $query)->setEagerLoads([])->whereDoesntHave('sale')->whereDoesntHave('allocation');
        if ($state === 'needs_action') {
            $candidates->where('historical_only', false)->whereIn('funds_state', ['observed', 'statement_verified'])
                ->where(fn ($c) => $c->whereNull('conflicts')->orWhereJsonLength('conflicts', 0));
            $receipts = $this->candidateRows($candidates);
            $matches = $this->referenceMatches($receipts, false);
            $unrecorded = $receipts->keys()->reject(fn ($id) => isset($matches['payments'][$id]) || isset($matches['bundles'][$id]))->values()->all();
            $query->where(fn ($q) => $q->whereIn('collection_receipts.id', $unrecorded)
                ->orWhereHas('allocation.subsidiaryObligation', fn ($o) => $o->whereIn('state', ['needs_approval', 'needs_profile', 'uncertain'])));

            return;
        }
        $receipts = $this->candidateRows($candidates);
        $matches = $this->referenceMatches($receipts, true);
        $recorded = $receipts->filter(fn ($r) => $this->recordedElsewhere($r, $matches['payments'][$r->id] ?? [], $matches['bundles'][$r->id] ?? [], $matches))->keys()->all();
        $query->whereIn('collection_receipts.id', $recorded);
    }

    private function candidateRows(Builder $candidates): Collection
    {
        return $candidates->toBase()->reorder()->select(['collection_receipts.id', 'collection_receipts.account_id', 'collection_receipts.reference_norm',
            'collection_receipts.currency', 'collection_receipts.principal_minor'])->get()->keyBy('id');
    }

    /** Collation-equivalent key: codes compare case-insensitively and ignore trailing spaces in MySQL/MariaDB. */
    private function referenceKey(?string $reference): ?string
    {
        $key = $reference === null ? '' : strtoupper(rtrim($reference, ' '));

        return $key === '' ? null : $key;
    }

    /**
     * Payments and bundles sharing a candidate's code or reviewed alias on one of its account's bound platforms.
     *
     * @return array{payments: array<int, array<int, object>>, bundles: array<int, array<int, object>>, clients: array, deals: array}
     */
    private function referenceMatches(Collection $receipts, bool $detail): array
    {
        $out = ['payments' => [], 'bundles' => [], 'clients' => [], 'deals' => []];
        if ($receipts->isEmpty()) {
            return $out;
        }
        $owners = $lookup = [];
        foreach ($receipts as $r) {
            if ($key = $this->referenceKey($r->reference_norm)) {
                $owners[$key][$r->id] = $r->id;
                $lookup[$r->reference_norm] = true;
            }
        }
        foreach ($receipts->keys()->chunk(1000) as $ids) {
            foreach (DB::table('collection_receipt_aliases')->whereIn('receipt_id', $ids->values())->get(['receipt_id', 'account_id', 'reference']) as $alias) {
                $key = $this->referenceKey($alias->reference);
                if ($key && (string) $receipts[$alias->receipt_id]->account_id === (string) $alias->account_id) {
                    $owners[$key][$alias->receipt_id] = $alias->receipt_id;
                    $lookup[$alias->reference] = true;
                }
            }
        }
        if (! $owners) {
            return $out;
        }
        $bound = DB::table('collection_account_platforms')->whereIn('account_id', $receipts->pluck('account_id')->unique()->values())->get(['account_id', 'platform_id'])
            ->groupBy('account_id')->map(fn ($rows) => $rows->pluck('platform_id')->map(fn ($id) => (int) $id)->all());
        $platforms = $bound->flatten()->unique()->values()->all();
        if (! $platforms) {
            return $out;
        }
        // Keys are cast back to strings: PHP turns numeric array keys into ints, which would compare numerically in SQL.
        $references = array_map('strval', array_keys($lookup));
        $columns = ['id', 'platform_id', 'reference_root', 'transaction_reference', 'reference_number'];
        $pair = function (string $type, object $row, array $refs) use (&$out, $owners, $receipts, $bound) {
            foreach (array_unique(array_filter(array_map(fn ($ref) => $this->referenceKey($ref), $refs))) as $key) {
                foreach ($owners[$key] ?? [] as $receiptId) {
                    if (in_array((int) $row->platform_id, $bound[$receipts[$receiptId]->account_id] ?? [], true)) {
                        $out[$type][$receiptId][$row->id] = $row;
                    }
                }
            }
        };
        foreach (array_chunk($references, 500) as $chunk) {
            DB::table('payments')->whereIn('platform_id', $platforms)
                ->where(fn ($q) => $q->whereNull('resolution_code')->orWhere('resolution_code', '!=', Payment::RESOLUTION_RECEIPT_DUPLICATE))
                ->where(fn ($q) => $q->whereIn('reference_root', $chunk)->orWhereIn('transaction_reference', $chunk)->orWhereIn('reference_number', $chunk))
                ->get($detail ? [...$columns, 'status', 'record_classification', 'provider_environment', 'payment_data', 'currency', 'amount', 'client_id', 'deal_id', 'manual_payment_bundle_id'] : $columns)
                ->each(fn ($p) => $pair('payments', $p, [$p->reference_root, $p->transaction_reference, $p->reference_number]));
            DB::table('manual_payment_bundles')->whereIn('platform_id', $platforms)->whereIn('reference_root', $chunk)
                ->get($detail ? ['id', 'platform_id', 'reference_root', 'status', 'currency', 'total_amount'] : ['id', 'platform_id', 'reference_root'])
                ->each(fn ($b) => $pair('bundles', $b, [$b->reference_root]));
        }
        if ($detail) {
            // Only a receipt with exactly one matching payment can be "already recorded"; check just those owners.
            $single = collect($out['payments'])->filter(fn ($m) => count($m) === 1)->map(fn ($m) => reset($m));
            $out['clients'] = DB::table('clients')->whereIn('id', $single->pluck('client_id')->filter()->unique()->values())->pluck('platform_id', 'id')->all();
            $out['deals'] = DB::table('deals')->whereIn('id', $single->pluck('deal_id')->filter()->unique()->values())->get(['id', 'client_id', 'platform_id'])->keyBy('id')->all();
        }

        return $out;
    }

    /** Exactly one live, successful, same-amount payment with a consistent client/deal, and only bundles it settles. */
    private function recordedElsewhere(object $r, array $payments, array $bundles, array $matches): bool
    {
        if (count($payments) !== 1) {
            return false;
        }
        $p = reset($payments);
        $data = is_string($p->payment_data) ? (json_decode($p->payment_data, true) ?: []) : (array) ($p->payment_data ?? []);
        $client = $matches['clients'][$p->client_id] ?? null;
        $deal = $matches['deals'][$p->deal_id] ?? null;
        if (! in_array($p->status, Payment::SUCCESSFUL_STATUSES, true) || ! in_array($p->record_classification, [null, Payment::RECORD_CLASSIFICATION_LIVE], true)
            || $p->provider_environment === 'sandbox' || data_get($data, 'test_mode')
            || $p->currency !== $r->currency || (int) round((float) $p->amount * 100) !== (int) $r->principal_minor
            || (data_get($data, 'collection.account_id') && data_get($data, 'collection.account_id') !== $r->account_id)
            || $client === null || (int) $client !== (int) $p->platform_id
            || ! $deal || (int) $deal->client_id !== (int) $p->client_id || (int) $deal->platform_id !== (int) $p->platform_id) {
            return false;
        }

        // An unrelated or incomplete bundle is a review, even if one cash row matches.
        foreach ($bundles as $b) {
            if ($b->status !== 'committed' || $b->currency !== $r->currency || (int) round((float) $b->total_amount * 100) !== (int) $r->principal_minor
                || (int) $p->manual_payment_bundle_id !== (int) $b->id) {
                return false;
            }
        }

        return true;
    }

    /** Fixed batched queries for a page; actor audit is fetched only for an opened receipt. */
    public function forReceipts(Collection $receipts, bool $detail = false): array
    {
        if ($receipts->isEmpty()) {
            return [];
        }
        $ids = $receipts->pluck('id');
        $allAliases = DB::table('collection_receipt_aliases')->whereIn('receipt_id', $ids)->get();
        $refs = $receipts->pluck('reference_norm')->merge($allAliases->pluck('reference'))->unique();
        $mapping = DB::table('collection_account_platforms')->whereIn('account_id', $receipts->pluck('account_id'))->get();
        $payments = Payment::with(['client', 'deal'])->withoutReceiptDuplicates()->whereIn('platform_id', $mapping->pluck('platform_id'))
            ->where(fn ($q) => $q->whereIn('reference_root', $refs)->orWhereIn('transaction_reference', $refs)->orWhereIn('reference_number', $refs))
            ->orderBy('id')->limit(626)->get();
        $bundles = DB::table('manual_payment_bundles')->whereIn('platform_id', $mapping->pluck('platform_id'))->whereIn('reference_root', $refs)->limit(626)->get();
        $aliases = $allAliases->groupBy('receipt_id');
        $out = [];
        foreach ($receipts as $r) {
            $platforms = $mapping->where('account_id', $r->account_id)->pluck('platform_id')->all();
            $references = [$r->reference_norm, ...($aliases[$r->id] ?? collect())->pluck('reference')->all()];
            $matches = $payments->filter(fn ($p) => in_array($p->platform_id, $platforms) && array_intersect($references, [$p->reference_root, $p->transaction_reference, $p->reference_number]))->values();
            $matchedBundles = $bundles->filter(fn ($b) => in_array($b->platform_id, $platforms) && in_array($b->reference_root, $references));
            $complete = $payments->count() < 626 && $bundles->count() < 626 && $matches->count() <= 25;
            $own = $r->relationLoaded('sale') ? $r->sale : null;
            $state = $own || $r->allocation ? 'owned' : 'unrecorded';
            if ($state !== 'owned' && (! $complete || $matches->isNotEmpty() || $matchedBundles->isNotEmpty())) {
                $state = 'conflict';
                $p = $matches->count() === 1 ? $matches[0] : null;
                if ($complete && $p && in_array($p->status, Payment::SUCCESSFUL_STATUSES, true) && in_array($p->record_classification, [null, 'live'], true) && ! $p->isClassifiedTest()
                    && ($p->provider_environment !== 'sandbox') && ! data_get($p->payment_data, 'test_mode')
                    && $p->currency === $r->currency && (int) round((float) $p->amount * 100) === $r->principal_minor
                    && (! data_get($p->payment_data, 'collection.account_id') || data_get($p->payment_data, 'collection.account_id') === $r->account_id)
                    && $p->deal && $p->client && (int) $p->deal->client_id === (int) $p->client_id && (int) $p->deal->platform_id === (int) $p->platform_id
                    && $matchedBundles->every(fn ($b) => $b->status === 'committed' && $b->currency === $r->currency && (int) round((float) $b->total_amount * 100) === $r->principal_minor && (int) $p->manual_payment_bundle_id === (int) $b->id)) {
                    $state = 'already_recorded';
                }
            }
            $out[$r->id] = ['state' => $state, 'complete' => $complete, 'matches' => $matches->take(25)->map(function ($p) use ($detail, $r) {
                return ['payment_id' => $p->id, 'status' => $p->status, 'amount' => $p->amount, 'currency' => $p->currency,
                    'client' => $p->client ? ['id' => $p->client->id, 'name' => $p->client->name] : null,
                    'deal' => $p->deal?->only(['id', 'status', 'plan_type', 'activated_at', 'expires_at']),
                    'destination' => data_get($p->payment_data, 'collection.account_id') === $r->account_id ? 'verified_account' : 'not_verified',
                    'responsibility' => $detail ? app(\App\Services\SubscriptionActivationAuditService::class)->forPayment($p) : null];
            })->all(), 'bundle_matches' => $matchedBundles->count(), 'checked_at' => now()->toIso8601String()];
        }

        return $out;
    }

    /** Advisory candidates only: a suffixed code never proves another cash receipt. */
    public function relatedPayments(CollectionReceipt $receipt): array
    {
        $references = [$receipt->reference_norm, ...DB::table('collection_receipt_aliases')
            ->where('receipt_id', $receipt->id)->where('account_id', $receipt->account_id)->pluck('reference')->all()];
        $references = array_values(array_unique($references));
        $platformIds = DB::table('collection_account_platforms')->where('account_id', $receipt->account_id)->pluck('platform_id');
        $payments = Payment::with(['client', 'deal', 'confirmedBy'])->whereIn('platform_id', $platformIds)
            ->where('id', '!=', $receipt->allocation?->payment_id ?? 0)
            ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
            ->where(fn ($q) => $q->whereNull('record_classification')->orWhere('record_classification', 'live'))
            ->where(fn ($q) => $q->whereNull('provider_environment')->orWhere('provider_environment', '!=', 'sandbox'))
            ->where(function ($q) use ($references) {
                foreach ($references as $reference) {
                    $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $reference).'%';
                    foreach (['reference_root', 'transaction_reference', 'reference_number'] as $column) {
                        $q->orWhereRaw($column." LIKE ? ESCAPE '!'", [$prefix]);
                    }
                }
            })->orderBy('id')->limit(26)->get();
        $matches = $payments->filter(function (Payment $payment) use ($references) {
            if ($payment->isClassifiedTest() || data_get($payment->payment_data, 'test_mode')) {
                return false;
            }
            foreach ($references as $reference) {
                foreach ([$payment->reference_root, $payment->transaction_reference, $payment->reference_number] as $candidate) {
                    if (preg_match('~^'.preg_quote($reference, '~').'(?:\s*/\s*[1-9][0-9]*)?$~D', (string) $candidate)) {
                        return true;
                    }
                }
            }

            return false;
        });

        return ['complete' => $payments->count() < 26, 'matches' => $matches->take(25)->map(fn (Payment $payment) => [
            'payment_id' => $payment->id, 'reference' => $payment->transaction_reference ?: $payment->reference_root,
            'status' => $payment->status, 'amount' => $payment->amount, 'currency' => $payment->currency,
            'client' => $payment->client ? ['id' => $payment->client->id, 'name' => $payment->client->name] : null,
            'deal' => $payment->deal?->only(['id', 'status', 'plan_type', 'activated_at', 'expires_at']),
            'recorded_by' => $payment->confirmedBy?->name,
        ])->values()->all()];
    }

    public function clients(Collection $clients): array
    {
        $deals = Deal::whereIn('client_id', $clients->pluck('id'))->where('status', 'active')->whereNotNull('activated_at')
            ->where('expires_at', '>', now())->orderByDesc('expires_at')->orderByDesc('id')->get()->groupBy('client_id');

        return $clients->mapWithKeys(function (Client $c) use ($deals) {
            $deal = ($deals[$c->id] ?? collect())->first();

            return [$c->id => ['profile_status' => $c->profile_status, 'subscription' => $deal?->only(['id', 'status', 'plan_type', 'expires_at']),
                'last_synced_at' => $c->last_synced_at?->toIso8601String(), 'source' => 'crm', 'checked_at' => now()->toIso8601String()]];
        })->all();
    }
}
