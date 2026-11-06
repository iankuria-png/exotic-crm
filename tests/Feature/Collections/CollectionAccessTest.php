<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAllocation;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionFulfillmentService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionAccessTest extends CollectionWorkflowTestCase
{
    public function test_staff_scope_default_disabled_and_shared_account_do_not_disclose_receipts(): void
    {
        [$account, $device, $client, $intent, $admin] = $this->purchase();
        $r = $this->received($device);
        Sanctum::actingAs($admin);
        $this->getJson('/api/crm/collections/capabilities')->assertOk()->assertJsonPath('enabled', false);
        $this->getJson('/api/crm/collections/receipts')->assertNotFound();
        config(['collection.workspace_enabled' => true]);
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('total', 1);
        $sales = User::factory()->create(['role' => 'sales', 'assigned_market_ids' => [$client->platform_id], 'status' => 'active']);
        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('payer_masked', '••••0001')->assertJsonMissingPath('payer_phone');
        $other = Platform::factory()->create();
        $snapshot = $intent->purchase_snapshot;
        $snapshot['subsidiary'] = ['platform_id' => $other->id, 'client_id' => 12345];
        $intent->update(['purchase_snapshot' => $snapshot]);
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertNotFound();
        $intent->update(['purchase_snapshot' => array_merge($snapshot, ['subsidiary' => null])]);
        DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $other->id, 'destination_snapshot' => '{}', 'valid_from' => now()]);
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertNotFound();
        $this->getJson('/api/crm/collections/arrivals')->assertOk()->assertJsonPath('unread_receipts', 0);
        $marketing = User::factory()->create(['role' => 'marketing']);
        Sanctum::actingAs($marketing);
        $this->getJson('/api/crm/collections/receipts')->assertForbidden();
    }

    public function test_stale_review_keeps_saved_choice_and_cross_market_target_is_rejected(): void
    {
        [$account, $device, $client, $intent, $admin] = $this->purchase();
        config(['collection.workspace_enabled' => true]);
        $this->fakeWordPress();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        app(CollectionFulfillmentService::class)->resume(CollectionAllocation::first()->id);
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/confirm', ['expected_version' => 1])->assertConflict()->assertJsonPath('reason', 'review_state_changed_or_unconfirmed');
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/confirm', ['expected_version' => $r->fresh()->version])->assertOk()->assertJsonPath('decision_state', 'reviewed');
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('allocation.decision_source', 'automatic');
        $this->assertSame(1, $this->posts);
    }

    public function test_arrival_read_cursor_is_monotonic_and_export_has_no_raw_sms_or_payer_phone(): void
    {
        [$account, $device, $client, $intent, $admin] = $this->purchase();
        config(['collection.workspace_enabled' => true]);
        $r = $this->received($device);
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/crm/collections/arrivals')->assertOk()->assertJsonPath('unread_receipts', 1);
        $cursor = $response->json('cursor');
        $this->postJson('/api/crm/collections/arrivals/read', ['cursor' => $cursor])->assertOk();
        $this->postJson('/api/crm/collections/arrivals/read', ['cursor' => 0])->assertOk();
        $this->getJson('/api/crm/collections/arrivals')->assertOk()->assertJsonPath('unread_receipts', 0);
        $this->postJson('/api/crm/collections/arrivals/read', ['cursor' => $cursor + 100])->assertStatus(422);
        $csv = $this->get('/api/crm/collections/receipts?export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('ABCDEF12345', $csv);
        $this->assertStringNotContainsString('255700000001', $csv);
        $this->assertStringNotContainsString('FIXTURE PAYER', $csv);
    }
}
