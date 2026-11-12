<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentRecoveryCase;
use App\Models\User;
use App\Services\Collections\CollectionCorrectionService;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\PaymentReconciliationAuditService;
use App\Services\SubscriptionActivationAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class CollectionSubscriptionLinkTest extends CollectionWorkflowTestCase
{
    private function scenario(bool $undo = true): array
    {
        [$account, $device, $source, $intent, $actor, $price] = $this->purchase();
        $this->compensationV2 = true;
        $this->fakeWordPress();
        config(['collection.workspace_enabled' => true]);
        $receipt = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $allocation = CollectionAllocation::firstOrFail();
        app(CollectionFulfillmentService::class)->resume($allocation->id);
        if ($undo) {
            $c = app(CollectionCorrectionService::class)->preview($allocation->fresh(), $actor, 'undo_effect', null, $allocation->fresh()->version, 'Wrong automatic profile');
            $this->assertTrue($c->preview['can_execute']);
            $this->assertSame('verified', app(CollectionCorrectionService::class)->execute($c, $actor, $c->version, (string) Str::uuid())->state);
        }
        $client = Client::factory()->create(['name' => 'Sabrina fixture', 'platform_id' => $source->platform_id,
            'wp_post_id' => 99, 'wp_user_id' => 88, 'is_high_risk' => false,
            'profile_paused_at' => null, 'profile_reverted_at' => null, 'profile_removal_requested_at' => null, 'closed_at' => null]);
        $activator = User::factory()->create(['name' => 'Joanne fixture', 'role' => 'sales']);
        $deal = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => $client->platform_id,
            'product_id' => $price->product_id, 'product_price_id' => $price->id, 'status' => 'active',
            'amount' => '35000.00', 'currency' => 'TZS', 'plan_type' => 'vip', 'duration_days' => 8,
            'is_free_trial' => false, 'origin' => 'manual', 'activated_at' => now()->subMinutes(20), 'expires_at' => now()->addDays(8)]);
        $payment = Payment::create(['client_id' => $client->id, 'platform_id' => $client->platform_id, 'deal_id' => $deal->id,
            'product_id' => $price->product_id, 'status' => 'completed', 'purpose' => 'subscription',
            'amount' => '35000.00', 'currency' => 'TZS', 'transaction_uuid' => 'manual_'.Str::uuid(),
            'transaction_reference' => $receipt->reference_norm.' /1', 'reference_number' => $receipt->reference_norm.' /1', 'reference_root' => $receipt->reference_norm.'/1',
            'source' => 'gateway', 'provider_key' => null, 'provider_environment' => null, 'record_classification' => 'live',
            'confirmed_by' => $activator->id, 'confirmed_at' => now()->subMinutes(20), 'completed_at' => now()->subMinutes(20), 'reconciliation_state' => 'open']);
        $deal->update(['payment_id' => $payment->id]);
        $this->remote[99] = $this->snapshot(99, 88, $deal->expires_at->timestamp, 'vip', $deal->id);
        app(SubscriptionActivationAuditService::class)->record($deal, $payment, $activator->id, 'manual', 'manual', null, $deal->activated_at->toIso8601String());
        $actor->update(['name' => 'CS linker', 'role' => 'sales']);
        $actor->platforms()->sync([$client->platform_id]);
        Sanctum::actingAs($actor);

        return [$receipt->fresh(), $allocation->fresh(), $payment->fresh(), $deal->fresh(), $client, $actor, $activator];
    }

    private function preview(array $scenario)
    {
        [$r, $a, $payment] = $scenario;

        return $this->postJson('/api/crm/collections/receipts/'.$r->id.'/subscription-link-preview', [
            'payment_id' => $payment->id, 'expected_version' => $r->version, 'expected_allocation_version' => $a->version,
        ])->assertOk();
    }

    private function link(array $scenario): array
    {
        $preview = $this->preview($scenario)->assertJsonPath('preview.can_execute', true)->json();
        $key = (string) Str::uuid();
        $result = $this->postJson('/api/crm/collections/subscription-links/'.$preview['id'].'/execute', [
            'preview_version' => $preview['version'], 'idempotency_key' => $key,
        ])->assertOk()->assertJsonPath('state', 'verified')->json();

        return [$result, $key];
    }

    public function test_sales_can_link_the_existing_manual_subscription_without_more_service_or_cash(): void
    {
        $scenario = $this->scenario();
        [$r, $a, $payment, $deal, $client, $actor, $activator] = $scenario;
        $originalId = $a->payment_id;
        $posts = $this->posts;
        $native = $this->remote[99];
        $before = $deal->getAttributes();
        $httpCount = Http::recorded()->count();
        [$result, $key] = $this->link($scenario);
        $this->assertSame($native, $this->remote[99]);
        $this->assertSame($before, $deal->fresh()->getAttributes());
        $this->assertSame($posts, $this->posts);
        foreach (Http::recorded()->slice($httpCount) as [$request]) {
            $this->assertSame('GET', $request->method());
        }
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, Payment::whereIn('status', Payment::SUCCESSFUL_STATUSES)->where('amount', '>', 0)->count());
        $this->assertSame(1, Payment::reportableSuccessful()->where('amount', '>', 0)->count());
        $this->assertSame('cancelled', Payment::findOrFail($originalId)->status);
        $this->assertSame(Payment::RESOLUTION_RECEIPT_DUPLICATE, Payment::findOrFail($originalId)->resolution_code);
        $this->assertSame('35000.00', Payment::findOrFail($originalId)->amount);
        $this->assertSame($r->reference_norm, $payment->fresh()->transaction_reference);
        $this->assertSame($activator->id, $payment->fresh()->confirmed_by);
        $this->assertSame(88, (int) $payment->fresh()->user_id);
        $this->assertSame(99, (int) $payment->fresh()->escort_post_id);
        $this->assertSame($payment->id, $a->fresh()->payment_id);
        $this->assertSame('reviewed', $a->fresh()->state);
        $this->assertSame('phone', $r->fresh()->evidence_level);
        $this->assertSame(0, (int) DB::table('collection_exposure_buckets')->where('bucket', 'outstanding')->value('unreviewed_minor'));
        $this->assertSame(3500000, (int) DB::table('collection_exposure_buckets')->where('bucket', 'outstanding')->value('unverified_minor'));
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()
            ->assertJsonPath('receipt_link.linked_by.name', 'CS linker')->assertJsonPath('activation.actor.name', 'Joanne fixture')
            ->assertJsonPath('activation.at', $deal->activated_at->toIso8601String())->assertJsonPath('subsidiary_obligation', null)
            ->assertJsonPath('clients.0.name', 'Sabrina fixture')->assertJsonPath('related_payments.matches', []);
        $this->postJson('/api/crm/collections/subscription-links/'.$result['id'].'/execute', [
            'preview_version' => $result['version'], 'idempotency_key' => $key,
        ])->assertOk()->assertJsonPath('preview.receipt_link.id', $result['id']);
        app(CollectionFulfillmentService::class)->resume($a->id);
        $this->assertSame($posts, $this->posts);
        $this->assertSame(1, DB::table('collection_audit')->where('action', 'existing_subscription_linked')->count());
    }

    public function test_original_code_matches_only_the_canonical_payment_in_statement_reconciliation(): void
    {
        $scenario = $this->scenario();
        [$r, $a, $payment, $deal, $client, $actor] = $scenario;
        $this->link($scenario);
        $result = app(PaymentReconciliationAuditService::class)->preview($r->reference_norm, [$client->platform], $actor->id);
        $this->assertCount(1, $result['rows']);
        $this->assertSame($payment->id, $result['rows'][0]['matched_payment_id']);
        $this->assertNotSame('duplicate_in_crm', $result['rows'][0]['classification']);
        $this->assertSame([$payment->id], Payment::withoutReceiptDuplicates()->pluck('id')->all());
        $actor->update(['role' => 'admin']);
        $daily = app(\App\Services\Reconciliation\DailyPaymentReviewService::class);
        $canonical = $daily->unit($actor, 'payment', $payment->id);
        $this->assertNotContains('Receipt reference also appears on another record', $canonical['blockers']);
        $historical = $daily->unit($actor, 'payment', $a->payment_id);
        $this->assertFalse($historical['is_current_cash']);
    }

    public function test_a_link_preview_cannot_execute_as_an_activation_correction_or_with_another_key(): void
    {
        $scenario = $this->scenario();
        $preview = $this->preview($scenario)->assertJsonPath('preview.can_execute', true)->json();
        $this->postJson('/api/crm/collections/corrections/'.$preview['id'].'/execute', [
            'preview_version' => $preview['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('reason', 'use_saved_subscription_link');
        [$link, $key] = $this->link($scenario);
        $this->postJson('/api/crm/collections/subscription-links/'.$link['id'].'/execute', [
            'preview_version' => $link['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('reason', 'subscription_link_request_conflict');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_changed_payment_or_native_subscription_rolls_back_the_entire_link(): void
    {
        $scenario = $this->scenario();
        [$r, $a, $payment] = $scenario;
        $preview = $this->preview($scenario)->assertJsonPath('preview.can_execute', true)->json();
        $payment->update(['confirmed_at' => now()]);
        $this->postJson('/api/crm/collections/subscription-links/'.$preview['id'].'/execute', [
            'preview_version' => $preview['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('reason', 'subscription_link_preview_stale');
        $this->assertSame('completed', $a->payment->fresh()->status);
        $this->assertSame('unassigned', $a->fresh()->state);
        $preview = $this->preview([$r->fresh(), $a->fresh(), $payment->fresh()])->assertJsonPath('preview.can_execute', true)->json();
        $this->remote[99] = $this->snapshot(99, 88, now()->addDays(20)->timestamp, 'vip', $payment->deal_id);
        $this->postJson('/api/crm/collections/subscription-links/'.$preview['id'].'/execute', [
            'preview_version' => $preview['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('reason', 'subscription_link_site_requires_review');
        $this->assertSame('completed', $a->payment->fresh()->status);
        $this->assertDatabaseMissing('collection_audit', ['action' => 'existing_subscription_linked']);
    }

    public function test_unfinished_removal_and_open_cash_recovery_require_review(): void
    {
        $scenario = $this->scenario(false);
        $this->preview($scenario)->assertJsonPath('preview.can_execute', false)->assertJsonPath('preview.reasons.0', 'finish_saved_removal_before_linking');
    }

    public function test_an_open_recovery_case_on_either_payment_holds_linking(): void
    {
        $scenario = $this->scenario();
        [$r, $a, $payment] = $scenario;
        foreach ([$a->payment_id, $payment->id] as $id) {
            $case = PaymentRecoveryCase::firstOrCreate(['payment_id' => $id], ['public_reference' => (string) Str::uuid(),
                'platform_id' => $payment->platform_id, 'state' => 'review', 'reason_code' => 'settlement_unverified', 'queue_key' => 'manual', 'opened_at' => now()]);
            $case->update(['state' => 'review']);
            $this->preview($scenario)->assertJsonPath('preview.can_execute', false)->assertJsonPath('preview.reasons.0', 'subscription_link_cash_requires_review');
            $case->update(['state' => 'closed']);
        }
        $this->preview($scenario)->assertJsonPath('preview.can_execute', true);
        $this->assertSame('unassigned', $a->fresh()->state);
    }

    public function test_linked_records_cannot_be_reopened_by_clearing_metadata_and_changing_purpose(): void
    {
        $scenario = $this->scenario();
        [$r, $a, $payment] = $scenario;
        $originalId = $a->payment_id;
        $this->link($scenario);
        foreach ([$originalId, $payment->id] as $id) {
            try {
                Payment::findOrFail($id)->fill(['payment_data' => [], 'resolution_code' => null, 'status' => 'completed', 'purpose' => 'visitor_contact_unlock'])->save();
                $this->fail('A generic writer bypassed the persisted receipt owner.');
            } catch (CollectionException $e) {
                $this->assertSame('linked_receipt_payment_is_managed', $e->reason);
            }
        }
        $this->assertSame('cancelled', Payment::findOrFail($originalId)->status);
        $this->assertSame('subscription', $payment->fresh()->purpose);
    }

    public function test_out_of_scope_staff_cannot_preview_or_execute_a_link(): void
    {
        $scenario = $this->scenario();
        $preview = $this->preview($scenario)->assertJsonPath('preview.can_execute', true)->json();
        $outsider = User::factory()->create(['role' => 'sales']);
        Sanctum::actingAs($outsider);
        $this->postJson('/api/crm/collections/receipts/'.$scenario[0]->id.'/subscription-link-preview', [
            'payment_id' => $scenario[2]->id, 'expected_version' => $scenario[0]->version, 'expected_allocation_version' => $scenario[1]->version,
        ])->assertNotFound();
        $this->postJson('/api/crm/collections/subscription-links/'.$preview['id'].'/execute', [
            'preview_version' => $preview['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertSame('unassigned', $scenario[1]->fresh()->state);
    }

    public function test_structured_split_authenticated_provider_and_wrong_amount_records_are_held(): void
    {
        $scenario = $this->scenario();
        [$r, $a, $payment] = $scenario;
        foreach ([['reference_sequence' => 1], ['provider_key' => 'pawapay'], ['amount' => '34000.00']] as $change) {
            $original = $payment->only(array_keys($change));
            $payment->update($change);
            $this->preview($scenario)->assertJsonPath('preview.can_execute', false)->assertJsonPath('preview.reasons.0', 'subscription_link_cash_requires_review');
            $payment->update($original);
        }
        $this->assertSame('unassigned', $a->fresh()->state);
        $this->assertSame(2, $this->posts);
    }

    public function test_authenticated_receipt_evidence_cannot_be_relabelled_as_a_manual_link(): void
    {
        $scenario = $this->scenario();
        $payment = $scenario[2];
        DB::table('payment_receipt_observations')->insert(['receipt_key' => str_repeat('f', 64), 'payment_id' => $payment->id,
            'platform_id' => $payment->platform_id, 'evidence_json' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->preview($scenario)->assertJsonPath('preview.can_execute', false)->assertJsonPath('preview.reasons.0', 'subscription_link_cash_requires_review');
        $this->assertDatabaseMissing('collection_audit', ['action' => 'existing_subscription_linked']);
    }

    public function test_another_fulfillment_owner_or_second_preview_cannot_claim_the_payment(): void
    {
        $scenario = $this->scenario();
        $first = $this->preview($scenario)->assertJsonPath('preview.can_execute', true)->json();
        $second = $this->preview($scenario)->assertJsonPath('preview.can_execute', true)->json();
        $this->postJson('/api/crm/collections/subscription-links/'.$first['id'].'/execute', [
            'preview_version' => $first['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('state', 'verified');
        $this->postJson('/api/crm/collections/subscription-links/'.$second['id'].'/execute', [
            'preview_version' => $second['version'], 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('reason', 'subscription_link_preview_stale');
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, DB::table('collection_audit')->where('action', 'existing_subscription_linked')->count());
    }

    public function test_a_future_purchase_quote_does_not_block_linking_but_another_profile_owner_does(): void
    {
        $scenario = $this->scenario();
        [$r, $a, $payment, $deal, $client] = $scenario;
        $intent = \App\Models\Collections\CollectionPurchaseIntent::findOrFail($a->intent_id)->replicate();
        $intent->id = (string) Str::uuid();
        $intent->client_id = $client->id;
        $intent->revoked_at = null;
        $intent->valid_until = now()->addDay();
        $intent->purchase_snapshot = array_merge($intent->purchase_snapshot, ['client_id' => $client->id, 'wp_post_id' => 99, 'wp_user_id' => 88]);
        $intent->save();
        $this->preview($scenario)->assertJsonPath('preview.can_execute', true);
        DB::table('collection_profile_holds')->insert(['client_id' => $client->id, 'allocation_id' => (string) Str::uuid(),
            'operation_id' => (string) Str::uuid(), 'created_at' => now()]);
        $this->preview($scenario)->assertJsonPath('preview.can_execute', false)->assertJsonPath('preview.reasons.0', 'collection_operation_owns_profile');
        $this->assertSame('unassigned', $a->fresh()->state);
    }
}
