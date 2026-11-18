<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionMatchAccuracyService;
use App\Services\Collections\CollectionMatchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionMatchQualityTest extends CollectionWorkflowTestCase
{
    private function autonomous(): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase(true, 6);
        $intent->update(['revoked_at' => now()]);
        CollectionPayerLink::query()->update(['revoked_at' => now()]);
        $price->forceFill(['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60)])->saveQuietly();
        $client->update(['phone_normalized' => '255700000999']);
        $approved = ['enabled' => true, 'approved_by' => $actor->id, 'approved_at' => now()->subDays(3)->toIso8601String(), 'platform_ids' => [6, 2], 'create_and_copy' => true];
        $account->update(['policy' => array_replace($account->policy, ['continuous_operation' => $approved, 'receipt_learning' => $approved,
            'independent_subsidiary' => $approved, 'clean_observation_days' => 0, 'positive_receiving_credits' => 0,
            'purchase_matching' => 'exact_catalogue_or_reviewed', 'purchase_matching_approved_by' => $actor->id,
            'purchase_matching_approved_at' => now()->subDays(3)->toIso8601String()])]);
        Platform::factory()->create(['id' => 2, 'currency_code' => 'TZS', 'phone_prefix' => '255']);

        return [$account, $device, $client, $actor, $price];
    }

    /** A staff-confirmed earlier receipt from the same payer for $client, whose subscription ends at $expires. */
    private function confirmed($account, Client $client, User $actor, $price, string $reference, $expires): CollectionReceipt
    {
        $source = CollectionReceipt::create(['account_id' => $account->id, 'reference_raw' => $reference, 'reference_norm' => $reference,
            'principal_minor' => 3500000, 'currency' => 'TZS', 'payer_phone' => '255700000001',
            'payer_fingerprint' => app(\App\Services\Collections\CollectionPrivacy::class)->fingerprint('255700000001'),
            'provider_at' => now()->subDays(8), 'first_received_at' => now()->subDays(8), 'enrichment_deadline' => now()->subDays(8),
            'created_at' => now()->subDays(8), 'historical_only' => false, 'funds_state' => 'observed']);
        $deal = Deal::create(['platform_id' => 6, 'client_id' => $client->id, 'product_id' => $price->product_id, 'product_price_id' => $price->id,
            'plan_type' => 'vip', 'amount' => '35000.00', 'currency' => 'TZS', 'duration' => 'weekly', 'duration_days' => 8,
            'status' => 'active', 'activated_at' => now()->subDays(8), 'expires_at' => $expires, 'assigned_to' => $actor->id]);
        $payment = $this->payment($client, $reference, ['deal_id' => $deal->id, 'completed_at' => now()->subDays(8), 'created_at' => now()->subDays(8), 'phone' => '255700000999']);
        $deal->update(['payment_id' => $payment->id]);
        TimelineEvent::create(['platform_id' => 6, 'entity_type' => 'deal', 'entity_id' => $deal->id, 'event_type' => 'subscription_activation_recorded',
            'actor_id' => $actor->id, 'content' => ['payment_id' => $payment->id, 'execution' => 'staff'], 'created_at' => now()->subDays(7)]);

        return $source;
    }

    private function payment(Client $client, string $reference, array $attributes = []): Payment
    {
        return Payment::withoutEvents(fn () => Payment::factory()->create($attributes + ['platform_id' => $client->platform_id, 'client_id' => $client->id,
            'product_id' => \App\Models\Product::where('platform_id', $client->platform_id)->value('id'), 'transaction_reference' => $reference,
            'reference_number' => $reference, 'reference_root' => $reference, 'amount' => '35000.00', 'currency' => 'TZS', 'status' => 'completed',
            'record_classification' => 'live', 'provider_environment' => 'production', 'phone' => '255700000555', 'purpose' => 'subscription',
            'completed_at' => now(), 'created_at' => now(), 'payment_data' => []]));
    }

    private function profile(int $post, array $attributes = []): Client
    {
        return Client::factory()->create($attributes + ['platform_id' => 6, 'wp_post_id' => $post, 'wp_user_id' => $post + 1000, 'is_high_risk' => false]);
    }

    public function test_confirmed_payer_and_a_different_phone_profile_need_a_staff_choice(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $this->confirmed($account, $client, $actor, $price, 'OLDPAY12345', now()->addDay());
        $other = $this->profile(91, ['phone_normalized' => '255700000001']);
        $receipt = $this->received($device);
        $match = app(CollectionMatchService::class)->automatic($receipt);
        $this->assertNull($match['selected_client_id']);
        $this->assertTrue($match['needs_choice']);
        $this->assertSame(['learned_payer_conflicts_with_other_evidence'], $match['guards']);
        $this->assertEqualsCanonicalizing([$client->id, $other->id], array_column($match['suggestions'], 'id'));
        $this->assertLessThanOrEqual(49, max(array_column($match['suggestions'], 'score')));
        $this->assertNotContains(0, array_column($match['suggestions'], 'score'), 'The phone-matched profile is not hidden behind a zero score.');
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $this->assertContains('learned_payer_conflicts_with_other_evidence', $receipt->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public function test_payer_of_several_profiles_suggests_the_due_profile_but_never_auto_activates(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $other = $this->profile(92, ['phone_normalized' => '255700000888']);
        $this->confirmed($account, $client, $actor, $price, 'OLDPAY12345', now()->addDays(9));
        $this->confirmed($account, $other, $actor, $price, 'OLDPAY67890', now()->addDay());
        $receipt = $this->received($device);
        $match = app(CollectionMatchService::class)->automatic($receipt);
        $this->assertSame($other->id, $match['suggestions'][0]['id']);
        $this->assertSame(85, $match['suggestions'][0]['score']);
        $this->assertSame('due', $match['suggestions'][0]['subscription']['state']);
        $this->assertSame('alternative', collect($match['suggestions'])->firstWhere('id', $client->id)['role']);
        $this->assertSame($other->id, $match['recommended_client_id']);
        $this->assertContains('payer_funds_several_profiles', $match['guards']);
        $this->assertNull($match['selected_client_id']);
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public function test_several_profiles_with_none_clearly_due_ask_staff_to_choose(): void
    {
        [$account, $device, $client, $actor, $price] = $this->autonomous();
        $other = $this->profile(93);
        $this->confirmed($account, $client, $actor, $price, 'OLDPAY12345', now()->addDays(9));
        $this->confirmed($account, $other, $actor, $price, 'OLDPAY67890', now()->addDays(10));
        $match = app(CollectionMatchService::class)->automatic($this->received($device));
        $this->assertTrue($match['needs_choice']);
        $this->assertSame([49, 49], array_column($match['suggestions'], 'score'));
        $this->assertNull($match['recommended_client_id']);
    }

    public function test_phone_only_match_on_an_old_never_paid_profile_is_downgraded_and_guarded(): void
    {
        [$account, $device] = $this->autonomous();
        $old = $this->profile(94, ['phone_normalized' => '255700000001']);
        $old->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();
        $match = app(CollectionMatchService::class)->automatic($receipt = $this->received($device));
        $this->assertSame(65, $match['suggestions'][0]['score']);
        $this->assertSame('none', $match['suggestions'][0]['subscription']['state']);
        $this->assertContains('suggested_profile_dormant', $match['guards']);
        $this->assertNull($match['recommended_client_id']);
        $old->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $fresh = app(CollectionMatchService::class)->automatic($receipt);
        $this->assertSame(80, $fresh['suggestions'][0]['score'], 'A new profile paying from its own phone keeps full confidence.');
        $this->assertSame('new', $fresh['suggestions'][0]['subscription']['state']);
        $this->assertSame([], $fresh['guards']);
    }

    public function test_staff_explain_a_different_choice_and_a_duplicate_stops_being_suggested(): void
    {
        [$account, $device, $client, $actor] = $this->autonomous();
        config(['collection.workspace_enabled' => true]);
        $old = $this->profile(95);
        $receipt = $this->received($device);
        $receipt->update(['candidate_snapshot' => ['matching' => ['rule_version' => 'collection-match-v4', 'suggestions' => [['id' => $old->id, 'score' => 80, 'rules' => ['advert_phone']]]]]]);
        $this->payment($client, $receipt->reference_norm);
        Sanctum::actingAs($actor);
        $path = '/api/crm/collections/receipts/'.$receipt->id.'/match-feedback';
        $this->getJson($path)->assertOk()->assertJsonPath('suggestion.client_id', $old->id)
            ->assertJsonPath('outcomes.0.id', $client->id)->assertJsonPath('outcomes.0.agreed', false)->assertJsonPath('outcomes.0.explained', false);
        $this->postJson($path, ['client_id' => $old->id, 'reason' => 'new_profile', 'source' => 'recorded'])->assertStatus(422);
        $this->postJson($path, ['client_id' => $client->id, 'reason' => 'other', 'source' => 'recorded'])->assertStatus(422);
        $this->postJson($path, ['client_id' => $client->id, 'reason' => 'old_duplicate', 'source' => 'recorded'])->assertCreated()->assertJsonPath('agreed', false);
        $this->assertDatabaseHas('collection_match_feedback', ['receipt_id' => $receipt->id, 'client_id' => $client->id, 'suggested_client_id' => $old->id,
            'suggested_score' => 80, 'reason' => 'old_duplicate', 'agreed' => false, 'actor_id' => $actor->id]);
        $this->assertDatabaseHas('collection_match_exclusions', ['client_id' => $old->id, 'receipt_id' => $receipt->id]);
        $this->postJson($path, ['client_id' => $client->id, 'reason' => 'advertiser_confirmed', 'source' => 'review'])->assertCreated();
        $this->assertDatabaseCount('collection_match_feedback', 1);
        $this->getJson($path)->assertOk()->assertJsonPath('outcomes.0.explained', true)->assertJsonPath('feedback.0.reason', 'advertiser_confirmed');
    }

    public function test_accuracy_report_measures_saved_suggestions_against_staff_outcomes(): void
    {
        [$account, $device, $client, $actor] = $this->autonomous();
        config(['collection.workspace_enabled' => true]);
        $other = $this->profile(96);
        $right = $this->received($device, 'RIGHT123456');
        $right->update(['candidate_snapshot' => ['matching' => ['suggestions' => [['id' => $client->id, 'score' => 90, 'rules' => ['payment_history_3plus']]], 'selected_client_id' => $client->id]]]);
        $this->payment($client, 'RIGHT123456');
        $wrong = $this->received($device, 'WRONG123456');
        $wrong->update(['candidate_snapshot' => ['matching' => ['suggestions' => [['id' => $other->id, 'score' => 95, 'source' => 'staff_confirmed_sms', 'reasons' => []]]]]]);
        $this->payment($client, 'WRONG123456');
        $none = $this->received($device, 'NONEXX12345');
        $none->update(['candidate_snapshot' => ['matching' => ['suggestions' => []]]]);
        $this->payment($other, 'NONEXX12345');
        $this->received($device, 'UNUSED12345');
        DB::table('collection_match_feedback')->insert(['receipt_id' => $wrong->id, 'account_id' => $account->id, 'client_id' => $client->id,
            'suggested_client_id' => $other->id, 'suggested_score' => 95, 'agreed' => false, 'reason' => 'pays_for_several', 'source' => 'recorded',
            'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $report = app(CollectionMatchAccuracyService::class)->build([$account->id], CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addMinute());
        $this->assertSame(['receipts' => 4, 'with_outcome' => 3, 'suggested' => 2, 'agreed' => 1, 'disagreed' => 1, 'in_list_not_top' => 0, 'no_suggestion' => 1], $report['totals']);
        $this->assertSame(0.5, $report['rates']['precision']);
        $this->assertSame(['count' => 1, 'correct' => 1, 'precision' => 1.0], $report['would_auto']);
        $this->assertSame(['n' => 1, 'correct' => 0], array_intersect_key(collect($report['bands'])->firstWhere('band', '95+'), ['n' => 1, 'correct' => 1]));
        $this->assertSame('staff_confirmed_payer', $report['cases']['disagreed'][0]['suggested']['rule']);
        $this->assertSame('pays_for_several', $report['cases']['disagreed'][0]['reason']);
        $this->assertSame(1, collect($report['reasons'])->firstWhere('reason', 'pays_for_several')['count']);
        $this->assertSame(['disagreements' => 1, 'explained' => 1], $report['feedback']);

        Sanctum::actingAs($actor);
        $this->getJson('/api/crm/collections/match-accuracy?days=7')->assertOk()->assertJsonPath('report.totals.with_outcome', 3);
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));
        $this->getJson('/api/crm/collections/match-accuracy?days=7')->assertForbidden();
    }
}
