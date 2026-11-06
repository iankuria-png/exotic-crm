<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionOutboxWorker;
use App\Services\Collections\CollectionReceiptService;
use Illuminate\Support\Facades\DB;

class CollectionDurabilityTest extends CollectionWorkflowTestCase
{
    public function test_outbox_persists_enrichment_wait_then_late_detail_activates_one_purchase(): void
    {
        [$account, $device] = $this->purchase();
        $this->fakeWordPress();
        $summary = 'ABCDEF12345 confirmed. You have received a payment of Tsh35,000.00 from 123456 - FIXTURE BANK on 30/10/26 at 14:59. New M-Pesa balance Tsh100,000.00';
        app(CollectionIngestService::class)->accept($device, $this->envelope($summary));
        $worker = app(CollectionOutboxWorker::class);
        $worker->run(); // Parse.
        $worker->run(); // Persist a bounded enrichment decision.
        $this->assertSame('collecting', CollectionReceipt::first()->decision_state);
        $this->assertDatabaseCount('payments', 0);
        $this->travel(26)->seconds();
        $worker->run();
        $this->assertSame('needs_review', CollectionReceipt::first()->decision_state);
        app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit(), 'late-detail'));
        $worker->run();
        $worker->run();
        $worker->run();
        $this->assertDatabaseCount('collection_receipts', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('active_review_pending', CollectionAllocation::first()->state);
        $this->assertSame(1, $this->posts);
    }

    public function test_event_and_outbox_failure_roll_back_together_before_acknowledgment(): void
    {
        [$account, $device] = $this->inbox();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'collection_outbox')) {
                throw new \RuntimeException('Fixture crash before event/outbox commit');
            }
        });
        try {
            app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit()));
            $this->fail('Event was acknowledged despite a failed durable outbox commit.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fixture crash before event/outbox commit', $e->getMessage());
        }
        $this->assertDatabaseCount('collection_events', 0);
        $this->assertDatabaseCount('collection_outbox', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_late_payer_conflict_does_not_reassign_or_repeat_an_active_purchase(): void
    {
        [$account, $device] = $this->purchase();
        $this->fakeWordPress();
        $r = $this->received($device);
        app(\App\Services\Collections\CollectionDecisionService::class)->evaluate($r->id);
        $allocation = CollectionAllocation::first();
        app(\App\Services\Collections\CollectionFulfillmentService::class)->resume($allocation->id);
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit('ABCDEF12345', '35,000', '255700000002'), 'conflicting-late-detail'));
        app(CollectionReceiptService::class)->process($event['event_id']);
        app(\App\Services\Collections\CollectionDecisionService::class)->evaluate($r->id);
        app(\App\Services\Collections\CollectionFulfillmentService::class)->resume($allocation->id);
        $this->assertSame('needs_review', $r->fresh()->decision_state);
        $this->assertSame($allocation->purchase_snapshot, $allocation->fresh()->purchase_snapshot);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, $this->posts);
    }
}
