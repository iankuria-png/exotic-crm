<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Payment;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionJourneyAssessment;
use App\Services\Collections\CollectionMatchService;
use App\Services\Collections\CollectionOutboxWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CollectionCataloguePurchaseMatchTest extends CollectionWorkflowTestCase
{
    private function catalogue(): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase();
        $intent->update(['revoked_at' => now()]);
        CollectionPayerLink::query()->update(['revoked_at' => now()]);
        $price->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->saveQuietly();
        $account->update(['policy' => array_replace($account->policy, ['purchase_matching' => 'exact_catalogue_or_reviewed',
            'purchase_matching_approved_by' => $actor->id, 'purchase_matching_approved_at' => now()->subDay()->toIso8601String()])]);
        foreach (range(1, 3) as $n) {
            Payment::withoutEvents(fn () => Payment::factory()->create(['platform_id' => $client->platform_id, 'product_id' => $price->product_id,
                'client_id' => $client->id, 'phone' => '255700000001', 'currency' => 'TZS', 'amount' => '35000.00',
                'transaction_reference' => 'PASTCODE'.$n, 'reference_number' => 'PASTCODE'.$n,
                'completed_at' => now()->subDay(), 'created_at' => now()->subDay()]));
        }

        return [$account, $device, $client, $price];
    }

    public function test_unique_exact_package_can_reserve_from_history_without_per_customer_agreement(): void
    {
        [$account, $device, $client, $price] = $this->catalogue();
        $r = $this->received($device);
        $before = app(CollectionMatchService::class)->automatic($r);
        $this->assertSame(90, $before['suggestions'][0]['score']);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::first();
        $this->assertNotNull($a, json_encode($r->fresh()->candidate_snapshot));
        $this->assertSame(8, $a->purchase_snapshot['duration_days']);
        $this->assertSame($price->id, $a->purchase_snapshot['product_price_id']);
        $this->assertSame('matched_catalogue', CollectionPurchaseIntent::findOrFail($a->intent_id)->authority);
        $this->assertSame(90, app(CollectionMatchService::class)->automatic($r->fresh())['suggestions'][0]['score']);
        $this->assertSame(0, $this->posts);
        $this->assertSame(3, Payment::count());
    }

    public function test_equal_amount_for_seven_and_eight_days_requires_staff_choice(): void
    {
        [$account, $device, $client, $price] = $this->catalogue();
        $other = $price->replicate();
        $other->duration_days = 7;
        $other->duration_key = 'weekly';
        $other->duration_label = '7 days';
        $other->created_at = now()->subDay();
        $other->updated_at = now()->subDay();
        $other->saveQuietly();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $this->assertContains('multiple_exact_package_matches', $r->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertSame(0, CollectionPurchaseIntent::where('authority', 'matched_catalogue')->count());
    }

    public function test_phone_only_score_cannot_use_an_exact_amount_to_identify_the_customer(): void
    {
        [$account, $device, $client, $price] = $this->catalogue();
        Payment::query()->delete();
        $client->update(['phone_normalized' => '+255700000001']);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $this->assertContains('client_match_below_threshold_or_conflicting', $r->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_approved_default_resolves_only_its_amount_without_changing_other_products(): void
    {
        [$account, $device, $client, $price] = $this->catalogue();
        $seven = $price->replicate();
        $seven->duration_days = 7;
        $seven->duration_key = 'weekly';
        $seven->created_at = now()->subDay();
        $seven->updated_at = now()->subDay();
        $seven->saveQuietly();
        $account->update(['policy' => array_replace($account->policy, ['catalogue_price_defaults' => [$client->platform_id.':TZS:3500000' => $seven->id],
            'catalogue_price_defaults_approved_by' => 1, 'catalogue_price_defaults_approved_at' => now()->subHour()->toIso8601String()])]);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $allocation = CollectionAllocation::firstOrFail();
        $this->assertSame(7, $allocation->purchase_snapshot['duration_days']);
        $this->assertSame($seven->id, $allocation->purchase_snapshot['product_price_id']);
        $this->assertSame(8, $price->fresh()->duration_days);
        $this->assertTrue($price->fresh()->is_active);
    }

    public function test_paused_account_can_explain_profile_and_package_without_creating_authority_or_dispatching(): void
    {
        [$account, $device, $client, $price] = $this->catalogue();
        $account->update(['mode' => 'shadow', 'policy' => array_replace($account->policy, ['automation_enabled' => false])]);
        $r = $this->received($device);
        $before = DB::table('collection_audit')->count();
        $assessment = app(CollectionJourneyAssessment::class)->forReceipt($r, [$client->id]);
        $this->assertSame(90, $assessment['profile']['score']);
        $this->assertSame($price->id, $assessment['package']['purchase']['product_price_id']);
        $this->assertSame('paused', $assessment['activation']['state']);
        $this->assertTrue($assessment['advisory']);
        $this->assertSame($before, DB::table('collection_audit')->count());
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertSame(0, CollectionPurchaseIntent::where('authority', 'matched_catalogue')->count());
        $this->assertSame(3, Payment::count());
        Http::assertNothingSent();
        $hidden = app(CollectionJourneyAssessment::class)->forReceipt($r, []);
        $this->assertNull($hidden['profile']);
        $this->assertSame('waiting_for_profile', $hidden['package']['state']);
    }

    public function test_assessment_keeps_package_collisions_and_phone_only_identity_for_staff_review(): void
    {
        [$account, $device, $client, $price] = $this->catalogue();
        $other = $price->replicate();
        $other->duration_days = 7;
        $other->duration_key = 'weekly';
        $other->created_at = now()->subDay();
        $other->updated_at = now()->subDay();
        $other->saveQuietly();
        $r = $this->received($device);
        $assessment = app(CollectionJourneyAssessment::class)->forReceipt($r, [$client->id]);
        $this->assertSame('multiple_exact_package_matches', $assessment['package']['reason']);
        Payment::query()->delete();
        $client->update(['phone_normalized' => '+255700000001']);
        $assessment = app(CollectionJourneyAssessment::class)->forReceipt($r, [$client->id]);
        $this->assertNull($assessment['profile']);
        $this->assertSame('waiting_for_profile', $assessment['package']['state']);
        $this->assertDatabaseCount('collection_allocations', 0);
        Http::assertNothingSent();
    }

    public function test_intake_worker_matches_standard_seven_day_default_and_recovers_lost_confirmation_once(): void
    {
        [$account, $device, $client, $price] = $this->catalogue();
        $seven = $price->replicate();
        $seven->duration_days = 7;
        $seven->duration_key = 'weekly';
        $seven->created_at = now()->subDay();
        $seven->updated_at = now()->subDay();
        $seven->saveQuietly();
        $account->update(['policy' => array_replace($account->policy, ['max_auto_age_seconds' => 3600,
            'catalogue_price_defaults' => [$client->platform_id.':TZS:3500000' => $seven->id],
            'catalogue_price_defaults_approved_by' => 1, 'catalogue_price_defaults_approved_at' => now()->subHour()->toIso8601String()])]);
        $this->fakeWordPress();
        $this->loseResponse = true;
        $envelope = $this->envelope($this->credit(), 'worker-credit');
        app(CollectionIngestService::class)->accept($device, $envelope, false);
        $worker = app(CollectionOutboxWorker::class);
        foreach (range(1, 4) as $tick) {
            $worker->run();
        }
        $this->assertNotNull(CollectionAllocation::first(), json_encode(['receipts' => DB::table('collection_receipts')->get(['decision_state', 'candidate_snapshot']), 'outbox' => DB::table('collection_outbox')->get(['kind', 'last_error_code', 'completed_at'])]));
        $allocation = CollectionAllocation::firstOrFail();
        $this->assertSame('uncertain', $allocation->state);
        $this->assertSame(7, $allocation->purchase_snapshot['duration_days']);
        $this->assertSame('automatic', $allocation->decision_source);
        $this->assertSame(1, $this->posts);
        $this->travel(301)->seconds();
        $worker->run();
        $this->assertSame('active_review_pending', $allocation->fresh()->state);
        app(CollectionIngestService::class)->accept($device, $envelope, false);
        $worker->run();
        $this->assertDatabaseCount('collection_receipts', 1);
        $this->assertDatabaseCount('collection_allocations', 1);
        $this->assertDatabaseCount('deals', 1);
        $this->assertSame(4, Payment::count()); // Three earlier payments plus this credit.
        $this->assertSame(1, $this->posts);
        $this->assertSame($seven->id, $allocation->fresh()->purchase_snapshot['product_price_id']);
        $this->assertSame(0, DB::table('collection_outbox')->whereNull('completed_at')->whereNotNull('last_error_code')->count());
    }
}
