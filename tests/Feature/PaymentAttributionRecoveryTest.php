<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentAttribution;
use App\Models\PaymentRecoveryCase;
use App\Models\Platform;
use App\Models\User;
use App\Services\PaymentAttributionService;
use App\Services\PaymentRecoveryCaseService;
use App\Services\PaymentRecoveryEvidencePolicy;
use App\Services\Revenue\CustomerMixSegments;
use App\Services\Revenue\PaymentAttributionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentAttributionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        User::factory()->create(['role' => 'admin']);
    }

    private function receipt(array $extra = []): Payment
    {
        $m = Platform::factory()->create();

        return Payment::factory()->create(array_merge(['platform_id' => $m->id, 'user_id' => null, 'client_id' => null, 'deal_id' => null, 'product_id' => null,
            'purpose' => 'subscription', 'amount' => 750, 'currency' => 'KES', 'status' => 'completed', 'provider_environment' => 'production', 'provider_key' => 'kopokopo', 'record_classification' => 'live', 'completed_at' => now()], $extra));
    }

    public function test_archived_attribution_survives_deleted_customer_and_counts_subject_once(): void
    {
        $p = $this->receipt();
        $c = Client::factory()->create(['platform_id' => $p->platform_id, 'created_at' => now()->subMonths(2)]);
        $p->update(['client_id' => $c->id]);
        $p2 = $this->receipt(['platform_id' => $p->platform_id, 'client_id' => $c->id]);
        app(PaymentAttributionService::class)->archiveClient($c);
        \Illuminate\Support\Facades\DB::table('payments')->whereIn('id', [$p->id, $p2->id])->update(['client_id' => null]);
        $c->delete();
        $q = Payment::whereIn('id', [$p->id, $p2->id]);
        $this->assertSame(2, PaymentAttributionQuery::matched(clone $q)->count());
        $this->assertSame(1, PaymentAttributionQuery::clientsCount(clone $q));
        $this->assertSame(0, CustomerMixSegments::apply(clone $q, 'unattributed', now()->subMonth(), now())->count());
        $this->assertSame(2, CustomerMixSegments::apply(clone $q, 'existing_active', now()->subMonth(), now())->count());
        $this->assertSame(2, PaymentAttributionQuery::state(clone $q, 'archived')->count());
        Http::assertNothingSent();
    }

    public function test_snapshot_conflict_never_falls_back_to_current_phone_or_counts_as_known(): void
    {
        $p = $this->receipt();
        $one = Client::factory()->create(['platform_id' => $p->platform_id]);
        $two = Client::factory()->create(['platform_id' => $p->platform_id]);
        $p->update(['client_id' => $one->id]);
        $p->update(['client_id' => $two->id]);
        $this->assertSame('disputed', PaymentAttribution::find($p->id)->attribution_state);
        $this->assertSame(0, PaymentAttributionQuery::matched(Payment::whereKey($p->id))->count());
        $this->assertSame(1, CustomerMixSegments::apply(Payment::whereKey($p->id), 'unattributed', now()->subMonth(), now())->count());
    }

    public function test_paid_linked_receipt_keeps_one_case_until_verified_benefit(): void
    {
        $p = $this->receipt();
        $c = Client::factory()->create(['platform_id' => $p->platform_id]);
        $ref = $p->recoveryCase->public_reference;
        $p->update(['client_id' => $c->id]);
        app(PaymentRecoveryCaseService::class)->synchronize($p->fresh());
        $this->assertSame(1, PaymentRecoveryCase::where('payment_id', $p->id)->count());
        $this->assertSame('settlement_review', $p->fresh()->recoveryCase->state);
        $deal = Deal::factory()->create(['platform_id' => $p->platform_id, 'client_id' => $c->id, 'payment_id' => $p->id, 'activated_at' => now(), 'expires_at' => now()->addWeek(), 'status' => 'active']);
        $case = $p->fresh()->recoveryCase;
        $this->assertSame('closed', $case->state);
        $this->assertSame($ref, $case->public_reference);
        $this->assertSame('fulfilled', $case->disposition);
        Http::assertNothingSent();
    }

    public function test_pending_underpayment_without_provider_attempt_is_visible_and_cannot_fulfill(): void
    {
        $p = $this->receipt(['status' => 'pending']);
        $policy = app(PaymentRecoveryEvidencePolicy::class);
        $assessment = ['settled_amount' => 500, 'settled_currency' => 'KES', 'completion_policy' => 'hold_for_review', 'review_required' => true];
        $auth = ['account' => 'fixture-account', 'environment' => 'production', 'transaction_id' => 'fixture-txn', 'verification_method' => 'verified_signature'];
        $policy->observe($p, $assessment, $auth);
        $policy->observe($p, $assessment, $auth);
        $case = app(PaymentRecoveryCaseService::class)->synchronize($p);
        $this->assertSame('settlement_review', $case->state);
        $this->assertSame('settlement_review', $policy->classify($p)['kind']);
        $this->assertFalse($policy->canFulfill($p));
        $this->assertDatabaseCount('payment_receipt_observations', 1);
        $this->assertDatabaseCount('deals', 0);
        Http::assertNothingSent();
    }

    public function test_proof_sandbox_and_reversal_do_not_become_accepted_cash(): void
    {
        $p = $this->receipt(['provider_environment' => 'sandbox']);
        $this->assertSame('excluded', app(PaymentRecoveryEvidencePolicy::class)->classify($p)['kind']);
        $this->assertNull($p->recoveryCase);
        $p2 = $this->receipt(['resolution_code' => 'reversed']);
        $this->assertSame('exception', app(PaymentRecoveryEvidencePolicy::class)->classify($p2)['kind']);
        $this->assertFalse(app(PaymentRecoveryEvidencePolicy::class)->canFulfill($p2));
    }

    public function test_recovery_case_market_scope_idempotency_and_closure_evidence(): void
    {
        $p = $this->receipt();
        $other = $this->receipt();
        $staff = User::factory()->create(['role' => 'sub_admin', 'assigned_market_ids' => [$p->platform_id]]);
        \Laravel\Sanctum\Sanctum::actingAs($staff);
        $this->getJson('/api/crm/payment-recovery-cases')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.payment_id', $p->id);
        $body = ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'version' => $p->recoveryCase->version, 'action' => 'assign', 'assigned_to' => $staff->id, 'reason' => 'Reviewing received payment.'];
        $url = '/api/crm/payment-recovery-cases/'.$p->recoveryCase->id;
        $first = $this->patchJson($url, $body)->assertOk();
        $this->patchJson($url, $body)->assertOk()->assertJsonPath('version', $first->json('version'));
        $body['reason'] = 'A different requested operation.';
        $this->patchJson($url, $body)->assertStatus(409);
        $body['request_key'] = (string) \Illuminate\Support\Str::uuid();
        $this->patchJson($url, $body)->assertStatus(409);
        $body['version'] = $first->json('version');
        $body['action'] = 'record_disposition';
        $body['disposition'] = 'fulfilled';
        $this->patchJson($url, $body)->assertStatus(422);
        $this->patchJson('/api/crm/payment-recovery-cases/'.$other->recoveryCase->id, $body)->assertNotFound();
    }

    public function test_authenticated_duplicate_transaction_is_held_and_never_counted_twice(): void
    {
        $p = $this->receipt();
        $second = $this->receipt(['platform_id' => $p->platform_id]);
        $assessment = ['settled_amount' => 750, 'settled_currency' => 'KES', 'completion_policy' => 'allow_completion', 'review_required' => false];
        $auth = ['account' => 'account', 'environment' => 'production', 'transaction_id' => 'one-real-transfer', 'verification_method' => 'verified_signature'];
        $policy = app(PaymentRecoveryEvidencePolicy::class);
        $this->assertTrue($policy->observe($p, $assessment, $auth));
        $this->assertFalse($policy->observe($second, $assessment, $auth));
        $this->assertDatabaseCount('payment_receipt_observations', 1);
        $this->assertSame('disputed', $policy->classify($p)['kind']);
        $this->assertSame('disputed', $policy->classify($second)['kind']);
        $this->assertFalse($policy->canFulfill($second));
        Http::assertNothingSent();
    }

    public function test_reviewed_receiving_account_and_import_need_exact_authorized_evidence(): void
    {
        foreach (['receiving_account_statement', 'reviewed_import'] as $source) {
            $p = $this->receipt();
            $actor = User::where('role', 'admin')->first();
            $audit = \App\Models\AuditLog::create(['platform_id' => $p->platform_id, 'actor_id' => $actor->id, 'entity_type' => 'payment', 'entity_id' => $p->id, 'action' => 'payment_receipt_verified', 'after_state' => ['source' => $source, 'amount' => 750, 'currency' => 'KES', 'transaction_id' => 'transfer-'.$p->id, 'account_digest' => hash('sha256', 'fixture-account'), 'evidence_sha256' => hash('sha256', 'fixture-statement')], 'reason' => 'Reviewed account statement.', 'created_at' => now()]);
            app(PaymentRecoveryEvidencePolicy::class)->recordReviewedReceipt($p, $audit->id);
            $this->assertSame('accepted_cash', app(PaymentRecoveryEvidencePolicy::class)->classify($p)['kind']);
            $this->assertFalse(app(PaymentRecoveryEvidencePolicy::class)->canFulfill($p));
        }
        Http::assertNothingSent();
    }

    public function test_owner_status_requires_signature_and_exact_beneficiary_even_with_wallet_disabled(): void
    {
        config(['services.exotic_crm_sync.shared_key' => 'fixture-key', 'billing.wallet.enabled' => false]);
        $p = $this->receipt(['user_id' => 55, 'escort_post_id' => 77]);
        $p->platform->update(['wp_api_url' => 'https://fixture.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        Http::fake(['*' => Http::response(['wp_user_id' => 55])]);
        $body = ['payment_id' => $p->id, 'wp_user_id' => 55, 'wp_post_id' => 77];
        $url = '/api/payment-recovery/status';
        $this->postJson($url, $body)->assertUnauthorized();
        $signed = function ($body) use ($p) {
            $time = time();

            return ['X-Exotic-CRM-Sync-Key' => 'fixture-key', 'X-Exotic-Platform-Id' => $p->platform_id, 'X-Exotic-Timestamp' => $time, 'X-Exotic-Signature' => hash_hmac('sha256', $time.'.'.json_encode($body), 'fixture-key')];
        };
        $this->postJson($url, $body, $signed($body))->assertOk()->assertJsonPath('case_reference', $p->recoveryCase->public_reference)->assertJsonMissing(['phone' => $p->phone]);
        $body['wp_user_id'] = 56;
        $this->postJson($url, $body, $signed($body))->assertNotFound();
    }

    public function test_backfill_and_exact_restore_preserve_receipt(): void
    {
        $p = $this->receipt();
        $c = Client::factory()->create(['platform_id' => $p->platform_id]);
        $p->update(['client_id' => $c->id]);
        PaymentAttribution::whereKey($p->id)->delete();
        $before = $p->fresh()->getAttributes();
        $this->artisan('crm:backfill-payment-attribution', ['--after' => $p->id - 1, '--limit' => 1])->assertSuccessful();
        $this->assertNull(PaymentAttribution::find($p->id));
        $proposal = [['payment_id' => $p->id, 'before' => $before, 'after' => ['platform_id' => $p->platform_id, 'original_client_id' => $c->id, 'wp_user_id' => $c->wp_user_id, 'wp_post_id' => $c->wp_post_id, 'client_first_seen_at' => $c->created_at, 'attribution_state' => 'current', 'evidence_json' => ['method' => 'verified_current_backfill', 'deal_id' => $p->deal_id]]]];
        $hash = hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR));
        $this->artisan('crm:backfill-payment-attribution', ['--after' => $p->id - 1, '--limit' => 1, '--apply' => true, '--expected' => $hash])->assertSuccessful();
        $paths = glob(storage_path('app/private/payment-attribution-backups/*.json'));
        usort($paths, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $path = collect($paths)->first(fn ($f) => str_contains(file_get_contents($f), $hash));
        $this->assertNotNull($path);
        $this->artisan('crm:backfill-payment-attribution', ['--restore' => $path])->assertSuccessful();
        $this->artisan('crm:backfill-payment-attribution', ['--restore' => $path, '--apply' => true, '--expected' => hash_file('sha256', $path)])->assertSuccessful();
        $this->assertNull(PaymentAttribution::find($p->id));
        $this->assertSame($before, $p->fresh()->getAttributes());
        unlink($path);
    }

    public function test_phone_reindex_refetches_source_and_exact_restore_preserves_other_fields(): void
    {
        $m = Platform::factory()->create(['phone_prefix' => '225', 'wp_api_url' => 'https://fixture.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        $c = Client::factory()->create(['platform_id' => $m->id, 'wp_user_id' => 55, 'wp_post_id' => 77, 'phone_normalized' => '225701020304']);
        Http::fake(['*' => Http::response(['wp_user_id' => 55, 'phone' => '0701020304'])]);
        $before = $c->fresh()->getAttributes();
        $row = ['client_id' => $c->id, 'before' => $before, 'after' => array_replace($before, ['phone_normalized' => '2250701020304']), 'source_digest' => hash('sha256', '0701020304')];
        $hash = hash('sha256', json_encode(['platform_id' => $m->id, 'prefix' => $m->phone_prefix, 'parser_version' => \App\Support\PhoneNormalizer::VERSION, 'rows' => [$row]], JSON_THROW_ON_ERROR));
        $args = ['--platform' => $m->id, '--limit' => 1];
        $this->artisan('crm:reindex-payment-phones', $args)->assertSuccessful();
        $this->assertSame($before, $c->fresh()->getAttributes());
        $this->artisan('crm:reindex-payment-phones', $args + ['--apply' => true, '--expected' => $hash])->assertSuccessful();
        $this->assertSame('2250701020304', $c->fresh()->phone_normalized);
        $paths = glob(storage_path('app/private/payment-phone-backups/*.json'));
        $path = collect($paths)->first(fn ($f) => str_contains(file_get_contents($f), $row['source_digest']));
        $this->assertNotNull($path);
        $this->artisan('crm:reindex-payment-phones', ['--restore' => $path, '--apply' => true, '--expected' => hash_file('sha256', $path)])->assertSuccessful();
        $this->assertSame($before, $c->fresh()->getAttributes());
        unlink($path);
    }

    public function test_sweep_skips_archived_fulfilled_receipts_and_discovers_next_cash_case(): void
    {
        $old = $this->receipt();
        $pending = $this->receipt();
        PaymentAttribution::create(['payment_id' => $old->id, 'platform_id' => $old->platform_id, 'original_client_id' => 999, 'attribution_state' => 'archived', 'evidence_json' => ['benefit' => ['payment_id' => $old->id, 'activated_at' => now()->toIso8601String()]]]);
        PaymentRecoveryCase::whereIn('payment_id', [$old->id, $pending->id])->delete();
        $this->artisan('crm:recover-paid-service', ['--limit' => 1, '--include-history' => true])->assertSuccessful();
        $this->assertNull($old->fresh()->recoveryCase);
        $this->assertNotNull($pending->fresh()->recoveryCase);
        Http::assertNothingSent();
    }

    public function test_manual_match_failure_stays_open_and_archived_subject_cannot_be_reactivated(): void
    {
        $p = $this->receipt();
        $c = Client::factory()->create(['platform_id' => $p->platform_id]);
        $result = app(\App\Services\PaymentMatchingService::class)->confirmMatch($p, $c->id, User::where('role', 'admin')->first()->id);
        $this->assertSame('manual_review', $result->reconciliation_state);
        $this->assertNotSame('closed', $result->recoveryCase->state);
        app(PaymentAttributionService::class)->archiveClient($c);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\PaymentMatchingService::class)->confirmMatch($result->fresh(), $c->id, 1);
    }

    public function test_manual_deal_only_link_retains_customer_and_benefit_when_profile_is_deleted(): void
    {
        $p = $this->receipt();
        $c = Client::factory()->create(['platform_id' => $p->platform_id]);
        $d = Deal::factory()->create(['platform_id' => $p->platform_id, 'client_id' => $c->id, 'payment_id' => $p->id, 'status' => 'active', 'activated_at' => now(), 'expires_at' => now()->addWeek()]);
        $this->assertSame($c->id, PaymentAttribution::find($p->id)->original_client_id);
        $this->assertNull($p->fresh()->client_id);
        $this->assertNull($p->fresh()->deal_id);
        app(PaymentAttributionService::class)->archiveClient($c);
        $d->delete();
        $c->delete();
        $this->assertSame('archived', PaymentAttribution::find($p->id)->attribution_state);
        $this->assertSame('fulfilled', app(PaymentRecoveryEvidencePolicy::class)->classify($p->fresh())['kind']);
        Http::assertNothingSent();
    }
}
