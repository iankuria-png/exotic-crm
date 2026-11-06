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
            ->whereIn('account_id', DB::table('collection_accounts')->whereIn('mode', ['assisted', 'auto'])->select('id'))->exists();
        if ($pilot && ! $this->owner) {
            throw new CollectionException('use_collection_payment_workspace');
        }
    }

    public function assertPayment(Payment $payment): void
    {
        if (! in_array($payment->purpose, [null, Payment::PURPOSE_SUBSCRIPTION], true)) {
            return;
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
}
