<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionSalesItem;
use App\Models\Payment;
use App\Services\Collections\CollectionOutboxWorker;
use App\Services\Collections\CollectionSalesCorrectionService;
use App\Services\Collections\CollectionSalesFulfillmentService;
use App\Services\Collections\CollectionSalesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionSalesCorrectionTest extends CollectionWorkflowTestCase
{
    private function sale(bool $shared = false): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase();
        $account->update(['mode' => 'shadow']);
        $this->compensationV2 = true;
        config(['collection.workspace_enabled' => true, 'collection_sales.verified_accounts' => [$account->id], 'collection_sales.verified_platforms' => [$client->platform_id]]);
        $r = $this->received($device);
        $items = [['client_id' => $client->id, 'product_price_id' => $price->id]];
        if ($shared) {
            $sibling = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 88, 'wp_user_id' => 66, 'is_high_risk' => false]);
            $this->remote[88] = $this->snapshot(88, 66);
            $items[] = ['client_id' => $sibling->id, 'product_price_id' => $price->id];
            $r->update(['principal_minor' => 7000000]);
        }
        $this->fakeWordPress();
        $input = ['receipt_version' => $r->version, 'session_version' => 0, 'request_key' => (string) Str::uuid(), 'items' => $items];
        $preview = app(CollectionSalesService::class)->preview($r, $actor, $input);
        $input['preview_token'] = $preview['preview_token'];
        app(CollectionSalesFulfillmentService::class)->prepare($r, $actor, $input);
        foreach (CollectionSalesItem::orderBy('position')->get() as $item) {
            app(CollectionSalesFulfillmentService::class)->resume($item->id);
        }

        $account->update(['mode' => 'auto']);

        return [$account, $r, CollectionSalesItem::orderBy('position')->firstOrFail(), $actor, $price];
    }

    public function test_revert_preserves_received_cash_and_replay_does_not_restore_old_sale(): void
    {
        [$account, $receipt, $item, $actor] = $this->sale();
        $service = app(CollectionSalesCorrectionService::class);
        $c = $service->preview($item, $actor, ['item_version' => $item->version, 'action' => 'undo_effect', 'reason' => 'Wrong advertiser']);
        $this->assertTrue($c->preview['can_execute'], json_encode($c->preview['reasons']));
        $key = (string) Str::uuid();
        $result = $service->execute($c, $actor, $key, 1);
        $this->assertSame('confirmed', $result->state, json_encode($item->fresh()->last_error_code));
        $this->assertSame('unassigned', $item->fresh()->state);
        $this->assertSame('private', $this->remote[77]['post_status']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('35000.00', Payment::first()->amount);
        $this->assertNull(Payment::first()->client_id);
        $posts = $this->posts;
        $service->execute($c->fresh(), $actor, $key, 1);
        app(CollectionSalesFulfillmentService::class)->resume($item->id);
        $this->assertSame($posts, $this->posts);
        $this->assertDatabaseCount('collection_match_exclusions', 1);
        $this->assertSame(0, DB::table('collection_sales_profile_holds')->count());
    }

    public function test_shared_reassignment_keeps_sibling_and_cash_and_fences_old_jobs(): void
    {
        [$account, $receipt, $item, $actor, $price] = $this->sale(true);
        $sibling = CollectionSalesItem::where('id', '!=', $item->id)->firstOrFail();
        $siblingBefore = $sibling->toArray();
        $siteBefore = $this->remote[88];
        $client = Client::factory()->create(['platform_id' => $item->purchase['platform_id'], 'wp_post_id' => 99, 'wp_user_id' => 88, 'is_high_risk' => false]);
        $this->remote[99] = $this->snapshot(99, 88);
        $service = app(CollectionSalesCorrectionService::class);
        $c = $service->preview($item, $actor, ['item_version' => $item->version, 'action' => 'reassign', 'client_id' => $client->id,
            'product_price_id' => $price->id, 'reason' => 'Use the intended advertiser']);
        $this->assertTrue($c->preview['can_execute'], json_encode($c->preview['reasons']));
        $result = $service->execute($c, $actor, (string) Str::uuid(), 1);
        $this->assertSame('confirmed', $result->state, (string) $item->fresh()->last_error_code);
        $this->assertSame($client->id, $item->fresh()->client_id);
        $this->assertSame($siblingBefore, $sibling->fresh()->toArray());
        $this->assertSame($siteBefore, $this->remote[88]);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('70000.00', number_format((float) Payment::sum('amount'), 2, '.', ''));
        $this->assertSame(2, $item->fresh()->generation);
        $this->assertTrue(DB::table('collection_outbox')->where('subject_id', $item->id)->where('kind', 'sales_benefits')->where('last_error_code', 'superseded_by_correction')->exists());
        $before = $this->posts;
        app(CollectionOutboxWorker::class)->run();
        $this->assertSame($before, $this->posts);
    }

    public function test_lost_source_response_recovers_original_uuid_before_target_without_duplicate_cash(): void
    {
        [$account, $receipt, $item, $actor] = $this->sale();
        $service = app(CollectionSalesCorrectionService::class);
        $c = $service->preview($item, $actor, ['item_version' => $item->version, 'action' => 'undo_effect', 'reason' => 'Wrong advertiser']);
        $this->loseResponse = true;
        $key = (string) Str::uuid();
        $this->assertSame('unknown', $service->execute($c, $actor, $key, 1)->state);
        $this->assertSame('correction_unknown', $item->fresh()->state);
        $posts = $this->posts;
        $this->assertSame('confirmed', $service->execute($c->fresh(), $actor, $key, 1)->state);
        $this->assertSame($posts, $this->posts);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_revert_before_dispatch_fences_original_job_without_a_wordpress_write(): void
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase();
        $account->update(['mode' => 'shadow']);
        $this->compensationV2 = true;
        config(['collection.workspace_enabled' => true, 'collection_sales.verified_accounts' => [$account->id], 'collection_sales.verified_platforms' => [$client->platform_id]]);
        $r = $this->received($device);
        $this->fakeWordPress();
        $input = ['receipt_version' => $r->version, 'session_version' => 0, 'request_key' => (string) Str::uuid(), 'items' => [['client_id' => $client->id, 'product_price_id' => $price->id]]];
        $input['preview_token'] = app(CollectionSalesService::class)->preview($r, $actor, $input)['preview_token'];
        app(CollectionSalesFulfillmentService::class)->prepare($r, $actor, $input);
        $account->update(['mode' => 'auto']);
        $item = CollectionSalesItem::firstOrFail();
        $service = app(CollectionSalesCorrectionService::class);
        $c = $service->preview($item, $actor, ['item_version' => $item->version, 'action' => 'undo_effect', 'reason' => 'Correct before any activation']);
        $this->assertTrue($c->preview['can_execute']);
        $result = $service->execute($c, $actor, (string) Str::uuid(), 1);
        $this->assertSame('confirmed', $result->state);
        app(CollectionSalesFulfillmentService::class)->resume($item->id);
        app(CollectionOutboxWorker::class)->run();
        $this->assertSame(0, $this->posts);
        $this->assertSame('unassigned', $item->fresh()->state);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('collection_match_exclusions', 1);
    }
}
