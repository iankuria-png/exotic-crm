<?php

namespace Tests\Feature\Ewms;

use App\Models\Payment;
use App\Services\Ewms\EwmsEvidenceConnection;
use App\Services\Ewms\EwmsKpiPaymentEvidenceQuery;
use App\Services\Ewms\EwmsKpiReadAudit;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;

class EwmsKpiContractTest extends EwmsKpiTestCase
{
    public function test_records_preserve_the_exact_contract_confirmation_dates_and_native_decimal_strings(): void
    {
        $id = $this->payment(['currency' => null, 'user_id' => null, 'phone' => 'PRIVATE', 'payment_data' => '{"customer":"PRIVATE"}']);
        $this->payment(['completed_at' => '2026-11-22 00:00:00']);
        $last = $this->payment(['completed_at' => '2026-11-21 23:59:59.999999', 'amount' => '0.10']);
        $fallback = $this->payment(['platform_id' => 2, 'currency' => null, 'completed_at' => null, 'created_at' => '2026-11-14 00:00:00']);
        $result = $this->evidence($this->token(), ['platform_ids' => [1, 2], 'from' => '2026-11-14', 'to' => '2026-11-21'])->assertOk();
        $result->assertJsonPath('meta.population', 'crm_cs_revenue')->assertJsonPath('meta.timezone', 'UTC')->assertJsonPath('data.0.payment_id', $last)->assertJsonPath('data.0.amount', '0.10');
        $rows = collect($result->json('data'))->keyBy('payment_id');
        $this->assertSame(EwmsKpiPaymentEvidenceQuery::COLUMNS, array_keys($rows[$id]));
        $this->assertSame('2026-11-14T10:00:00Z', $rows[$id]['recorded_at']);
        $this->assertSame('125.50', $rows[$id]['amount']);
        $this->assertSame('KES', $rows[$id]['currency']);
        $this->assertNull($rows[$id]['crm_user_id']);
        $this->assertSame('NGN', $rows[$fallback]['currency']);
        $this->assertCount(3, $rows);
        $this->assertSame('no-store, private', $result->headers->get('Cache-Control'));
        $audit = DB::table('audit_log')->first();
        $this->assertNull($audit->platform_id);
        $this->assertNotNull($audit->actor_id);
        $this->assertSame('ewms_kpi_payment_evidence_read', $audit->action);
        $this->assertStringNotContainsString('PRIVATE', $audit->after_state);
        $this->assertStringNotContainsString('EWMS-SYNTHETIC-REFERENCE', $audit->after_state);
    }

    public function test_complete_summary_is_independent_of_the_recent_limit_and_keeps_failed_review_and_zero_states_separate(): void
    {
        $token = $this->token();
        $this->payment(['amount' => '0.10']);
        $this->payment(['amount' => '0.20']);
        $this->payment(['amount' => '0.00']);
        $this->payment(['amount' => '10.00', 'status' => 'failed']);
        $this->payment(['amount' => '9.00', 'status' => 'pending']);
        $this->payment(['amount' => '8.00', 'resolution_code' => 'reversed']);
        $this->payment(['amount' => '7.00', 'reconciliation_state' => 'manual_review']);
        $this->evidence($token, ['platform_ids' => [1], 'from' => '2026-11-14', 'to' => '2026-11-21', 'limit' => 1])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.truncated', true);
        $this->evidence($token, [], true)->assertOk()->assertJsonPath('meta.complete', true)
            ->assertJsonPath('data.0.candidate_count', 7)->assertJsonPath('data.0.candidate_amount', '34.30')
            ->assertJsonPath('data.0.reportable_count', 3)->assertJsonPath('data.0.reportable_amount', '0.30')
            ->assertJsonPath('data.0.failed_count', 1)->assertJsonPath('data.0.failed_amount', '10.00');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'ewms_kpi_payment_evidence_summary_read')->count());
    }

    public function test_capped_equal_timestamp_order_and_refresh_find_old_changed_and_missing_evidence(): void
    {
        $token = $this->token();
        $old = $this->payment(['created_at' => '2026-01-01 10:00:00', 'completed_at' => null]);
        $a = $this->payment();
        $b = $this->payment();
        $this->evidence($token, ['platform_ids' => [1], 'from' => '2026-11-14', 'to' => '2026-11-21', 'limit' => 1])->assertOk()->assertJsonPath('data.0.payment_id', $b);
        DB::table('payments')->where('id', $a)->update(['resolution_code' => 'reversed']);
        DB::table('payments')->where('id', $b)->update(['record_classification' => 'test']);
        $outside = $this->payment(['platform_id' => 2]);
        $this->evidence($token, ['platform_ids' => [1], 'payment_ids' => [$old, $a, $b, $outside, 999]])
            ->assertOk()->assertJsonPath('meta.truncated', false)->assertJsonPath('meta.missing_payment_ids', [$b, $outside, 999])
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.payment_id', $old)->assertJsonPath('data.1.is_reportable_success', 0);
    }

    public function test_view_population_and_success_match_native_payment_scopes_including_collection_guards(): void
    {
        $this->payment();
        foreach (['completed', 'expired', 'pending', 'failed', null] as $status) {
            foreach ([null, 'manual_review'] as $review) {
                foreach ([null, 'reversed', 'invalid_reference'] as $resolution) {
                    $this->payment(['status' => $status, 'reconciliation_state' => $review, 'resolution_code' => $resolution]);
                }
            }
        }
        foreach (['premium_content_sale', 'send_love', 'monetize_pass', 'tour_pass', 'wallet_topup', 'visitor_contact_unlock', null] as $purpose) {
            $this->payment(['purpose' => $purpose]);
        }
        foreach ([['provider_environment' => 'sAnDbOx'], ['record_classification' => 'test'], ['payment_data' => '{"test_mode":true}'], ['payment_data' => '{"test_mode":false}']] as $attributes) {
            $this->payment($attributes);
        }
        foreach (['committing', 'compensation_failed', 'voided', 'committed'] as $i => $status) {
            DB::table('manual_payment_bundles')->insert(['id' => $i + 1, 'status' => $status]);
            $this->payment(['manual_payment_bundle_id' => $i + 1]);
        }
        DB::table('collection_sales_sessions')->insert(['bundle_id' => 4]);
        $confirmed = $this->payment(['manual_payment_bundle_id' => 4]);
        DB::table('collection_sales_items')->insert(['payment_id' => $confirmed, 'state' => 'confirmed', 'verified_at' => '2026-11-14 09:00:00']);
        $this->assertEquals(Payment::query()->businessVisible()->excludingWalletTopups()->orderBy('id')->pluck('id')->all(), DB::table('ewms_kpi_payment_evidence')->orderBy('payment_id')->pluck('payment_id')->all());
        $this->assertEquals(Payment::query()->reportableSuccessful()->excludingWalletTopups()->orderBy('id')->pluck('id')->all(), DB::table('ewms_kpi_payment_evidence')->where('is_reportable_success', 1)->orderBy('payment_id')->pluck('payment_id')->all());
    }

    public function test_source_and_audit_failures_return_unavailable_without_partial_data_or_references(): void
    {
        $token = $this->token();
        $this->payment();
        $audit = Mockery::mock(EwmsKpiReadAudit::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('PRIVATE token/reference/SQL'));
        $this->app->instance(EwmsKpiReadAudit::class, $audit);
        $this->evidence($token)->assertStatus(503)->assertJsonMissingPath('data')->assertDontSee('PRIVATE');
        $this->assertSame(0, DB::table('audit_log')->count());
        DB::statement('DROP VIEW ewms_kpi_payment_evidence');
        $this->evidence($token)->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_platform_metadata_failure_is_unavailable_and_never_a_partial_success(): void
    {
        $token = $this->token();
        \Illuminate\Support\Facades\Schema::drop('platforms');
        $this->evidence($token)->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_real_audit_storage_failure_never_returns_data_or_changes_payments(): void
    {
        $token = $this->token();
        $this->payment();
        $before = DB::table('payments')->get()->toJson();
        \Illuminate\Support\Facades\Schema::drop('audit_log');
        $this->evidence($token)->assertStatus(503)->assertJsonMissingPath('data');
        $this->assertSame($before, DB::table('payments')->get()->toJson());
    }

    public function test_unknown_currency_is_not_usd_or_zero_and_empty_success_has_a_complete_summary(): void
    {
        $token = $this->token();
        $this->evidence($token, [], true)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.complete', true);
        config(['ewms.allowed_platform_ids' => [1, 3]]);
        $this->payment(['platform_id' => 3, 'currency' => null]);
        $this->evidence($token, ['platform_ids' => [3], 'from' => '2026-11-14', 'to' => '2026-11-21'], true)->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_refresh_never_invents_a_timestamp_for_missing_source_dates(): void
    {
        $id = $this->payment(['created_at' => null, 'completed_at' => null]);
        $this->evidence($this->token(), ['platform_ids' => [1], 'payment_ids' => [$id]])
            ->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_evidence_connection_is_read_only_and_does_not_change_the_source_connection(): void
    {
        $id = $this->payment();
        $connection = app(EwmsEvidenceConnection::class)->connection();
        $this->assertSame(1, $connection->table('ewms_kpi_payment_evidence')->count());
        try {
            $connection->table('payments')->where('id', $id)->update(['amount' => '999.00']);
            $this->fail('The evidence connection must not write payment data.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertEquals(125.50, DB::table('payments')->where('id', $id)->value('amount'));
        }
    }

    public function test_migration_roundtrip_and_drift_refusal_preserve_all_payment_rows(): void
    {
        $this->payment(['currency' => null]);
        $before = DB::table('payments')->get()->toJson();
        $this->migration()->down();
        $this->assertSame('USD', DB::table('ewms_kpi_payment_evidence')->first()->currency);
        $this->migration()->up();
        $this->assertSame('KES', DB::table('ewms_kpi_payment_evidence')->first()->currency);
        $this->assertSame($before, DB::table('payments')->get()->toJson());
        DB::statement('DROP VIEW ewms_kpi_payment_evidence');
        DB::statement('CREATE VIEW ewms_kpi_payment_evidence AS SELECT id AS payment_id FROM payments');
        try {
            $this->migration()->up();
            $this->fail('Unexpected definitions must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('drift', $exception->getMessage());
        }
        $this->assertSame(['payment_id'], array_keys((array) DB::table('ewms_kpi_payment_evidence')->first()));
    }
}
