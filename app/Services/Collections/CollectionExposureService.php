<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Facades\DB;

class CollectionExposureService
{
    /** A correction reopens the original review risk, without spending daily principal twice. */
    public function reopenReview(CollectionReceipt $receipt): void
    {
        $row = DB::table('collection_exposure_reservations')->where('receipt_id', $receipt->id)->lockForUpdate()->first();
        if (! $row || ! $row->review_released) {
            return;
        }
        DB::table('collection_exposure_reservations')->where('id', $row->id)->update(['review_released' => false]);
        DB::table('collection_exposure_buckets')->where('account_id', $row->account_id)->where('currency', $row->currency)->where('bucket', 'outstanding')
            ->increment('unreviewed_minor', $row->amount_minor);
    }

    /** Caller owns the account lock and allocation transaction. Policy versions never reset buckets. */
    public function reserve(CollectionReceipt $receipt, CollectionAllocation $allocation): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Exposure must be reserved with allocation.');
        }
        if (DB::table('collection_exposure_reservations')->where('receipt_id', $receipt->id)->lockForUpdate()->first() !== null) {
            return;
        }
        $account = $receipt->account;
        $policy = $account->policy ?? [];
        if (! app(CollectionPolicyService::class)->configured($policy)) {
            throw new CollectionException('automatic_exposure_policy_missing');
        }
        $amount = $receipt->principal_minor;
        // Freeze a started accounting day. A timezone revision takes effect after
        // that day's boundary; it cannot open another allowance during the day.
        $latestDay = DB::table('collection_exposure_buckets')->where('account_id', $account->id)->where('currency', $receipt->currency)
            ->where('bucket', '!=', 'outstanding')->orderByDesc('bucket')->lockForUpdate()->first();
        $timezone = $latestDay && now()->setTimezone($latestDay->timezone)->toDateString() === $latestDay->bucket ? $latestDay->timezone : $account->timezone;
        $date = now()->setTimezone($timezone)->toDateString();
        foreach ([$date, 'outstanding'] as $bucket) {
            DB::table('collection_exposure_buckets')->insertOrIgnore(['account_id' => $account->id, 'currency' => $receipt->currency, 'bucket' => $bucket, 'timezone' => $timezone]);
        }
        $daily = DB::table('collection_exposure_buckets')->where('account_id', $account->id)->where('currency', $receipt->currency)->where('bucket', $date)->lockForUpdate()->first();
        $outstanding = DB::table('collection_exposure_buckets')->where('account_id', $account->id)->where('currency', $receipt->currency)->where('bucket', 'outstanding')->lockForUpdate()->first();
        $unverified = $receipt->evidence_level === 'phone' ? $amount : 0;
        foreach (['single_cap_minor' => $amount, 'daily_cap_minor' => $daily->daily_minor + $amount,
            'unreviewed_cap_minor' => $outstanding->unreviewed_minor + $amount,
            'unverified_cap_minor' => $outstanding->unverified_minor + $unverified] as $cap => $exposure) {
            if ($policy[$cap] !== null && $exposure > $policy[$cap]) {
                throw new CollectionException('automatic_exposure_limit');
            }
        }
        DB::table('collection_exposure_buckets')->where('id', $daily->id)->increment('daily_minor', $amount);
        DB::table('collection_exposure_buckets')->where('id', $outstanding->id)->update(['unreviewed_minor' => $outstanding->unreviewed_minor + $amount, 'unverified_minor' => $outstanding->unverified_minor + $unverified]);
        DB::table('collection_exposure_reservations')->insert(['receipt_id' => $receipt->id, 'account_id' => $account->id, 'allocation_id' => $allocation->id,
            'currency' => $receipt->currency, 'amount_minor' => $amount, 'accounting_date' => $date, 'timezone' => $daily->timezone,
            'policy_version' => $policy['version'], 'verification_released' => $unverified === 0, 'created_at' => now()]);
    }

    public function release(CollectionReceipt $receipt, string $kind): void
    {
        if (! in_array($kind, ['review', 'verification'])) {
            throw new \InvalidArgumentException('Unsupported release kind.');
        }
        DB::transaction(function () use ($receipt, $kind) {
            \App\Models\Collections\CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
            $r = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
            if ($r->conflicts || in_array($r->funds_state, ['reversed', 'disputed'])) {
                throw new CollectionException('finance_resolution_required');
            }
            $reservation = DB::table('collection_exposure_reservations')->where('receipt_id', $receipt->id)->lockForUpdate()->first();
            if (! $reservation) {
                return;
            }
            $field = $kind === 'review' ? 'review_released' : 'verification_released';
            if ($reservation->$field) {
                return;
            }
            if ($kind === 'review' && ! $r->reviewed_at || $kind === 'verification' && $r->evidence_level === 'phone') {
                throw new CollectionException('release_evidence_missing');
            }
            DB::table('collection_exposure_reservations')->where('id', $reservation->id)->update([$field => true]);
            DB::table('collection_exposure_buckets')->where('account_id', $reservation->account_id)->where('currency', $reservation->currency)->where('bucket', 'outstanding')
                ->decrement($kind === 'review' ? 'unreviewed_minor' : 'unverified_minor', $reservation->amount_minor);
            app(CollectionPurchaseService::class)->audit($r, $r->reviewed_by, 'exposure_'.$kind.'_released', ['reservation_id' => $reservation->id], 'release:'.$reservation->id.':'.$kind);
        }, 3);
    }
}
