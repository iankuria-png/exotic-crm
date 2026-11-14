<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Payment;
use App\Services\Collections\CollectionActivationAttribution;
use App\Services\Collections\CollectionCorrectionService;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\Collections\CollectionReceiptStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class CollectionInlineCorrectionTest extends CollectionWorkflowTestCase
{
    private function active(): array
    {
        [$account, $device, $client, $intent, $user, $price] = $this->purchase();
        $this->compensationV2 = true;
        $this->fakeWordPress();
        config(['collection.correction_duration_policy' => 'full_purchased', 'collection.workspace_enabled' => true]);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::firstOrFail();
        app(CollectionFulfillmentService::class)->resume($a->id);
        $user->update(['role' => 'sales']);
        $user->platforms()->sync([$client->platform_id]);
        Sanctum::actingAs($user);

        return [$r->fresh(), $a->fresh(), $client, $price, $user];
    }

    private function target(Client $source): Client
    {
        $this->remote[99] = $this->snapshot(99, 88);

        return Client::factory()->create(['name' => 'Latifa fixture', 'platform_id' => $source->platform_id, 'wp_post_id' => 99,
            'wp_user_id' => 88, 'is_high_risk' => false, 'wp_profile_permalink' => 'https://fixture.test/escort/latifa-love/']);
    }

    private function preview($r, $a, $client, $price)
    {
        return $this->postJson('/api/crm/collections/receipts/'.$r->id.'/correction-preview', [
            'expected_version' => $a->version, 'action' => 'reassign', 'client_id' => $client->id, 'product_price_id' => $price->id,
        ])->assertOk()->assertJsonPath('preview.can_execute', true);
    }

    private function relatedPayment($r, Client $client, string $reference, array $overrides = []): Payment
    {
        $payment = Payment::findOrFail($r->allocation->payment_id)->replicate();
        $payment->fill(array_merge(['client_id' => $client->id, 'deal_id' => null,
            'transaction_reference' => $reference, 'reference_root' => $reference, 'reference_number' => $reference,
            'transaction_uuid' => (string) Str::uuid(), 'confirmed_by' => auth()->id()], $overrides));
        // Reproduce an older/manual record arriving outside the guarded correction workflow.
        $id = DB::table('payments')->insertGetId($payment->getAttributes());

        return Payment::findOrFail($id);
    }

    public function test_related_manual_code_is_visible_and_holds_reassignment_but_allows_undo(): void
    {
        [$r, $a, $source, $price, $actor] = $this->active();
        $target = $this->target($source);
        $extra = $this->relatedPayment($r, $target, $r->reference_norm.' /1');
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()
            ->assertJsonPath('related_payments.complete', true)
            ->assertJsonPath('related_payments.matches.0.client.name', $target->name)
            ->assertJsonPath('related_payments.matches.0.recorded_by', $actor->name);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/correction-preview', [
            'expected_version' => $a->version, 'action' => 'reassign', 'client_id' => $target->id, 'product_price_id' => $price->id,
        ])->assertOk()->assertJsonPath('preview.can_execute', false)->assertJsonPath('preview.reasons.0', 'related_payment_requires_review');
        $undo = app(CollectionCorrectionService::class)->preview($a, $actor, 'undo_effect', null, $a->version, 'Wrong match');
        $this->assertTrue($undo->preview['can_execute']);
        $result = app(CollectionCorrectionService::class)->execute($undo, $actor, $undo->version, (string) Str::uuid());
        $this->assertSame('verified', $result->state);
        $this->assertSame($target->id, $extra->fresh()->client_id);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_related_evidence_excludes_unrelated_prefixes_sandbox_test_and_other_markets(): void
    {
        [$r, $a, $source] = $this->active();
        $target = $this->target($source);
        $this->relatedPayment($r, $target, $r->reference_norm.'X/1');
        $this->relatedPayment($r, $target, $r->reference_norm.'/1', ['provider_environment' => 'sandbox']);
        $this->relatedPayment($r, $target, $r->reference_norm.'/2', ['record_classification' => 'test']);
        $market = \App\Models\Platform::factory()->create();
        $this->relatedPayment($r, $target, $r->reference_norm.'/3', ['platform_id' => $market->id]);
        $related = app(CollectionReceiptStatusService::class)->relatedPayments($r);
        $this->assertSame(['complete' => true, 'matches' => []], $related);
    }

    public function test_related_record_inserted_after_preview_prevents_execution(): void
    {
        [$r, $a, $source, $price, $actor] = $this->active();
        $target = $this->target($source);
        $preview = $this->preview($r, $a, $target, $price)->json();
        $this->relatedPayment($r, $target, $r->reference_norm);
        $this->postJson('/api/crm/collections/corrections/'.$preview['id'].'/execute', [
            'preview_version' => $preview['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('reason', 'related_payment_requires_review');
        $this->assertSame(1, $this->posts);
    }

    public function test_resumed_correction_checks_related_records_before_new_target_dispatch(): void
    {
        [$r, $a, $source, $price, $actor] = $this->active();
        $target = $this->target($source);
        $preview = $this->preview($r, $a, $target, $price)->json();
        $key = (string) Str::uuid();
        $this->loseResponse = true;
        $first = $this->postJson('/api/crm/collections/corrections/'.$preview['id'].'/execute', [
            'preview_version' => $preview['version'], 'idempotency_key' => $key,
        ])->assertStatus(202)->assertJsonPath('state', 'uncertain')->json();
        $this->relatedPayment($r, $target, $r->reference_norm.'/1');
        $this->postJson('/api/crm/collections/corrections/'.$first['id'].'/execute', [
            'preview_version' => $first['version'], 'idempotency_key' => $key,
        ])->assertStatus(202)->assertJsonPath('state', 'uncertain');
        $this->assertSame(2, $this->posts);
        $this->assertSame('0', $this->remote[99]['meta']['escort_expire']);
        $this->assertDatabaseMissing('collection_benefit_effects', ['effect_kind' => 'target_paid']);
    }

    public function test_truncated_prefix_candidates_hold_assignment_even_without_an_exact_related_match(): void
    {
        [$r, $a, $source, $price] = $this->active();
        $target = $this->target($source);
        for ($i = 0; $i < 26; $i++) {
            $this->relatedPayment($r, $target, $r->reference_norm.'OTHER'.$i);
        }
        $this->assertSame(['complete' => false, 'matches' => []], app(CollectionReceiptStatusService::class)->relatedPayments($r));
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/correction-preview', [
            'expected_version' => $a->version, 'action' => 'reassign', 'client_id' => $target->id, 'product_price_id' => $price->id,
        ])->assertOk()->assertJsonPath('preview.can_execute', false)->assertJsonPath('preview.reasons.0', 'related_payment_requires_review');
        $this->assertSame(1, $this->posts);
    }

    public function test_related_record_does_not_prevent_readback_of_an_already_committed_target(): void
    {
        [$r, $a, $source, $price] = $this->active();
        $target = $this->target($source);
        $preview = $this->preview($r, $a, $target, $price)->json();
        $key = (string) Str::uuid();
        $body = ['preview_version' => $preview['version'], 'idempotency_key' => $key];
        $path = '/api/crm/collections/corrections/'.$preview['id'].'/execute';
        $this->loseResponse = true;
        $this->postJson($path, $body)->assertStatus(202)->assertJsonPath('state', 'uncertain');
        $this->loseResponse = true;
        $this->postJson($path, $body)->assertStatus(202)->assertJsonPath('state', 'uncertain');
        $this->assertSame(3, $this->posts);
        $this->relatedPayment($r, $target, $r->reference_norm.'/1');
        $this->postJson($path, $body)->assertStatus(202)->assertJsonPath('state', 'verified');
        $this->assertSame(3, $this->posts);
        $this->assertSame($target->id, $a->fresh()->purchase_snapshot['client_id']);
    }

    public function test_sales_can_move_activation_with_profile_and_price_and_replay_the_same_cash_once(): void
    {
        [$r, $a, $source, $price, $actor] = $this->active();
        $target = $this->target($source);
        $payment = Payment::firstOrFail();
        $c = $this->preview($r, $a, $target, $price)->assertJsonPath('action', 'reassign')->json();
        $key = (string) Str::uuid();
        $path = '/api/crm/collections/corrections/'.$c['id'].'/execute';
        $body = ['preview_version' => $c['version'], 'idempotency_key' => $key];
        $this->postJson($path, $body)->assertStatus(202)->assertJsonPath('state', 'verified');
        $posts = $this->posts;
        $this->postJson($path, $body)->assertStatus(202)->assertJsonPath('state', 'verified');
        $this->assertSame($posts, $this->posts);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($target->id, $payment->fresh()->client_id);
        $this->assertEquals('35000.00', $payment->fresh()->amount);
        $attribution = app(CollectionActivationAttribution::class)->forReceipts(collect([$r->fresh()->load('allocation.effects')]), []);
        $this->assertSame('staff', $attribution[$r->id]['kind']);
        $this->assertSame($actor->id, $attribution[$r->id]['actor']['id']);
        $this->assertSame('Payment correction', $attribution[$r->id]['via']);
    }

    public function test_undone_payment_assigns_without_repeating_source_compensation_or_showing_old_activation(): void
    {
        [$r, $a, $source, $price, $actor] = $this->active();
        $service = app(CollectionCorrectionService::class);
        $undo = $service->preview($a, $actor, 'undo_effect', null, $a->version, 'Wrong profile');
        $service->execute($undo, $actor, $undo->version, (string) Str::uuid());
        $a->refresh();
        $sourceState = $this->remote[77];
        $attribution = app(CollectionActivationAttribution::class)->forReceipts(collect([$r->fresh()->load('allocation.effects')]), []);
        $this->assertNull($attribution[$r->id]);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/correction-preview', [
            'expected_version' => $a->version, 'action' => 'undo_effect',
        ])->assertStatus(409)->assertJsonPath('reason', 'activation_already_removed');
        $target = $this->target($source);
        $c = $this->preview($r, $a, $target, $price)->assertJsonPath('preview.source_steps', [])->json();
        $beforePosts = $this->posts;
        $this->postJson('/api/crm/collections/corrections/'.$c['id'].'/execute', [
            'preview_version' => $c['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202)->assertJsonPath('state', 'verified');
        $this->assertSame($beforePosts + 1, $this->posts);
        $this->assertSame($sourceState, $this->remote[77]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($target->id, Payment::firstOrFail()->client_id);
    }

    public function test_same_profile_package_is_inferred_and_stale_preview_creates_no_authority(): void
    {
        [$r, $a, $source, $price] = $this->active();
        $replacement = $price->replicate();
        $replacement->duration_days = 7;
        $replacement->duration_key = 'custom_7';
        $replacement->duration_label = '7 days';
        $replacement->save();
        $this->preview($r, $a, $source, $replacement)->assertJsonPath('action', 'change_package')
            ->assertJsonPath('preview.target_purchase.duration_days', 7);
        $before = CollectionPurchaseIntent::count();
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/correction-preview', [
            'expected_version' => $a->version + 1, 'action' => 'reassign', 'client_id' => $source->id, 'product_price_id' => $price->id,
        ])->assertStatus(409)->assertJsonPath('reason', 'correction_preview_stale_or_held');
        $this->assertSame($before, CollectionPurchaseIntent::count());
    }

    public function test_profile_link_search_selects_exact_profile_and_price_mismatch_cannot_write(): void
    {
        [$r, $a, $source, $price] = $this->active();
        $target = $this->target($source);
        $this->getJson('/api/crm/collections/receipts/'.$r->id.'/candidates?search='.urlencode('https://fixture.test/escort/latifa-love'))
            ->assertOk()->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $target->id);
        $price->update(['price' => 40000]);
        $before = $this->posts;
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/correction-preview', [
            'expected_version' => $a->version, 'action' => 'reassign', 'client_id' => $target->id, 'product_price_id' => $price->id,
        ])->assertStatus(422)->assertJsonPath('reason', 'purchase_authority_stale_or_incompatible');
        $this->assertSame($before, $this->posts);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_independent_pairing_stays_internal_and_existing_approved_price_is_retained(): void
    {
        [$r, $a, $source, $price, $actor] = $this->active();
        $price->update(['price' => 50000]);
        $snapshot = array_merge($a->purchase_snapshot, ['subsidiary_mode' => 'independent', 'subsidiary_platform_id' => 2,
            'catalogue_minor' => 5000000, 'original_amount' => '50000.00', 'discount_approved_by' => $actor->id,
            'discount_source' => 'manager_approved', 'discount_percentage' => 30]);
        $a->update(['purchase_snapshot' => $snapshot]);
        config(['collection.subsidiary_platforms.'.$source->platform_id => 2]);
        $target = $this->target($source);
        $this->preview($r, $a, $target, $price)->assertJsonPath('preview.target_purchase.subsidiary_mode', 'independent')
            ->assertJsonPath('preview.target_purchase.subsidiary_platform_id', 2)->assertJsonPath('preview.target_purchase.subsidiary', null)
            ->assertJsonPath('preview.target_purchase.amount_minor', 3500000)->assertJsonPath('preview.target_purchase.discount_approved_by', $actor->id);
    }

    public function test_non_customer_service_role_and_out_of_scope_profile_cannot_create_correction_authority(): void
    {
        [$r, $a, $source, $price, $actor] = $this->active();
        $before = CollectionPurchaseIntent::count();
        $target = $this->target($source);
        $other = \App\Models\Platform::factory()->create();
        $target->update(['platform_id' => $other->id]);
        $body = ['expected_version' => $a->version, 'action' => 'reassign', 'client_id' => $target->id, 'product_price_id' => $price->id];
        $path = '/api/crm/collections/receipts/'.$r->id.'/correction-preview';
        $this->postJson($path, $body)->assertForbidden();
        $actor->update(['role' => 'marketing']);
        Sanctum::actingAs($actor);
        $this->postJson($path, $body)->assertForbidden();
        $this->assertSame($before, CollectionPurchaseIntent::count());
        $this->assertDatabaseCount('collection_corrections', 0);
        $this->assertSame(1, $this->posts);
    }
}
