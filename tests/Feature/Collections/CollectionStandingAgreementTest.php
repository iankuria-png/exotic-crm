<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\User;
use App\Services\Collections\CollectionMatchExclusionService;
use App\Services\Collections\CollectionStandingAgreementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class CollectionStandingAgreementTest extends CollectionWorkflowTestCase
{
    public function test_manager_explicitly_approves_exact_future_terms_once_without_dispatch(): void
    {
        [$account, $device, $client, $old, $actor, $price] = $this->purchase();
        $old->update(['revoked_at' => now()]);
        $r = $this->received($device);
        $input = ['receipt_version' => $r->version, 'client_id' => $client->id, 'product_price_id' => $price->id,
            'valid_until' => now()->addWeek()->toIso8601String(), 'max_renewals' => 2, 'reason' => 'Customer explicitly agreed to eight-day VIP renewals', 'request_key' => (string) Str::uuid()];
        $service = app(CollectionStandingAgreementService::class);
        $result = $service->create($r, $actor, $input);
        $this->assertSame(8, $result['purchase']['duration_days']);
        $this->assertSame(3500000, $result['purchase']['amount_minor']);
        $this->assertSame(2, $result['max_renewals']);
        $this->assertTrue($result['future_payments_only']);
        $this->assertSame($result['intent_id'], $service->create($r, $actor, $input)['intent_id']);
        $this->assertSame(1, CollectionPurchaseIntent::whereNull('revoked_at')->count());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertSame(0, $this->posts);
    }

    public function test_rejected_identity_can_only_be_cleared_by_named_fresh_review_and_current_version(): void
    {
        [$account, $device, $client, $old, $actor, $price] = $this->purchase();
        $r = $this->received($device);
        DB::transaction(fn () => app(CollectionMatchExclusionService::class)->record($r, $client->id, null, 'explicit-correction', $actor->id, 'Prior payer mismatch'));
        $exclusion = DB::table('collection_match_exclusions')->first();
        $input = ['receipt_version' => $r->version, 'client_id' => $client->id, 'product_price_id' => $price->id,
            'valid_until' => now()->addWeek()->toIso8601String(), 'max_renewals' => 1, 'reason' => 'Fresh identity and package instruction verified', 'request_key' => (string) Str::uuid()];
        try {
            app(CollectionStandingAgreementService::class)->create($r, $actor, $input);
            $this->fail('Exclusion must require explicit named review');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('current_match_exclusions_require_explicit_review', $e->reason);
        }
        $this->assertSame(0, CollectionPurchaseIntent::whereNull('revoked_at')->count());
        $result = app(CollectionStandingAgreementService::class)->create($r, $actor, $input + ['clear_exclusions' => [['id' => $exclusion->id, 'version' => 1]]]);
        $this->assertSame([$exclusion->id], $result['cleared_exclusions']);
        $this->assertDatabaseHas('collection_match_exclusions', ['id' => $exclusion->id, 'cleared_by' => $actor->id, 'version' => 2]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_sales_cannot_create_recurring_authority_through_the_route(): void
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase();
        config(['collection.workspace_enabled' => true]);
        $r = $this->received($device);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$client->platform_id]]);
        Sanctum::actingAs($sales);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/standing-agreements', ['receipt_version' => $r->version,
            'client_id' => $client->id, 'product_price_id' => $price->id, 'valid_until' => now()->addWeek()->toIso8601String(),
            'max_renewals' => 1, 'reason' => 'Unauthorised recurring instruction', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('collection_purchase_intents', 1);
    }
}
