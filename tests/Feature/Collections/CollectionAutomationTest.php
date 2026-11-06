<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CollectionAutomationTest extends CollectionWorkflowTestCase
{
    public function test_clear_receipt_autonomously_activates_eight_day_price_once_then_awaits_review(): void
    {
        [$account, $device, $client, $intent] = $this->purchase();
        $this->fakeWordPress();
        // A colliding seven-day price cannot override the standing eight-day purchase.
        ProductPrice::create(['product_id' => $intent->purchase_snapshot['product_id'], 'duration_key' => 'weekly', 'duration_label' => '7 days', 'duration_days' => 7, 'price' => 35000, 'currency' => 'TZS', 'is_active' => true]);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::first();
        $this->assertNotNull($a, json_encode($r->fresh()->candidate_snapshot));
        $result = app(CollectionFulfillmentService::class)->resume($a->id);
        $this->assertSame('active_review_pending', $result->state, json_encode(DB::table('collection_audit')->get()));
        $this->assertSame('active_review_pending', $r->fresh()->decision_state);
        $this->assertSame(8, Deal::first()->duration_days);
        $this->assertSame(now()->addDays(8)->timestamp, Deal::first()->expires_at->timestamp);
        $this->assertSame('publish', $client->fresh()->profile_status);
        $this->assertSame(now()->addDays(8)->timestamp, $client->fresh()->escort_expire);
        app(CollectionDecisionService::class)->evaluate($r->id);
        app(CollectionFulfillmentService::class)->resume($a->id);
        $this->assertSame(1, $this->posts);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_fulfillment_operations', 1);
        $this->assertDatabaseCount('collection_exposure_reservations', 1);
        $this->assertDatabaseCount('collection_profile_holds', 0);
        $this->assertSame('manual_confirmation', Payment::first()->source);
        $this->assertSame('manual_review', Payment::first()->reconciliation_state);
    }

    public function test_lost_response_recovers_by_existing_operation_lookup_without_a_second_post(): void
    {
        [$account, $device] = $this->purchase();
        $this->fakeWordPress();
        $this->loseResponse = true;
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::firstOrFail();
        $this->assertSame('uncertain', app(CollectionFulfillmentService::class)->resume($a->id)->state);
        $this->assertDatabaseCount('collection_profile_holds', 1);
        // A kill switch stops new writes, while a known result can still be recovered.
        config(['collection.entitlement_platforms' => [], 'collection.automatic_accounts' => []]);
        $this->travel(301)->seconds(); // Recovery must still work when the phone is offline.
        $this->assertSame('active_review_pending', app(CollectionFulfillmentService::class)->resume($a->id)->state);
        $this->assertSame(1, $this->posts);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_history_and_disabled_production_gates_never_create_a_payment_or_dispatch(): void
    {
        [$a, $device] = $this->purchase(false);
        $r = $this->received($device);
        $this->assertSame('needs_review', app(CollectionDecisionService::class)->evaluate($r->id)->decision_state);
        $old = $this->received($device, 'OLDHIST1234', true);
        $this->assertSame('historical', app(CollectionDecisionService::class)->evaluate($old->id)->decision_state);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_shared_payer_missing_authority_and_changed_amount_produce_exceptions(): void
    {
        [$a, $device, $client, $intent, $user] = $this->purchase();
        $other = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 88]);
        $link = CollectionPayerLink::first()->replicate();
        $link->id = (string) Str::uuid();
        $link->client_id = $other->id;
        $link->save();
        $r = $this->received($device);
        $this->assertSame('needs_review', app(CollectionDecisionService::class)->evaluate($r->id)->decision_state);
        $this->assertDatabaseCount('collection_allocations', 0);
        $link->delete();
        $intent->update(['revoked_at' => now()]);
        $this->assertSame('needs_review', app(CollectionDecisionService::class)->evaluate($r->id)->decision_state);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_daily_budget_does_not_reset_with_policy_revision_and_unknown_risk_crosses_midnight(): void
    {
        [$a, $device, $client, $intent] = $this->purchase();
        $a->update(['policy' => array_merge($a->policy, ['daily_cap_minor' => 6000000, 'unreviewed_cap_minor' => 6000000])]);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $second = $this->received($device, 'SECOND12345');
        // Release the profile mutex only to isolate the aggregate budget assertion.
        DB::table('collection_profile_holds')->delete();
        $this->assertSame('needs_review', app(CollectionDecisionService::class)->evaluate($second->id)->decision_state);
        $this->assertContains('automatic_exposure_limit', $second->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('collection_allocations', 1);
        $this->assertDatabaseCount('collection_exposure_reservations', 1);
        $this->travel(1)->days();
        $a->update(['policy' => array_merge($a->policy, ['version' => 2])]);
        $third = $this->received($device, 'THIRDA12345');
        // Real new provider time after midnight, rather than the historical fixture clock.
        $third->update(['provider_at' => now()->subMinute(), 'provider_latest_at' => now()->subMinute()]);
        $this->assertSame('needs_review', app(CollectionDecisionService::class)->evaluate($third->id)->decision_state);
        $this->assertDatabaseCount('collection_exposure_reservations', 1);
        $out = DB::table('collection_exposure_buckets')->where('bucket', 'outstanding')->first();
        $this->assertSame(3500000, (int) $out->unreviewed_minor);
    }

    public function test_mixed_tier_preserves_existing_paid_entitlement_and_writer_guard_blocks_legacy_activation(): void
    {
        [$a, $device, $client, $intent] = $this->purchase();
        $this->remote[77] = $this->snapshot(77, 55, now()->addDays(10)->timestamp, 'vvip');
        $before = $this->remote[77];
        $this->fakeWordPress();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $allocation = CollectionAllocation::firstOrFail();
        $this->assertSame('needs_review', app(CollectionFulfillmentService::class)->resume($allocation->id)->state);
        $this->assertSame(0, $this->posts);
        $this->assertSame($before, $this->remote[77]);
        $this->expectException(CollectionException::class);
        WpSyncService::forPlatform($client->platform_id)->activateClient(77, 'vip', 8);
    }

    public function test_unscoped_legacy_cash_claim_is_held_instead_of_creating_duplicate_revenue(): void
    {
        [$a, $device, $client, $intent] = $this->purchase();
        $a->update(['mode' => 'shadow']);
        Payment::factory()->create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'amount' => 35000, 'currency' => 'TZS', 'transaction_reference' => 'ABCDEF12345', 'purpose' => 'subscription', 'record_classification' => 'live', 'provider_environment' => 'production']);
        $a->update(['mode' => 'auto']);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $this->assertContains('legacy_destination_unverified', $r->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('collection_allocations', 0);
        Http::assertNothingSent();
    }

    public function test_tz_primary_and_dependent_free_exposure_share_one_principal_and_replay_neither_interval(): void
    {
        [$account, $device, $client, $intent] = $this->purchase(true, 6);
        $market = Platform::factory()->create(['id' => 2, 'currency_code' => 'TZS', 'phone_prefix' => '255', 'wp_api_url' => 'https://subsidiary-fixture.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        $subsidiary = Client::factory()->create(['platform_id' => 2, 'wp_post_id' => 88, 'wp_user_id' => 66]);
        Product::factory()->create(['platform_id' => 2, 'tier' => 'vip', 'name' => 'VIP', 'currency' => 'TZS', 'is_active' => true, 'is_archived' => false]);
        $p = $intent->purchase_snapshot;
        $p['subsidiary'] = ['client_id' => $subsidiary->id, 'platform_id' => 2, 'wp_post_id' => 88, 'wp_user_id' => 66];
        $intent->update(['purchase_snapshot' => $p]);
        config(['collection.entitlement_platforms' => [6, 2], 'collection.verified_writer_platforms' => [6, 2]]);
        $this->remote[88] = $this->snapshot(88, 66);
        $this->fakeWordPress();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $allocation = CollectionAllocation::firstOrFail();
        $result = app(CollectionFulfillmentService::class)->resume($allocation->id);
        $this->assertSame('active_review_pending', $result->state, json_encode(DB::table('collection_audit')->get()));
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseCount('collection_benefit_effects', 2);
        $this->assertSame(1, Payment::where('amount', '>', 0)->count());
        $this->assertSame(1, Payment::where('amount', 0)->count());
        $this->assertSame($this->remote[77]['meta']['escort_expire'], $this->remote[88]['meta']['escort_expire']);
        app(CollectionFulfillmentService::class)->resume($allocation->id);
        $this->assertSame(2, $this->posts);
        $this->assertSame(1, DB::table('collection_exposure_reservations')->count());
    }

    public function test_delayed_subsidiary_retries_only_free_effect_with_original_paid_end_and_preserves_longer_coverage(): void
    {
        [$account, $device, $client, $intent] = $this->purchase(true, 6);
        $market = Platform::factory()->create(['id' => 2, 'currency_code' => 'TZS', 'wp_api_url' => 'https://subsidiary-fixture.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        $subsidiary = Client::factory()->create(['platform_id' => 2, 'wp_post_id' => 88, 'wp_user_id' => 66]);
        Product::factory()->create(['platform_id' => 2, 'tier' => 'vip', 'name' => 'VIP', 'currency' => 'TZS', 'is_active' => true, 'is_archived' => false]);
        $p = $intent->purchase_snapshot;
        $p['subsidiary'] = ['client_id' => $subsidiary->id, 'platform_id' => 2, 'wp_post_id' => 88, 'wp_user_id' => 66];
        $intent->update(['purchase_snapshot' => $p]);
        config(['collection.entitlement_platforms' => [6, 2], 'collection.verified_writer_platforms' => [6, 2]]);
        $longer = now()->addDays(20)->timestamp;
        $this->remote[88] = $this->snapshot(88, 66, $longer, 'vip', 999);
        $this->unavailablePost = 88;
        $this->fakeWordPress();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::firstOrFail();
        $this->assertSame('dependent_pending', app(CollectionFulfillmentService::class)->resume($a->id)->state);
        $primaryEnd = (int) $this->remote[77]['meta']['escort_expire'];
        $this->assertSame('dependent_pending', $r->fresh()->decision_state);
        $this->assertSame(1, $this->posts);
        $this->travel(1)->days();
        $this->captureCheckIn($device);
        $this->unavailablePost = null;
        $this->assertSame('active_review_pending', app(CollectionFulfillmentService::class)->resume($a->id)->state);
        $free = \App\Models\Collections\CollectionBenefitEffect::where('effect_kind', 'subsidiary_free')->firstOrFail();
        $this->assertSame($primaryEnd, $free->contribution['end']);
        $this->assertSame($longer, (int) $this->remote[88]['meta']['escort_expire']);
        $this->assertSame($primaryEnd, (int) $this->remote[77]['meta']['escort_expire']);
        $this->assertSame(2, $this->posts);
        $this->assertSame(1, Payment::where('amount', '>', 0)->count());
    }
}
