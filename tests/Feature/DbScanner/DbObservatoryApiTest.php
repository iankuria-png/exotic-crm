<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanAuditEvent;
use App\Models\DbScanConnection;
use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Models\DbScanSetting;
use App\Services\DbScanner\Engine\PassController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class DbObservatoryApiTest extends TestCase
{
    use BuildsWordPressFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->bootScanner();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixtures();
        parent::tearDown();
    }

    private function scannedMarket(string $name = 'Zimbabwe', string $domain = 'zim-market.test'): array
    {
        $pdo = $this->newFixture();
        $ids = $this->seedCompromisedMarket($pdo, $domain);
        $platform = $this->marketPlatform($name, $domain);
        $this->connectFixture($platform, $this->fixturePath($pdo));

        return [$platform, $ids, $pdo];
    }

    public function test_admin_can_start_watch_and_read_a_pass(): void
    {
        [$platform] = $this->scannedMarket();
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'standard', 'markets' => [$platform->id]]);
        $response->assertStatus(202)->assertJsonPath('markets', 1);
        $this->drain();

        $run = DbScanMarketRun::query()->firstOrFail();
        $this->getJson('/api/crm/db-observatory/market-runs/'.$run->id)->assertOk()->assertJsonPath('run.status', $run->status);
        $events = $this->getJson('/api/crm/db-observatory/market-runs/'.$run->id.'/events')->assertOk()->json('events');
        $this->assertNotEmpty($events);
        $this->assertStringNotContainsString('<?php', json_encode($events), 'Events never contain matched values.');

        $coverage = $this->getJson('/api/crm/db-observatory/market-runs/'.$run->id.'/coverage')->assertOk();
        $this->assertGreaterThan(0, $coverage->json('complete'));
        $this->assertNotEmpty($coverage->json('rules'));

        $overview = $this->getJson('/api/crm/db-observatory/overview')->assertOk();
        $this->assertGreaterThan(0, $overview->json('counts.critical_open'));
        $this->assertGreaterThan(0, $overview->json('malware.strong'));

        $findings = $this->getJson('/api/crm/db-observatory/findings?category=malware&group_by=rule')->assertOk();
        $this->assertNotEmpty($findings->json('groups'));
        $this->assertSame('malware', $findings->json('data.0.category'));

        $this->getJson('/api/crm/db-observatory/markets')->assertOk()->assertJsonPath('data.0.connection.preflight_status', 'passed');
        $this->getJson('/api/crm/db-observatory/markets/'.$platform->id.'/inventory')->assertOk()->assertJsonPath('snapshot.core.stylesheet', 'escortwp-child');
        $this->assertTrue(DbScanAuditEvent::query()->where('entity', 'pass')->where('action', 'start')->exists());
    }

    public function test_busy_market_returns_409_and_blocked_admission_returns_423(): void
    {
        [$platform] = $this->scannedMarket();
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$platform->id]])->assertStatus(202);
        $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$platform->id]])->assertStatus(409);
        $this->drain();

        DbScanSetting::current()->forceFill(['enabled' => false])->save();
        $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$platform->id]])
            ->assertStatus(423)->assertJsonPath('blocked.0.reason', 'scanner_off');

        DbScanSetting::current()->forceFill(['enabled' => true])->save();
        $this->freshOpsState(1);
        $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$platform->id]])
            ->assertStatus(423)->assertJsonPath('blocked.0.reason', 'load');

        $this->freshOpsState(0);
        $platform->forceFill(['health_status' => 'server_error'])->save();
        $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$platform->id]])
            ->assertStatus(423)->assertJsonPath('blocked.0.reason', 'health');
    }

    public function test_idempotency_key_returns_the_original_pass(): void
    {
        [$platform] = $this->scannedMarket();
        Sanctum::actingAs($this->adminUser());

        $first = $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$platform->id], 'idempotency_key' => 'abc'])->json('pass_id');
        $second = $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$platform->id], 'idempotency_key' => 'abc'])->assertStatus(202)->json('pass_id');
        $this->assertSame($first, $second);
    }

    public function test_sub_admin_is_read_only_and_scoped_to_assigned_markets(): void
    {
        [$zim] = $this->scannedMarket('Zimbabwe', 'zim-market.test');
        [$ken] = $this->scannedMarket('Kenya', 'ken-market.test');
        app(PassController::class)->start([$zim->id, $ken->id], 'standard', 'manual');
        $this->drain();

        $sub = $this->adminUser('sub_admin', [$zim->id]);
        Sanctum::actingAs($sub);

        $markets = collect($this->getJson('/api/crm/db-observatory/findings?per_page=100')->assertOk()->json('data'))->pluck('platform_id')->unique()->values()->all();
        $this->assertSame([$zim->id], $markets);

        $kenFinding = DbScanFinding::query()->where('platform_id', $ken->id)->firstOrFail();
        $this->getJson('/api/crm/db-observatory/findings/'.$kenFinding->id)->assertNotFound();
        $kenRun = DbScanMarketRun::query()->where('platform_id', $ken->id)->firstOrFail();
        $this->getJson('/api/crm/db-observatory/market-runs/'.$kenRun->id.'/events')->assertNotFound();
        $this->getJson('/api/crm/db-observatory/markets/'.$ken->id.'/inventory')->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/crm/db-observatory/markets')->json('data'));
        $this->assertArrayNotHasKey('host_group', $this->getJson('/api/crm/db-observatory/markets')->json('data.0.connection'));

        $zimFinding = DbScanFinding::query()->where('platform_id', $zim->id)->firstOrFail();
        $this->patchJson('/api/crm/db-observatory/findings/'.$zimFinding->id, ['status' => 'acknowledged'])->assertForbidden();
        $this->postJson('/api/crm/db-observatory/passes', ['profile' => 'quick', 'markets' => [$zim->id]])->assertForbidden();
        $this->putJson('/api/crm/db-observatory/settings', ['enabled' => false, 'revision' => 1])->assertForbidden();
        $this->getJson('/api/crm/db-observatory/connections')->assertForbidden();
        $this->postJson('/api/crm/db-observatory/rules/malware.remote_loader/test', ['platform_id' => $zim->id])->assertForbidden();
        $this->putJson('/api/crm/db-observatory/rules/content.spam_lexicon', ['enabled' => false])->assertForbidden();
    }

    public function test_sub_admin_without_assignments_sees_nothing_and_sales_is_forbidden(): void
    {
        [$zim] = $this->scannedMarket();
        app(PassController::class)->start([$zim->id], 'quick', 'manual');
        $this->drain();

        Sanctum::actingAs($this->adminUser('sub_admin', []));
        $this->assertSame(0, $this->getJson('/api/crm/db-observatory/findings')->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/crm/db-observatory/overview')->assertOk()->json('counts.critical_open'));

        Sanctum::actingAs($this->adminUser('sales', [$zim->id]));
        $this->getJson('/api/crm/db-observatory/overview')->assertForbidden();
        $this->getJson('/api/crm/db-observatory/findings')->assertForbidden();
    }

    public function test_restricted_mcp_token_cannot_reach_the_observatory(): void
    {
        $admin = $this->adminUser();
        $token = $admin->createToken('mcp', ['mcp:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/crm/db-observatory/overview')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $full = $admin->createToken('session', ['*'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$full)->getJson('/api/crm/db-observatory/overview')->assertOk();
    }

    public function test_triage_cannot_resolve_directly_and_false_positive_needs_a_note(): void
    {
        [$platform] = $this->scannedMarket();
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();
        Sanctum::actingAs($admin = $this->adminUser());
        $finding = DbScanFinding::query()->where('rule_key', 'malware.remote_loader')->firstOrFail();

        $this->patchJson('/api/crm/db-observatory/findings/'.$finding->id, ['status' => 'resolved'])->assertStatus(422);
        $this->patchJson('/api/crm/db-observatory/findings/'.$finding->id, ['status' => 'false_positive'])->assertStatus(422);
        $this->patchJson('/api/crm/db-observatory/findings/'.$finding->id, ['status' => 'acknowledged', 'assigned_to' => $admin->id])->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->patchJson('/api/crm/db-observatory/findings/'.$finding->id, ['status' => 'snoozed', 'snoozed_until' => now()->addDays(7)->toIso8601String()])->assertOk();

        $detail = $this->getJson('/api/crm/db-observatory/findings/'.$finding->id)->assertOk();
        $this->assertNotEmpty($detail->json('rule.remediation'));
        $this->assertSame('status_changed', $detail->json('events.0.type'));
        $this->assertTrue(DbScanAuditEvent::query()->where('entity', 'finding')->where('action', 'triage')->exists());
    }

    public function test_suppression_preview_then_exact_suppression_and_revoke(): void
    {
        [$platform] = $this->scannedMarket();
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();
        Sanctum::actingAs($this->adminUser());
        $finding = DbScanFinding::query()->where('rule_key', 'content.script_injection')->firstOrFail();

        $preview = $this->postJson('/api/crm/db-observatory/findings/'.$finding->id.'/suppressions', [
            'scope' => 'network', 'match' => 'subject', 'reason' => 'Approved embed', 'expires_in_days' => 30,
        ])->assertOk();
        $this->assertTrue($preview->json('requires_acknowledgement'));
        $this->assertSame('open', $finding->fresh()->status, 'Network suppression needs an acknowledged preview.');

        $created = $this->postJson('/api/crm/db-observatory/findings/'.$finding->id.'/suppressions', [
            'scope' => 'market', 'match' => 'subject', 'reason' => 'Approved embed', 'expires_in_days' => 30,
        ])->assertCreated();
        $this->assertSame('allowlisted', $finding->fresh()->status);

        $this->deleteJson('/api/crm/db-observatory/suppressions/'.$created->json('suppression_id'))->assertOk();
        $this->assertSame('open', $finding->fresh()->status);
    }

    public function test_settings_use_optimistic_locking_and_reject_limits_outside_the_envelope(): void
    {
        Sanctum::actingAs($this->adminUser());
        $revision = $this->getJson('/api/crm/db-observatory/settings')->assertOk()->json('revision');

        $this->putJson('/api/crm/db-observatory/settings', ['limits' => ['statement_timeout_seconds' => 30], 'revision' => $revision])->assertStatus(422);
        $this->putJson('/api/crm/db-observatory/settings', ['limits' => ['host_slots' => 2], 'revision' => $revision])->assertStatus(422);
        $this->putJson('/api/crm/db-observatory/settings', ['limits' => ['global_slots' => 3], 'revision' => $revision])->assertStatus(422);
        $this->putJson('/api/crm/db-observatory/settings', ['limits' => ['statement_timeout_seconds' => 5], 'revision' => $revision])->assertOk()
            ->assertJsonPath('limits.statement_timeout_seconds.value', 5);
        $this->putJson('/api/crm/db-observatory/settings', ['paused' => true, 'revision' => $revision])->assertStatus(409);
    }

    public function test_connection_secrets_are_write_only_and_rotation_voids_preflight(): void
    {
        [$platform] = $this->scannedMarket();
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/crm/db-observatory/connections/'.$platform->id, [
            'host' => 'db.remote.test', 'database' => 'market', 'prefix' => 'wp_', 'username' => 'reader', 'password' => 'pw', 'tls_mode' => 'none',
        ])->assertStatus(422)->assertJsonValidationErrors('tls_mode');

        $this->putJson('/api/crm/db-observatory/connections/'.$platform->id, [
            'host' => '127.0.0.1', 'database' => 'market', 'prefix' => 'wp_', 'username' => 'reader2', 'password' => 'new-secret-value', 'tls_mode' => 'none',
        ])->assertOk()->assertJsonPath('preflight_status', 'never')->assertJsonPath('host_group', 'local');

        $list = $this->getJson('/api/crm/db-observatory/connections')->assertOk();
        $this->assertStringNotContainsString('new-secret-value', $list->getContent());
        $this->assertStringNotContainsString('reader2', $list->getContent());
        $this->assertFalse(DbScanConnection::query()->where('platform_id', $platform->id)->first()->preflightValid());

        $audit = DbScanAuditEvent::query()->where('entity', 'connection')->latest('id')->first();
        $this->assertStringNotContainsString('new-secret-value', json_encode($audit->after));
    }

    public function test_preflight_works_while_scanner_is_off_but_not_during_emergency_stop(): void
    {
        [$platform] = $this->scannedMarket();
        DbScanConnection::query()->where('platform_id', $platform->id)->update(['preflight_status' => 'never', 'preflight_config_version' => null, 'enabled' => false]);
        DbScanSetting::current()->forceFill(['enabled' => false])->save();
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/crm/db-observatory/connections/'.$platform->id.'/preflight')->assertOk()->assertJsonPath('status', 'passed');
        $this->assertTrue(DbScanConnection::query()->where('platform_id', $platform->id)->first()->preflightValid());
        $this->assertSame(0, DbScanFinding::query()->count(), 'Preflight never creates findings.');

        DbScanSetting::current()->forceFill(['emergency_stop' => true])->save();
        $this->postJson('/api/crm/db-observatory/connections/'.$platform->id.'/preflight')->assertStatus(423)->assertJsonPath('code', 'emergency_stop');
    }

    public function test_export_is_scoped_capped_and_neutralises_formulas(): void
    {
        [$platform] = $this->scannedMarket();
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();
        DbScanFinding::query()->first()->forceFill(['title' => '=HYPERLINK("http://x")'])->save();
        Sanctum::actingAs($this->adminUser());

        $response = $this->get('/api/crm/db-observatory/findings/export');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertSame('0', $response->headers->get('X-Export-Truncated'));
    }

    public function test_rule_test_writes_samples_not_findings(): void
    {
        [$platform] = $this->scannedMarket();
        Sanctum::actingAs($this->adminUser());

        $runId = $this->postJson('/api/crm/db-observatory/rules/malware.remote_loader/test', ['platform_id' => $platform->id])->assertStatus(202)->json('run_id');
        $this->drain();

        $run = DbScanMarketRun::query()->findOrFail($runId);
        $this->assertSame('test', $run->mode);
        $this->assertNotEmpty($run->test_samples);
        $this->assertSame(0, DbScanFinding::query()->count());
    }

    public function test_rule_overrides_validate_thresholds_and_apply_to_new_runs(): void
    {
        [$platform] = $this->scannedMarket();
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/crm/db-observatory/rules/hygiene.autoload_size', ['thresholds' => ['unknown' => 1]])->assertStatus(422);
        $this->putJson('/api/crm/db-observatory/rules/hygiene.autoload_size', ['platform_id' => $platform->id, 'thresholds' => ['max_bytes' => 512000]])->assertOk();
        $this->putJson('/api/crm/db-observatory/rules/content.spam_lexicon', ['platform_id' => $platform->id, 'disabled_lists' => ['lexicon.casino']])->assertOk();
        $this->putJson('/api/crm/db-observatory/rules/content.spam_lexicon', ['disabled_lists' => ['not.a.list']])->assertStatus(422);

        $rule = collect($this->getJson('/api/crm/db-observatory/rules')->json('data'))->firstWhere('key', 'hygiene.autoload_size');
        $this->assertSame(512000, $rule['overrides'][0]['thresholds']['max_bytes']);
        $this->assertSame(2, DbScanAuditEvent::query()->where('entity', 'rule')->count());
    }

    public function test_schedule_crud_rejects_bad_cron_and_delete_disables(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/crm/db-observatory/schedules', ['name' => 'Bad', 'profile' => 'quick', 'cron' => '* * * * *', 'market_scope' => ['mode' => 'enabled_connections']])->assertStatus(422);
        $id = $this->postJson('/api/crm/db-observatory/schedules', ['name' => 'Morning', 'profile' => 'quick', 'cron' => '15 6 * * *', 'market_scope' => ['mode' => 'enabled_connections'], 'enabled' => true])->assertCreated()->json('id');
        $this->patchJson('/api/crm/db-observatory/schedules/'.$id, ['cron' => '30 6 * * *', 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson('/api/crm/db-observatory/schedules/'.$id, ['name' => 'x', 'revision' => 1])->assertStatus(409);
        $this->deleteJson('/api/crm/db-observatory/schedules/'.$id)->assertOk()->assertJsonPath('enabled', false);
    }
}
