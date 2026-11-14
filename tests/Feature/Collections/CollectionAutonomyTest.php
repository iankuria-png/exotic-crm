<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionReceipt;
use App\Models\Collections\CollectionSubsidiaryObligation;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Services\Collections\CollectionAutomationPolicy;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\Collections\CollectionGate;
use App\Services\Collections\CollectionMatchService;
use App\Services\Collections\CollectionPackageHistoryService;
use App\Services\Collections\CollectionSubsidiaryFulfillmentService;
use Illuminate\Support\Facades\DB;

class CollectionAutonomyTest extends CollectionWorkflowTestCase
{
    private function autonomous(): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase(true, 6);
        $intent->update(['revoked_at' => now()]);
        CollectionPayerLink::query()->update(['revoked_at' => now()]);
        $price->forceFill(['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60)])->saveQuietly();
        $client->update(['phone_normalized' => '255700000999']); // The real payer differs from the advert contact.
        $approved = ['enabled' => true, 'approved_by' => $actor->id, 'approved_at' => now()->subDays(3)->toIso8601String(), 'platform_ids' => [6, 2], 'create_and_copy' => true];
        $account->update(['policy' => array_replace($account->policy, ['continuous_operation' => $approved, 'receipt_learning' => $approved,
            'independent_subsidiary' => $approved, 'clean_observation_days' => 0, 'positive_receiving_credits' => 0,
            'purchase_matching' => 'exact_catalogue_or_reviewed', 'purchase_matching_approved_by' => $actor->id,
            'purchase_matching_approved_at' => now()->subDays(3)->toIso8601String()])]);
        Platform::factory()->create(['id' => 2, 'currency_code' => 'TZS', 'phone_prefix' => '255']);

        return [$account, $device, $client, $actor, $price];
    }

    private function confirmed($account, $client, $actor, $price, string $reference = 'OLDPAY12345', int $days = 8): CollectionReceipt
    {
        $source = CollectionReceipt::create(['account_id' => $account->id, 'reference_raw' => $reference, 'reference_norm' => $reference,
            'principal_minor' => 3500000, 'currency' => 'TZS', 'payer_phone' => '255700000001',
            'payer_fingerprint' => app(\App\Services\Collections\CollectionPrivacy::class)->fingerprint('255700000001'),
            'provider_at' => now()->subDays(2), 'first_received_at' => now()->subDays(2), 'enrichment_deadline' => now()->subDays(2),
            'created_at' => now()->subDays(2), 'historical_only' => false, 'funds_state' => 'observed']);
        $deal = Deal::create(['platform_id' => 6, 'client_id' => $client->id, 'product_id' => $price->product_id, 'product_price_id' => $price->id,
            'plan_type' => 'vip', 'amount' => '35000.00', 'currency' => 'TZS', 'duration' => 'weekly', 'duration_days' => $days,
            'status' => 'active', 'activated_at' => now()->subDays(2), 'expires_at' => now()->addDays(6), 'assigned_to' => $actor->id]);
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create(['platform_id' => 6, 'client_id' => $client->id, 'deal_id' => $deal->id,
            'product_id' => $price->product_id, 'transaction_reference' => $reference, 'reference_number' => $reference, 'reference_root' => $reference,
            'amount' => '35000.00', 'currency' => 'TZS', 'status' => 'completed', 'record_classification' => 'live', 'provider_environment' => 'production',
            'phone' => '255700000999', 'purpose' => 'subscription', 'completed_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'payment_data' => []]));
        $deal->update(['payment_id' => $payment->id]);
        TimelineEvent::create(['platform_id' => 6, 'entity_type' => 'deal', 'entity_id' => $deal->id, 'event_type' => 'subscription_activation_recorded',
            'actor_id' => $actor->id, 'content' => ['payment_id' => $payment->id, 'execution' => 'staff'], 'created_at' => now()->subDay()]);

        return $source;
    }

    public function test_one_confirmed_observed_payer_teaches_client_and_exact_package_without_a_standing_agreement(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $source = $this->confirmed($account, $client, $actor, $price);
        $receipt = $this->received($device);
        $match = app(CollectionMatchService::class)->automatic($receipt);
        $this->assertSame($client->id, $match['selected_client_id']);
        $this->assertSame(95, $match['suggestions'][0]['score']);
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $a = CollectionAllocation::firstOrFail();
        $this->assertSame('independent', $a->purchase_snapshot['subsidiary_mode']);
        $this->assertNull($a->purchase_snapshot['subsidiary']);
        $this->assertSame([$source->id], $a->purchase_snapshot['history_evidence']['receipt_ids']);
        $this->assertDatabaseHas('collection_purchase_intents', ['id' => $a->intent_id, 'authority' => 'matched_receipt_history', 'max_renewals' => 1]);
        $this->assertSame(0, CollectionPayerLink::whereNull('revoked_at')->count());
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $this->assertDatabaseCount('collection_allocations', 1);
        $this->assertSame(1, DB::table('collection_purchase_intents')->where('authority', 'matched_receipt_history')->count());
    }

    public function test_shared_observed_payer_never_selects_one_client_from_the_amount(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $this->confirmed($account, $client, $actor, $price);
        $other = Client::factory()->create(['platform_id' => 6, 'wp_post_id' => 88, 'wp_user_id' => 66, 'is_high_risk' => false]);
        $this->confirmed($account, $other, $actor, $price, 'OTHERP12345');
        $receipt = $this->received($device);
        $this->assertNull(app(CollectionMatchService::class)->automatic($receipt)['selected_client_id']);
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public function test_later_confirmation_and_unreviewed_automatic_effect_cannot_train_an_earlier_receipt(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $source = $this->confirmed($account, $client, $actor, $price);
        TimelineEvent::query()->update(['created_at' => now()->addMinute()]);
        $receipt = $this->received($device);
        $this->assertEmpty(app(CollectionPackageHistoryService::class)->payer($receipt)['clients']);
        TimelineEvent::query()->update(['created_at' => now()->subDay(), 'actor_id' => null, 'content' => json_encode(['execution' => 'automatic'])]);
        $this->assertEmpty(app(CollectionPackageHistoryService::class)->payer($receipt)['clients']);
        $source->update(['reviewed_at' => null]);
    }

    public function test_contradictory_client_package_history_holds_instead_of_falling_back_to_a_default(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $this->confirmed($account, $client, $actor, $price);
        $this->confirmed($account, $client, $actor, $price, 'OTHERP12345', 7);
        $receipt = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $this->assertContains('client_purchase_history_conflicting', $receipt->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public function test_continuous_operation_survives_pilot_expiry_but_pause_and_other_signoffs_still_hold(): void
    {
        [$account, $device] = $this->autonomous();
        $p = $account->policy;
        $p['observation_exception'] = ['starts_at' => now()->subDays(2)->toIso8601String(), 'expires_at' => now()->subDay()->toIso8601String()];
        $account->update(['policy' => $p]);
        app(CollectionGate::class)->assertWrites($account->fresh(), [6], true);
        $presented = app(CollectionAutomationPolicy::class)->present($account->fresh());
        $this->assertSame('live', app(CollectionAutomationPolicy::class)->status($account->fresh(), $presented)['live_state']);
        unset($p['settlement_policy_accepted_at']);
        $account->update(['policy' => $p]);
        $this->expectException(\App\Services\Collections\CollectionException::class);
        app(CollectionGate::class)->assertWrites($account->fresh(), [6], true);
    }

    public function test_paid_primary_stays_active_when_subsidiary_policy_or_identity_needs_review(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $this->confirmed($account, $client, $actor, $price);
        $receipt = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $allocation = CollectionAllocation::firstOrFail();
        $this->fakeWordPress();
        app(CollectionFulfillmentService::class)->resume($allocation->id);
        $this->assertSame(1, $this->posts);
        $this->assertSame('active_review_pending', $allocation->fresh()->state);
        $this->assertSame(1, Payment::where('amount', '>', 0)->where('id', $allocation->fresh()->payment_id)->count());
        $owner = CollectionSubsidiaryObligation::firstOrFail();
        $this->assertSame($allocation->id, $owner->allocation_id);
        $p = $account->fresh()->policy;
        $p['independent_subsidiary']['enabled'] = false;
        $account->update(['policy' => $p]);
        app(CollectionSubsidiaryFulfillmentService::class)->resume($owner->id);
        $this->assertSame('needs_approval', $owner->fresh()->state);
        $this->assertSame('active_review_pending', $allocation->fresh()->state);
        $this->assertSame('active_review_pending', $receipt->fresh()->decision_state);
        $this->assertSame(1, $this->posts);
        $this->assertDatabaseCount('collection_subsidiary_obligations', 1);
        $this->assertSame(0, DB::table('collection_profile_holds')->where('client_id', $client->id)->count());
    }

    public function test_expected_subsidiary_hold_can_be_retried_without_a_generic_lifecycle_lock_failure(): void
    {
        [$account, $device, $client, $intent] = $this->purchase();
        config(['collection.subsidiary_platforms.'.$client->platform_id => 2, 'client_lifecycle.hold_during_test_transactions' => true]);
        $account->update(['policy' => array_diff_key($account->policy, ['subsidiary_grant_approved_by' => true, 'subsidiary_grant_valid_until' => true])]);
        $r = $this->received($device);
        foreach ([1, 2] as $attempt) {
            app(CollectionDecisionService::class)->evaluate($r->id);
            $this->assertContains('linked_subsidiary_profile_required', $r->fresh()->candidate_snapshot['reasons']);
        }
        $this->assertDatabaseCount('collection_allocations', 0);
    }
}
