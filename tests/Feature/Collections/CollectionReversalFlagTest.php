<?php

namespace Tests\Feature\Collections;

use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionReceiptService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionReversalFlagTest extends CollectionWorkflowTestCase
{
    public function test_provider_reversal_flags_the_payment_without_suspending_the_wallet_and_staff_can_record_a_review(): void
    {
        [$account, $device, , , $admin] = $this->purchase(false);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($admin);
        $receipt = $this->received($device, 'ZXCVBN12345');
        $other = $this->received($device, 'KEEPGOING12');
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope('The transaction ZXCVBN12345 has been successfully reversed.', 'reversal-1'));
        app(CollectionReceiptService::class)->process($event['event_id']);

        $this->assertNull($account->fresh()->suspended_at, 'A reversal must not stop the wallet');
        $this->assertSame('reversed', $receipt->fresh()->funds_state);
        $this->assertSame('observed', $other->fresh()->funds_state);
        $this->assertSame(1, DB::table('collection_audit')->where('action', 'receipt_reversed_by_provider')->where('receipt_id', $receipt->id)->count());

        $list = $this->getJson('/api/crm/collections/receipts')->assertOk();
        $this->assertSame('ZXCVBN12345', $list->json('reversals.0.reference'));
        $this->assertFalse($list->json('reversals.0.service_active'));
        $this->assertNull($list->json('reversals.0.reviewed'));
        $this->getJson('/api/crm/collections/receipts?state=reversed')->assertOk()->assertJsonPath('total', 1);

        $this->postJson('/api/crm/collections/receipts/'.$receipt->id.'/reversal-review', ['decision' => 'keep_service', 'note' => 'Customer paid again in cash'])->assertOk();
        $this->postJson('/api/crm/collections/receipts/'.$other->id.'/reversal-review', ['decision' => 'keep_service', 'note' => 'Not reversed'])->assertStatus(422);
        $this->assertSame('Keep service active', $this->getJson('/api/crm/collections/receipts')->json('reversals.0.reviewed.decision'));
    }

    public function test_unmatched_reversal_is_quarantined_and_flagged_without_suspension(): void
    {
        [$account, $device] = $this->purchase(false);
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope('Your transaction has been successfully reversed.', 'reversal-unmatched'));
        app(CollectionReceiptService::class)->process($event['event_id']);
        $this->assertNull($account->fresh()->suspended_at);
        $this->assertSame(1, DB::table('collection_audit')->where('action', 'unmatched_reversal_flagged')->count());
    }
}
