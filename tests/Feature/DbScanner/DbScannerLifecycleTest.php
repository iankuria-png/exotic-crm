<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanChunk;
use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Models\DbScanObservation;
use App\Models\DbScanOccurrence;
use App\Models\DbScanSchedule;
use App\Models\DbScanSetting;
use App\Models\DbScanSlot;
use App\Models\DbScanSuppression;
use App\Models\DbScanSweep;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\Engine\Recovery;
use App\Services\DbScanner\Engine\ScanExecutor;
use App\Services\DbScanner\Engine\ScheduleDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class DbScannerLifecycleTest extends TestCase
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

    private function market(string $name = 'Zimbabwe', string $domain = 'zim-market.test', string $hostGroup = 'local'): array
    {
        $pdo = $this->newFixture();
        $ids = $this->seedCompromisedMarket($pdo, $domain);
        $platform = $this->marketPlatform($name, $domain);
        $this->connectFixture($platform, $this->fixturePath($pdo), 'wp_', $hostGroup);

        return [$platform, $ids, $pdo];
    }

    private function refreshGates($platform): void
    {
        $this->freshOpsState();
        $platform->forceFill(['health_status' => 'healthy', 'health_checked_at' => now()])->save();
    }

    public function test_a_stale_or_duplicate_job_is_a_no_op(): void
    {
        [$platform] = $this->market();
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $run = DbScanMarketRun::query()->firstOrFail();
        $executor = app(ScanExecutor::class);

        $this->assertNotSame('stale', $executor->runSlice($run->id, 0));
        $this->assertSame('stale', $executor->runSlice($run->id, 0), 'A duplicate job with an old generation does nothing.');
        $this->drain();

        $observations = DbScanObservation::query()->count();
        $this->assertSame('stale', $executor->runSlice($run->id, 1));
        $this->assertSame($observations, DbScanObservation::query()->count());
        $this->assertSame(DbScanChunk::query()->count(), DbScanChunk::query()->distinct('chunk_key')->count('chunk_key'));
    }

    public function test_host_group_allows_one_market_at_a_time_and_waiting_runs_retry(): void
    {
        [$a] = $this->market('Zimbabwe', 'zim-market.test', 'shared-host');
        [$b] = $this->market('Kenya', 'ken-market.test', 'shared-host');
        app(PassController::class)->start([$a->id, $b->id], 'quick', 'manual');
        $runs = DbScanMarketRun::query()->orderBy('id')->get();

        // Simulate the first market holding the host slot.
        DbScanSlot::query()->updateOrCreate(['slot_key' => 'host:shared-host'], ['owner_run_id' => $runs[0]->id, 'owner_token' => 'other', 'generation' => 1, 'lease_expires_at' => now()->addMinute()]);
        $this->assertSame('contention', app(ScanExecutor::class)->runSlice($runs[1]->id, 0));
        $this->assertSame('waiting_lock', $runs[1]->fresh()->status);
        $this->assertNotNull($runs[1]->fresh()->next_attempt_at);

        DbScanSlot::query()->where('slot_key', 'host:shared-host')->update(['owner_run_id' => null, 'owner_token' => null, 'lease_expires_at' => null]);
        $this->drain();
        $this->assertSame(2, DbScanMarketRun::query()->whereIn('status', ['completed', 'completed_with_gaps'])->count());
    }

    public function test_recovery_fences_a_dead_owner_and_the_old_owner_cannot_commit(): void
    {
        [$platform] = $this->market();
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $run = DbScanMarketRun::query()->firstOrFail();

        // A worker claimed the run then died without heartbeating.
        $run->forceFill(['status' => 'running', 'owner_token' => 'dead-worker', 'generation' => 1, 'heartbeat_at' => now()->subMinutes(10)])->save();
        DbScanSlot::query()->create(['slot_key' => 'global:1', 'owner_run_id' => $run->id, 'owner_token' => 'dead-worker', 'generation' => 1, 'lease_expires_at' => now()->subMinutes(8)]);

        app(Recovery::class)->sweep();
        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertSame(2, $run->generation);
        $this->assertNull(DbScanSlot::query()->where('slot_key', 'global:1')->value('owner_run_id'));

        $this->expectException(\App\Services\DbScanner\Engine\LeaseLostException::class);
        app(ScanExecutor::class)->lockOwned($run, 'dead-worker');
    }

    public function test_pause_keeps_progress_and_resume_continues_from_the_checkpoint(): void
    {
        [$platform] = $this->market();
        $passes = app(PassController::class);
        $pass = $passes->start([$platform->id], 'standard', 'manual');
        $passes->pause($pass);
        $this->assertSame('paused', DbScanMarketRun::query()->first()->status);
        $this->assertSame('paused', $pass->fresh()->status);
        $this->drain();
        $this->assertSame(0, DbScanFinding::query()->count(), 'A paused run reads nothing.');

        $passes->resume($pass->fresh());
        $this->drain();
        $this->assertContains(DbScanMarketRun::query()->first()->status, ['completed', 'completed_with_gaps']);
        $this->assertGreaterThan(0, DbScanFinding::query()->count());
    }

    public function test_global_pause_and_load_gate_pause_runs_and_dispatcher_auto_resumes_load_only(): void
    {
        [$platform] = $this->market();
        app(PassController::class)->start([$platform->id], 'quick', 'manual');

        $this->freshOpsState(1);
        $this->drain();
        $run = DbScanMarketRun::query()->firstOrFail();
        $this->assertSame('paused', $run->status);
        $this->assertSame('load', $run->pause_reason);

        $this->travel(2)->minutes();
        $this->refreshGates($platform);
        app(ScheduleDispatcher::class)->tick();
        $this->assertSame('queued', $run->fresh()->status, 'Load pauses resume automatically once the gate clears.');

        DbScanSetting::current()->forceFill(['paused' => true])->save();
        $this->drain();
        $this->assertSame('operator_global', $run->fresh()->pause_reason);
        $this->travel(2)->minutes();
        $this->refreshGates($platform);
        DbScanSetting::current()->forceFill(['paused' => false])->save();
        app(ScheduleDispatcher::class)->tick();
        $this->assertSame('paused', $run->fresh()->status, 'A global pause needs an explicit resume.');
    }

    public function test_dispatcher_creates_one_occurrence_per_due_time_and_coalesces_while_busy(): void
    {
        [$platform] = $this->market();
        $schedule = DbScanSchedule::query()->where('name', 'Nightly quick')->firstOrFail();
        $schedule->forceFill(['enabled' => true, 'window' => null, 'cron' => '30 1 * * *'])->save();
        $dispatcher = app(ScheduleDispatcher::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-02 01:35:00', 'Africa/Harare'));
        $this->refreshGates($platform);
        $dispatcher->tick();
        $dispatcher->tick();

        $this->assertSame(1, DbScanOccurrence::query()->count(), 'Duplicate ticks create one occurrence.');
        $occurrence = DbScanOccurrence::query()->firstOrFail();
        $this->assertSame('dispatched', $occurrence->state);
        $this->assertSame('2026-10-01 23:30:00', $occurrence->due_at_utc->format('Y-m-d H:i:s'), 'Due time is the market-local 01:30 in UTC.');
        $this->assertSame(1, DbScanMarketRun::query()->count());

        // Next day while the market is still busy: deferred, not discarded.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 01:31:00', 'Africa/Harare'));
        $this->refreshGates($platform);
        DbScanSweep::query()->update(['status' => 'complete']);
        $dispatcher->tick();
        $pending = DbScanOccurrence::query()->latest('id')->first();
        $this->assertSame('pending', $pending->state);
        $this->assertStringContainsString('deferred', (string) $pending->skip_reason);

        $this->drain();
        $this->refreshGates($platform);
        $dispatcher->tick();
        $this->assertSame('dispatched', $pending->fresh()->state);
    }

    public function test_missed_schedules_only_catch_up_the_latest_occurrence_within_a_day(): void
    {
        [$platform] = $this->market();
        DbScanSchedule::query()->where('name', 'Nightly quick')->update(['enabled' => true, 'window' => null]);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'Africa/Harare'));
        $this->refreshGates($platform);
        app(ScheduleDispatcher::class)->tick();

        $this->assertSame(1, DbScanOccurrence::query()->count());
        $this->assertSame('2026-10-04 23:30:00', DbScanOccurrence::query()->first()->due_at_utc->format('Y-m-d H:i:s'));
    }

    public function test_stopping_a_sweep_prevents_any_continuation(): void
    {
        [$platform] = $this->market();
        config(['db_scanner.envelope.budgets.standard' => ['min' => 0, 'max' => 0, 'default' => 0]]);
        $passes = app(PassController::class);
        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();
        $sweep = DbScanSweep::query()->firstOrFail();
        $this->assertSame('running', $sweep->status);

        $this->travel(2)->minutes();
        $this->refreshGates($platform);
        $continuation = $passes->continueSweep($sweep->fresh());
        $this->assertNotNull($continuation);
        $passes->stop($continuation);
        $this->drain();

        $this->assertSame('stopped', $sweep->fresh()->status);
        $runs = DbScanMarketRun::query()->count();
        $this->travel(2)->minutes();
        $this->refreshGates($platform);
        app(ScheduleDispatcher::class)->tick();
        $this->assertNull($passes->continueSweep($sweep->fresh()));
        $this->assertSame($runs, DbScanMarketRun::query()->count(), 'No continuation is created for a stopped sweep.');
    }

    public function test_a_quick_scan_can_run_between_segments_of_an_unfinished_standard_sweep(): void
    {
        [$platform] = $this->market();
        config(['db_scanner.envelope.budgets.standard' => ['min' => 0, 'max' => 0, 'default' => 0]]);
        $passes = app(PassController::class);
        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();
        $standard = DbScanSweep::query()->where('profile', 'standard')->firstOrFail();
        $this->assertSame('running', $standard->status);

        $this->refreshGates($platform);
        $passes->start([$platform->id], 'quick', 'schedule');
        $this->drain();

        $this->assertSame('running', $standard->fresh()->status, 'A Quick sweep does not reset the unfinished Standard sweep.');
        $this->assertContains(DbScanSweep::query()->where('profile', 'quick')->first()->status, ['complete', 'complete_with_gaps']);
        $this->assertNotNull($passes->continueSweep($standard->fresh()));
    }

    public function test_multibyte_values_are_sliced_by_bytes_and_later_rows_still_progress(): void
    {
        $pdo = $this->newFixture();
        $this->createWordPressSchema($pdo);
        $this->wpOption($pdo, 'siteurl', 'https://zim-market.test');
        $this->wpOption($pdo, 'active_plugins', serialize([]));
        $this->wpUser($pdo, 'ian', 'ian@exotic-online.com', '2024-01-01 00:00:00');
        $huge = $this->wpPost($pdo, str_repeat('外', 40000).'<script src="https://tail-only.test/x.js"></script>', ['post_status' => 'private']);
        $after = $this->wpPost($pdo, '<script src="https://after-huge.test/x.js"></script>');
        $platform = $this->marketPlatform();
        $this->connectFixture($platform, $this->fixturePath($pdo));

        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();

        $this->assertFalse(DbScanFinding::query()->where('subject->row_id', $huge)->where('rule_key', 'malware.remote_loader')->exists(), 'The tail past 64 KiB is never read.');
        $this->assertTrue(DbScanFinding::query()->where('subject->row_id', $after)->where('rule_key', 'malware.remote_loader')->exists());
        $coverage = \App\Models\DbScanSurfaceCoverage::query()->where('surface_key', 'posts.content')->firstOrFail();
        $this->assertSame(1, $coverage->values_truncated);
        $this->assertLessThanOrEqual(65536 + 2000, (int) $coverage->bytes_read);
    }

    public function test_a_changed_payload_reopens_a_suppressed_finding(): void
    {
        [$platform, $ids, $pdo] = $this->market();
        $passes = app(PassController::class);
        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();

        $finding = DbScanFinding::query()->where('rule_key', 'content.script_injection')->where('subject->row_id', $ids['external_script'])->firstOrFail();
        $suppression = DbScanSuppression::query()->create([
            'rule_key' => $finding->rule_key, 'scope_key' => 'platform:'.$platform->id, 'platform_id' => $platform->id,
            'subject_hash' => $finding->subject_hash, 'rule_version_hash' => $finding->rule_version_hash, 'reason' => 'ok',
            'actor_id' => 1, 'expires_at' => now()->addDays(30),
        ]);
        $finding->forceFill(['status' => 'allowlisted', 'suppression_id' => $suppression->id])->save();

        // The fixture's persistence trigger blocks post edits, exactly as on a real infected site.
        $pdo->exec('DROP TRIGGER wds_protect_7_before_update');
        $pdo->exec("UPDATE wp_posts SET post_content = '<p>Hello</p><script src=\"https://evil-cdn.test/v2.js\"></script>' WHERE ID = ".$ids['external_script']);
        $this->travel(2)->minutes();
        $this->refreshGates($platform);
        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();

        $this->assertSame('open', $finding->fresh()->status);
        $this->assertTrue($finding->events()->where('type', 'reopened')->exists());
    }
}
