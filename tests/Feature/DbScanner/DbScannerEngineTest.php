<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Models\DbScanSurfaceCoverage;
use App\Models\DbScanSweep;
use App\Services\DbScanner\Engine\PassController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class DbScannerEngineTest extends TestCase
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

    public function test_standard_pass_detects_the_malicious_fixture_journey_and_leaves_benign_rows_alone(): void
    {
        $pdo = $this->newFixture();
        $ids = $this->seedCompromisedMarket($pdo);
        $platform = $this->marketPlatform();
        $this->connectFixture($platform, $this->fixturePath($pdo));

        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();

        $run = DbScanMarketRun::query()->firstOrFail();
        $this->assertContains($run->status, ['completed', 'completed_with_gaps'], json_encode($run->only('status', 'error_code')));

        $findings = DbScanFinding::query()->get();
        $by = fn (string $rule) => $findings->where('rule_key', $rule);

        // Zimbabwe reproduction.
        $this->assertSame('critical', $by('access.admin_unexpected_email')->first()?->severity);
        $this->assertSame('strong', $by('access.admin_unexpected_email')->first()?->confidence);
        $this->assertTrue($by('persistence.plugin_not_allowlisted')->contains(fn ($f) => str_contains($f->subject['item'], 'crawl-page-optimizer')));
        $this->assertTrue($by('seo.malformed_links')->contains(fn ($f) => $f->subject['row_id'] === $ids['widget'] && str_contains($f->subject['field'], '[text]')));

        // Persistence indicators.
        $this->assertCount(1, $by('persistence.ioc_option_names'));
        $this->assertSame($ids['lookalike'], $by('persistence.lookalike_options')->first()?->subject['row_id']);
        $this->assertTrue($by('access.hidden_admin_capabilities')->contains(fn ($f) => ($f->subject['row_id'] ?? null) === $ids['hidden_meta'] && $f->confidence === 'strong'));
        $this->assertTrue($by('persistence.mysql_triggers')->contains(fn ($f) => str_contains($f->subject['item'], 'wds_protect_7_before_update')));

        // Malware behaviours with provenance and confidence.
        $webshell = $by('malware.db_webshell')->firstWhere('subject.row_id', $ids['webshell']);
        $this->assertNotNull($webshell);
        $this->assertSame('strong', $webshell->confidence);
        $this->assertSame('malware', $webshell->pack);
        $this->assertSame('1.1.0', $webshell->pack_version);
        $this->assertSame('critical', $webshell->severity);

        $this->assertTrue($by('malware.remote_loader')->contains(fn ($f) => $f->subject['row_id'] === $ids['external_script'] && $f->confidence === 'strong'));
        $this->assertTrue($by('malware.remote_loader')->contains(fn ($f) => $f->subject['row_id'] === $ids['lookalike']));
        $this->assertTrue($by('malware.obfuscated_payload')->contains(fn ($f) => $f->subject['row_id'] === $ids['lookalike'] && in_array('base64', $f->evidence['transformations'] ?? [], true)));
        $this->assertTrue($by('malware.redirect_or_overlay')->contains(fn ($f) => $f->subject['row_id'] === $ids['snippet_option'] && $f->behavior === 'redirect'));
        $this->assertTrue($by('malware.redirect_or_overlay')->contains(fn ($f) => $f->subject['row_id'] === $ids['fake_update'] && $f->behavior === 'fake_update'));
        $this->assertTrue($by('malware.credential_or_payment_capture')->contains(fn ($f) => $f->subject['row_id'] === $ids['card_skimmer']));
        $this->assertCount(1, $by('malware.reinfection_cluster'));

        // Content integrity.
        $this->assertTrue($by('content.unexpected_script')->contains(fn ($f) => $f->subject['row_id'] === $ids['cjk']));
        $this->assertTrue($by('content.spam_lexicon')->contains(fn ($f) => $f->subject['row_id'] === $ids['cjk']));
        $this->assertTrue($by('content.seo_meta_injection')->contains(fn ($f) => $f->subject['row_id'] === $ids['seo_meta']));
        $this->assertTrue($by('content.hidden_text')->contains(fn ($f) => $f->subject['row_id'] === $ids['hidden_term']));

        // Benign rows are never labelled malware.
        $benignMalware = $findings->filter(fn ($f) => $f->category === 'malware' && ($f->subject['row_id'] ?? null) === $ids['benign'] && $f->subject['surface'] === 'posts.content');
        $this->assertCount(0, $benignMalware);
        $this->assertCount(0, $by('access.open_registration'), 'A non-privileged default role is not a finding.');
        $this->assertFalse($findings->contains(fn ($f) => ($f->subject['row_id'] ?? null) === $ids['admin_ok'] && $f->rule_key === 'access.admin_unexpected_email'));

        // The secret-named option was excluded whole, never fetched.
        $this->assertFalse($findings->contains(fn ($f) => ($f->subject['row_id'] ?? null) === $ids['secret'] && $f->subject['surface'] === 'options.values'));
        $options = DbScanSurfaceCoverage::query()->where('market_run_id', $run->id)->where('surface_key', 'options.values')->firstOrFail();
        $this->assertGreaterThanOrEqual(1, $options->excluded_values);
        $this->assertSame('complete', $options->status);

        // Evidence is inert and redacted.
        $json = json_encode($findings->pluck('evidence'));
        $this->assertStringNotContainsString('should-never-be-read', $json);
        $this->assertStringNotContainsString('SESSIONTOKENSECRET', $json);
        $this->assertStringNotContainsString('secret-hash-never-read', $json);
        $this->assertStringNotContainsString('support_admin@wordpress.com', $json);
    }

    public function test_second_pass_creates_no_duplicates_and_resolves_a_removed_payload(): void
    {
        $pdo = $this->newFixture();
        $ids = $this->seedCompromisedMarket($pdo);
        $platform = $this->marketPlatform();
        $this->connectFixture($platform, $this->fixturePath($pdo));
        $passes = app(PassController::class);

        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();
        $firstCount = DbScanFinding::query()->count();
        $webshell = DbScanFinding::query()->where('rule_key', 'malware.db_webshell')->firstOrFail();

        $pdo->exec('DELETE FROM wp_posts WHERE ID = '.$ids['webshell']);
        $this->travel(2)->minutes();
        $this->freshOpsState();
        $platform->forceFill(['health_checked_at' => now()])->save();

        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();

        $this->assertSame($firstCount, DbScanFinding::query()->count(), 'Findings are not duplicated across runs.');
        $this->assertSame('resolved', $webshell->fresh()->status);
        $this->assertSame(1, $webshell->fresh()->occurrences);

        $remote = DbScanFinding::query()->where('rule_key', 'malware.remote_loader')->where('subject->row_id', $ids['external_script'])->firstOrFail();
        $this->assertSame(2, $remote->occurrences, 'Occurrences count distinct observing runs.');
        $this->assertSame('open', $remote->status);
    }

    public function test_a_stopped_run_records_incomplete_coverage_and_resolves_nothing(): void
    {
        $pdo = $this->newFixture();
        $ids = $this->seedCompromisedMarket($pdo);
        $platform = $this->marketPlatform();
        $this->connectFixture($platform, $this->fixturePath($pdo));
        $passes = app(PassController::class);

        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();
        $webshell = DbScanFinding::query()->where('rule_key', 'malware.db_webshell')->firstOrFail();
        $pdo->exec('DELETE FROM wp_posts WHERE ID = '.$ids['webshell']);

        $this->travel(2)->minutes();
        $this->freshOpsState();
        $platform->forceFill(['health_checked_at' => now()])->save();
        $pass = $passes->start([$platform->id], 'standard', 'manual');
        $passes->stop($pass);
        $this->drain();

        $run = DbScanMarketRun::query()->where('pass_id', $pass->id)->firstOrFail();
        $this->assertSame('stopped', $run->status);
        $this->assertSame('stopped', $pass->fresh()->status);
        $this->assertSame('stopped', DbScanSweep::query()->find($run->sweep_id)->status);
        $this->assertSame('open', $webshell->fresh()->status, 'A stopped run never resolves findings.');
        $this->assertTrue(DbScanSurfaceCoverage::query()->where('market_run_id', $run->id)->where('status', 'incomplete')->exists());
        $this->assertFalse(DbScanSurfaceCoverage::query()->where('market_run_id', $run->id)->where('status', 'complete')->exists());
    }

    public function test_budget_limited_runs_continue_the_same_sweep_until_the_final_range(): void
    {
        $pdo = $this->newFixture();
        $this->createWordPressSchema($pdo);
        $this->wpOption($pdo, 'siteurl', 'https://zim-market.test');
        $this->wpOption($pdo, 'home', 'https://zim-market.test');
        $this->wpOption($pdo, 'active_plugins', serialize([]));
        $this->wpUser($pdo, 'ian', 'ian@exotic-online.com', '2024-01-01 00:00:00');
        for ($i = 0; $i < 60; $i++) {
            $this->wpPost($pdo, '<p>Ordinary profile '.$i.'</p>', ['post_type' => 'escort']);
        }
        $last = $this->wpPost($pdo, '<p>x</p><script src="https://late-injection.test/x.js"></script>');

        $platform = $this->marketPlatform();
        $this->connectFixture($platform, $this->fixturePath($pdo));
        \App\Models\DbScanSetting::current()->forceFill(['limits' => ['chunk_rows' => 100]])->save();

        // A budget so small every run ends partial after a slice.
        config(['db_scanner.envelope.budgets.standard' => ['min' => 0, 'max' => 0, 'default' => 0]]);
        $passes = app(PassController::class);
        $passes->start([$platform->id], 'standard', 'manual');
        $this->drain();

        $first = DbScanMarketRun::query()->firstOrFail();
        $this->assertSame('partial', $first->status);
        $sweep = DbScanSweep::query()->findOrFail($first->sweep_id);
        $this->assertSame('running', $sweep->status);

        for ($i = 0; $i < 20 && $sweep->fresh()->status === 'running'; $i++) {
            $this->travel(2)->minutes();
            $this->freshOpsState();
            $platform->forceFill(['health_checked_at' => now()])->save();
            $this->assertNotNull($passes->continueSweep($sweep->fresh()));
            $this->drain();
        }

        $runs = DbScanMarketRun::query()->where('sweep_id', $sweep->id)->count();
        $this->assertGreaterThanOrEqual(3, $runs, 'The sweep needed several bounded runs.');
        $this->assertContains($sweep->fresh()->status, ['complete', 'complete_with_gaps']);
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'malware.remote_loader')->where('subject->row_id', $last)->exists());
    }
}
