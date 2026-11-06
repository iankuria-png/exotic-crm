<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAllocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CollectionOutboxWorker
{
    public function run(int $limit = 100, ?int $deadline = null): array
    {
        $done = 0;
        $held = 0;
        $ids = DB::table('collection_outbox')->whereNull('completed_at')->where('available_at', '<=', now())->orderBy('id')->limit(min(1000, $limit))->pluck('id');
        foreach ($ids as $id) {
            if ($deadline !== null && hrtime(true) >= $deadline) {
                break;
            }
            // Outbox tasks may replay. Financial dispatch is separately guarded by durable effect state.
            $lock = Cache::lock('collection-outbox:'.$id, 300);
            if (! $lock->get()) {
                continue;
            }
            try {
                $row = DB::table('collection_outbox')->where('id', $id)->first();
                if ($row->completed_at) {
                    continue;
                }
                DB::table('collection_outbox')->where('id', $id)->increment('attempts');
                match ($row->kind) {
                    'parse' => app(CollectionReceiptService::class)->process((int) $row->subject_id),
                    'decide' => app(CollectionDecisionService::class)->evaluate((int) $row->subject_id),
                    'fulfill' => $this->fulfill($row->subject_id),
                    'benefits' => $this->benefits($row->subject_id),
                    default => throw new CollectionException('unknown_outbox_kind'),
                };
                DB::table('collection_outbox')->where('id', $id)->update(['completed_at' => now(), 'last_error_code' => null]);
                $done++;
            } catch (\Throwable $e) {
                DB::table('collection_outbox')->where('id', $id)->update(['available_at' => now()->addMinutes(5), 'last_error_code' => $e instanceof CollectionException ? $e->reason : 'processing_failed']);
                $held++;
            } finally {
                $lock->release();
            }
        }

        return compact('done', 'held');
    }

    private function fulfill(string $id): void
    {
        $allocation = app(CollectionFulfillmentService::class)->resume($id);
        if (in_array($allocation->state, ['uncertain', 'dependent_pending'])) {
            // Keep recovery durable; lookup retries do not blindly re-dispatch an uncertain write.
            throw new CollectionException('fulfillment_recovery_pending');
        }
    }

    private function benefits(string $id): void
    {
        $a = CollectionAllocation::with('payment')->findOrFail($id);
        if (in_array($a->state, ['corrected', 'unassigned']) || $a->effects()->whereNotNull('correction_id')->exists()) {
            throw new CollectionException('benefits_correction_review_required');
        }
        if (! $a->payment?->deal_id || ! in_array($a->receipt->funds_state, ['observed', 'statement_verified']) || $a->receipt->conflicts) {
            throw new CollectionException('benefits_not_ready');
        }
        app(CollectionWriterGuard::class)->owned($a, function () use ($a) {
            app(\App\Jobs\GrantNewSubscriptionPass::class, ['dealId' => (int) $a->payment->deal_id])->handle(app(\App\Services\Monetization\ComplimentaryPassService::class));
        });
    }
}
