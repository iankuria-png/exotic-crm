<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use App\Models\User;
use App\Services\ManualPaymentSubmissionService;
use Illuminate\Support\Facades\DB;

class CollectionReviewService
{
    public function confirm(CollectionReceipt $receipt, User $actor, int $expectedVersion): CollectionReceipt
    {
        $allocation = $receipt->allocation;
        if (! $allocation) {
            throw new CollectionException('verified_allocation_required');
        }
        app(CollectionPurchaseService::class)->assertScope($receipt->account, Client::findOrFail($allocation->purchase_snapshot['client_id']), $actor);

        return app(CollectionWriterGuard::class)->owned($allocation, fn () => DB::transaction(function () use ($receipt, $allocation, $actor, $expectedVersion) {
            CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
            $r = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
            if ($r->reviewed_at) {
                return $r;
            }
            if ($r->version !== $expectedVersion || ! in_array($allocation->fresh()->state, ['active_review_pending', 'reviewed'])
                || $r->conflicts || in_array($r->funds_state, ['reversed', 'disputed'])) {
                throw new CollectionException('review_state_changed_or_unconfirmed');
            }
            $r->update(['reviewed_at' => now(), 'reviewed_by' => $actor->id, 'decision_state' => 'reviewed', 'version' => $r->version + 1]);
            $allocation->update(['state' => 'reviewed']);
            $payment = $allocation->payment;
            $payment->update(['reconciliation_state' => 'resolved', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            if ($payment->manualSubmission) {
                app(ManualPaymentSubmissionService::class)->markSubmissionReview($payment->manualSubmission, 'approved', $actor->id);
            }
            // SMS remains phone evidence; sales review releases only the sales counter.
            app(CollectionExposureService::class)->release($r, 'review');
            app(CollectionPurchaseService::class)->audit($r, $actor->id, 'collection_sales_reviewed', ['allocation_id' => $allocation->id], 'review:'.$r->id);
            app(\App\Services\Rebates\RebateGrantService::class)->afterSettlement($payment);

            return $r->fresh();
        }, 3));
    }
}
