<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionReceipt;
use App\Models\Payment;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectionPaymentHistoryService
{
    public function forReceipt(CollectionReceipt $receipt, User $actor, ?string $cursor = null): array
    {
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        $platforms = DB::table('collection_account_platforms')->where('account_id', $receipt->account_id)
            ->where('valid_from', '<=', $receipt->provider_at ?? now())->whereNull('valid_until')->pluck('platform_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $scope = hash('sha256', json_encode([$actor->id, $receipt->id, $receipt->account_id, $receipt->payer_fingerprint, $platforms]));
        $offset = 0;
        $anchor = null;
        if ($cursor) {
            try {
                $page = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException $e) {
                throw new CollectionException('history_cursor_invalid', 422);
            }
            if (($page['scope'] ?? '') !== $scope || ($page['expires'] ?? 0) < time() || ! is_int($page['offset'] ?? null) || $page['offset'] < 0) {
                throw new CollectionException('history_cursor_changed_scope', 422);
            }
            $offset = $page['offset'];
            $anchor = (int) ($page['anchor'] ?? 0);
        }
        $out = ['prior_count' => null, 'crm_count' => null, 'observed_only_count' => null, 'complete' => false,
            'as_of' => now()->toIso8601String(), 'scanned_rows' => 0, 'total_rows' => 0, 'items' => [], 'next_cursor' => null,
            'client_counts' => [], 'identity_conflict' => false, 'scope' => ['platform_ids' => $platforms, 'label' => 'Subscription payments in this receiving account’s permitted markets', 'earliest_at' => null]];
        if (! $receipt->payer_phone || ! $receipt->provider_at) {
            return $out + ['reason' => 'full_payer_phone_required'];
        }
        $q = Payment::query()->reportableSuccessful()->whereIn('platform_id', $platforms)->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', 'subscription'))
            ->whereRaw('COALESCE(completed_at, created_at) < ?', [$receipt->provider_at]);
        $anchor ??= (int) ((clone $q)->max('id') ?? 0);
        $q->where('id', '<=', $anchor);
        $out['total_rows'] = (clone $q)->count();
        $out['scope']['earliest_at'] = (clone $q)->min('created_at');
        $limit = max(1, min(50000, (int) config('collection.review_history_scan_limit', 50000)));
        $currentRefs = array_values(array_unique(array_merge([$receipt->reference_norm], DB::table('collection_receipt_aliases')
            ->where('account_id', $receipt->account_id)->where('receipt_id', $receipt->id)->pluck('reference')->all())));
        $currentPayments = CollectionAllocation::where('receipt_id', $receipt->id)->whereNotNull('payment_id')->pluck('payment_id')->all();
        $prefixes = DB::table('platforms')->whereIn('id', $platforms)->pluck('phone_prefix', 'id');
        $groups = [];
        $bundleScanned = [];
        foreach ((clone $q)->select(['id', 'platform_id', 'client_id', 'deal_id', 'phone', 'amount', 'currency', 'created_at', 'completed_at',
            'transaction_reference', 'reference_number', 'reference_root', 'reference_sequence', 'manual_payment_bundle_id', 'payment_data'])
            ->lazyByIdDesc(500)->take($limit) as $payment) {
            $out['scanned_rows']++;
            if ($payment->manual_payment_bundle_id) {
                $bundleScanned[$payment->manual_payment_bundle_id] = ($bundleScanned[$payment->manual_payment_bundle_id] ?? 0) + 1;
            }
            if (PhoneNormalizer::normalize($payment->phone, (string) ($prefixes[$payment->platform_id] ?? '255')) !== $receipt->payer_phone) {
                continue;
            }
            $owner = data_get($payment->payment_data, 'collection.account_id');
            if ($owner && $owner !== $receipt->account_id) {
                continue;
            }
            $refs = $this->references($payment);
            $root = $payment->reference_root ? strtoupper(trim($payment->reference_root)) : ($refs[0] ?? null);
            $key = $payment->manual_payment_bundle_id ? 'bundle:'.$payment->manual_payment_bundle_id
                : ($root ? 'reference:'.$root : 'payment:'.$payment->id);
            $groups[$key] ??= ['payments' => [], 'refs' => [], 'current' => false, 'bundle' => $payment->manual_payment_bundle_id];
            $groups[$key]['payments'][] = $payment;
            $groups[$key]['refs'] = array_unique(array_merge($groups[$key]['refs'], $refs));
            $groups[$key]['current'] = $groups[$key]['current'] || count(array_intersect($currentRefs, $refs)) > 0 || in_array($payment->id, $currentPayments);
        }
        $complete = $out['total_rows'] <= $out['scanned_rows'];
        $bundleIds = array_values(array_filter(array_column($groups, 'bundle')));
        $bundleActual = DB::table('payments')->whereIn('manual_payment_bundle_id', $bundleIds)
            ->selectRaw('manual_payment_bundle_id, COUNT(*) AS total')->groupBy('manual_payment_bundle_id')->pluck('total', 'manual_payment_bundle_id');
        $bundleStates = DB::table('manual_payment_bundles')->whereIn('id', $bundleIds)->pluck('status', 'id');
        $paymentIds = collect($groups)->flatMap(fn ($g) => array_map(fn ($p) => $p->id, $g['payments']))->all();
        $badAttributions = Schema::hasTable('payment_attributions') ? DB::table('payment_attributions')->whereIn('payment_id', $paymentIds)
            ->whereIn('attribution_state', ['archived', 'disputed'])->pluck('payment_id')->all() : [];
        $dealIds = collect($groups)->flatMap(fn ($g) => array_map(fn ($p) => $p->deal_id, $g['payments']))->filter()->unique();
        $deals = DB::table('deals')->whereIn('id', $dealIds)->whereIn('platform_id', $platforms)->get(['id', 'client_id', 'platform_id'])->keyBy('id');
        $subjectIds = collect($groups)->flatMap(fn ($g) => array_map(fn ($p) => $p->client_id ?: ($deals[$p->deal_id]->client_id ?? null), $g['payments']))->filter()->unique();
        $subjectMarkets = Client::whereIn('id', $subjectIds)->whereIn('platform_id', $platforms)->pluck('platform_id', 'id');
        $items = [];
        $seenRefs = [];
        $seenPayments = [];
        $clientCounts = [];
        foreach ($groups as $key => $group) {
            $rows = $group['payments'];
            // A partial/inaccessible bundle cannot establish a count or a unique customer.
            if ($group['bundle'] && (($bundleStates[$group['bundle']] ?? '') !== 'committed'
                || (int) ($bundleActual[$group['bundle']] ?? -1) !== count($rows)
                || (int) ($bundleScanned[$group['bundle']] ?? -1) !== count($rows))) {
                $complete = false;
                $out['identity_conflict'] = true;

                continue;
            }
            if ($group['current']) {
                $seenRefs = array_merge($seenRefs, $group['refs']);

                continue;
            }
            if (! $group['bundle'] && count($rows) > 1) {
                // Without exact receiving-account provenance, duplicate CRM roots are ambiguous.
                $explicitOwners = array_unique(array_map(fn ($p) => data_get($p->payment_data, 'collection.account_id'), $rows));
                if ($explicitOwners !== [$receipt->account_id]
                    || count(array_unique(array_map(fn ($p) => $p->currency.':'.$p->amount, $rows))) !== 1) {
                    $complete = false;
                    $out['identity_conflict'] = true;

                    continue;
                }
            }
            $currencies = array_unique(array_map(fn ($p) => $p->currency, $rows));
            if (count($currencies) !== 1 || array_intersect($seenRefs, $group['refs'])) {
                $complete = false;
                $out['identity_conflict'] = true;

                continue;
            }
            $ids = [];
            foreach ($rows as $p) {
                if (in_array($p->id, $badAttributions)) {
                    $out['identity_conflict'] = true;

                    continue;
                }
                $deal = $deals[$p->deal_id] ?? null;
                $client = $p->client_id ?: (($deal && (int) $deal->platform_id === (int) $p->platform_id) ? $deal->client_id : null);
                if ($client && (int) ($subjectMarkets[$client] ?? 0) === (int) $p->platform_id) {
                    $ids[(int) $client] = true;
                } elseif ($client) {
                    $out['identity_conflict'] = true;
                }
            }
            foreach (array_keys($ids) as $id) {
                $clientCounts[$id] = ($clientCounts[$id] ?? 0) + 1;
            }
            $seenRefs = array_merge($seenRefs, $group['refs']);
            $seenPayments = array_merge($seenPayments, array_map(fn ($p) => $p->id, $rows));
            $amount = $group['bundle'] ? array_sum(array_map(fn ($p) => app(TzMpesaParser::class)->minor((string) $p->amount), $rows))
                : app(TzMpesaParser::class)->minor((string) $rows[0]->amount);
            $items[] = ['key' => $key, 'source' => 'crm', 'payment_ids' => array_map(fn ($p) => $p->id, $rows),
                'client_ids' => array_keys($ids), 'amount_minor' => $amount, 'currency' => $rows[0]->currency,
                'at' => (string) ($rows[0]->completed_at ?? $rows[0]->created_at), 'reference' => $group['refs'][0] ?? null];
        }
        $crmCount = count($items);
        $captured = CollectionReceipt::with('allocation')->where('account_id', $receipt->account_id)->where('payer_fingerprint', $receipt->payer_fingerprint)
            ->where('id', '!=', $receipt->id)->where('provider_at', '<', $receipt->provider_at)->where('principal_minor', '>', 0)
            ->whereIn('funds_state', ['observed', 'statement_verified'])->orderByDesc('id')->limit(10001)->get();
        if ($captured->count() > 10000) {
            $complete = false;
        }
        $aliases = DB::table('collection_receipt_aliases')->where('account_id', $receipt->account_id)->whereIn('receipt_id', $captured->pluck('id'))
            ->get(['receipt_id', 'reference'])->groupBy('receipt_id');
        foreach ($captured->take(10000) as $r) {
            if ($r->conflicts) {
                $out['identity_conflict'] = true;

                continue;
            }
            $refs = array_merge([$r->reference_norm], $aliases->get($r->id, collect())->pluck('reference')->all());
            if (array_intersect($currentRefs, $refs)) {
                continue;
            }
            if (array_intersect($refs, $seenRefs) || ($r->allocation?->payment_id && in_array($r->allocation->payment_id, $seenPayments))) {
                continue;
            }
            $seenRefs = array_merge($seenRefs, $refs);
            $items[] = ['key' => 'receipt:'.$r->id, 'source' => 'observed', 'client_ids' => [], 'amount_minor' => $r->principal_minor,
                'currency' => $r->currency, 'at' => $r->provider_at->toIso8601String(), 'reference' => $r->reference_norm];
        }
        usort($items, fn ($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));
        $out['complete'] = $complete;
        $out['prior_count'] = count($items);
        $out['crm_count'] = $crmCount;
        $out['observed_only_count'] = count($items) - $crmCount;
        $out['client_counts'] = $clientCounts;
        $out['items'] = array_slice($items, $offset, 10);
        if ($offset + 10 < count($items)) {
            $out['next_cursor'] = Crypt::encryptString(json_encode(['scope' => $scope, 'anchor' => $anchor, 'offset' => $offset + 10, 'expires' => time() + 300], JSON_THROW_ON_ERROR));
        }

        return $out;
    }

    private function references(Payment $payment): array
    {
        $refs = [];
        foreach ([$payment->transaction_reference, $payment->reference_number, $payment->reference_root] as $value) {
            $literal = strtoupper(trim((string) $value));
            if (preg_match('/^[A-Z0-9][A-Z0-9_.\/-]{5,127}$/D', $literal) && ! in_array($literal, ['UNKNOWN', 'UNAVAILABLE', 'MANUAL', 'NONE'])) {
                $refs[] = $literal;
            }
            preg_match_all('/(?<![A-Z0-9])[A-Z0-9]{10,11}(?![A-Z0-9])/i', (string) $value, $matches);
            $refs = array_merge($refs, array_map('strtoupper', $matches[0]));
        }

        return array_values(array_unique($refs));
    }
}
