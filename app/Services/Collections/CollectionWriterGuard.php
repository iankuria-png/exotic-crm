<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Payment;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Durable holds survive workers, cache lease expiry and disabled deployment gates. */
class CollectionWriterGuard
{
    private ?string $owner = null;

    private ?string $saleItem = null;

    private ?string $saleCorrection = null;

    /** @var array{account_id: string, reference: string}|null */
    private ?array $receiptSale = null;

    /**
     * Staff activating a receipt from the payments workspace own that receipt's purchase choices,
     * including the automation's own match for the same payment. Choices for other receipts still block.
     */
    public function forReceiptSale(\App\Models\Collections\CollectionReceipt $receipt, Closure $callback): mixed
    {
        $previous = $this->receiptSale;
        $this->receiptSale = ['account_id' => $receipt->account_id, 'reference' => $receipt->reference_norm];
        try {
            return $callback();
        } finally {
            $this->receiptSale = $previous;
        }
    }

    public function ownedSaleCorrection(\App\Models\Collections\CollectionSalesItem $item, string $correctionId, Closure $callback): mixed
    {
        $previous = $this->saleCorrection;
        $this->saleCorrection = $correctionId;
        try {
            return $this->ownedSale($item, $callback);
        } finally {
            $this->saleCorrection = $previous;
        }
    }

    public function ownedSale(\App\Models\Collections\CollectionSalesItem $item, Closure $callback): mixed
    {
        $previous = $this->saleItem;
        $this->saleItem = $item->id;
        try {
            return $callback();
        } finally {
            $this->saleItem = $previous;
        }
    }

    /** Serialize captured-reference cash inserts with receipt claims, including legacy entry points. */
    public function paymentWrite(Payment $payment, Closure $callback): mixed
    {
        if ($this->saleItem || ($payment->exists && ! $payment->isDirty(['platform_id', 'amount', 'currency', 'client_id', 'deal_id', 'transaction_reference', 'reference_number', 'reference_root'])) || ! Schema::hasTable('collection_sales_sessions')
            || ! in_array($payment->purpose, [null, Payment::PURPOSE_SUBSCRIPTION], true)) {
            return $callback();
        }
        $accounts = DB::table('collection_account_platforms')->where('platform_id', $payment->platform_id)->pluck('account_id')->unique()->sort()->values();
        if ($accounts->isEmpty()) {
            return $callback();
        }

        return DB::transaction(function () use ($accounts, $callback) {
            \App\Models\Collections\CollectionAccount::whereIn('id', $accounts)->orderBy('id')->lockForUpdate()->get();

            return $callback();
        });
    }

    public function assertReceipt(int $receiptId): void
    {
        if (Schema::hasTable('collection_sales_sessions') && DB::table('collection_sales_sessions')->where('receipt_id', $receiptId)->where('state', '!=', 'draft')->exists()) {
            throw new CollectionException('receipt_owned_by_manual_sale');
        }
    }

    public function owned(CollectionAllocation $allocation, Closure $callback): mixed
    {
        $previous = $this->owner;
        $this->owner = $allocation->id;
        try {
            return $callback();
        } finally {
            $this->owner = $previous;
        }
    }

    public function assertClient(int $clientId): void
    {
        if (Schema::hasTable('collection_sales_profile_holds')) {
            $sale = DB::table('collection_sales_profile_holds')->where('client_id', $clientId)->first();
            if ($sale && $sale->item_id !== $this->saleItem) {
                throw new CollectionException('receipt_sale_owns_profile');
            }
        }
        if (! Schema::hasTable('collection_profile_holds')) {
            return;
        }
        $hold = DB::table('collection_profile_holds')->where('client_id', $clientId)->first();
        if ($hold) {
            if ($hold->allocation_id === $this->owner) {
                return;
            }
            throw new CollectionException('collection_operation_owns_profile');
        }
        $pilot = CollectionPurchaseIntent::whereNull('revoked_at')->where('valid_until', '>', now())
            ->where(fn ($q) => $q->where('client_id', $clientId)->orWhere('purchase_snapshot->subsidiary->client_id', $clientId))
            ->whereIn('account_id', DB::table('collection_accounts')->whereIn('mode', ['assisted', 'auto'])->select('id'))
            ->when($this->receiptSale, fn ($q) => $q->where(fn ($q) => $q->where('account_id', '!=', $this->receiptSale['account_id'])
                ->orWhereNull('provider_reference')->orWhere('provider_reference', '!=', $this->receiptSale['reference'])))->exists();
        $saleOwnsClient = $this->saleItem && DB::table('collection_sales_items')->where('id', $this->saleItem)->where('client_id', $clientId)->exists();
        if ($pilot && ! $this->owner && ! $saleOwnsClient) {
            throw new CollectionException('use_collection_payment_workspace');
        }
    }

    public function assertPayment(Payment $payment): void
    {
        if (! in_array($payment->purpose, [null, Payment::PURPOSE_SUBSCRIPTION], true)) {
            return;
        }
        if (Schema::hasTable('collection_sales_items')) {
            $sale = $payment->exists ? \App\Models\Collections\CollectionSalesItem::where('payment_id', $payment->id)->first() : null;
            if ($sale && $sale->id !== $this->saleItem) {
                throw new CollectionException('payment_owned_by_receipt_sale');
            }
            $refs = array_values(array_unique(array_map(fn ($v) => strtoupper(trim($v)), array_filter([$payment->transaction_reference, $payment->reference_number, $payment->reference_root]))));
            if ($refs) {
                $receipts = DB::table('collection_receipts as r')->join('collection_account_platforms as b', 'b.account_id', '=', 'r.account_id')
                    ->where('b.platform_id', $payment->platform_id)->where(function ($q) use ($refs) {
                        $q->whereIn('r.reference_norm', $refs)->orWhereExists(fn ($alias) => $alias->selectRaw('1')->from('collection_receipt_aliases as alias')
                            ->whereColumn('alias.account_id', 'r.account_id')->whereColumn('alias.receipt_id', 'r.id')->whereIn('alias.reference', $refs));
                    })->get(['r.id']);
                foreach ($receipts as $receipt) {
                    $owner = DB::table('collection_sales_sessions')->where('receipt_id', $receipt->id)->where('state', '!=', 'draft')->first();
                    if ($owner && ! $this->correctionOwnsPayment($payment) && (! $this->saleItem || ! DB::table('collection_sales_items')->where('session_id', $owner->id)->where('id', $this->saleItem)
                        ->where('client_id', $payment->client_id)->where('deal_id', $payment->deal_id)->exists())) {
                        throw new CollectionException('receipt_principal_owned_by_manual_sale');
                    }
                }
            }
        }
        if (! Schema::hasTable('collection_allocations')) {
            return;
        }
        $allocation = $payment->exists ? CollectionAllocation::where('payment_id', $payment->id)->first() : null;
        if ($allocation) {
            if ($allocation->id !== $this->owner) {
                throw new CollectionException('collection_payment_is_managed');
            }

            return;
        }
        if (! $payment->exists && (float) $payment->amount > 0) {
            $clientId = (int) ($payment->client_id ?: ($payment->user_id ? Client::where('platform_id', $payment->platform_id)->where('wp_user_id', $payment->user_id)->value('id') : 0));
            if ($clientId) {
                $this->assertClient($clientId);
            }
            $refs = array_filter([$payment->transaction_reference, $payment->reference_number, $payment->reference_root]);
            if ($refs) {
                $reserved = DB::table('collection_receipts as r')->join('collection_allocations as a', 'a.receipt_id', '=', 'r.id')
                    ->join('collection_account_platforms as b', 'b.account_id', '=', 'r.account_id')->where('b.platform_id', $payment->platform_id)
                    ->where(function ($q) use ($refs) {
                        foreach ($refs as $ref) {
                            $q->orWhere('r.reference_norm', strtoupper(trim($ref)));
                        }
                    })->first(['a.id']);
                if ($reserved && $reserved->id !== $this->owner) {
                    throw new CollectionException('receipt_principal_already_reserved');
                }
            }
        }
    }

    private function correctionOwnsPayment(Payment $payment): bool
    {
        if (! $this->saleCorrection || ! $this->saleItem || ! $payment->exists
            || $payment->isDirty(['platform_id', 'amount', 'currency', 'transaction_reference', 'reference_number', 'reference_root', 'manual_payment_bundle_id'])) {
            return false;
        }
        $journal = \App\Models\Collections\CollectionSalesCorrection::whereKey($this->saleCorrection)->where('item_id', $this->saleItem)
            ->whereNotNull('request_key')->whereIn('state', ['reserved', 'source_compensating', 'target_applying', 'unknown'])->first();
        if (! $journal || (int) data_get($journal->preview, 'source.payment_id') !== (int) $payment->id) {
            return false;
        }
        $target = $journal->preview['target'];

        return $target ? (int) $payment->client_id === (int) $target['client_id'] && (int) $payment->deal_id === (int) $journal->target_deal_id
            : $payment->client_id === null && $payment->deal_id === null;
    }
}
