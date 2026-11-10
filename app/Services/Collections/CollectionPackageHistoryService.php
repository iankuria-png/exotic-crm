<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use App\Models\Payment;
use App\Models\ProductPrice;
use App\Models\TimelineEvent;
use App\Services\SubscriptionActivationAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Actual observed SMS payers and confirmed purchases. Reads never manufacture staff authority. */
class CollectionPackageHistoryService
{
    private array $cache = [];

    public function enabled(CollectionAccount $account, ?Carbon $at = null): bool
    {
        $p = $account->policy['receipt_learning'] ?? [];

        return ($p['enabled'] ?? false) === true && ! empty($p['approved_by']) && ! empty($p['approved_at'])
            && (! $at || Carbon::parse($p['approved_at'])->lte($at));
    }

    /** A completed staff confirmation, never the current receipt or a later correction, teaches identity. */
    public function payer(CollectionReceipt $receipt): array
    {
        $empty = ['clients' => [], 'source_digest' => null, 'complete' => true];
        if (! $receipt->provider_at || ! $receipt->payer_fingerprint || ! $this->enabled($receipt->account, $receipt->provider_at)) {
            return $empty;
        }
        $history = $this->outcomes($receipt->account, $receipt->provider_at, $receipt->payer_fingerprint);
        $ids = array_values(array_unique(array_column($history['items'], 'client_id')));
        $excluded = app(CollectionMatchExclusionService::class)->clientIds($receipt);
        $clients = [];
        foreach ($ids as $id) {
            $sources = array_values(array_filter($history['items'], fn ($r) => $r['client_id'] === $id));
            $clients[] = ['client_id' => $id, 'excluded' => in_array($id, $excluded, true), 'receipt_ids' => array_column($sources, 'receipt_id'),
                'confirmed_at' => max(array_column($sources, 'confirmed_at')), 'source_count' => count($sources)];
        }

        return ['clients' => $clients, 'source_digest' => $this->digest($history['items']), 'complete' => $history['complete']];
    }

    /** Exact client/amount history can choose a package; it cannot identify its own customer. */
    public function package(CollectionReceipt $receipt, Client $client): ?array
    {
        if (! $receipt->provider_at || ! $this->enabled($receipt->account, $receipt->provider_at)) {
            return null;
        }
        $history = $this->outcomes($receipt->account, $receipt->provider_at);
        if (! $history['complete']) {
            throw new CollectionException('confirmed_purchase_history_incomplete');
        }
        $items = array_values(array_filter($history['items'], fn ($r) => $r['client_id'] === $client->id
            && $r['amount_minor'] === $receipt->principal_minor && $r['currency'] === $receipt->currency));
        if (! $items) {
            return null;
        }
        if (count(array_unique(array_map(fn ($r) => $r['tier'].':'.$r['duration_days'], $items))) !== 1) {
            throw new CollectionException('client_purchase_history_conflicting');
        }
        usort($items, fn ($a, $b) => strcmp($b['confirmed_at'], $a['confirmed_at']) ?: ($b['receipt_id'] <=> $a['receipt_id']));
        $last = $items[0];
        $price = ProductPrice::with('product')->find($last['product_price_id']);
        if (! $this->currentPrice($price, $last, $client->platform_id)
            || $price->created_at->gt($receipt->provider_at) || $price->updated_at->gt($receipt->provider_at)) {
            throw new CollectionException('confirmed_purchase_price_unavailable');
        }

        return ['price' => $price, 'evidence' => ['receipt_ids' => array_column($items, 'receipt_id'), 'source_digest' => $this->digest($items),
            'confirmed_at' => $last['confirmed_at'], 'source_count' => count($items)]];
    }

    public function evidence(CollectionAccount $account): array
    {
        $history = $this->outcomes($account, now());
        $groups = [];
        foreach ($history['items'] as $item) {
            $key = $item['platform_id'].':'.$item['currency'].':'.$item['amount_minor'];
            $groups[$key][] = $item;
        }
        $suggestions = [];
        foreach ($groups as $key => $items) {
            $alternatives = [];
            foreach ($items as $item) {
                $k = ($item['product_price_id'] ?? 'none').':'.$item['tier'].':'.$item['duration_days'];
                $alternatives[$k] ??= ['product_price_id' => $item['product_price_id'], 'tier' => $item['tier'], 'duration_days' => $item['duration_days'], 'count' => 0];
                $alternatives[$k]['count']++;
            }
            uasort($alternatives, fn ($a, $b) => $b['count'] <=> $a['count']);
            $first = reset($alternatives);
            $price = ProductPrice::with('product')->find($first['product_price_id']);
            $clientCount = count(array_unique(array_column($items, 'client_id')));
            $eligible = $history['complete'] && count($items) >= 5 && $clientCount >= 5 && count($alternatives) === 1
                && $this->currentPrice($price, $items[0], $items[0]['platform_id']);
            $suggestions[] = ['key' => $key, 'amount_minor' => $items[0]['amount_minor'], 'currency' => $items[0]['currency'],
                'qualifying_count' => count($items), 'client_count' => $clientCount, 'alternatives' => array_values($alternatives),
                'product_price_id' => $first['product_price_id'], 'name' => $price?->product?->name, 'eligible' => $eligible,
                'last_confirmed_at' => max(array_column($items, 'confirmed_at')), 'source_digest' => $this->digest($items),
                'approved' => (int) ($account->policy['catalogue_price_defaults'][$key] ?? 0) ?: null];
        }
        usort($suggestions, fn ($a, $b) => $b['qualifying_count'] <=> $a['qualifying_count']);

        return ['policy_version' => $account->policy['version'] ?? 0, 'as_of' => now()->toIso8601String(), 'lookback_days' => 90,
            'complete' => $history['complete'], 'qualifying_count' => count($history['items']), 'excluded_count' => $history['excluded_count'],
            'suggestions' => $suggestions, 'learning' => $account->policy['receipt_learning'] ?? ['enabled' => false]];
    }

    /** Bounded exact-reference join. Legacy payment phone and assignment are never payer authority. */
    public function outcomes(CollectionAccount $account, Carbon $before, ?string $fingerprint = null): array
    {
        $key = $account->id.':'.$before->format('Y-m-d H:i:s.u').':'.$fingerprint;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $query = CollectionReceipt::where('account_id', $account->id)->where('provider_at', '<', $before)
            ->where('provider_at', '>=', $before->copy()->subDays(90))->where('created_at', '<', $before)
            ->where('principal_minor', '>', 0)->whereIn('funds_state', ['observed', 'statement_verified'])
            ->where(fn ($q) => $q->whereNull('conflicts')->orWhereJsonLength('conflicts', 0));
        if ($fingerprint !== null) {
            $query->where('payer_fingerprint', $fingerprint);
        }
        $receipts = $query->orderByDesc('id')->limit(5001)->get();
        $result = ['items' => [], 'complete' => $receipts->count() <= 5000, 'excluded_count' => 0];
        $platforms = DB::table('collection_account_platforms')->where('account_id', $account->id)->whereNull('valid_until')->pluck('platform_id')->all();
        foreach ($receipts->take(5000)->chunk(150) as $batch) {
            $aliases = DB::table('collection_receipt_aliases')->where('account_id', $account->id)->whereIn('receipt_id', $batch->pluck('id'))->get()->groupBy('receipt_id');
            $refs = $batch->pluck('reference_norm')->merge($aliases->flatten()->pluck('reference'))->unique()->values()->all();
            $payments = Payment::with('deal')->whereIn('platform_id', $platforms)->where(function ($q) use ($refs) {
                $q->whereIn('reference_root', $refs)->orWhereIn('transaction_reference', $refs)->orWhereIn('reference_number', $refs);
            })->limit(1501)->get();
            if ($payments->count() > 1500) {
                $result['complete'] = false;

                continue;
            }
            $events = TimelineEvent::where('entity_type', 'deal')->whereIn('entity_id', $payments->pluck('deal_id')->filter()->unique())
                ->whereIn('event_type', [SubscriptionActivationAuditService::EVENT, 'deal_activated'])->whereNotNull('actor_id')
                ->where('created_at', '<', $before)->orderBy('id')->get()->groupBy(fn ($e) => (int) data_get($e->content, 'payment_id'));
            $sales = \App\Models\Collections\CollectionSalesSession::with('items')->whereIn('receipt_id', $batch->pluck('id'))->get()->keyBy('receipt_id');
            $allocations = \App\Models\Collections\CollectionAllocation::with('effects')->whereIn('receipt_id', $batch->pluck('id'))->get()->keyBy('receipt_id');
            $corrected = DB::table('collection_corrections as c')->join('collection_allocations as a', 'a.id', '=', 'c.allocation_id')
                ->whereIn('a.receipt_id', $batch->pluck('id'))->whereNotNull('c.request_key')->pluck('a.receipt_id')->all();
            $corrected = array_merge($corrected, DB::table('collection_sales_corrections as c')->join('collection_sales_items as i', 'i.id', '=', 'c.item_id')
                ->join('collection_sales_sessions as s', 's.id', '=', 'i.session_id')->whereIn('s.receipt_id', $batch->pluck('id'))->whereNotNull('c.request_key')->pluck('s.receipt_id')->all());
            $payerEvidence = app(CollectionPayerEvidenceService::class)->forReceipts($batch);
            foreach ($batch as $r) {
                $ownRefs = [$r->reference_norm, ...($aliases->get($r->id, collect())->pluck('reference')->all())];
                $matches = $payments->filter(fn ($p) => array_intersect($ownRefs, [$p->reference_root, $p->transaction_reference, $p->reference_number]));
                $payment = $matches->count() === 1 ? $matches->first() : null;
                $deal = $payment?->deal;
                $sale = $sales->get($r->id);
                $allocation = $allocations->get($r->id);
                $confirmedAt = null;
                $actor = null;
                $purchase = null;
                if ($sale) {
                    $item = $sale->items->count() === 1 ? $sale->items->first() : null;
                    if ($sale->state === 'confirmed' && $sale->remainder_minor === 0 && $item?->state === 'confirmed'
                        && $item->verified_at && (int) $item->payment_id === (int) $payment?->id && (int) $item->amount_minor === (int) $r->principal_minor) {
                        $confirmedAt = $sale->confirmed_at;
                        $actor = $sale->actor_id;
                        $purchase = $item->purchase;
                    }
                } elseif ($allocation) {
                    if ($r->reviewed_at && $r->reviewed_by && $allocation->effects->contains(fn ($e) => $e->state === 'succeeded' && ! $e->superseded_at && in_array($e->effect_kind, ['primary_paid', 'target_paid']))) {
                        $confirmedAt = $r->reviewed_at;
                        $actor = $r->reviewed_by;
                        $purchase = $allocation->purchase_snapshot;
                    }
                } elseif ($payment && ! $payment->manual_payment_bundle_id && ! $payment->reference_sequence) {
                    $event = $events->get($payment->id, collect())->first(fn ($e) => (int) $e->entity_id === (int) $payment->deal_id
                        && (int) $e->platform_id === (int) $payment->platform_id && data_get($e->content, 'execution') !== 'automatic');
                    if ($event) {
                        $confirmedAt = $event->created_at;
                        $actor = $event->actor_id;
                    } else {
                        $proof = app(SubscriptionActivationAuditService::class)->forPayment($payment);
                        if (($proof['state'] ?? '') === 'staff') {
                            $confirmedAt = ! empty($proof['recorded_at']) ? Carbon::parse($proof['recorded_at']) : null;
                            $actor = data_get($proof, 'actor.id');
                        }
                    }
                }
                if (! $payment || ! $deal || ! $actor || ! $confirmedAt || $confirmedAt->gte($before)
                    || in_array($r->id, $corrected) || ! in_array($payment->status, Payment::SUCCESSFUL_STATUSES, true)
                    || $payment->isSandboxTest() || $payment->isClassifiedTest() || ! in_array($payment->purpose, [null, 'subscription'], true)
                    || (data_get($payment->payment_data, 'collection.account_id') && data_get($payment->payment_data, 'collection.account_id') !== $account->id)
                    || app(TzMpesaParser::class)->minor((string) $payment->amount) !== $r->principal_minor || $payment->currency !== $r->currency
                    || (int) $deal->payment_id !== (int) $payment->id || (int) $deal->client_id !== (int) $payment->client_id
                    || (int) $deal->platform_id !== (int) $payment->platform_id || ! $deal->activated_at || $deal->is_free_trial
                    || in_array($deal->status, ['cancelled', 'failed', 'reversed']) || ($payerEvidence[$r->id]['complete'] ?? false) !== true) {
                    $result['excluded_count']++;

                    continue;
                }
                $purchase ??= ['product_price_id' => $deal->product_price_id, 'tier' => $deal->plan_type, 'duration_days' => $deal->duration_days];
                if ((int) ($purchase['product_price_id'] ?? 0) !== (int) $deal->product_price_id
                    || ($purchase['tier'] ?? null) !== $deal->plan_type || (int) ($purchase['duration_days'] ?? 0) !== (int) $deal->duration_days) {
                    $result['excluded_count']++;

                    continue;
                }
                $result['items'][] = ['receipt_id' => (int) $r->id, 'payment_id' => (int) $payment->id, 'deal_id' => (int) $deal->id,
                    'client_id' => (int) $payment->client_id, 'platform_id' => (int) $payment->platform_id, 'payer_fingerprint' => $r->payer_fingerprint,
                    'product_price_id' => $deal->product_price_id ? (int) $deal->product_price_id : null, 'tier' => $deal->plan_type,
                    'duration_days' => (int) $deal->duration_days, 'amount_minor' => (int) $r->principal_minor, 'currency' => $r->currency,
                    'confirmed_at' => $confirmedAt->toIso8601String(), 'confirmed_by' => (int) $actor,
                    'discounted' => (bool) ($deal->discount_approved_by || $deal->discount_source || (float) $deal->discount_percentage > 0
                        || ($deal->original_amount !== null && (float) $deal->original_amount !== (float) $payment->amount)
                        || app(TzMpesaParser::class)->minor((string) $deal->amount) !== $r->principal_minor)];
            }
        }

        // The service is request/job scoped; no cross-request personal evidence is cached.
        return $this->cache[$key] = $result;
    }

    private function currentPrice(?ProductPrice $price, array $item, int $platform): bool
    {
        return $price && ! $item['discounted'] && $price->is_active && $price->product?->is_active && ! $price->product->is_archived
            && (int) $price->product->platform_id === $platform && $price->currency === $item['currency']
            && app(TzMpesaParser::class)->minor((string) $price->price) === $item['amount_minor']
            && $price->product->tier === $item['tier'] && (int) $price->duration_days === $item['duration_days']
            && in_array($price->product->tier, ['basic', 'premium', 'vip'], true) && $price->duration_days > 0;
    }

    public function digest(array $items): string
    {
        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
    }
}
