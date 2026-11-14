<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Payment;
use App\Services\Collections\CollectionReceiptStatusService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionReceiptStatusTest extends CollectionWorkflowTestCase
{
    private function fixture(): array
    {
        $f = $this->purchase(false);
        $f[0]->update(['mode' => 'shadow']);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($f[4]);

        return [...$f, $this->received($f[1])];
    }

    private function cash($client, array $values = []): Payment
    {
        return Payment::withoutEvents(fn () => Payment::factory()->create($values + ['client_id' => $client->id,
            'platform_id' => $client->platform_id, 'currency' => 'TZS', 'amount' => '35000.00', 'status' => 'completed',
            'record_classification' => 'live', 'provider_environment' => 'live', 'payment_data' => [], 'reference_root' => 'ABCDEF12345']));
    }

    public function test_existing_code_shows_deal_and_verified_actor_without_claiming_destination_or_mutating(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $p = $this->cash($c);
        $d = Deal::withoutEvents(fn () => Deal::factory()->create(['client_id' => $c->id, 'platform_id' => $c->platform_id,
            'payment_id' => $p->id, 'status' => 'active', 'plan_type' => 'vip', 'activated_at' => now(), 'expires_at' => now()->addDays(7)]));
        DB::table('payments')->where('id', $p->id)->update(['deal_id' => $d->id]);
        app(\App\Services\SubscriptionActivationAuditService::class)->record($d, $p->fresh(), $actor->id, 'manual', 'fixture');
        $before = DB::table('payments')->count();
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('usage.state', 'already_recorded')
            ->assertJsonPath('usage.matches.0.deal.id', $d->id)->assertJsonPath('usage.matches.0.destination', 'not_verified')
            ->assertJsonPath('usage.matches.0.responsibility.actor.id', $actor->id);
        $this->getJson('/api/crm/collections/receipts?state=already_recorded')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.activation.kind', 'staff')->assertJsonPath('data.0.activation.actor.id', $actor->id)->assertJsonPath('data.0.activation.actor.name', $actor->name);
        $this->getJson('/api/crm/collections/receipts?state=needs_action')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame($before, DB::table('payments')->count());
        $this->assertDatabaseCount('collection_sales_sessions', 0);
    }

    public function test_pending_failed_and_test_reference_collisions_remain_review_not_unused(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $p = $this->cash($c, ['status' => 'pending']);
        foreach ([['status' => 'pending'], ['status' => 'failed'], ['status' => 'completed', 'record_classification' => 'test']] as $state) {
            DB::table('payments')->where('id', $p->id)->update($state);
            $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('usage.state', 'conflict');
            $this->getJson('/api/crm/collections/receipts?state=needs_action')->assertOk()->assertJsonPath('total', 0);
            $this->getJson('/api/crm/collections/receipts?state=already_recorded')->assertOk()->assertJsonPath('total', 0);
        }
    }

    public function test_unrelated_code_same_payer_remains_actionable_and_alias_blocks_duplicate(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $this->cash($c, ['reference_root' => 'OLDER12345', 'transaction_reference' => 'OLDER12345', 'reference_number' => 'OLDER12345']);
        $this->getJson('/api/crm/collections/receipts?state=needs_action')->assertOk()->assertJsonPath('total', 1);
        DB::table('collection_receipt_aliases')->insert(['account_id' => $a->id, 'receipt_id' => $r->id, 'reference' => 'OLDER12345', 'reviewed_by' => $actor->id, 'evidence_reference' => 'Fixture alias', 'created_at' => now()]);
        $this->getJson('/api/crm/collections/receipts?state=needs_action')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_manual_context_does_not_require_secondary_profile_or_change_shadow_policy(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        config(['collection.subsidiary_platforms.'.$c->platform_id => 999999]);
        $this->getJson('/api/crm/collections/receipts/'.$r->id.'/review-context?workflow=manual')->assertOk()
            ->assertJsonPath('capture_only', false)->assertJsonPath('workflow', 'manual')->assertJsonPath('subsidiary_platform_id', null);
        $this->getJson('/api/crm/collections/receipts/'.$r->id.'/candidates?workflow=manual&selected_client_id='.$c->id)->assertOk()->assertJsonPath('subsidiary_platform_id', null);
        $this->assertSame('shadow', $a->fresh()->mode);
        $this->assertDatabaseCount('collection_sales_sessions', 0);
    }

    public function test_eat_date_boundaries_and_export_share_deterministic_sort_and_phone_search(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $r->update(['provider_at' => '2026-10-29 21:00:00']); // 30 October00:00EAT
        $second = $r->replicate();
        $second->reference_norm = 'LATER12345';
        $second->provider_at = '2026-10-30 20:59:59';
        $second->save();
        $third = $r->replicate();
        $third->reference_norm = 'NEXTDAY12345';
        $third->provider_at = '2026-10-30 21:00:00';
        $third->save();
        $url = '/api/crm/collections/receipts?date_from=2026-10-30&date_to=2026-10-30&sort=oldest';
        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $r->id)->assertJsonPath('data.1.id', $second->id);
        $csv = $this->get($url.'&export=1')->assertOk()->streamedContent();
        $this->assertLessThan(strpos($csv, 'LATER12345'), strpos($csv, 'ABCDEF12345'));
        $this->assertStringNotContainsString('NEXTDAY12345', $csv);
        $this->getJson('/api/crm/collections/receipts?date_to=2026-10-30')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/crm/collections/receipts?search=0700000001')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/crm/collections/receipts?sort=invalid')->assertStatus(422);
        $this->getJson('/api/crm/collections/receipts?date_from=2026-10-31&date_to=2026-10-30')->assertStatus(422);
    }

    public function test_bundle_only_and_foreign_destination_collisions_are_review_states(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $bundle = \App\Models\ManualPaymentBundle::create(['platform_id' => $c->platform_id, 'reference_root' => $r->reference_norm,
            'total_amount' => 35000, 'allocated_amount' => 35000, 'unallocated_amount' => 0, 'currency' => 'TZS',
            'reason' => 'Fixture', 'status' => 'committed', 'audit_state' => 'pending_finance_review', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => $actor->id]);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('usage.state', 'conflict');
        $this->getJson('/api/crm/collections/receipts?state=needs_action')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/crm/collections/receipts?state=already_recorded')->assertOk()->assertJsonPath('total', 0);
        $p = $this->cash($c, ['manual_payment_bundle_id' => $bundle->id, 'payment_data' => ['collection' => ['account_id' => 'other-account']]]);
        $d = Deal::withoutEvents(fn () => Deal::factory()->create(['client_id' => $c->id, 'platform_id' => $c->platform_id, 'payment_id' => $p->id]));
        DB::table('payments')->where('id', $p->id)->update(['deal_id' => $d->id]);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('usage.state', 'conflict');
        $this->getJson('/api/crm/collections/receipts?state=already_recorded')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_owned_sale_is_progress_not_a_legacy_reference_collision(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $this->cash($c);
        \App\Models\Collections\CollectionSalesSession::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'receipt_id' => $r->id,
            'account_id' => $a->id, 'platform_id' => $c->platform_id, 'actor_id' => $actor->id, 'receipt_version' => $r->version,
            'state' => 'prepared', 'currency' => 'TZS', 'principal_minor' => $r->principal_minor, 'allocated_minor' => $r->principal_minor]);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('usage.state', 'owned')->assertJsonPath('sale.state', 'prepared');
        $this->getJson('/api/crm/collections/receipts?state=already_recorded')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_page_status_queries_are_batched_for_twenty_five_receipts(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        for ($i = 1; $i < 25; $i++) {
            $next = $r->replicate();
            $next->reference_norm = 'PAGE'.str_pad($i, 8, '0', STR_PAD_LEFT);
            $next->save();
        }
        $receipts = CollectionReceipt::with('sale', 'allocation')->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = app(CollectionReceiptStatusService::class)->forReceipts($receipts);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(25, $data);
        $this->assertLessThanOrEqual(8, count($queries));
        $this->assertSame(['unrecorded'], array_values(array_unique(array_column($data, 'state'))));
    }
}
