<?php

namespace Tests\Feature\Collections;

use App\Models\BillingSubscriptionRule;
use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionSubsidiaryObligation;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\Collections\CollectionSubsidiaryFulfillmentService;
use App\Services\SubsidiaryClientResolver;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionSubsidiaryAutomationTest extends CollectionWorkflowTestCase
{
    private function paired(bool $existing = true, bool $emptyExpiry = false): array
    {
        [$account, $device, $source, $intent, $actor] = $this->purchase(true, 6);
        $source->update(['name' => 'Paired fixture', 'phone_normalized' => '255700000001', 'email' => 'source@login.test',
            'profile_reverted_at' => null, 'profile_removal_requested_at' => null]);
        $actor->update(['status' => 'active']);
        $target = Platform::factory()->create(['id' => 2, 'name' => 'Tanzania', 'is_active' => true, 'currency_code' => 'TZS',
            'phone_prefix' => '255', 'wp_api_url' => 'https://paired-fixture.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture', 'db_host' => 'localhost',
            'db_name' => 'fixture_target', 'db_user' => 'fixture', 'db_pass' => 'fixture', 'db_prefix' => 'fixture_']);
        $product = Product::factory()->create(['platform_id' => 2, 'tier' => 'vip', 'name' => 'VIP', 'currency' => 'TZS', 'is_active' => true, 'is_archived' => false]);
        $target->update(['product_id' => $product->id]);
        BillingSubscriptionRule::create(['market_id' => 2, 'free_trial_json' => ['enabled' => true, 'duration_days' => 7]]);
        $policy = $account->policy;
        $policy['independent_subsidiary'] = ['enabled' => true, 'approved_by' => $actor->id,
            'approved_at' => now()->subDays(3)->toIso8601String(), 'platform_ids' => [6, 2], 'create_and_copy' => true];
        $account->update(['policy' => $policy]);
        $intent->update(['purchase_snapshot' => array_merge($intent->purchase_snapshot,
            ['subsidiary_mode' => 'independent', 'subsidiary_platform_id' => 2])]);
        config(['collection.workspace_enabled' => true, 'reconciliation.trials_enabled' => true, 'subsidiary_trials.pairs' => [6 => 2],
            'subsidiary_trials.profile_copy_enabled' => false, 'collection.entitlement_platforms' => [6, 2],
            'collection.verified_writer_platforms' => [6, 2], 'collection.verified_correction_platforms' => [6, 2]]);
        $client = $existing ? Client::factory()->create(['platform_id' => 2, 'wp_post_id' => 88, 'wp_user_id' => 66,
            'name' => 'Paired fixture', 'phone_normalized' => $source->phone_normalized, 'email' => 'different@login.test',
            'profile_reverted_at' => null, 'profile_removal_requested_at' => null, 'profile_status' => 'private', 'is_high_risk' => false]) : null;
        $this->remote[88] = $this->snapshot(88, 66);
        if ($emptyExpiry) {
            foreach ([77, 88] as $post) {
                $snapshot = $this->remote[$post];
                $snapshot['meta']['escort_expire'] = '';
                $snapshot['meta_present'] = array_values(array_diff($snapshot['meta_present'], ['escort_expire']));
                unset($snapshot['fingerprint']);
                $snapshot['fingerprint'] = hash('sha256', json_encode($snapshot));
                $this->remote[$post] = $snapshot;
            }
        }
        $receipt = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($receipt->id);
        $this->assertTrue(CollectionAllocation::exists(), json_encode($receipt->fresh()->candidate_snapshot));
        $allocation = CollectionAllocation::firstOrFail();
        $this->fakeWordPress();
        app(CollectionFulfillmentService::class)->resume($allocation->id);
        $owner = CollectionSubsidiaryObligation::firstOrFail();

        return [$owner, $source, $target, $client, $actor];
    }

    private function resolveTo(Client $client, string $origin): void
    {
        $resolver = \Mockery::mock(SubsidiaryClientResolver::class, [app(\App\Support\CrossPlatformPhoneResolver::class)])->makePartial();
        $resolver->shouldReceive('resolveFrozenOutcome')->once()->andReturn(['client' => $client, 'origin' => $origin]);
        $this->app->instance(SubsidiaryClientResolver::class, $resolver);
    }

    public function test_automatic_existing_profile_with_different_email_activates_once_and_preserves_both_accounts(): void
    {
        [$owner, $source, $target, $client] = $this->paired();
        $this->resolveTo($client, 'existing');
        $after = app(CollectionSubsidiaryFulfillmentService::class)->resume($owner->id);
        $this->assertSame('succeeded', $after->state, json_encode(DB::table('collection_audit')->where('receipt_id', $owner->allocation->receipt_id)->get()));
        $this->assertSame($client->id, FreeTrialRequest::findOrFail($after->trial_request_id)->client_id);
        $this->assertSame($owner->interval['end'], (int) $this->remote[88]['meta']['escort_expire']);
        $this->assertSame('source@login.test', $source->fresh()->email);
        $this->assertSame('different@login.test', $client->fresh()->email);
        app(CollectionSubsidiaryFulfillmentService::class)->resume($owner->id);
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseCount('collection_subsidiary_obligations', 1);
        $this->assertDatabaseCount('clients', 2);
        $this->assertSame(1, $owner->allocation->effects()->where('effect_kind', 'subsidiary_free')->count());
        $this->assertSame('active_review_pending', $owner->allocation->fresh()->state);
    }

    public function test_undo_recovers_legacy_empty_expiry_payloads_on_both_paid_and_paired_profiles(): void
    {
        $this->compensationV2 = true;
        [$owner, $source, $target, $client, $actor] = $this->paired(true, true);
        $this->resolveTo($client, 'existing');
        app(CollectionSubsidiaryFulfillmentService::class)->resume($owner->id);
        $allocation = $owner->allocation->fresh();
        $paymentCount = \App\Models\Payment::count();
        $service = app(\App\Services\Collections\CollectionCorrectionService::class);
        $correction = $service->preview($allocation, $actor, 'undo_effect', null, $allocation->version, 'Wrong paid profile and paired exposure');
        $this->assertTrue($correction->preview['can_execute'], json_encode($correction->preview));
        $preview = $correction->preview;
        $this->assertCount(2, $preview['source_steps']);
        foreach ($preview['source_steps'] as &$step) {
            $step['target_state']['restore_snapshot']['meta']['escort_expire'] = '0';
        }
        unset($step);
        $correction->update(['preview' => $preview]);
        $key = (string) \Illuminate\Support\Str::uuid();
        $result = $service->execute($correction, $actor, $correction->version, $key);
        $this->assertSame('verified', $result->state, json_encode(DB::table('collection_audit')->get()));
        foreach ([77, 88] as $post) {
            $this->assertSame('', $this->remote[$post]['meta']['escort_expire']);
            $this->assertSame('private', $this->remote[$post]['post_status']);
        }
        $this->assertSame('unassigned', $allocation->fresh()->state);
        $this->assertDatabaseCount('payments', $paymentCount);
        $this->assertSame(1, \App\Models\Payment::where('amount', '>', 0)->count());
        $this->assertSame(4, $this->posts);
        $service->execute($result, $actor, $result->version, $key);
        $this->assertSame(4, $this->posts);
    }

    public function test_missing_profile_is_created_and_activated_without_a_staff_setup_step(): void
    {
        [$owner, $source, $target] = $this->paired(false);
        $resolver = \Mockery::mock(SubsidiaryClientResolver::class, [app(\App\Support\CrossPlatformPhoneResolver::class)])->makePartial();
        $resolver->shouldReceive('resolveFrozenOutcome')->once()->andReturnUsing(function ($market, $seed) use ($source) {
            $this->assertTrue($seed['separate_account']);
            $client = Client::factory()->create(['platform_id' => 2, 'wp_post_id' => 88, 'wp_user_id' => 66,
                'phone_normalized' => $source->phone_normalized, 'email' => 'generated@onboard.invalid',
                'profile_reverted_at' => null, 'profile_removal_requested_at' => null, 'profile_status' => 'private', 'is_high_risk' => false]);

            return ['client' => $client, 'origin' => 'created'];
        });
        $this->app->instance(SubsidiaryClientResolver::class, $resolver);
        $after = app(CollectionSubsidiaryFulfillmentService::class)->resume($owner->id);
        $this->assertSame('succeeded', $after->state, json_encode(DB::table('collection_audit')->where('receipt_id', $owner->allocation->receipt_id)->get()));
        $this->assertSame('created', FreeTrialRequest::findOrFail($after->trial_request_id)->activation_result_json['profile_origin']);
        $this->assertSame(2, $this->posts);
        app(CollectionSubsidiaryFulfillmentService::class)->resume($owner->id);
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseCount('clients', 2);
        $this->assertSame('publish', $this->remote[88]['post_status']);
    }

    public function test_duplicate_phones_keep_the_primary_active_and_explain_the_exact_manual_choice(): void
    {
        [$owner, $source, $target, $client, $actor] = $this->paired();
        Client::factory()->create(['platform_id' => 2, 'phone_normalized' => $source->phone_normalized]);
        $after = app(CollectionSubsidiaryFulfillmentService::class)->resume($owner->id);
        $this->assertSame('needs_profile', $after->state);
        $this->assertSame('subsidiary_multiple_profiles_require_selection', $after->last_error_code);
        $this->assertSame(1, $this->posts);
        $this->assertSame('active', Deal::findOrFail($owner->source_deal_id)->status);
        $this->assertDatabaseCount('free_trial_requests', 0);
        Sanctum::actingAs($actor);
        $this->getJson('/api/crm/collections/receipts/'.$owner->allocation->receipt_id.'/subsidiary')->assertOk()
            ->assertJsonPath('last_error_code', 'subsidiary_multiple_profiles_require_selection')
            ->assertJsonPath('duration_days', 8)->assertJsonPath('profile_action', 'finding')
            ->assertJsonPath('automation.attempts', 0);
    }
}
