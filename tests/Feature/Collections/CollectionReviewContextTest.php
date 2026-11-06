<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionReceipt;
use App\Models\ManualPaymentBundle;
use App\Models\Payment;
use App\Models\User;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionPayerEvidenceService;
use App\Services\Collections\CollectionReceiptService;
use App\Services\Collections\TzMpesaParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class CollectionReviewContextTest extends CollectionWorkflowTestCase
{
    private function fixture(): array
    {
        $fixture = $this->purchase(false);
        [$account, $device, $client, $intent, $actor] = $fixture;
        $account->update(['mode' => 'shadow']);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($actor);

        return [...$fixture, $this->received($device)];
    }

    private function payment(Client $client, array $attributes = []): Payment
    {
        return Payment::withoutEvents(fn () => Payment::factory()->create($attributes + ['platform_id' => $client->platform_id,
            'product_id' => \App\Models\Product::where('platform_id', $client->platform_id)->value('id'), 'client_id' => $client->id, 'phone' => '255700000001', 'currency' => 'TZS', 'amount' => '35000.00',
            'completed_at' => now()->subDay(), 'created_at' => now()->subDay()]));
    }

    private function path(CollectionReceipt $r): string
    {
        return '/api/crm/collections/receipts/'.$r->id.'/review-context';
    }

    private function input($r, $client, $price): array
    {
        return ['client_id' => $client->id, 'product_price_id' => $price->id, 'request_key' => (string) Str::uuid(), 'expected_version' => $r->version];
    }

    public function test_full_sms_payer_is_explicit_and_read_endpoints_have_no_business_writes(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $before = $this->counts();
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('data.0.payer.name', 'FIXTURE PAYER')
            ->assertJsonPath('data.0.payer.phone', '+255700000001')->assertJsonPath('data.0.captured_payer_receipts', 1);
        $this->getJson('/api/crm/collections/receipts/'.$r->id)->assertOk()->assertJsonPath('payer.name', 'FIXTURE PAYER')->assertJsonMissingPath('payer_phone');
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 0)->assertJsonPath('suggestions.0.score', 95)
            ->assertJsonPath('recommended_client_id', $client->id)->assertJsonPath('can_activate', false)->assertJsonPath('capture_only', true);
        $this->assertSame($before, $this->counts());
        $this->assertArrayNotHasKey('payer_phone', $r->toArray());
        $this->assertArrayNotHasKey('normalized', DB::table('collection_events')->exists() ? \App\Models\Collections\CollectionEvent::first()->toArray() : []);
    }

    public function test_name_only_bank_detail_and_conflicting_names_have_honest_display(): void
    {
        $parser = app(TzMpesaParser::class);
        $bank = 'ABCDEF12345 confirmed. You have received a payment of Tsh35,000.00 from 123456 - FIXTURE BANK on 30/10/26 at 14:59. New M-Pesa balance Tsh100,000.00';
        $this->assertNull($parser->payerDetails('M-PESA', $bank)['name']);
        $detail = 'ABCDEF12345 imethibitishwa. Umepokea Tshs 35,000.00 kutoka BANK : 255700000001 - FULL EXAMPLE PAYER NAME tarehe 30/10/2026 saa 14:59:30.';
        $this->assertSame('FULL EXAMPLE PAYER NAME', $parser->payerDetails('M-PESA', $detail)['name']);
        $nameOnly = 'ABCDEF12345 Confirmed. On 30/10/26, 2:59 PM Receive Tsh35,000 from FULL EXAMPLE PAYER NAME and balance Tsh900,000.';
        $this->assertSame('FULL EXAMPLE PAYER NAME', $parser->payerDetails('M-PESA', $nameOnly)['name']);
        $this->assertNull($parser->payerDetails('PERSONAL', $detail)['name']);
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope($detail, 'bank-detail'));
        app(CollectionReceiptService::class)->process($event['event_id']);
        $payer = app(CollectionPayerEvidenceService::class)->forReceipt($r->fresh());
        $this->assertNull($payer['name']);
        $this->assertSame('conflicting', $payer['name_state']);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('recommended_client_id', null);
    }

    public function test_phone_history_normalizes_and_excludes_non_cash_test_and_current_transactions(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        foreach (['+255 700 000 001', '0700000001', '255700000001'] as $phone) {
            $this->payment($client, ['phone' => $phone]);
        }
        foreach ([['status' => 'failed'], ['amount' => 0], ['record_classification' => 'test'], ['provider_environment' => 'sandbox'],
            ['payment_data' => ['test_mode' => true]], ['purpose' => 'tour_pass'], ['purpose' => 'wallet_topup'], ['purpose' => 'visitor_contact_unlock'],
            ['resolution_code' => 'reversed'], ['reconciliation_state' => 'manual_review'], ['completed_at' => now()->addDay()],
            ['phone' => '255700000002'], ['transaction_reference' => $r->reference_norm]] as $attrs) {
            $this->payment($client, $attrs);
        }
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 3)->assertJsonPath('history.crm_count', 3)
            ->assertJsonPath('history.complete', true)->assertJsonPath('history.observed_only_count', 0);
    }

    public function test_captured_and_crm_same_reference_count_once_but_additional_receipt_is_visible(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        foreach (['PRIOR000001', 'PRIOR000002'] as $ref) {
            CollectionReceipt::create(['account_id' => $account->id, 'reference_raw' => $ref, 'reference_norm' => $ref,
                'payer_phone' => $r->payer_phone, 'payer_fingerprint' => $r->payer_fingerprint, 'principal_minor' => 3500000, 'currency' => 'TZS',
                'provider_at' => now()->subDays(2), 'enrichment_deadline' => now(), 'historical_only' => false, 'conflicts' => []]);
        }
        $this->payment($client, ['transaction_reference' => 'PRIOR000001']);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 2)->assertJsonPath('history.crm_count', 1)
            ->assertJsonPath('history.observed_only_count', 1);
    }

    public function test_shared_payer_never_preselects_the_more_frequent_profile(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $other = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 78, 'wp_user_id' => 56]);
        $this->payment($client);
        $this->payment($client);
        $this->payment($other);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('recommended_client_id', null)->assertJsonPath('needs_choice', true)
            ->assertJsonCount(2, 'suggestions')->assertJsonPath('suggestions.0.score', 49);
    }

    public function test_complete_repeat_history_and_unique_advert_phone_have_explained_scores(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        CollectionPayerLink::where('account_id', $account->id)->update(['revoked_at' => now()]);
        $client->update(['phone_normalized' => '255700000001']);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('suggestions.0.score', 80)->assertJsonPath('recommended_client_id', $client->id);
        for ($i = 0; $i < 3; $i++) {
            $this->payment($client);
        }
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('suggestions.0.score', 90);
        config(['collection.review_history_scan_limit' => 1]);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.complete', false)->assertJsonPath('history.prior_count', 1)
            ->assertJsonPath('recommended_client_id', null)->assertJsonPath('suggestions.0.score', 49);
    }

    public function test_a_committed_multi_profile_cash_bundle_counts_once_and_blocks_preselection(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $other = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 78, 'wp_user_id' => 56]);
        $bundle = ManualPaymentBundle::create(['platform_id' => $client->platform_id, 'reference_root' => 'BUNDLE00001', 'total_amount' => 70000,
            'allocated_amount' => 70000, 'unallocated_amount' => 0, 'currency' => 'TZS', 'reason' => 'Fixture split', 'status' => 'committed',
            'audit_state' => 'resolved', 'idempotency_key' => (string) Str::uuid(), 'created_by' => $actor->id]);
        foreach ([$client, $other] as $i => $c) {
            $this->payment($c, ['manual_payment_bundle_id' => $bundle->id, 'reference_root' => 'BUNDLE00001', 'reference_sequence' => $i + 1]);
        }
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 1)->assertJsonPath('history.items.0.amount_minor', 7000000)
            ->assertJsonPath('recommended_client_id', null)->assertJsonPath('needs_choice', true);
        $bundle->update(['status' => 'committing']);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 0);
    }

    public function test_current_bundle_and_ambiguous_duplicate_cash_roots_are_excluded(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->payment($client, ['transaction_reference' => 'DUPREF00001']);
        $this->payment($client, ['transaction_reference' => 'DUPREF00001']);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 0)->assertJsonPath('history.complete', false)
            ->assertJsonPath('recommended_client_id', null);
        Payment::query()->update(['payment_data' => json_encode(['collection' => ['account_id' => $account->id]])]);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 1);
    }

    public function test_history_cursor_is_private_scoped_and_returns_the_next_page(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        for ($i = 0; $i < 12; $i++) {
            $this->payment($client);
        }
        $page = $this->getJson($this->path($r))->assertOk()->assertJsonPath('history.prior_count', 12)->assertJsonCount(10, 'history.items');
        $cursor = $page->json('history.next_cursor');
        $this->getJson($this->path($r).'?cursor='.urlencode($cursor))->assertOk()->assertJsonCount(2, 'history.items');
        $other = $this->received($device, 'OTHER000001');
        $this->getJson($this->path($other).'?cursor='.urlencode($cursor))->assertStatus(422);
        $this->getJson($this->path($r).'?cursor=forged')->assertStatus(422);
    }

    public function test_shadow_choice_is_atomic_idempotent_and_does_not_activate(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $input = $this->input($r, $client, $price) + ['remember_payer' => true];
        $path = '/api/crm/collections/receipts/'.$r->id.'/intents';
        $first = $this->postJson($path, $input)->assertCreated()->assertJsonPath('intent.max_renewals', 1)->assertJsonPath('intent.authority', 'reviewed_quote');
        $counts = $this->counts();
        $again = $this->postJson($path, $input)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertSame($first->json('intent.id'), $again->json('intent.id'));
        $this->assertSame($counts, $this->counts());
        $this->assertSame(0, DB::table('collection_allocations')->count());
        $this->assertSame(0, DB::table('collection_benefit_effects')->count());
        $this->assertSame(0, $this->posts);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('saved_choice.valid', true)->assertJsonPath('can_activate', false);
        $this->postJson($path, array_replace($input, ['remember_payer' => false]))->assertConflict()->assertJsonPath('reason', 'review_request_key_reused');
    }

    public function test_failed_receipt_amount_or_profile_validation_rolls_back_the_checked_payer_link(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $input = $this->input($r, $client, $price) + ['remember_payer' => true];
        $before = $this->counts();
        $price->update(['price' => '40000.00']);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/intents', $input)->assertStatus(422);
        $this->assertSame($before, $this->counts());
        $price->update(['price' => '35000.00']);
        $client->update(['profile_paused_at' => now()]);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/intents', $input)->assertStatus(422);
        $this->assertSame($before, $this->counts());
    }

    public function test_stale_save_and_unconfirmed_replacement_preserve_the_saved_choice(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $path = '/api/crm/collections/receipts/'.$r->id.'/intents';
        $this->postJson($path, array_replace($this->input($r, $client, $price), ['expected_version' => 99]))->assertConflict();
        $saved = $this->postJson($path, $this->input($r, $client, $price))->assertCreated()->json('intent.id');
        $r->refresh();
        $this->postJson($path, $this->input($r, $client, $price))->assertConflict()->assertJsonPath('reason', 'saved_choice_replacement_confirmation_required');
        $this->postJson($path, $this->input($r, $client, $price) + ['replace_saved_choice' => true])->assertCreated();
        $this->assertNotNull(\App\Models\Collections\CollectionPurchaseIntent::find($saved)->revoked_at);
    }

    public function test_read_and_search_recheck_staff_market_scope_and_revoke_old_suggestions(): void
    {
        [$account, $device, $client, $intent, $actor, $price, $r] = $this->fixture();
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('recommended_client_id', $client->id);
        CollectionPayerLink::where('account_id', $account->id)->update(['revoked_at' => now()]);
        $this->getJson($this->path($r))->assertOk()->assertJsonPath('recommended_client_id', null);
        $sales = User::factory()->create(['role' => 'sales', 'assigned_market_ids' => [], 'status' => 'active']);
        Sanctum::actingAs($sales);
        $this->getJson($this->path($r))->assertNotFound();
        $this->getJson('/api/crm/collections/receipts/'.$r->id.'/candidates?selected_client_id='.$client->id)->assertNotFound();
    }

    private function counts(): array
    {
        return collect(['collection_purchase_intents', 'collection_payer_links', 'collection_receipts', 'collection_events', 'collection_audit',
            'collection_allocations', 'collection_benefit_effects', 'payments'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }
}
