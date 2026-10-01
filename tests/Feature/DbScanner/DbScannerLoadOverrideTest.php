<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanAuditEvent;
use App\Models\DbScanConnection;
use App\Models\DbScanMarketRun;
use App\Models\DbScanPass;
use App\Models\DbScanSetting;
use App\Models\DbScanSweep;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\Engine\ScanExecutor;
use App\Services\DbScanner\Engine\ScheduleDispatcher;
use App\Services\DbScanner\Reader\ReaderException;
use App\Services\Ops\LoadShedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class DbScannerLoadOverrideTest extends TestCase
{
    use BuildsWordPressFixture;
    use RefreshDatabase;

    private const REASON = 'First Kenya canary during production setup';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->bootScanner();
        Sanctum::actingAs($this->adminUser());
    }

    protected function tearDown(): void
    {
        $this->tearDownFixtures();
        parent::tearDown();
    }

    private function market(string $name = 'Kenya')
    {
        $pdo = $this->newFixture();
        $this->seedCompromisedMarket($pdo, strtolower($name).'.test');
        $platform = $this->marketPlatform($name, strtolower($name).'.test');
        $this->connectFixture($platform, $this->fixturePath($pdo));

        return $platform;
    }

    private function start($platform, array $extra = [])
    {
        return $this->postJson('/api/crm/db-observatory/passes', $extra + [
            'profile' => 'standard', 'markets' => [$platform->id], 'override_reason' => self::REASON,
        ]);
    }

    public function test_admin_can_scan_under_limp_load_without_changing_global_state(): void
    {
        $market = $this->market();
        $this->freshOpsState(2);
        $this->start($market, ['override_reason' => null])->assertStatus(423)->assertJsonPath('load.override_allowed', true);
        $this->start($market, ['idempotency_key' => 'canary'])->assertStatus(202);
        $pass = DbScanPass::query()->firstOrFail();
        $this->assertSame(self::REASON, $pass->scope['load_override']['reason']);
        $this->assertSame(now()->addMinutes(15)->toIso8601String(), $pass->scope['load_override']['expires_at']);
        // A retry cannot mint a new exception or renew the first one's expiry.
        $this->start($market, ['idempotency_key' => 'canary'])->assertStatus(202)->assertJsonPath('pass_id', $pass->id);
        $this->assertSame(1, DbScanAuditEvent::query()->where('action', 'load_override_granted')->count());
        $this->drain();
        $run = DbScanMarketRun::query()->firstOrFail();
        $this->assertContains($run->status, ['completed', 'completed_with_gaps']);
        foreach ($run->cursor['surfaces'] as $surface) {
            $this->assertLessThanOrEqual(250, $surface['batch_rows']);
        }
        $this->assertSame(2, app(LoadShedder::class)->state()['level']);
        $this->getJson('/api/crm/db-observatory/passes/'.$pass->id)->assertOk()->assertJsonPath('pass.load_override.reason', self::REASON);
    }

    public function test_preflight_override_works_with_scanner_off_and_does_not_authorize_later_checks(): void
    {
        $market = $this->market();
        $this->freshOpsState(2);
        config(['db_scanner.enabled' => false]);
        DbScanSetting::current()->update(['enabled' => false]);
        $connection = DbScanConnection::query()->firstOrFail();
        $connection->update(['preflight_status' => 'never', 'preflight_config_version' => null]);
        $url = '/api/crm/db-observatory/connections/'.$market->id.'/preflight';
        $this->postJson($url)->assertStatus(423)->assertJsonPath('code', 'load');
        $this->postJson($url, ['override_reason' => self::REASON])->assertOk()->assertJsonPath('status', 'passed');
        $this->assertTrue($connection->fresh()->preflightValid());
        $this->postJson($url)->assertStatus(423)->assertJsonPath('code', 'load');
        $this->assertSame(1, DbScanPass::query()->count());
        $this->assertSame('preflight', DbScanAuditEvent::query()->where('action', 'load_override_granted')->firstOrFail()->after['mode']);
    }

    public function test_override_does_not_bypass_critical_missing_stale_health_credentials_or_switches(): void
    {
        $market = $this->market();
        $connection = DbScanConnection::query()->firstOrFail();
        $this->freshOpsState(3);
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'load')->assertJsonPath('load.override_allowed', false);
        Cache::forget(LoadShedder::STATE_CACHE_KEY);
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'ops_state_missing');
        $this->freshOpsState(2);
        $this->travel(4)->minutes();
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'ops_state_stale');
        $this->freshOpsState(2);
        $market->update(['health_status' => 'server_error']);
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'health');
        $market->update(['health_status' => 'healthy']);
        $connection->update(['preflight_status' => 'never']);
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'credentials');
        $connection->update(['preflight_status' => 'passed']);
        DbScanSetting::current()->update(['emergency_stop' => true]);
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'emergency_stop');
        $this->postJson('/api/crm/db-observatory/connections/'.$market->id.'/preflight', ['override_reason' => self::REASON])->assertStatus(423)->assertJsonPath('code', 'emergency_stop');
        DbScanSetting::current()->update(['emergency_stop' => false, 'paused' => true]);
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'paused_global');
        DbScanSetting::current()->update(['paused' => false, 'enabled' => false]);
        $this->start($market)->assertStatus(423)->assertJsonPath('blocked.0.reason', 'scanner_off');
        $this->assertSame(0, DbScanPass::query()->count());
    }

    public function test_only_admins_can_override_one_explicit_market_with_a_reason(): void
    {
        $a = $this->market();
        $b = $this->market('Zimbabwe');
        $this->freshOpsState(2);
        $this->start($a, ['markets' => [$a->id, $b->id]])->assertStatus(422);
        $this->start($a, ['markets' => 'all'])->assertStatus(422);
        $this->start($a, ['override_reason' => 'short'])->assertStatus(422);
        $this->start($a, ['override_reason' => str_repeat(' ', 20)])->assertStatus(423); // Middleware trims to null: no override.
        Sanctum::actingAs($this->adminUser('sub_admin', [$a->id]));
        $this->start($a)->assertForbidden();
        $this->postJson('/api/crm/db-observatory/connections/'.$a->id.'/preflight', ['override_reason' => self::REASON])->assertForbidden();
        $this->assertSame(0, DbScanPass::query()->count());
    }

    public function test_expired_queued_override_pauses_without_reading_and_cannot_be_resumed(): void
    {
        $market = $this->market();
        $this->freshOpsState(2);
        $this->start($market)->assertStatus(202);
        $pass = DbScanPass::query()->firstOrFail();
        $this->travel(16)->minutes();
        $this->freshOpsState(0);
        $market->update(['health_checked_at' => now()]);
        $this->drain();
        $run = DbScanMarketRun::query()->firstOrFail();
        $this->assertSame('override_expired', $run->pause_reason);
        $this->assertNull($run->started_at);
        app(ScheduleDispatcher::class)->tick();
        $this->assertSame('paused', $run->fresh()->status);
        $this->postJson('/api/crm/db-observatory/passes/'.$pass->id.'/resume')->assertStatus(409);
    }

    public function test_revoke_stops_the_run_closes_the_sweep_and_records_the_actor(): void
    {
        $market = $this->market();
        $this->freshOpsState(2);
        $this->start($market)->assertStatus(202);
        $pass = DbScanPass::query()->firstOrFail();
        $this->postJson('/api/crm/db-observatory/passes/'.$pass->id.'/stop')->assertOk();
        $this->assertNotNull($pass->fresh()->scope['load_override']['revoked_at']);
        $this->assertSame('stopped', DbScanMarketRun::query()->firstOrFail()->status);
        $this->assertSame('stopped', DbScanSweep::query()->firstOrFail()->status);
        $audit = DbScanAuditEvent::query()->where('action', 'load_override_revoked')->firstOrFail();
        $this->assertSame((int) $pass->triggered_by, (int) $audit->actor_id);
        $this->assertSame((int) $market->id, (int) $audit->platform_id);
    }

    public function test_continuations_do_not_inherit_the_exception(): void
    {
        $market = $this->market();
        config(['db_scanner.envelope.budgets.standard' => ['min' => 0, 'max' => 0, 'default' => 0]]);
        $this->freshOpsState(2);
        $this->start($market)->assertStatus(202);
        $this->drain();
        $sweep = DbScanSweep::query()->firstOrFail();
        $pass = app(PassController::class)->continueSweep($sweep);
        $this->assertNotNull($pass);
        $this->assertNull($pass->scope['load_override'] ?? null);
        $this->drain();
        $this->assertSame('load', $pass->runs()->firstOrFail()->pause_reason);
    }

    public function test_running_override_checks_critical_load_expiry_and_revoke_before_the_next_read(): void
    {
        $market = $this->market();
        $this->freshOpsState(2);
        $this->start($market)->assertStatus(202);
        $run = DbScanMarketRun::query()->firstOrFail();
        $run->update(['status' => 'running', 'owner_token' => 'worker']);
        $executor = app(ScanExecutor::class);
        $control = new \ReflectionMethod($executor, 'control');
        foreach (['critical', 'expired', 'revoked'] as $condition) {
            $this->freshOpsState($condition === 'critical' ? 3 : 2);
            if ($condition === 'expired') {
                $this->travel(16)->minutes();
                $this->freshOpsState(2);
            }
            if ($condition === 'revoked') {
                app(PassController::class)->stop($run->pass, (int) $run->pass->triggered_by);
            }
            $lastControl = 0.0;
            $lastRenew = microtime(true);
            try {
                $control->invokeArgs($executor, [$run, 'worker', microtime(true), &$lastControl, &$lastRenew]);
                $this->fail('The next read should have been aborted: '.$condition);
            } catch (ReaderException $e) {
                $this->assertSame(ReaderException::CONTROL_ABORT, $e->errorCode);
            }
        }
    }

    public function test_preflight_rechecks_critical_load_after_initial_admission(): void
    {
        $market = $this->market();
        $this->mock(LoadShedder::class, function ($mock) {
            $mock->shouldReceive('state')->andReturn(
                ['level' => 2, 'sampled_at' => now()->toIso8601String()],
                ['level' => 3, 'sampled_at' => now()->toIso8601String()],
            );
        });
        $this->postJson('/api/crm/db-observatory/connections/'.$market->id.'/preflight', ['override_reason' => self::REASON])
            ->assertStatus(422)->assertJsonPath('code', 'load');
        $this->assertSame('failed', DbScanConnection::query()->firstOrFail()->preflight_status);
    }

    public function test_an_override_is_not_queued_when_its_audit_write_fails(): void
    {
        $market = $this->market();
        $actor = $this->adminUser();
        $override = \App\Services\DbScanner\Engine\LoadOverride::issue($market->id, $actor->id, 'scan', self::REASON);
        $this->mock(\App\Services\DbScanner\DbScanAuditWriter::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        });
        try {
            app(PassController::class)->start([$market->id], 'quick', 'manual', $actor->id, loadOverride: $override);
            $this->fail('An unaudited override must not be admitted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame(0, DbScanPass::query()->count());
        $this->assertSame(0, DbScanMarketRun::query()->count());
        Queue::assertNothingPushed();
    }
}
