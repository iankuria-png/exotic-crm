<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionPackageHistoryService;
use Illuminate\Support\Facades\Crypt;

/** Provider payer identity comes from captured receipts, never the advertiser contact phone. */
class KopokopoPaymentHistory
{
    public function read(CollectionReceipt $receipt, string $reader, ?string $cursor): array
    {
        $scope = hash('sha256', json_encode([$reader, $receipt->id, $receipt->account_id, $receipt->payer_fingerprint]));
        $offset = 0;
        $anchor = null;
        if ($cursor) {
            try {
                $page = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new CollectionException('history_cursor_invalid', 422);
            }
            if (($page['scope'] ?? '') !== $scope || ($page['expires'] ?? 0) < time() || ! is_int($page['offset'] ?? null) || $page['offset'] < 0) {
                throw new CollectionException('history_cursor_changed_scope', 422);
            }
            $offset = $page['offset'];
            $anchor = $page['anchor'] ?? null;
        }
        $q = CollectionReceipt::where('account_id', $receipt->account_id)->where('id', '!=', $receipt->id)
            ->where('provider_at', '<', $receipt->provider_at ?? now())->where('created_at', '<', $receipt->provider_at ?? now())
            ->where('provider_at', '>=', ($receipt->provider_at ?? now())->copy()->subDays(90));
        if ($receipt->payer_fingerprint) {
            $q->where('payer_fingerprint', $receipt->payer_fingerprint);
        } else {
            $q->whereRaw('1=0');
        }
        $anchor ??= (int) ((clone $q)->max('id') ?? 0);
        $captured = $q->where('id', '<=', $anchor)->orderByDesc('provider_at')->limit(5001)->get();
        $confirmed = $receipt->payer_fingerprint && $receipt->provider_at
            ? app(CollectionPackageHistoryService::class)->outcomes($receipt->account, $receipt->provider_at, $receipt->payer_fingerprint)
            : ['items' => [], 'complete' => true];
        $byReceipt = collect($confirmed['items'])->groupBy('receipt_id');
        $counts = [];
        $items = [];
        $identityConflict = false;
        foreach ($captured->take(5000) as $r) {
            $rows = $byReceipt->get($r->id, collect());
            $clients = $rows->pluck('client_id')->unique()->values()->all();
            foreach ($clients as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
            $identityConflict = $identityConflict || (bool) $r->conflicts;
            $items[] = ['key' => 'receipt:'.$r->id, 'source' => $clients ? 'crm' : 'observed', 'client_ids' => $clients,
                'amount_minor' => $r->principal_minor, 'currency' => $r->currency, 'at' => $r->provider_at->toIso8601String(), 'reference' => $r->reference_norm];
        }
        $crm = count(array_filter($items, fn ($i) => $i['source'] === 'crm'));
        $next = $offset + 10 < count($items) ? Crypt::encryptString(json_encode(['scope' => $scope, 'anchor' => $anchor, 'offset' => $offset + 10, 'expires' => time() + 300], JSON_THROW_ON_ERROR)) : null;

        return ['prior_count' => count($items), 'crm_count' => $crm, 'observed_only_count' => count($items) - $crm,
            'complete' => $captured->count() <= 5000 && $confirmed['complete'], 'as_of' => now()->toIso8601String(), 'scanned_rows' => min(5000, $captured->count()),
            'total_rows' => $captured->count(), 'items' => array_slice($items, $offset, 10), 'next_cursor' => $next, 'client_counts' => $counts,
            'identity_conflict' => $identityConflict || count($counts) > 1,
            'scope' => ['platform_ids' => [1], 'label' => 'Captured KopoKopo payer receipts · Last 90 days', 'earliest_at' => $captured->last()?->provider_at?->toIso8601String()]];
    }
}
