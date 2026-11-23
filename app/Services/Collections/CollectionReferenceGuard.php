<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff-typed transaction IDs on markets with a receiving phone. Only PROVEN conflicts are refused: the phone
 * received the money and it is already fully used, activated from Payments, or reversed. An ID the phone has not
 * seen is allowed and appears in claim checks instead (Ian, 18 Nov 2026: flag unverified claims, never hold them).
 */
class CollectionReferenceGuard
{
    public function assertStaffClaim(Deal $deal, string $reference, float $amount): void
    {
        $accounts = DB::table('collection_account_platforms')->where('platform_id', $deal->platform_id)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->pluck('account_id');
        $reference = (int) $deal->platform_id === 1 ? \App\Services\Collections\Kopokopo\KopokopoReference::root($reference) : trim($reference);
        if ((int) $deal->platform_id === 1) {
            \App\Models\Collections\CollectionAccount::whereIn('id', $accounts)->orderBy('id')->lockForUpdate()->get();
        }
        if ($accounts->isEmpty() || $reference === '') {
            return;
        }
        preg_match_all('/(?<!\d)\d{9,13}(?!\d)/', $reference, $m);
        $refs = array_values(array_unique([$reference, ...$m[0]]));
        $receiptId = DB::table('collection_receipt_aliases')->whereIn('account_id', $accounts)->whereIn('reference', $refs)->value('receipt_id');
        $receipt = CollectionReceipt::whereIn('account_id', $accounts)->where(fn ($q) => $q->whereIn('reference_norm', $refs)->when($receiptId, fn ($q) => $q->orWhere('id', $receiptId)))
            ->lockForUpdate()->first();
        if (! $receipt) {
            return;
        }
        $label = $receipt->reference_norm;
        $money = $receipt->currency.' '.number_format($receipt->principal_minor / 100, $receipt->principal_minor % 100 ? 2 : 0);
        if (in_array($receipt->funds_state, ['reversed', 'disputed'], true)) {
            $this->refuse("The provider withheld or reversed payment {$label} ({$money}). Ask Finance before activating it.");
        }
        $sale = DB::table('collection_sales_sessions')->where('receipt_id', $receipt->id)->where('state', '!=', 'draft')->exists()
            || DB::table('collection_allocations')->where('receipt_id', $receipt->id)->exists();
        if ($sale) {
            $this->refuse("Payment {$label} ({$money}) was already activated from Payments. Open it there to change or correct the activation.");
        }
        $refsWithAliases = array_values(array_unique([$receipt->reference_norm, ...DB::table('collection_receipt_aliases')->where('receipt_id', $receipt->id)->pluck('reference')->all(), ...$refs]));
        $used = Payment::with('client:id,name')->withoutReceiptDuplicates()->where('platform_id', $deal->platform_id)->whereIn('status', Payment::SUCCESSFUL_STATUSES)
            ->where(function ($q) use ($refsWithAliases) {
                $q->whereIn('transaction_reference', $refsWithAliases)->orWhereIn('reference_root', $refsWithAliases)->orWhereIn('reference_number', $refsWithAliases);
                \App\Services\Collections\Kopokopo\KopokopoReference::orNormalized($q, ['transaction_reference', 'reference_root', 'reference_number'], $refsWithAliases);
            })
            ->lockForUpdate()->get();
        $usedMinor = (int) round($used->sum(fn ($p) => (float) $p->amount) * 100);
        if ($usedMinor + (int) round($amount * 100) > $receipt->principal_minor) {
            $names = $used->map(fn ($p) => $p->client?->name)->filter()->unique()->implode(', ');
            $this->refuse($used->isEmpty()
                ? "Payment {$label} received only {$money}, less than this subscription. Record the correct transaction ID or ask the customer to pay the balance."
                : "Payment {$label} received {$money} and is already used for {$names}. To pay for several profiles with one payment, use the shared manual payment flow.");
        }
    }

    private function refuse(string $message): void
    {
        throw ValidationException::withMessages(['payment_reference' => $message]);
    }
}
