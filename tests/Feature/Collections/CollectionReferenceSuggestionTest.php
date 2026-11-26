<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionMatchService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionReferenceSuggestionTest extends CollectionWorkflowTestCase
{
    private function fixture(): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase(false);
        $account->update(['mode' => 'shadow']);
        $client->update(['phone_normalized' => '255700000001']);
        $receipt = $this->received($device);
        CollectionPurchaseIntent::query()->delete();
        CollectionPayerLink::query()->delete();
        $receipt->update(['candidate_snapshot' => null]);
        $deal = Deal::factory()->create(['platform_id' => $client->platform_id, 'client_id' => $client->id,
            'product_id' => $price->product_id, 'product_price_id' => $price->id, 'amount' => '35000.00', 'currency' => 'TZS', 'status' => 'pending']);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($actor);

        return [$account, $client, $receipt, $deal, $actor];
    }

    private function url($client, $deal, array $params = []): string
    {
        return '/api/crm/collections/clients/'.$client->id.'/reference-suggestions?'.http_build_query($params + ['deal_id' => $deal->id]);
    }

    public function test_missing_snapshot_is_read_as_current_evidence_without_writing_or_selecting_a_purchase(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        DB::enableQueryLog();
        $response = $this->getJson($this->url($client, $deal))->assertOk()->assertJsonPath('items.0.reference', $receipt->reference_norm)
            ->assertJsonPath('items.0.possible_profiles.0.current_profile', true)->assertJsonPath('items.0.use_code_available', true)
            ->assertJsonPath('scan_complete', true)->assertHeader('Cache-Control', 'no-store, private');
        $writes = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query']));
        $this->assertSame([], array_values($writes));
        $this->assertNull($receipt->fresh()->candidate_snapshot);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertDatabaseCount('collection_purchase_intents', 0);
        $this->assertCount(1, $response->json('items'));
    }

    public function test_every_profile_including_zero_score_alternative_sees_the_receipt_and_all_names(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        $other = Client::factory()->create(['platform_id' => $client->platform_id]);
        $otherDeal = Deal::factory()->create(['client_id' => $other->id, 'platform_id' => $other->platform_id, 'currency' => 'TZS', 'amount' => 35000]);
        $profiles = collect([$client, $other])->keyBy('id');
        $this->mock(CollectionMatchService::class, function ($mock) use ($profiles, $client, $other) {
            $mock->shouldReceive('evidence')->andReturn(['clients' => $profiles, 'suggestions' => [
                ['id' => $other->id, 'score' => 95, 'reasons' => ['Previous payer.']],
                ['id' => $client->id, 'score' => 0, 'role' => 'alternative', 'reasons' => ['Also paid for by this payer.']],
            ], 'learned_payer' => ['complete' => true]]);
        });
        foreach ([[$client, $deal], [$other, $otherDeal]] as [$pageClient, $pageDeal]) {
            $this->getJson($this->url($pageClient, $pageDeal))->assertOk()->assertJsonCount(2, 'items.0.possible_profiles')
                ->assertJsonPath('items.0.possible_profiles.1.role', 'alternative')->assertJsonPath('items.0.possible_profiles.1.name', $client->name);
        }
    }

    public function test_explicit_correction_exclusion_is_not_resurrected_as_an_alternative(): void
    {
        [$account, $client, $receipt, $deal, $actor] = $this->fixture();
        DB::table('collection_match_exclusions')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'account_id' => $account->id,
            'payer_fingerprint' => $receipt->payer_fingerprint, 'client_id' => $client->id, 'source_owner' => 'fixture',
            'receipt_id' => $receipt->id, 'actor_id' => $actor->id, 'reason' => 'Correction fixture', 'effective_at' => now()]);
        $this->getJson($this->url($client, $deal))->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_same_currency_and_active_binding_and_deal_ownership_are_required(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        $stranger = Client::factory()->create(['platform_id' => $client->platform_id]);
        $this->getJson($this->url($stranger, $deal))->assertNotFound();
        $receipt->update(['currency' => 'KES']);
        $this->getJson($this->url($client, $deal))->assertOk()->assertJsonCount(0, 'items');
        $receipt->update(['currency' => 'TZS']);
        DB::table('collection_account_platforms')->where('account_id', $account->id)->update(['valid_until' => now()->subMinute()]);
        $this->getJson($this->url($client, $deal))->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_used_reversed_historical_and_alias_codes_are_unavailable_but_manual_typing_is_not_affected(): void
    {
        [$account, $client, $receipt, $deal, $actor] = $this->fixture();
        foreach ([['funds_state' => 'reversed'], ['historical_only' => true]] as $changes) {
            $receipt->update($changes);
            $this->getJson($this->url($client, $deal, ['search' => $receipt->reference_norm]))->assertOk()->assertJsonPath('lookup.state', 'unavailable')->assertJsonCount(0, 'items');
            $receipt->update(['funds_state' => 'observed', 'historical_only' => false]);
        }
        Payment::withoutEvents(fn () => Payment::factory()->create(['platform_id' => $client->platform_id, 'client_id' => $client->id,
            'reference_root' => 'REVIEWEDALIAS', 'transaction_reference' => 'REVIEWEDALIAS', 'reference_number' => 'REVIEWEDALIAS', 'status' => 'pending']));
        DB::table('collection_receipt_aliases')->insert(['receipt_id' => $receipt->id, 'account_id' => $account->id, 'reference' => 'REVIEWEDALIAS', 'reviewed_by' => $actor->id, 'evidence_reference' => 'Fixture alias', 'created_at' => now()]);
        $this->getJson($this->url($client, $deal, ['search' => $receipt->reference_norm]))->assertOk()->assertJsonPath('lookup.reason', 'used')->assertJsonCount(0, 'items');
    }

    public function test_exact_lookup_outside_recent_window_and_amount_mismatch_are_explicit(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        $receipt->update(['provider_at' => now()->subDays(3)]);
        $this->getJson($this->url($client, $deal))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->url($client, $deal, ['search' => strtolower($receipt->reference_norm)]))->assertOk()->assertJsonPath('lookup.state', 'selectable');
        $this->getJson($this->url($client, $deal, ['search' => $receipt->reference_norm, 'amount_minor' => 123]))->assertOk()
            ->assertJsonPath('items.0.use_code_available', false)->assertJsonPath('lookup.reason', 'amount_mismatch');
        $this->getJson($this->url($client, $deal, ['search' => 'NOTCAPTURED']))->assertOk()->assertJsonPath('lookup.state', 'not_found');
    }

    public function test_receipt_pagination_has_no_candidate_cutoff_and_cursor_cannot_cross_quote_or_actor(): void
    {
        [$account, $client, $receipt, $deal, $actor] = $this->fixture();
        for ($i = 1; $i <= 12; $i++) {
            $copy = $receipt->replicate();
            $copy->reference_norm = 'PAGEREF'.$i;
            $copy->provider_at = now()->subMinutes($i);
            $copy->save();
        }
        $first = $this->getJson($this->url($client, $deal))->assertOk()->assertJsonPath('scan_complete', false);
        $cursor = $first->json('next_cursor');
        $ids = array_column($first->json('items'), 'receipt_id');
        $next = $cursor;
        $pages = 0;
        while ($next && $pages++ < 20) {
            $page = $this->getJson($this->url($client, $deal, ['cursor' => $next]))->assertOk();
            $ids = [...$ids, ...array_column($page->json('items'), 'receipt_id')];
            $next = $page->json('next_cursor');
        }
        $this->assertNull($next);
        $this->assertCount(13, array_unique($ids));
        $this->getJson($this->url($client, $deal, ['cursor' => $cursor, 'amount_minor' => 5]))->assertUnprocessable();
        $this->getJson($this->url($client, $deal, ['cursor' => 'forged']))->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson($this->url($client, $deal, ['cursor' => $cursor]))->assertUnprocessable();
    }

    public function test_inaccessible_shared_account_and_staff_role_do_not_leak_payer_data(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        $other = Platform::factory()->create();
        DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $other->id, 'valid_from' => now()->subDay(), 'destination_snapshot' => '{}']);
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'assigned_market_ids' => [$client->platform_id]]));
        $this->getJson($this->url($client, $deal))->assertOk()->assertJsonCount(0, 'items');
        Sanctum::actingAs(User::factory()->create(['role' => 'marketing', 'assigned_market_ids' => [$client->platform_id]]));
        $this->getJson($this->url($client, $deal))->assertForbidden();
    }

    public function test_incomplete_history_is_disclosed_even_with_a_supported_phone_candidate(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        $this->mock(\App\Services\Collections\CollectionPaymentHistoryService::class, function ($mock) use ($client) {
            $mock->shouldReceive('forReceipt')->once()->andReturn(['scope' => ['platform_ids' => [$client->platform_id]],
                'client_counts' => [], 'identity_conflict' => false, 'complete' => false]);
        });
        $this->getJson($this->url($client, $deal))->assertOk()->assertJsonPath('coverage', 'partial')
            ->assertJsonPath('items.0.coverage', 'partial')->assertJsonPath('items.0.possible_profiles.0.id', $client->id);
    }

    public function test_all_supported_profiles_are_returned_without_a_three_profile_limit(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        $profiles = Client::factory()->count(4)->create(['platform_id' => $client->platform_id])->push($client);
        $this->mock(CollectionMatchService::class, function ($mock) use ($profiles) {
            $mock->shouldReceive('evidence')->andReturn(['clients' => $profiles->keyBy('id'),
                'suggestions' => $profiles->map(fn ($p) => ['id' => $p->id, 'score' => 0, 'role' => 'alternative', 'reasons' => ['Supported alternative.']])->all()]);
        });
        $this->getJson($this->url($client, $deal))->assertOk()->assertJsonCount(5, 'items.0.possible_profiles');
    }

    public function test_ten_receipts_with_paid_history_have_a_bounded_query_budget(): void
    {
        [$account, $client, $receipt, $deal] = $this->fixture();
        for ($i = 1; $i < 10; $i++) {
            $copy = $receipt->replicate();
            $copy->reference_norm = 'BUDGET'.$i;
            $copy->save();
        }
        $template = Payment::factory()->make(['platform_id' => $client->platform_id, 'client_id' => $client->id,
            'deal_id' => null, 'phone' => '255700000001', 'status' => 'completed', 'currency' => 'TZS', 'amount' => 35000,
            'created_at' => now()->subDays(3), 'completed_at' => now()->subDays(3), 'payment_data' => [],
            'record_classification' => 'live', 'provider_environment' => 'production'])->getAttributes();
        $history = [];
        for ($i = 0; $i < 500; $i++) {
            $history[] = array_replace($template, ['transaction_uuid' => 'budget-uuid-'.$i, 'transaction_reference' => 'BUDGETOLD'.$i,
                'reference_number' => 'BUDGETOLD'.$i, 'reference_root' => 'BUDGETOLD'.$i]);
        }
        DB::table('payments')->insert($history);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $result = $this->getJson($this->url($client, $deal))->assertOk();
        $this->assertNotEmpty($result->json('items'));
        $this->assertLessThanOrEqual(10, $result->json('scanned_receipts'));
        $elapsed = microtime(true) - $start;
        $count = count(DB::getQueryLog());
        $this->assertLessThan(500, $count, 'Suggestion page must not issue per-payment history queries.');
        fwrite(STDERR, sprintf("\nSuggestion benchmark: 10 queued receipts/500 paid history rows, %d scanned, %d queries, %.3fs\n", $result->json('scanned_receipts'), $count, $elapsed));
        $this->assertLessThan(10, $elapsed, 'Suggestion lookup must yield between expensive receipt reads.');
    }
}
