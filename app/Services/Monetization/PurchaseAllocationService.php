<?php

namespace App\Services\Monetization;

use App\Models\PremiumContentOffer;
use App\Models\VisitorContentPurchase;
use App\Models\VisitorContentPurchaseAllocation;

/**
 * Exact creator earnings for a purchase. A normal item or same-escort bundle earns one
 * 100% row; a multi-escort collection splits the gross equally per distinct escort in
 * integer minor units, giving any remainder one unit at a time by ascending client ID.
 */
class PurchaseAllocationService
{
    /**
     * @param  array<int>  $clientIds
     * @return array<int, array{client_id: int, amount_minor: int, share_numerator: int, share_denominator: int}>
     */
    public function split(int $grossMinor, array $clientIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $clientIds)));
        sort($ids);
        $count = count($ids);
        abort_if($count === 0, 409, 'The creator account needs a settlement review.');
        $base = intdiv($grossMinor, $count);
        $remainder = $grossMinor - $base * $count;

        return array_map(fn ($id, $index) => ['client_id' => $id, 'amount_minor' => $base + ($index < $remainder ? 1 : 0), 'share_numerator' => 1, 'share_denominator' => $count], $ids, array_keys($ids));
    }

    /** @return array<int> distinct earning escorts for an offer */
    public function recipients(PremiumContentOffer $offer): array
    {
        if ($offer->isMultiCreator()) {
            return $offer->assets->pluck('client_id')->filter()->unique()->sort()->values()->all();
        }

        return $offer->client_id ? [(int) $offer->client_id] : [];
    }

    public function preview(PremiumContentOffer $offer, float $amount): array
    {
        return array_map(fn ($row) => $row + ['amount' => number_format($row['amount_minor'] / 100, 2, '.', '')], $this->split((int) round($amount * 100), $this->recipients($offer)));
    }

    /** Snapshot allocations before payment so later membership changes cannot alter them. */
    public function freeze(VisitorContentPurchase $purchase, PremiumContentOffer $offer): void
    {
        $this->store($purchase, $this->recipients($offer));
    }

    /** Allocation rows for fulfilment; purchases created before allocations get one 100% row. */
    public function forFulfilment(VisitorContentPurchase $purchase)
    {
        if (! $purchase->allocations()->exists()) {
            abort_unless($purchase->client_id, 409, 'The creator account needs a settlement review.');
            $this->store($purchase, [(int) $purchase->client_id]);
        }

        return $purchase->allocations()->orderBy('client_id')->lockForUpdate()->get();
    }

    private function store(VisitorContentPurchase $purchase, array $clientIds): void
    {
        foreach ($this->split((int) round((float) $purchase->gross_amount * 100), $clientIds) as $row) {
            VisitorContentPurchaseAllocation::firstOrCreate(['purchase_id' => $purchase->id, 'client_id' => $row['client_id']], $row + ['currency' => $purchase->currency, 'status' => 'pending', 'idempotency_key' => 'premium-content-credit:'.$purchase->id.':'.$row['client_id']]);
        }
    }
}
