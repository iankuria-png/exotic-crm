<?php

namespace Tests\Feature\Mcp;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Mcp\McpAuthorizationContext;
use App\Services\SubscriptionAudit\SubscriptionAuditBuilder;
use App\Services\SubscriptionAudit\SubscriptionAuditException;
use App\Services\SubscriptionAudit\SubscriptionAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpSubscriptionProfileAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Platform $site;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Named Audit Manager']);
        $this->site = Platform::factory()->create(['country' => 'Kenya', 'currency_code' => 'KES', 'wp_api_url' => 'https://profiles.example.test/wp-json/exotic-crm-sync/v1']);
        $this->productId = \App\Models\Product::factory()->create(['platform_id' => $this->site->id])->id;
    }

    public function test_complete_population_includes_old_activation_and_no_deal_and_separates_published_seo(): void
    {
        $none = $this->client(['name' => 'No Deal Advertiser']);
        [$old] = $this->action(now()->subDays(200));
        $seo = $this->client(['lifecycle_state' => 'expired']);
        $this->client(['profile_status' => 'private', 'notactive' => true]);
        $this->client(['closed_at' => now()]);
        $run = app(SubscriptionAuditBuilder::class)->build();
        $report = $this->report(['run_ref' => $run->run_ref]);
        $this->assertSame(2, $report['total_matching']);
        $this->assertSame(0, DB::table('subscription_audit_events')->where('run_id', $run->id)->count());
        $rows = collect($report['rows'])->keyBy('client_handle');
        $oldRow = $rows[\App\Services\SubscriptionAudit\AuditValue::handle('cli', $old->id)];
        $this->assertNotNull($oldRow['latest_activation_at']);
        $this->assertSame('outside_trace_window', $oldRow['latest_activation']['trace_state']);
        $this->assertNull($oldRow['latest_activation']['event_ref']);
        $this->assertSame(3, $this->report(['run_ref' => $run->run_ref, 'profile_scope' => 'published'])['total_matching']);
    }

    public function test_invalid_wordpress_ids_do_not_abort_the_snapshot_or_attach_named_identity(): void
    {
        [$badPost] = $this->action(now()->subDay());
        DB::table('clients')->where('id', $badPost->id)->update(['wp_post_id' => -48]);
        $badPost->refresh();
        $zeroPost = $this->client(['wp_post_id' => 0]);
        $badUser = $this->client(['wp_user_id' => -19]);
        $zeroUser = $this->client(['wp_user_id' => 0]);
        $valid = $this->client(['wp_user_id' => null]);
        $run = app(SubscriptionAuditBuilder::class)->build();
        $rows = collect($this->report(['identified' => true])['rows'])->keyBy('client_handle');
        $this->assertSame(5, $rows->count());
        foreach ([$badPost, $zeroPost, $badUser, $zeroUser] as $client) {
            $row = $rows[\App\Services\SubscriptionAudit\AuditValue::handle('cli', $client->id)];
            $this->assertContains('wordpress_identity_invalid', $row['review_flags']);
            $this->assertSame('identity_unavailable', $row['audit_identity']['state']);
            $this->assertNull($row['audit_identity']['client_display_name']);
            $this->assertNull($row['audit_identity']['profile_url']);
            $this->assertSame($client->wp_post_id, $client->fresh()->wp_post_id);
            $this->assertSame($client->wp_user_id, $client->fresh()->wp_user_id);
            $frozen = DB::table('subscription_audit_profiles')->where('run_id', $run->id)->where('client_id', $client->id)->first();
            $this->assertSame((int) $client->wp_post_id > 0 ? (int) $client->wp_post_id : null, $frozen->wp_post_id);
            $this->assertSame((int) $client->wp_user_id > 0 ? (int) $client->wp_user_id : null, $frozen->wp_user_id);
        }
        $eventRef = $rows[\App\Services\SubscriptionAudit\AuditValue::handle('cli', $badPost->id)]['latest_action']['event_ref'];
        $trace = app(SubscriptionAuditService::class)->report('trace', ['purpose' => 'Invalid identity regression',
            'run_ref' => $run->run_ref, 'event_ref' => $eventRef, 'identified' => true], $this->auth());
        $this->assertSame('identity_unavailable', $trace['event']['audit_identity']['state']);
        $this->assertNull($trace['event']['audit_identity']['client_display_name']);
        $this->assertNull($trace['event']['audit_identity']['profile_url']);
        $validRow = $rows[\App\Services\SubscriptionAudit\AuditValue::handle('cli', $valid->id)];
        $this->assertSame('current_identity', $validRow['audit_identity']['state']);
        $this->assertNotNull($validRow['audit_identity']['profile_url']);
    }

    public function test_cash_history_separates_wallet_purchases_topups_tests_and_failed_payments(): void
    {
        $cash = $this->client();
        $wallet = $this->client();
        $topup = $this->client();
        $failed = $this->client();
        $sandbox = $this->client();
        $zero = $this->client();
        foreach ([[$cash, 'gateway', 'completed', 'live', 'subscription', '11.10'], [$wallet, 'wallet', 'completed', 'live', 'subscription', '11.10'],
            [$topup, 'gateway', 'completed', 'live', 'wallet_topup', '12.20'], [$topup, 'wallet', 'completed', 'live', 'subscription', '12.20'],
            [$failed, 'gateway', 'failed', 'live', 'subscription', '11.10'], [$sandbox, 'gateway', 'completed', 'sandbox', 'subscription', '11.10'],
            [$zero, 'free_trial', 'completed', 'live', 'subscription', '0.00']] as [$c, $source, $status, $env, $purpose, $amount]) {
            Payment::factory()->create(['client_id' => $c->id, 'platform_id' => $this->site->id, 'product_id' => $this->productId, 'source' => $source, 'status' => $status, 'provider_environment' => $env, 'purpose' => $purpose, 'amount' => $amount]);
        }
        app(SubscriptionAuditBuilder::class)->build();
        $all = $this->report();
        $this->assertSame(6, $all['total_matching']);
        $groups = collect($all['by_payment_history'])->pluck('profile_count', 'payment_history_state');
        $this->assertEquals(2, $groups['cash_recorded']);
        $this->assertEquals(1, $groups['payment_history_unverified']);
        $this->assertEquals(3, $groups['no_retained_cash']);
        $filtered = $this->report(['payment_filter' => 'no_successful_cash_payment_in_retained_crm']);
        $this->assertSame(3, $filtered['total_matching']);
        $topupRow = collect($all['rows'])->firstWhere('client_handle', \App\Services\SubscriptionAudit\AuditValue::handle('cli', $topup->id));
        $this->assertSame('12.20', $topupRow['payment_history']['by_currency'][0]['cash_amount']);
        $this->assertSame(1, $topupRow['payment_history']['cash_record_count']);
    }

    public static function protocols(): array
    {
        return array_map(fn ($v) => [$v], ['2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28']);
    }

    #[DataProvider('protocols')]
    public function test_named_profile_pages_and_trace_share_names_urls_and_scope_across_protocols(string $version): void
    {
        [$client] = $this->action(now()->subDay());
        $this->client();
        $run = app(SubscriptionAuditBuilder::class)->build();
        $token = $this->token(true);
        $args = ['purpose' => 'Management subscription audit', 'run_ref' => $run->run_ref, 'platform_id' => $this->site->id, 'identified' => true, 'page_size' => 1];
        $first = $this->rpc($token, $version, 'profiles', $args)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $row = $first->json('result.structuredContent.data.rows.0');
        $this->assertSame($client->name, $row['audit_identity']['client_display_name']);
        $this->assertSame('https://profiles.example.test/?p='.$client->wp_post_id, $row['audit_identity']['profile_url']);
        $this->assertStringContainsString($row['audit_identity']['profile_url'], $first->json('result.content.0.text'));
        $this->assertSame(0, $first->json('result._meta.exotic/ttlMs'));
        $cursor = $first->json('result.structuredContent.data.next_cursor');
        $this->assertNotNull($cursor);
        $second = $this->rpc($token, $version, 'profiles', $args + ['cursor' => $cursor])->assertOk();
        $this->assertNull($second->json('result.structuredContent.data.next_cursor'));
        $this->assertNotSame($row['profile_ref'], $second->json('result.structuredContent.data.rows.0.profile_ref'));
        $ref = $row['latest_action']['event_ref'];
        $trace = $this->rpc($token, $version, 'trace', ['purpose' => 'Management audit trace', 'run_ref' => $run->run_ref, 'event_ref' => $ref, 'identified' => true])->assertOk();
        $this->assertSame($client->name, $trace->json('result.structuredContent.data.event.audit_identity.client_display_name'));
        foreach (['phone', 'email', 'client_id', 'deal_id', 'payment_id'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'":', $first->getContent());
        }
        $this->assertDatabaseHas('audit_log', ['actor_id' => $this->admin->id, 'action' => 'subscription_audit_identity_read']);
    }

    public function test_named_access_does_not_follow_from_anonymous_audit_permission_and_strict_false_is_rejected(): void
    {
        $this->client();
        app(SubscriptionAuditBuilder::class)->build();
        $this->rpc($this->token(false), '2025-03-26', 'profiles', ['purpose' => 'Management audit', 'identified' => true])->assertForbidden();
        $this->rpc($this->token(true), '2026-07-28', 'profiles', ['purpose' => 'Management audit', 'identified' => 'false'])->assertStatus(422);
        $anonymous = $this->rpc($this->token(false), '2025-03-26', 'profiles', ['purpose' => 'Management audit'])->assertOk();
        $this->assertArrayNotHasKey('audit_identity', $anonymous->json('result.structuredContent.data.rows.0'));
    }

    public function test_current_identity_changes_do_not_change_membership_and_moved_bindings_return_unavailable(): void
    {
        $client = $this->client();
        app(SubscriptionAuditBuilder::class)->build();
        DB::table('clients')->where('id', $client->id)->update(['name' => 'Renamed Advertiser', 'profile_status' => 'private']);
        $row = $this->report(['identified' => true])['rows'][0];
        $this->assertTrue($row['business_active']);
        $this->assertSame('Renamed Advertiser', $row['audit_identity']['client_display_name']);
        DB::table('clients')->where('id', $client->id)->update(['wp_post_id' => $client->wp_post_id + 1]);
        $this->assertSame('identity_unavailable', $this->report(['identified' => true])['rows'][0]['audit_identity']['state']);
    }

    public function test_cursors_bind_profile_filters_identity_mode_and_report_kind(): void
    {
        $this->client();
        $this->client();
        $run = app(SubscriptionAuditBuilder::class)->build();
        $first = $this->report(['page_size' => 1]);
        foreach ([['identified' => true], ['profile_scope' => 'published'], ['payment_filter' => 'no_successful_cash_payment_in_retained_crm']] as $change) {
            try {
                $this->report($change + ['cursor' => $first['next_cursor']]);
                $this->fail('A cursor cannot change its report identity or filters.');
            } catch (SubscriptionAuditException $e) {
                $this->assertSame('audit_cursor_mismatch', $e->reason);
            }
        }
        $this->expectException(SubscriptionAuditException::class);
        app(SubscriptionAuditService::class)->report('events', ['purpose' => 'Management audit', 'cursor' => $first['next_cursor']], $this->auth());
    }

    public function test_backdated_event_window_has_current_profile_clock_and_failed_build_keeps_last_snapshot(): void
    {
        $this->client();
        $run = app(SubscriptionAuditBuilder::class)->build(90, CarbonImmutable::now('UTC')->subDays(100), CarbonImmutable::now('UTC')->subDays(10));
        $report = $this->report();
        $this->assertGreaterThan($run->to_at, CarbonImmutable::parse($report['profile_as_of']));
        Config::set('mcp.subscription_profiles.max_profiles', 0);
        try {
            app(SubscriptionAuditBuilder::class)->build();
            $this->fail('Oversize build must fail.');
        } catch (SubscriptionAuditException $e) {
            $this->assertSame('audit_source_limit', $e->reason);
        }
        $this->assertSame(1, DB::table('subscription_audit_runs')->count());
        $this->assertSame($run->run_ref, $this->report()['run_ref']);
    }

    public function test_identity_audit_failure_returns_no_names_and_does_not_modify_business_sources(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite abort trigger fault injection; other profile journeys run on MySQL.');
        }
        $this->client();
        app(SubscriptionAuditBuilder::class)->build();
        $before = DB::table('clients')->get()->toJson();
        DB::statement('CREATE TRIGGER fail_named_audit BEFORE INSERT ON audit_log WHEN NEW.action = \'subscription_audit_identity_read\' BEGIN SELECT RAISE(ABORT, \'audit unavailable\'); END');
        $response = $this->rpc($this->token(true), '2026-07-28', 'profiles', ['purpose' => 'Management audit', 'identified' => true])->assertStatus(503);
        $this->assertStringNotContainsString('client_display_name', $response->getContent());
        $this->assertSame($before, DB::table('clients')->get()->toJson());
    }

    public function test_trial_approval_is_exact_and_pin_authoriser_is_never_reported_as_executor(): void
    {
        [$client, $deal, $payment] = $this->action(now()->subHour());
        $at = now('UTC')->subHour()->startOfSecond();
        $requestId = DB::table('free_trial_requests')->insertGetId(['request_key' => (string) \Illuminate\Support\Str::uuid(), 'requester_id' => $this->admin->id,
            'platform_id' => $this->site->id, 'client_id' => $client->id, 'deal_id' => $deal->id, 'action' => 'activate',
            'terms_json' => json_encode(['action' => 'activate', 'client_ids' => [$client->id], 'product_id' => $deal->product_id, 'duration_days' => 7]),
            'source_version' => str_repeat('a', 64), 'policy_version' => 1, 'status' => 'activating', 'approved_via' => 'pin', 'authorized_actor_id' => $this->admin->id,
            'reason' => 'Sensitive arbitrary reason stays in CRM', 'authorized_at' => $at->copy()->subMinute(), 'expires_at' => $at->copy()->addDay()]);
        $operation = (string) \Illuminate\Support\Str::uuid();
        $opId = DB::table('trial_activation_operations')->insertGetId(['operation_key' => $operation, 'request_id' => $requestId, 'item_key' => 'profile:'.$client->id,
            'platform_id' => $this->site->id, 'client_id' => $client->id, 'deal_id' => $deal->id, 'state' => 'succeeded',
            'command_json' => json_encode(['post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'product_id' => $deal->product_id, 'duration_days' => 7, 'method' => 'activate']),
            'source_digest' => str_repeat('a', 64), 'verified_effect_json' => json_encode(['wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'post_status' => 'publish', 'escort_expire' => $deal->expires_at->timestamp]),
            'dispatched_at' => $at->copy()->subSecond(), 'verified_at' => $at->copy()->addSecond(), 'finalized_at' => $at->copy()->addSeconds(2)]);
        DB::table('free_trial_request_events')->insert(['request_id' => $requestId, 'actor_id' => $this->admin->id, 'action' => 'authorized', 'detail_json' => json_encode(['method' => 'pin']), 'created_at' => $at->copy()->subMinute()]);
        DB::table('payments')->where('id', $payment->id)->update(['amount' => 0, 'source' => 'free_trial', 'payment_data' => json_encode(['trial_request_id' => $requestId, 'trial_operation_id' => $operation])]);
        DB::table('audit_log')->where('action', 'deal_activate')->update(['after_state' => json_encode(['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => 0, 'currency' => 'KES', 'is_free_trial' => true, 'expires_at' => $deal->expires_at->toDateTimeString()]), 'created_at' => $at]);
        $run = app(SubscriptionAuditBuilder::class)->build();
        $row = $this->report(['run_ref' => $run->run_ref, 'identified' => true])['rows'][0];
        $this->assertSame('authorised_free_record', $row['action_payment_state']);
        $trial = $row['latest_action']['trial_authorisation'];
        $this->assertSame('pin', $trial['approval_method']);
        $this->assertNull($trial['executed_by']);
        $this->assertNull($trial['approver']);
        $this->assertNull($row['latest_action']['approver']);
        $this->assertSame('unknown_system_mediated', $row['latest_action']['execution']);
        $this->assertNull($row['latest_action']['actor']);
        $this->assertStringNotContainsString('Sensitive arbitrary reason', json_encode($row));
        $otherClient = $this->client();
        $otherDeal = Deal::factory()->create(['platform_id' => $this->site->id, 'product_id' => $this->productId, 'client_id' => $otherClient->id]);
        AuditLog::query()->create(['platform_id' => $this->site->id, 'actor_id' => $this->admin->id, 'action' => 'deal_activate', 'entity_type' => 'deal', 'entity_id' => $otherDeal->id,
            'after_state' => ['deal_status' => 'active', 'payment_id' => $payment->id, 'is_free_trial' => true, 'amount' => 0, 'currency' => 'KES', 'expires_at' => $deal->expires_at->toDateTimeString()], 'created_at' => $at]);
        app(SubscriptionAuditBuilder::class)->build();
        $conflict = collect($this->report()['rows'])->firstWhere('client_handle', \App\Services\SubscriptionAudit\AuditValue::handle('cli', $otherClient->id));
        $this->assertNotSame('authorised_free_record', $conflict['action_payment_state']);
        DB::table('trial_activation_operations')->where('id', $opId)->update(['state' => 'uncertain']);
        app(SubscriptionAuditBuilder::class)->build();
        $this->assertNotSame('authorised_free_record', $this->report()['rows'][0]['action_payment_state']);
    }

    public function test_paired_audit_timeline_and_recorder_use_the_same_financial_projection_as_trace(): void
    {
        [$client, $deal, $payment] = $this->action(now()->subDay()->startOfSecond());
        foreach (['deal_activated', 'subscription_activation_recorded'] as $type) {
            DB::table('timeline_events')->insert(['platform_id' => $this->site->id, 'entity_type' => 'deal', 'entity_id' => $deal->id, 'event_type' => $type,
                'actor_id' => $this->admin->id, 'content' => json_encode(['deal_id' => $deal->id, 'payment_id' => $payment->id, 'activated_at' => $deal->activated_at->toIso8601String(), 'source' => 'crm_activation']), 'created_at' => $deal->activated_at->copy()->addSecond()]);
        }
        $run = app(SubscriptionAuditBuilder::class)->build();
        $row = $this->report()['rows'][0];
        $trace = app(SubscriptionAuditService::class)->report('trace', ['purpose' => 'Management audit', 'run_ref' => $run->run_ref, 'event_ref' => $row['latest_action']['event_ref']], $this->auth());
        $this->assertSame(1, DB::table('subscription_audit_events')->where('run_id', $run->id)->count());
        $this->assertSame($trace['event']['payment_state'], $row['latest_action']['payment_state']);
        $this->assertSame('crm_recorded_only', $row['action_payment_state']);
        DB::table('clients')->where('id', $client->id)->update(['wp_post_id' => $client->wp_post_id + 1]);
        $trace = app(SubscriptionAuditService::class)->report('trace', ['purpose' => 'Management audit', 'run_ref' => $run->run_ref, 'event_ref' => $row['latest_action']['event_ref'], 'identified' => true], $this->auth());
        $this->assertSame('identity_unavailable', $trace['event']['audit_identity']['state']);
        $this->assertNull($trace['event']['audit_identity']['profile_url']);
    }

    public function test_cross_chunk_conflicting_deal_never_establishes_cash_history(): void
    {
        $first = $this->client();
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['platform_id' => $this->site->id, 'wp_post_id' => 9000000 + $i, 'wp_user_id' => 9100000 + $i,
                'name' => 'Synthetic batch profile', 'phone_normalized' => '254900'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'profile_status' => 'publish', 'needs_payment' => false, 'notactive' => false, 'lifecycle_state' => 'active'];
        }
        DB::table('clients')->insert($rows);
        $lastId = DB::table('clients')->max('id');
        $deal = Deal::factory()->create(['platform_id' => $this->site->id, 'product_id' => $this->productId, 'client_id' => $lastId]);
        Payment::factory()->create(['platform_id' => $this->site->id, 'product_id' => $this->productId, 'client_id' => $first->id, 'deal_id' => $deal->id, 'provider_environment' => 'live']);
        app(SubscriptionAuditBuilder::class)->build();
        $row = $this->report(['page_size' => 1])['rows'][0];
        $this->assertSame('payment_history_unverified', $row['payment_history_state']);
        $this->assertSame(0, $row['payment_history']['cash_record_count']);
    }

    public function test_duplicate_receipt_candidates_do_not_hide_review_when_other_valid_cash_exists(): void
    {
        $client = $this->client();
        foreach (['DUPLICATE-RECEIPT', 'DUPLICATE-RECEIPT', 'UNIQUE-RECEIPT'] as $root) {
            Payment::factory()->create(['platform_id' => $this->site->id, 'product_id' => $this->productId, 'client_id' => $client->id,
                'provider_environment' => 'live', 'reference_root' => $root, 'amount' => '10.00']);
        }
        app(SubscriptionAuditBuilder::class)->build();
        $row = $this->report()['rows'][0];
        $this->assertSame('cash_recorded', $row['payment_history_state']);
        $this->assertSame(1, $row['payment_history']['cash_record_count']);
        $this->assertTrue($row['payment_history']['has_unverified_candidates']);
        $this->assertContains('payment_history_candidates_unverified', $row['review_flags']);
    }

    public function test_receipt_grouping_normalises_fallback_roots_and_keeps_bundle_and_market_boundaries(): void
    {
        $duplicate = $this->client();
        $bundleClient = $this->client();
        $clean = $this->client();
        $bundle = DB::table('manual_payment_bundles')->insertGetId(['platform_id' => $this->site->id,
            'reference_root' => 'BUNDLE-ROOT', 'total_amount' => '20.00', 'allocated_amount' => '20.00',
            'currency' => 'KES', 'status' => 'committed', 'audit_state' => 'clean', 'idempotency_key' => 'synthetic-audit-bundle']);
        foreach ([
            [$duplicate, ['reference_root' => ' duplicate-root ']],
            [$duplicate, ['reference_root' => '', 'transaction_reference' => 'DUPLICATE-ROOT']],
            [$bundleClient, ['reference_root' => 'BUNDLE-ROOT', 'manual_payment_bundle_id' => $bundle, 'reference_sequence' => 1]],
            [$bundleClient, ['reference_root' => 'BUNDLE-ROOT', 'manual_payment_bundle_id' => $bundle, 'reference_sequence' => 2]],
            [$clean, ['reference_root' => 'MARKET-ROOT']],
        ] as [$client, $extra]) {
            Payment::factory()->create($extra + ['platform_id' => $this->site->id, 'product_id' => $this->productId,
                'client_id' => $client->id, 'provider_environment' => 'live', 'amount' => '10.00']);
        }
        $other = Platform::factory()->create(['currency_code' => 'KES']);
        Payment::factory()->create(['platform_id' => $other->id, 'client_id' => Client::factory()->create(['platform_id' => $other->id])->id,
            'provider_environment' => 'live', 'reference_root' => 'MARKET-ROOT', 'amount' => '10.00']);
        app(SubscriptionAuditBuilder::class)->build();
        $rows = collect($this->report()['rows'])->keyBy('client_handle');
        $duplicateRow = $rows[\App\Services\SubscriptionAudit\AuditValue::handle('cli', $duplicate->id)];
        $this->assertSame('payment_history_unverified', $duplicateRow['payment_history_state']);
        $this->assertSame(0, $duplicateRow['payment_history']['cash_record_count']);
        $bundleRow = $rows[\App\Services\SubscriptionAudit\AuditValue::handle('cli', $bundleClient->id)];
        $this->assertSame(2, $bundleRow['payment_history']['cash_record_count']);
        $this->assertSame('20.00', $bundleRow['payment_history']['by_currency'][0]['cash_amount']);
        $this->assertFalse($bundleRow['payment_history']['has_unverified_candidates']);
        $cleanRow = $rows[\App\Services\SubscriptionAudit\AuditValue::handle('cli', $clean->id)];
        $this->assertSame(1, $cleanRow['payment_history']['cash_record_count']);
        $this->assertFalse($cleanRow['payment_history']['has_unverified_candidates']);
    }

    public function test_missing_optional_trial_source_is_visible_in_coverage(): void
    {
        $this->client();
        $adapter = \Mockery::mock(\App\Services\SubscriptionAudit\TrialAuditEvidence::class);
        $adapter->shouldReceive('available')->andReturn(false);
        $adapter->shouldReceive('forPayments')->andReturn([]);
        $this->app->instance(\App\Services\SubscriptionAudit\TrialAuditEvidence::class, $adapter);
        app(SubscriptionAuditBuilder::class)->build();
        $coverage = app(SubscriptionAuditService::class)->report('coverage', ['purpose' => 'Management audit'], $this->auth());
        $this->assertSame('unavailable', $coverage['source_availability']['durable_trial_approvals']);
        $this->assertStringContainsString('Durable trial approval source tables are unavailable', implode(' ', $coverage['_report_meta']['caveats']));
    }

    public function test_pre_window_receipt_reuse_has_identical_retained_allocation_in_profile_trace_and_summary(): void
    {
        [$client, $deal, $payment] = $this->action(now()->subDays(200));
        AuditLog::query()->create(['platform_id' => $this->site->id, 'actor_id' => $this->admin->id, 'action' => 'deal_extend', 'entity_type' => 'deal', 'entity_id' => $deal->id,
            'after_state' => ['extension_payment_id' => $payment->id, 'amount' => '10.10', 'currency' => 'KES', 'new_expires_at' => now()->addMonths(2)->toDateTimeString()], 'created_at' => now()->subDay()]);
        AuditLog::query()->create(['platform_id' => $this->site->id, 'actor_id' => $this->admin->id, 'action' => 'deal_discount', 'entity_type' => 'deal', 'entity_id' => $deal->id,
            'after_state' => ['discount_approved_by' => $this->admin->id], 'created_at' => now()->subDays(2)]);
        $run = app(SubscriptionAuditBuilder::class)->build();
        $profile = $this->report()['rows'][0]['latest_action'];
        $trace = app(SubscriptionAuditService::class)->report('trace', ['purpose' => 'Management audit', 'run_ref' => $run->run_ref, 'event_ref' => $profile['event_ref']], $this->auth())['event'];
        $this->assertSame('allocation_conflict', $profile['payment_state']);
        $this->assertSame($profile['payment_state'], $trace['payment_state']);
        $this->assertSame('all_retained_profile_history', $trace['payment_allocation_scope']);
        $this->assertSame($profile['approval_evidence_candidates'], $trace['approval_evidence_candidates']);
        $this->assertNotEmpty($trace['approval_evidence_candidates']);
        $summary = app(SubscriptionAuditService::class)->report('summary', ['purpose' => 'Management audit', 'run_ref' => $run->run_ref], $this->auth());
        $this->assertSame('10.10', $summary['summary']['by_currency'][0]['potential_exposure']);
    }

    public function test_unlinked_wallet_credit_is_unknown_until_explicit_noncash_provenance_exists(): void
    {
        $client = $this->client();
        $id = DB::table('wallet_transactions')->insertGetId(['client_id' => $client->id, 'platform_id' => $this->site->id, 'type' => 'credit', 'currency_code' => 'KES',
            'amount' => '100.00', 'balance_after' => '100.00', 'idempotency_key' => 'synthetic-unexplained-credit', 'reference_type' => 'legacy_import', 'description' => 'Do not expose this private description']);
        app(SubscriptionAuditBuilder::class)->build();
        $this->assertSame('payment_history_unverified', $this->report()['rows'][0]['payment_history_state']);
        DB::table('wallet_transactions')->where('id', $id)->update(['reference_type' => 'admin_adjustment', 'performed_by' => $this->admin->id, 'metadata' => json_encode(['performed_by_role' => 'admin'])]);
        app(SubscriptionAuditBuilder::class)->build();
        $row = $this->report()['rows'][0];
        $this->assertSame('no_retained_cash', $row['payment_history_state']);
        $this->assertSame(1, $row['payment_history']['noncash_credit_count']);
        $this->assertStringNotContainsString('private description', json_encode($row));
    }

    public function test_deadline_crossed_by_final_insert_rolls_back_the_new_snapshot(): void
    {
        $this->client();
        $previous = app(SubscriptionAuditBuilder::class)->build();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'subscription_audit_profiles')) {
                Config::set('mcp.subscription_profiles.build_seconds', -1);
            }
        });
        try {
            app(SubscriptionAuditBuilder::class)->build();
            $this->fail('The final write cannot bypass the build deadline.');
        } catch (SubscriptionAuditException $error) {
            $this->assertSame('audit_source_limit', $error->reason);
        }
        $this->assertSame(1, DB::table('subscription_audit_runs')->count());
        $this->assertSame($previous->run_ref, $this->report()['run_ref']);
    }

    private function client(array $extra = []): Client
    {
        return Client::factory()->create($extra + ['platform_id' => $this->site->id, 'escort_expire' => now()->addMonth()->timestamp, 'lifecycle_state' => 'active']);
    }

    private function action($at): array
    {
        $client = $this->client(['name' => 'Synthetic Named Advertiser']);
        $deal = Deal::factory()->create(['platform_id' => $this->site->id, 'client_id' => $client->id, 'product_id' => $this->productId, 'activated_at' => $at, 'amount' => '10.10', 'currency' => 'KES']);
        $payment = Payment::factory()->create(['platform_id' => $this->site->id, 'client_id' => $client->id, 'deal_id' => $deal->id, 'product_id' => $deal->product_id, 'amount' => '10.10', 'currency' => 'KES', 'provider_environment' => 'live']);
        DB::table('deals')->where('id', $deal->id)->update(['payment_id' => $payment->id]);
        AuditLog::query()->create(['platform_id' => $this->site->id, 'actor_id' => $this->admin->id, 'action' => 'deal_activate', 'entity_type' => 'deal', 'entity_id' => $deal->id,
            'after_state' => ['deal_status' => 'active', 'payment_id' => $payment->id, 'amount' => '10.10', 'currency' => 'KES', 'expires_at' => $deal->expires_at->toDateTimeString()], 'created_at' => $at]);

        return [$client, $deal, $payment];
    }

    private function auth(bool $named = true): McpAuthorizationContext
    {
        return new McpAuthorizationContext($this->admin, array_merge(['mcp:read', 'mcp:subscription-audit'], $named ? ['mcp:identified-clients'] : []), [$this->site->id]);
    }

    private function report(array $args = []): array
    {
        return app(SubscriptionAuditService::class)->report('profiles', $args + ['purpose' => 'Management subscription audit'], $this->auth());
    }

    private function token(bool $named): string
    {
        return $this->admin->createToken('mcp:profile-audit-test', array_merge($this->auth($named)->abilities, array_map(fn ($kind) => 'mcp:tool:exotic_subscription_audit_'.$kind, ['profiles', 'events', 'trace', 'summary', 'coverage'])))->plainTextToken;
    }

    private function rpc(string $token, string $version, string $kind, array $args)
    {
        $params = ['name' => 'exotic_subscription_audit_'.$kind, 'arguments' => $args];
        if ($version === '2026-07-28') {
            $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => $version];
        }

        return $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params],
            ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => $version]);
    }
}
