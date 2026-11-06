<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Payment;
use App\Services\Collections\CollectionCorrectionService;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\Collections\CollectionReviewService;
use App\Services\Collections\CollectionSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionCorrectionReviewTest extends CollectionWorkflowTestCase
{
    private function activated(): array
    {
        [$account, $device, $client, $intent, $user] = $this->purchase();
        $this->fakeWordPress();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::firstOrFail();
        app(CollectionFulfillmentService::class)->resume($a->id);

        return [$account, $r->fresh(), $client, $intent, $user, $a->fresh()];
    }

    private function target($intent): CollectionPurchaseIntent
    {
        $client = Client::factory()->create(['platform_id' => $intent->platform_id, 'wp_post_id' => 99, 'wp_user_id' => 88]);
        $target = $intent->replicate();
        $target->id = (string) Str::uuid();
        $target->client_id = $client->id;
        $target->used_count = 0;
        $target->purchase_snapshot = array_merge($intent->purchase_snapshot, ['client_id' => $client->id, 'wp_post_id' => 99, 'wp_user_id' => 88]);
        $target->save();
        $this->remote[99] = $this->snapshot(99, 88);

        return $target;
    }

    public function test_reserved_purchase_can_be_reassigned_before_dispatch_with_no_cash_or_site_mutation(): void
    {
        [$account, $device, $client, $intent, $user] = $this->purchase();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::firstOrFail();
        $target = $this->target($intent);
        $c = app(CollectionCorrectionService::class)->preview($a, $user, 'reassign', $target->id, $a->version, 'Customer selected the other profile');
        $this->assertTrue($c->preview['pre_activation']);
        $this->assertTrue($c->preview['can_execute']);
        $result = app(CollectionCorrectionService::class)->execute($c, $user, $c->version, (string) Str::uuid());
        $this->assertSame('verified', $result->state);
        $this->assertSame($target->id, $a->fresh()->intent_id);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_fulfillment_operations', 0);
        $this->assertDatabaseCount('collection_exposure_reservations', 1);
        $this->assertDatabaseMissing('collection_profile_holds', ['client_id' => $client->id]);
        $this->assertDatabaseHas('collection_profile_holds', ['client_id' => $target->client_id]);
        $this->assertSame(0, $this->posts);
    }

    public function test_sales_review_and_statement_verification_release_separate_counters_exactly_once(): void
    {
        [$account, $r, $client, $intent, $user, $a] = $this->activated();
        $review = app(CollectionReviewService::class);
        $review->confirm($r, $user, $r->version);
        $review->confirm($r->fresh(), $user, $r->fresh()->version);
        $out = DB::table('collection_exposure_buckets')->where('bucket', 'outstanding')->first();
        $this->assertSame(0, (int) $out->unreviewed_minor);
        $this->assertSame(3500000, (int) $out->unverified_minor);
        $this->assertSame('phone', $r->fresh()->evidence_level);
        $row = ['reference' => $r->reference_norm, 'amount_minor' => 3500000, 'currency' => 'TZS', 'provider_at' => $r->provider_at,
            'artifact_sha256' => str_repeat('a', 64), 'evidence_reference' => 'fixture-statement-line-1'];
        app(CollectionSettlementService::class)->statement($account, $user, $row);
        app(CollectionSettlementService::class)->statement($account, $user, $row);
        $out = DB::table('collection_exposure_buckets')->where('bucket', 'outstanding')->first();
        $this->assertSame(0, (int) $out->unverified_minor);
        $this->assertSame(3500000, (int) DB::table('collection_exposure_buckets')->where('bucket', '!=', 'outstanding')->value('daily_minor'));
        $this->assertDatabaseCount('collection_settlement_entries', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_v1_site_cannot_execute_compensation_and_preview_keeps_cash_unchanged(): void
    {
        [$account, $r, $client, $intent, $user, $a] = $this->activated();
        $c = app(CollectionCorrectionService::class)->preview($a, $user, 'undo_effect', null, $a->version, 'Wrong profile matched');
        $this->assertFalse($c->preview['can_execute']);
        $this->assertContains('wordpress_compensation_v2_required', $c->preview['reasons']);
        $this->assertFalse($c->preview['cash_changed']);
        $this->assertSame(1, $this->posts);
        $this->expectException(CollectionException::class);
        app(CollectionCorrectionService::class)->execute($c, $user, $c->version, (string) Str::uuid());
    }

    public function test_undo_compensates_only_owned_effect_and_keeps_one_cash_event_unassigned(): void
    {
        [$account, $r, $client, $intent, $user, $a] = $this->activated();
        $this->compensationV2 = true;
        config(['collection.verified_correction_platforms' => [$client->platform_id]]);
        $c = app(CollectionCorrectionService::class)->preview($a, $user, 'undo_effect', null, $a->version, 'Wrong profile matched');
        $this->assertTrue($c->preview['can_execute']);
        $key = (string) Str::uuid();
        $result = app(CollectionCorrectionService::class)->execute($c, $user, $c->version, $key);
        $this->assertSame('verified', $result->state, json_encode(DB::table('collection_audit')->get()));
        $this->assertSame('unassigned', $a->fresh()->state);
        $this->assertNull(Payment::first()->client_id);
        $this->assertNull(Payment::first()->deal_id);
        $this->assertSame('completed', Payment::first()->status);
        $this->assertEquals(35000, Payment::first()->amount);
        $this->assertSame('0', $this->remote[77]['meta']['escort_expire']);
        $this->assertSame('private', $this->remote[77]['post_status']);
        $this->assertSame('private', $client->fresh()->profile_status);
        $this->assertSame(0, $client->fresh()->escort_expire);
        app(CollectionCorrectionService::class)->execute($result, $user, $result->version, $key);
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('collection_profile_holds', 0);
        $this->assertDatabaseHas('collection_payer_links', ['auto_allowed' => false]);
    }

    public function test_reassignment_keeps_original_payment_and_resumes_lost_source_response_before_target(): void
    {
        [$account, $r, $client, $intent, $user, $a] = $this->activated();
        $paymentId = $a->payment_id;
        $this->compensationV2 = true;
        config(['collection.verified_correction_platforms' => [$client->platform_id], 'collection.correction_duration_policy' => 'full_purchased']);
        $target = $this->target($intent);
        $c = app(CollectionCorrectionService::class)->preview($a, $user, 'reassign', $target->id, $a->version, 'Correct purchase belongs to another profile');
        $this->assertTrue($c->preview['can_execute'], json_encode($c->preview));
        $key = (string) Str::uuid();
        $this->loseResponse = true;
        $first = app(CollectionCorrectionService::class)->execute($c, $user, $c->version, $key);
        $this->assertSame('uncertain', $first->state);
        $this->assertSame('0', $this->remote[99]['meta']['escort_expire']);
        $this->assertSame(2, $this->posts, json_encode(DB::table('collection_audit')->get()));
        app(CollectionFulfillmentService::class)->resume($a->id);
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseCount('collection_profile_holds', 2);
        $this->travel(5)->minutes();
        $final = app(CollectionCorrectionService::class)->execute($first, $user, $first->version, $key);
        $this->assertSame('verified', $final->state);
        $this->assertSame(3, $this->posts);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($paymentId, Payment::first()->id);
        $this->assertSame($target->client_id, Payment::first()->client_id);
        $this->assertSame(now()->addDays(8)->timestamp, (int) $this->remote[99]['meta']['escort_expire']);
        $this->assertSame(1, DB::table('collection_exposure_reservations')->count());
        $this->assertSame(3500000, (int) DB::table('collection_exposure_buckets')->where('bucket', '!=', 'outstanding')->value('daily_minor'));
    }

    public function test_untracked_later_entitlement_change_blocks_correction_before_external_write(): void
    {
        [$account, $r, $client, $intent, $user, $a] = $this->activated();
        $this->compensationV2 = true;
        config(['collection.verified_correction_platforms' => [$client->platform_id]]);
        $this->remote[77] = $this->snapshot(77, 55, now()->addDays(30)->timestamp, 'vvip', 999);
        $c = app(CollectionCorrectionService::class)->preview($a, $user, 'undo_effect', null, $a->version, 'Wrong profile');
        $this->assertFalse($c->preview['can_execute']);
        $this->assertContains('untracked_entitlement_change_requires_recovery', $c->preview['reasons']);
        $this->assertSame(1, $this->posts);
    }
}
