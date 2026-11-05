<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Client;
use App\Models\ManualPaymentBundle;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyPaymentReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $market = Platform::factory()->create();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$market->id]]);
        $client = Client::factory()->create(['platform_id' => $market->id]);
        $payment = Payment::create(['platform_id' => $market->id, 'client_id' => $client->id, 'confirmed_by' => $sales->id, 'amount' => 35000, 'currency' => 'TZS', 'purpose' => 'subscription', 'source' => 'manual_confirmation', 'status' => 'completed', 'transaction_reference' => 'REVIEW-'.Str::random(10), 'completed_at' => now()]);
        Sanctum::actingAs($admin);

        return [$market, $admin, $sales, $payment];
    }

    private function row(Payment $p): array
    {
        return $this->getJson('/api/crm/reconciliations/payment/'.$p->id)->assertOk()->json('unit');
    }

    private function preview(array $row, string $action, array $extra = [])
    {
        return $this->postJson('/api/crm/reconciliations/decisions/preview', array_merge(['units' => [$row], 'action' => $action, 'reason' => 'Daily record checked'], $extra));
    }

    private function decide(array $row, string $action, array $extra = []): array
    {
        $p = $this->preview($row, $action, $extra)->assertOk()->json();

        return $this->postJson('/api/crm/reconciliations/decisions', ['preview_token' => $p['preview_token'], 'idempotency_key' => (string) Str::uuid()])->assertOk()->json();
    }

    public function test_daily_approval_and_denial_only_write_the_review_ledger(): void
    {
        [,,,$payment] = $this->fixture();
        $before = $payment->fresh()->getRawOriginal();
        $row = $this->row($payment);
        $this->assertArrayNotHasKey('_snapshot', $row);
        $this->decide($row, 'approve');
        $this->assertSame('approved', $this->row($payment)['review_status']);
        $this->decide($this->row($payment), 'deny');
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertSame('denied', $this->row($payment)['review_status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_review_events', 2);
    }

    public function test_maker_checker_and_reasoned_admin_exception(): void
    {
        [$m,$admin,$sales,$p] = $this->fixture();
        DB::table('payment_review_grants')->insert(['user_id' => $sales->id, 'platform_id' => $m->id, 'capability' => 'review', 'granted_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($sales);
        $this->assertNotEmpty($this->preview($this->row($p), 'approve')->assertOk()->json('blocked'));
        $p->update(['confirmed_by' => $admin->id]);
        Sanctum::actingAs($admin);
        $this->assertNotEmpty($this->preview($this->row($p), 'approve')->assertOk()->json('blocked'));
        $this->decide($this->row($p), 'approve', ['self_approval_reason' => 'Admin recorded this receipt; proof checked.']);
        $this->assertTrue($this->row($p)['self_approval']);
    }

    public function test_stale_preview_fails_atomically_and_completed_retry_is_idempotent(): void
    {
        [,,,$p] = $this->fixture();
        $preview = $this->preview($this->row($p), 'approve')->assertOk()->json();
        $p->update(['amount' => 36000]);
        $this->postJson('/api/crm/reconciliations/decisions', ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('daily_payment_reviews', 0);
        $preview = $this->preview($this->row($p), 'approve')->assertOk()->json();
        $args = ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) Str::uuid()];
        $first = $this->postJson('/api/crm/reconciliations/decisions', $args)->assertOk()->json();
        $this->assertEquals($first, $this->postJson('/api/crm/reconciliations/decisions', $args)->assertOk()->json());
        $this->assertDatabaseCount('payment_review_events', 1);
    }

    public function test_changed_source_invalidates_approval_and_flag_blocks_it(): void
    {
        [,,,$p] = $this->fixture();
        $this->decide($this->row($p), 'approve');
        $p->update(['amount' => 37000]);
        $this->assertSame('pending', $this->row($p)['review_status']);
        $this->decide($this->row($p), 'flag');
        $this->assertNotEmpty($this->preview($this->row($p), 'approve')->assertOk()->json('blocked'));
        $this->decide($this->row($p), 'clear_flag');
        $this->decide($this->row($p), 'approve');
        $this->assertSame('approved', $this->row($p)['review_status']);
    }

    public function test_sales_correction_round_trip_and_no_cross_owner_access(): void
    {
        [$m,$admin,$sales,$p] = $this->fixture();
        $this->decide($this->row($p), 'request_correction', ['assigned_to' => $sales->id, 'reason_code' => 'evidence', 'required_fields' => ['evidence']]);
        Sanctum::actingAs($sales);
        $inbox = $this->getJson('/api/crm/reconciliations/my-corrections')->assertOk()->json('data.0');
        $this->postJson('/api/crm/reconciliations/corrections/'.$inbox['request']['id'].'/resubmit', ['version' => 0, 'source_digest' => $inbox['unit']['source_digest'], 'response' => 'Checked; reference is legible in the original receipt.', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $other = User::factory()->create(['role' => 'sales', 'assigned_market_ids' => [$m->id], 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/crm/reconciliations/payment/'.$p->id)->assertForbidden();
        Sanctum::actingAs($admin);
        $this->assertSame('resubmitted', $this->row($p)['review_status']);
        $this->decide($this->row($p), 'approve');
        $this->assertDatabaseHas('payment_correction_requests', ['status' => 'resolved', 'open_review_id' => null]);
    }

    public function test_native_totals_exclude_wallet_and_free_records_and_export_requires_its_grant(): void
    {
        [$m,$admin,$sales,$p] = $this->fixture();
        $copy = $p->replicate();
        $copy->transaction_reference = 'WALLET-TEST';
        $copy->source = 'wallet';
        $copy->save();
        $copy = $p->replicate();
        $copy->transaction_reference = 'ZERO-TEST';
        $copy->amount = 0;
        $copy->save();
        $result = $this->getJson('/api/crm/reconciliations?channel=all&date=2026-11-05')->assertOk()->json();
        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('35000.00', $result['summary']['totals'][0]['recorded']);
        $this->get('/api/crm/reconciliations/export?date=2026-11-05')->assertOk();
        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/reconciliations/payment/'.$p->id)->assertForbidden();
    }

    public function test_bundle_is_one_receipt_with_full_market_scope(): void
    {
        [$m,$admin,$sales,$p] = $this->fixture();
        $bundle = ManualPaymentBundle::create(['platform_id' => $m->id, 'reference_root' => 'BUNDLE-TEST', 'total_amount' => 70000, 'allocated_amount' => 70000, 'unallocated_amount' => 0, 'currency' => 'TZS', 'status' => 'committed', 'audit_state' => 'pending_finance_review', 'created_by' => $sales->id, 'idempotency_key' => (string) Str::uuid()]);
        $p->update(['manual_payment_bundle_id' => $bundle->id]);
        $p2 = $p->replicate();
        $p2->transaction_reference = 'SECOND-ALLOCATION';
        $p2->save();
        $this->getJson('/api/crm/reconciliations?date=2026-11-05')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', '70000.00');
        $this->getJson('/api/crm/reconciliations/payment/'.$p->id)->assertUnprocessable();
        $u = $this->getJson('/api/crm/reconciliations/bundle/'.$bundle->id)->assertOk()->json('unit');
        $this->decide($u, 'approve');
        $this->assertSame('pending_finance_review', $bundle->fresh()->audit_state);
    }

    public function test_audit_failure_rolls_back_every_decision_and_source_changes_cannot_be_cleared_into_approval(): void
    {
        [,,,$p] = $this->fixture();
        $this->decide($this->row($p), 'approve');
        $p->update(['amount' => 39000]);
        $this->decide($this->row($p), 'clear_flag');
        $this->assertSame('pending', $this->row($p)['review_status']);
        $this->mock(\App\Services\AuditService::class, function ($mock) {
            $mock->shouldReceive('record')->andReturn(null);
        });
        $preview = $this->preview($this->row($p), 'deny')->assertOk()->json();
        $this->postJson('/api/crm/reconciliations/decisions', ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) Str::uuid()])->assertStatus(500);
        $this->assertSame('pending', $this->row($p)['review_status']);
        $this->assertDatabaseCount('payment_review_events', 3);
    }

    public function test_canonical_financial_correction_is_required_before_sales_resubmission(): void
    {
        [,,$sales,$p] = $this->fixture();
        $this->decide($this->row($p), 'request_correction', ['assigned_to' => $sales->id, 'required_fields' => ['amount']]);
        Sanctum::actingAs($sales);
        $inbox = $this->getJson('/api/crm/reconciliations/my-corrections')->assertOk()->json('data.0');
        $this->postJson('/api/crm/reconciliations/corrections/'.$inbox['request']['id'].'/resubmit', ['version' => 0, 'source_digest' => $inbox['unit']['source_digest'], 'response' => 'Only explaining the wrong amount.', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame('completed', $p->fresh()->status);
    }

    public function test_private_proof_stream_is_scoped_and_assignment_revocation_takes_effect(): void
    {
        [$m,$admin,$sales,$p] = $this->fixture();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->decide($this->row($p), 'request_correction', ['assigned_to' => $sales->id, 'required_fields' => ['evidence']]);
        Sanctum::actingAs($sales);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $id = $this->post('/api/crm/reconciliations/payment/'.$p->id.'/attachments', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('attachment.id');
        $url = '/api/crm/reconciliations/payment/'.$p->id.'/evidence/attachment/'.$id;
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$m->id]]);
        Sanctum::actingAs($admin);
        $r = \App\Models\PaymentCorrectionRequest::first();
        $this->postJson('/api/crm/reconciliations/corrections/'.$r->id.'/reassign', ['version' => $r->version, 'assigned_to' => $other->id, 'reason' => 'Coverage reassigned.'])->assertOk();
        Sanctum::actingAs($sales);
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($other);
        $this->get($url)->assertOk();
    }

    public function test_access_changes_require_a_current_version_and_export_does_not_silently_omit_markets(): void
    {
        [$m,$admin,$sales,$p] = $this->fixture();
        $payload = ['expected_version' => 0, 'reason' => 'Accounting review coverage.', 'grants' => [['platform_id' => $m->id, 'capability' => 'review']]];
        $this->putJson('/api/crm/reconciliations/grants/'.$sales->id, $payload)->assertOk();
        $this->putJson('/api/crm/reconciliations/grants/'.$sales->id, $payload)->assertConflict();
        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/reconciliations/export?date=2026-11-05')->assertForbidden();
    }

    public function test_history_retains_reclassified_cash_without_inflating_recorded_totals(): void
    {
        [,,,$p] = $this->fixture();
        $this->decide($this->row($p), 'approve');
        $p->update(['record_classification' => 'test']);
        $this->getJson('/api/crm/reconciliations?date=2026-11-05')->assertOk()->assertJsonPath('meta.total', 0);
        $history = $this->getJson('/api/crm/reconciliations?history=1&date=2026-11-05')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.review_status', 'pending')->json();
        $this->assertSame([], $history['summary']['totals']);
        $this->assertNotEmpty($history['data'][0]['blockers']);
    }

    public function test_revoked_scope_is_checked_before_returning_idempotent_result(): void
    {
        [$m,$admin,$sales,$p] = $this->fixture();
        $reviewer = User::factory()->create(['role' => 'sub_admin', 'assigned_market_ids' => [$m->id], 'status' => 'active']);
        DB::table('payment_review_grants')->insert(['user_id' => $reviewer->id, 'platform_id' => $m->id, 'capability' => 'review', 'granted_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($reviewer);
        $preview = $this->preview($this->row($p), 'approve')->assertOk()->json();
        $args = ['preview_token' => $preview['preview_token'], 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/crm/reconciliations/decisions', $args)->assertOk();
        DB::table('payment_review_grants')->delete();
        $this->postJson('/api/crm/reconciliations/decisions', $args)->assertForbidden();
    }
}
