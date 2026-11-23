<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\Collections\CollectionOutboxWorker;
use App\Services\Collections\Kopokopo\KopokopoStatementImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class KopokopoWorkflowTest extends KopokopoCollectionTestCase
{
    public function test_preexisting_standing_authority_fulfills_once_and_retries_do_not_duplicate_service(): void
    {
        [$feed] = $this->kenya();
        $this->fakeWordPress();
        $accepted = $this->receive($feed);
        $worker = app(CollectionOutboxWorker::class);
        $worker->run();
        $allocation = CollectionAllocation::first();
        $this->assertNotNull($allocation, json_encode(CollectionReceipt::find($accepted['receipt_id'])->candidate_snapshot));
        $worker->run();
        $this->assertSame('active_review_pending', $allocation->fresh()->state);
        $this->receive($feed);
        $worker->run();
        app(CollectionFulfillmentService::class)->resume($allocation->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('collection_allocations', 1);
        $this->assertSame(1, $this->posts);
    }

    public function test_reviewed_payer_history_without_purchase_authority_never_activates(): void
    {
        [$feed, , $intent] = $this->kenya();
        $intent->delete();
        $accepted = $this->receive($feed);
        app(CollectionOutboxWorker::class)->run();
        $r = CollectionReceipt::find($accepted['receipt_id']);
        $this->assertSame('needs_review', $r->decision_state);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertContains('current_purchase_authority_required', data_get($r->candidate_snapshot, 'matching.guards', []));
    }

    public function test_reversal_after_activation_flags_review_without_revocation_or_refund(): void
    {
        [$feed] = $this->kenya();
        $this->fakeWordPress();
        $accepted = $this->receive($feed);
        app(CollectionDecisionService::class)->evaluate($accepted['receipt_id']);
        $a = CollectionAllocation::firstOrFail();
        app(CollectionFulfillmentService::class)->resume($a->id);
        $expiry = $this->remote[77]['meta']['escort_expire'];
        $this->receive($feed, $this->providerBody([], 'reversed-after-service', 'buygoods_transaction_reversed'));
        app(CollectionOutboxWorker::class)->run();
        $this->assertSame('reversed', CollectionReceipt::find($accepted['receipt_id'])->funds_state);
        $this->assertSame($expiry, $this->remote[77]['meta']['escort_expire']);
        $this->assertSame(1, $this->posts);
        $this->assertDatabaseCount('payments', 1);
        $this->assertNull($feed->account->fresh()->suspended_at);
    }

    public function test_staff_review_preview_activate_and_retry_use_existing_sales_endpoints(): void
    {
        [$feed, $client, $intent, $actor, $price] = $this->kenya();
        $intent->delete();
        $p = $feed->account->policy;
        $p['automation_enabled'] = false;
        $feed->account->update(['mode' => 'assisted', 'policy' => $p]);
        $this->fakeWordPress();
        Sanctum::actingAs($actor);
        $accepted = $this->receive($feed->fresh());
        $r = CollectionReceipt::find($accepted['receipt_id']);
        $this->getJson('/api/crm/collections/receipts/'.$r->id.'/review-context?workflow=manual')->assertOk()->assertJsonPath('payer.source', 'kopokopo');
        $input = ['receipt_version' => $r->version, 'session_version' => 0, 'request_key' => (string) Str::uuid(),
            'items' => [['client_id' => $client->id, 'product_price_id' => $price->id]]];
        $preview = $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/preview', $input)->assertOk()->json();
        $input['preview_token'] = $preview['preview_token'];
        $response = $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/activate', $input)->assertSuccessful()->json();
        $item = DB::table('collection_sales_items')->first();
        $this->assertNotNull($item);
        app(\App\Services\Collections\CollectionSalesFulfillmentService::class)->resume($item->id);
        app(\App\Services\Collections\CollectionSalesFulfillmentService::class)->resume($item->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, $this->posts);
    }

    public function test_statement_history_is_idempotent_and_does_not_grant_service_or_reclassify_a_live_receipt(): void
    {
        [$feed, , , $actor] = $this->kenya();
        $path = $this->csv();
        $service = app(KopokopoStatementImport::class);
        $statement = $service->read($path);
        $this->assertDatabaseCount('collection_receipts', 0);
        $counts = $service->import($feed, $statement, $actor);
        $this->assertSame(1, $counts['accepted']);
        $r = CollectionReceipt::firstOrFail();
        $this->assertTrue($r->historical_only);
        $this->assertSame('statement', $r->evidence_level);
        $this->assertFalse($r->automation_eligible_at_ingest);
        $this->assertSame(1, $service->import($feed, $statement, $actor)['duplicate']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertSame(0, $this->posts);
        $live = $this->receive($feed, $this->providerBody(['id' => 'live-resource-2', 'reference' => 'UKXFIX0002'], 'live-event-2'));
        $liveRow = CollectionReceipt::find($live['receipt_id']);
        $epoch = $liveRow->automation_epoch;
        $liveStatement = $service->read($this->csv('UKXFIX0002'));
        $service->import($feed, $liveStatement, $actor);
        $this->assertFalse($liveRow->fresh()->historical_only);
        $this->assertSame($epoch, $liveRow->fresh()->automation_epoch);
        $this->assertSame('provider', $liveRow->fresh()->evidence_level);
    }

    public function test_entire_statement_is_validated_before_any_apply(): void
    {
        [$feed] = $this->kenya();
        $path = $this->csv();
        $h = fopen($path, 'a');
        fputcsv($h, [now()->tz('Africa/Nairobi')->format('Y-m-d H:i'), 'UKXFIX0002', str_repeat('b', 64), 'Other Payer', 'K317087', 'N/A', '1.00', '1500.00', 'Safaricom', 'Complete', '', '']);
        fclose($h);
        try {
            app(KopokopoStatementImport::class)->read($path);
            $this->fail('Foreign till accepted');
        } catch (CollectionException $e) {
            $this->assertSame('provider_statement_receiving_scope_mismatch', $e->reason);
        }
        $this->assertDatabaseCount('collection_receipts', 0);
    }

    public function test_migration_rollback_retains_enrolled_provider_evidence(): void
    {
        $this->kenya();
        $migration = require database_path('migrations/2026_11_23_210000_create_kopokopo_collection_transport.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider evidence must be retained');
        $migration->down();
    }

    private function csv(string $reference = 'UKXFIX0001'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kopo-fixture-');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        $h = fopen($path, 'w');
        fputcsv($h, ['Transactions report']);
        fputcsv($h, []);
        fputcsv($h, KopokopoStatementImport::HEADER);
        fputcsv($h, [now()->subMinute()->tz('Africa/Nairobi')->format('Y-m-d H:i'), $reference, str_repeat('a', 64), 'Fixture Payer', '268491', 'N/A', '9.00', '1500.00', 'Safaricom', 'Complete', '', '']);
        fclose($h);

        return $path;
    }
}
