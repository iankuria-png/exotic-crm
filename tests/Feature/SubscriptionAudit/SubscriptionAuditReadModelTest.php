<?php

namespace Tests\Feature\SubscriptionAudit;

use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\SubscriptionAuditRun;
use App\Models\User;
use App\Services\Mcp\McpAuthorizationContext;
use App\Services\SubscriptionAudit\AuditValue;
use App\Services\SubscriptionAudit\SubscriptionAuditBuilder;
use App\Services\SubscriptionAudit\SubscriptionAuditException;
use App\Services\SubscriptionAudit\SubscriptionAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionAuditReadModelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->platform = Platform::factory()->create(['country' => 'Kenya', 'domain' => 'audit.example.test', 'currency_code' => 'KES']);
    }

    public function test_two_unpaid_extensions_keep_distinct_effects_and_never_borrow_the_original_payment(): void
    {
        [$deal, $payment] = $this->paidDeal();
        $this->audit($deal, 'deal_extend', ['expires_at' => '2026-11-10 23:59:59', 'payment_id' => $payment->id, 'extension_payment_id' => null, 'amount' => '100.00', 'payment_method' => 'manual']);
        $this->audit($deal, 'deal_extend', ['expires_at' => '2026-12-10 23:59:59', 'payment_id' => $payment->id, 'extension_payment_id' => null, 'amount' => '100.00', 'payment_method' => 'manual']);
        $this->build();
        $rows = $this->report('events', ['event_type' => 'extension'])['rows'];
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('extension', $row['event_type']);
            $this->assertNull($row['payment_locator']);
            $this->assertSame('no_match', $row['payment_state']);
            $this->assertNull($row['potential_exposure']);
            $this->assertSame('unpriced_extension', $row['valuation_basis']);
        }
        $this->assertSame(2, $this->report('summary', ['event_type' => 'extension'])['summary']['unpriced_count']);
    }

    public function test_audit_snapshot_and_legacy_activation_represent_one_effect_without_requiring_snapshot_expiry(): void
    {
        [$deal, $payment] = $this->paidDeal();
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00', 'expires_at' => '2026-11-10 23:59:59']);
        $this->timeline($deal, 'subscription_activation_recorded', ['deal_id' => $deal->id, 'payment_id' => $payment->id, 'source' => 'crm_activation', 'method' => 'manual', 'execution' => 'staff']);
        $this->timeline($deal, 'deal_activated', ['deal_id' => $deal->id, 'payment_id' => $payment->id, 'expires_at' => '2026-11-10 23:59:59']);
        $this->build();
        $result = $this->report('events');
        $this->assertCount(1, $result['rows']);
        $this->assertSame('crm_recorded_only', $result['rows'][0]['payment_state']);
        $this->assertSame('0.00', $result['rows'][0]['potential_exposure']);
        $this->assertCount(2, $result['rows'][0]['evidence']['supporting_source_refs']);
    }

    public function test_successful_renewal_uses_the_new_deal_and_snapshot_source_without_double_counting(): void
    {
        [$old] = $this->paidDeal();
        [$new, $payment] = $this->paidDeal();
        $this->audit($old, 'deal_renew', ['new_status' => 'active', 'new_deal_id' => $new->id, 'payment_id' => $payment->id, 'amount' => '100.00', 'new_expires_at' => '2026-12-01 23:59:59']);
        $this->timeline($new, 'subscription_activation_recorded', ['payment_id' => $payment->id, 'source' => 'crm_renewal', 'method' => 'manual']);
        $this->timeline($new, 'deal_activated', ['payment_id' => $payment->id, 'renewed_from_deal_id' => $old->id, 'expires_at' => '2026-12-01 23:59:59']);
        $this->build();
        $rows = $this->report('events', ['event_type' => 'renewal'])['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(app(\App\Services\Mcp\PseudonymService::class)->handle('deal', $new->id), $rows[0]['deal_handle']);
    }

    public function test_timeline_only_later_unpaid_extension_survives_an_earlier_paid_activation_audit(): void
    {
        [$deal, $payment] = $this->paidDeal();
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00', 'expires_at' => '2026-11-10 23:59:59']);
        $this->timeline($deal, 'deal_extended', ['deal_id' => $deal->id, 'extension_payment_id' => null, 'new_expires_at' => '2026-12-10 23:59:59']);
        $this->build();
        $rows = $this->report('events', ['event_type' => 'extension'])['rows'];
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['payment_locator']);
        $this->assertContains('additional_source_representation_unresolved', $rows[0]['exception_codes']);
    }

    public function test_timeline_extensions_with_reused_payment_and_different_expiries_remain_distinct(): void
    {
        [$deal, $payment] = $this->paidDeal();
        $this->timeline($deal, 'deal_extended', ['extension_payment_id' => $payment->id, 'new_expires_at' => '2026-12-01 23:59:59', 'amount' => '100.00', 'currency' => 'KES']);
        $this->timeline($deal, 'deal_extended', ['extension_payment_id' => $payment->id, 'new_expires_at' => '2027-01-01 23:59:59', 'amount' => '100.00', 'currency' => 'KES']);
        $this->build();
        $rows = $this->report('events', ['event_type' => 'extension'])['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('allocation_conflict', $rows[1]['payment_state']);
        $this->assertSame('100.00', $rows[1]['potential_exposure']);
    }

    public function test_false_string_test_flag_does_not_hide_a_live_effect(): void
    {
        [$deal, $payment] = $this->paidDeal();
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00', 'test_mode' => 'false']);
        $this->build();
        $this->assertSame('live', $this->report('events')['rows'][0]['population']);
    }

    public function test_pending_activation_and_renewal_attempts_do_not_become_successful_effects(): void
    {
        $deal = $this->deal(['activated_at' => null, 'status' => 'awaiting_payment']);
        $this->audit($deal, 'deal_activate', ['deal_status' => 'awaiting_payment', 'amount' => '100.00']);
        $this->audit($deal, 'deal_renew', ['new_status' => 'awaiting_payment', 'new_deal_id' => $deal->id]);
        $this->build();
        $this->assertSame(0, $this->report('summary')['summary']['event_count']);
    }

    public function test_sandbox_payment_does_not_hide_a_live_effect_or_clear_its_exposure(): void
    {
        [$deal, $payment] = $this->paidDeal(['provider_environment' => 'sandbox', 'record_classification' => 'test']);
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00']);
        $this->build();
        $row = $this->report('events')['rows'][0];
        $this->assertSame('live', $row['population']);
        $this->assertSame('excluded_test_payment', $row['payment_state']);
        $this->assertSame('100.00', $row['potential_exposure']);
    }

    public function test_explicit_source_test_effect_is_separate_from_live_exposure(): void
    {
        [$deal, $payment] = $this->paidDeal(['provider_environment' => 'sandbox']);
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00', 'test_mode' => true]);
        $this->build();
        $this->assertSame(0, $this->report('summary')['summary']['event_count']);
        $test = $this->report('events', ['population' => 'test'])['rows'][0];
        $this->assertNull($test['potential_exposure']);
    }

    public function test_non_subscription_and_refunded_payment_evidence_remain_exceptions(): void
    {
        foreach ([['purpose' => 'send_love'], ['status' => 'refunded']] as $attributes) {
            [$deal, $payment] = $this->paidDeal($attributes);
            $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00']);
        }
        $this->build();
        $rows = $this->report('events')['rows'];
        $this->assertSame(['purpose_conflict', 'payment_not_successful'], array_column($rows, 'payment_state'));
        $this->assertSame('200.00', $this->report('summary')['summary']['by_currency'][0]['potential_exposure']);
    }

    public function test_reused_payment_value_cannot_cover_two_chargeable_effects(): void
    {
        [$deal, $payment] = $this->paidDeal();
        foreach (['2026-11-20 23:59:59', '2026-12-20 23:59:59'] as $expiry) {
            $this->audit($deal, 'deal_extend', ['extension_payment_id' => $payment->id, 'expires_at' => $expiry, 'amount' => '100.00']);
        }
        $this->build();
        $rows = $this->report('events', ['event_type' => 'extension'])['rows'];
        $this->assertSame(['crm_recorded_only', 'allocation_conflict'], array_column($rows, 'payment_state'));
        $this->assertSame(['0.00', '100.00'], array_column($rows, 'potential_exposure'));
    }

    public function test_existing_pin_and_discount_audits_are_visible_without_inventing_exact_effect_approval(): void
    {
        $deal = $this->deal();
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_method' => 'free_trial', 'amount' => '0.00', 'is_free_trial' => true]);
        $this->audit($deal, 'deal_free_trial', ['approval_mode' => 'pin']);
        $this->audit($deal, 'deal_discount', ['discount_approved_by' => $this->admin->id, 'discount_percentage' => '10.00']);
        $this->build();
        $row = $this->report('events')['rows'][0];
        $this->assertSame('approval_evidence_available_link_unverified', $row['authorisation_state']);
        $this->assertCount(2, $row['approval_evidence_candidates']);
        $this->assertNull($row['approver']);
        $this->assertContains('pin_authorisation_recorded', array_column($row['approval_evidence_candidates'], 'approval_method'));
        $this->assertNull($row['potential_exposure']);
    }

    public function test_snapshot_retention_and_immutability_preserve_pinned_runs_and_source_records(): void
    {
        [$deal, $payment] = $this->paidDeal();
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00']);
        $sourceBefore = DB::table('payments')->where('id', $payment->id)->first();
        $run = $this->build();
        $this->assertEquals($sourceBefore, DB::table('payments')->where('id', $payment->id)->first());
        try {
            $run->update(['rule_version' => 'mutated']);
            $this->fail('Completed runs must reject model mutation.');
        } catch (\LogicException) {
            $this->assertSame(SubscriptionAuditBuilder::RULE_VERSION, $run->fresh()->rule_version);
        }
        DB::table('subscription_audit_runs')->where('id', $run->id)->update(['created_at' => now()->subHours(7)]);
        $this->assertSame(0, app(SubscriptionAuditBuilder::class)->prune());
        $this->build();
        $this->assertSame(1, app(SubscriptionAuditBuilder::class)->prune());
        $this->assertSame(0, DB::table('subscription_audit_events')->where('run_id', $run->id)->count());
    }

    public function test_scoped_coverage_and_pagination_do_not_leak_other_markets_or_change_a_pinned_run(): void
    {
        foreach (range(1, 3) as $n) {
            [$deal, $payment] = $this->paidDeal();
            $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00']);
        }
        $outside = Platform::factory()->create(['country' => 'Outside']);
        $run = $this->build();
        $auth = $this->auth([$this->platform->id]);
        $first = $this->report('events', ['page_size' => 1], $auth);
        $this->assertLessThan(500, strlen($first['next_cursor']));
        $this->build();
        $second = $this->report('events', ['page_size' => 1, 'cursor' => $first['next_cursor']], $auth);
        $this->assertSame($run->run_ref, $second['run_ref']);
        $this->assertNotSame($first['rows'][0]['event_ref'], $second['rows'][0]['event_ref']);
        $coverage = $this->report('coverage', [], $auth);
        $this->assertSame([], $coverage['source_counts']);
        $this->assertSame([$this->platform->id], array_column($coverage['coverage'], 'platform_id'));
        $this->assertStringNotContainsString('Outside', json_encode($coverage));
        $this->expectException(SubscriptionAuditException::class);
        $this->report('events', ['cursor' => $first['next_cursor'], 'exceptions_only' => true], $auth);
    }

    public function test_money_is_exact_and_source_timestamps_distinguish_null_expiry_from_missing(): void
    {
        $this->assertSame(30, AuditValue::minor('0.10') + AuditValue::minor('0.20'));
        $this->assertSame('0.30', AuditValue::decimal(30));
        $deal = $this->deal();
        $this->audit($deal, 'deal_extend', ['expires_at' => '2026-12-10 23:59:59', 'extension_payment_id' => null]);
        $this->build();
        $this->assertSame('recorded_no_expiry', $this->report('events', ['event_type' => 'extension'])['rows'][0]['old_expiry_evidence']);
    }

    public function test_cli_builds_reporting_only_and_invalid_rebuild_keeps_completed_snapshot(): void
    {
        [$deal, $payment] = $this->paidDeal();
        $this->audit($deal, 'deal_activate', ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '100.00']);
        $this->artisan('crm:subscription-audit-build', ['--days' => 90])->assertSuccessful();
        $runRef = $this->report('summary')['run_ref'];
        $this->artisan('crm:subscription-audit-build', ['--days' => 0])->assertFailed();
        $this->assertSame($runRef, $this->report('summary')['run_ref']);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('active', $deal->fresh()->status);
    }

    public function test_cli_failure_reports_safe_database_codes_without_query_or_payment_details(): void
    {
        $previous = $this->build();
        $cause = new \PDOException('Private payment reference and client name');
        $cause->errorInfo = ['22003', 1264, 'Private SQL value'];
        $error = new \Illuminate\Database\QueryException('mysql', 'insert private_payment_payload values (?)', ['Private client name'], $cause);
        $builder = \Mockery::mock(SubscriptionAuditBuilder::class);
        $builder->shouldReceive('build')->once()->andThrow($error);
        $this->app->instance(SubscriptionAuditBuilder::class, $builder);
        $this->artisan('crm:subscription-audit-build')->expectsOutput('Subscription audit snapshot build failed (QueryException; SQLSTATE 22003; driver 1264). Previous completed reports remain available.')->assertFailed();
        $this->assertSame($previous->run_ref, $this->report('summary')['run_ref']);
    }

    public function test_cli_budget_failure_identifies_the_safe_reporting_limit(): void
    {
        $builder = \Mockery::mock(SubscriptionAuditBuilder::class);
        $builder->shouldReceive('build')->once()->andThrow(new SubscriptionAuditException('Private implementation detail', 'audit_source_limit'));
        $this->app->instance(SubscriptionAuditBuilder::class, $builder);
        $this->artisan('crm:subscription-audit-build')->expectsOutput('Subscription audit snapshot build failed (SubscriptionAuditException; audit_source_limit). Previous completed reports remain available.')->assertFailed();
    }

    public function test_cleanup_timeout_does_not_report_a_completed_snapshot_as_a_build_failure(): void
    {
        $run = $this->build();
        $cause = new \PDOException('Private retention query');
        $cause->errorInfo = ['HY000', 1205, 'Private SQL data'];
        $error = new \Illuminate\Database\QueryException('mysql', 'delete private_rows where value = ?', ['Private client name'], $cause);
        $builder = \Mockery::mock(SubscriptionAuditBuilder::class);
        $builder->shouldReceive('build')->once()->andReturn($run);
        $builder->shouldReceive('prune')->once()->andThrow($error);
        $this->app->instance(SubscriptionAuditBuilder::class, $builder);
        $this->artisan('crm:subscription-audit-build')
            ->expectsOutput('Completed audit run '.$run->run_ref.'; UTC '.$run->from_at->toIso8601String().' to '.$run->to_at->toIso8601String().'.')
            ->expectsOutput('The completed snapshot is available; old-snapshot cleanup failed (QueryException; SQLSTATE HY000; driver 1205). Cleanup will retry on the next build.')
            ->assertSuccessful();
        $this->assertSame($run->run_ref, $this->report('summary')['run_ref']);
    }

    public function test_mysql_command_skips_another_connection_build_and_releases_its_lock_after_failure(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent MySQL advisory-lock connections.');
        }
        $database = DB::connection()->getDatabaseName();
        $name = 'exotic:audit:'.substr(hash('sha256', $database), 0, 48);
        Config::set('database.connections.audit_lock_probe', DB::connection()->getConfig());
        $other = DB::connection('audit_lock_probe');
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
        $builder = \Mockery::mock(SubscriptionAuditBuilder::class);
        $builder->shouldReceive('build')->once()->andThrow(new SubscriptionAuditException('Synthetic failure', 'audit_source_limit'));
        $builder->shouldNotReceive('prune');
        $this->app->instance(SubscriptionAuditBuilder::class, $builder);
        try {
            $this->artisan('crm:subscription-audit-build')
                ->expectsOutput('Another subscription audit build or cleanup is running. This invocation was skipped; completed reports remain available.')
                ->assertSuccessful();
            $this->assertSame(0, DB::table('subscription_audit_runs')->count());
            $other->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            $this->artisan('crm:subscription-audit-build')->assertFailed();
            $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            DB::purge('audit_lock_probe');
        }
    }

    private function auth(?array $platforms = null): McpAuthorizationContext
    {
        return new McpAuthorizationContext($this->admin, ['mcp:read', 'mcp:subscription-audit'], $platforms);
    }

    private function report(string $kind, array $args = [], ?McpAuthorizationContext $auth = null): array
    {
        return app(SubscriptionAuditService::class)->report($kind, ['purpose' => 'financial control review'] + $args, $auth ?? $this->auth());
    }

    private function build(): SubscriptionAuditRun
    {
        return app(SubscriptionAuditBuilder::class)->build();
    }

    private function deal(array $attributes = []): Deal
    {
        return Deal::withoutEvents(fn () => Deal::factory()->create($attributes + ['platform_id' => $this->platform->id, 'amount' => '100.00', 'currency' => 'KES', 'activated_at' => now()->subDay(), 'expires_at' => now()->addDays(5)]));
    }

    private function paidDeal(array $paymentAttributes = []): array
    {
        $deal = $this->deal();
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create($paymentAttributes + ['platform_id' => $deal->platform_id, 'product_id' => $deal->product_id, 'deal_id' => $deal->id, 'client_id' => $deal->client_id, 'amount' => '100.00', 'currency' => 'KES', 'status' => 'completed', 'completed_at' => now()->subDay()]));
        DB::table('deals')->where('id', $deal->id)->update(['payment_id' => $payment->id]);

        return [$deal->fresh(), $payment];
    }

    private function audit(Deal $deal, string $action, array $after): int
    {
        return DB::table('audit_log')->insertGetId(['platform_id' => $deal->platform_id, 'actor_id' => $this->admin->id, 'entity_type' => 'deal', 'entity_id' => $deal->id,
            'action' => $action, 'before_state' => json_encode(['expires_at' => null]), 'after_state' => json_encode($after), 'created_at' => now()->subHour()]);
    }

    private function timeline(Deal $deal, string $kind, array $content): void
    {
        DB::table('timeline_events')->insert(['platform_id' => $deal->platform_id, 'actor_id' => $this->admin->id, 'entity_type' => 'deal', 'entity_id' => $deal->id,
            'event_type' => $kind, 'content' => json_encode($content), 'created_at' => now()->subHour()]);
    }
}
