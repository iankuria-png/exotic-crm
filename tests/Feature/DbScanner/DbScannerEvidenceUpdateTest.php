<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Models\DbScanSnapshot;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\Engine\WordfenceAlertCollector;
use App\Services\DbScanner\Rules\RuleSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

/** Synthetic equivalents of update 2; imported market data stays local. */
class DbScannerEvidenceUpdateTest extends TestCase
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

    private function fixture(string $host = 'update.test'): array
    {
        $pdo = $this->newFixture();
        $this->createWordPressSchema($pdo);
        foreach (['siteurl' => 'https://'.$host, 'home' => 'https://'.$host, 'gmt_offset' => '3', 'wp_user_roles' => serialize(['administrator' => ['capabilities' => ['manage_options' => true]], 'subscriber' => ['capabilities' => ['read' => true]]])] as $k => $v) {
            $this->wpOption($pdo, $k, $v);
        }
        $pdo->exec('CREATE TABLE wp_aryo_activity_log (histid INTEGER PRIMARY KEY AUTOINCREMENT, hist_time INTEGER, hist_ip TEXT, user_id INTEGER, object_type TEXT, object_name TEXT, action TEXT, request_source TEXT)');
        $pdo->exec('CREATE TABLE wp_email_log (id INTEGER PRIMARY KEY AUTOINCREMENT, subject TEXT, message TEXT, sent_date TEXT)');
        $p = $this->marketPlatform($host, $host);
        $this->connectFixture($p, $this->fixturePath($pdo));

        return [$pdo, $p];
    }

    private function event($pdo, int $time, string $ip, int $id, string $name, string $action): void
    {
        $pdo->prepare('INSERT INTO wp_aryo_activity_log (hist_time,hist_ip,user_id,object_type,object_name,action,request_source) VALUES (?,?,?,\'Users\',?,?,\'web\')')->execute([$time, $ip, $id, $name, $action]);
    }

    private function key($pdo, int $id, string $name, string $ip): void
    {
        $pdo->prepare('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,\'_application_passwords\',?)')->execute([$id, serialize([['name' => $name, 'password' => 'synthetic-private-key-hash', 'last_ip' => $ip, 'last_used' => strtotime('2026-09-30T10:00:00Z')]])]);
    }

    private function scan($p): void
    {
        app(PassController::class)->start([$p->id], 'standard', 'manual');
        $this->drain();
    }

    public function test_keyed_account_rotation_does_not_require_ioc_or_rapid_logins(): void
    {
        [$pdo, $p] = $this->fixture();
        $id = $this->wpUser($pdo, 'dormant-member', 'member@example.test', '2024-01-01 00:00:00', ['subscriber' => true]);
        $ordinary = $this->wpUser($pdo, 'ordinary-member', 'ordinary@example.test', '2024-01-01 00:00:00', ['subscriber' => true]);
        $this->key($pdo, $id, 'auto-bootstrap', '176.53.159.25');
        for ($i = 0; $i < 19; $i++) {
            $t = strtotime('2026-09-06T10:00:00Z') + $i * 86400;
            foreach ([[$id, 'dormant-member'], [$ordinary, 'ordinary-member']] as [$actor, $name]) {
                $this->event($pdo, $t, '203.0.113.'.($i + 1), $actor, $name, 'logged_in');
            }
        }
        $this->scan($p);
        $hits = DbScanFinding::query()->where('rule_key', 'access.account_campaign')->get();
        $this->assertCount(1, $hits);
        $this->assertSame($id, $hits[0]->evidence['details']['user_id']);
        $this->assertSame(19, $hits[0]->evidence['details']['keyed_rotation']['distinct_ips']);
        $this->assertSame(0, $hits[0]->evidence['details']['rotating_windows']);
        $this->assertSame('2026-09-06T07:00:00Z', $hits[0]->evidence['details']['at_utc']);
    }

    public function test_failed_campaign_targets_are_warning_and_successful_accounts_are_excluded(): void
    {
        [$pdo, $p] = $this->fixture();
        $t = strtotime('2026-09-09T10:00:00Z');
        foreach (['target-member', 'successful-member', 'office-member'] as $name) {
            // A company-domain address alone does not establish staff ownership.
            $id = $this->wpUser($pdo, $name, $name.($name === 'target-member' ? '@exotic-africa.com' : '@example.test'), '2024-01-01 00:00:00', ['subscriber' => true]);
            for ($i = 0; $i < 120; $i++) {
                $this->event($pdo, $t + $i, $name === 'office-member' ? '41.139.154.203' : '91.92.241.12', 0, $name, 'failed_login');
            }
            if ($name === 'successful-member') {
                $this->event($pdo, $t + 121, '203.0.113.3', $id, $name, 'logged_in');
            }
        }
        $this->scan($p);
        $f = DbScanFinding::query()->where('rule_key', 'access.targeted_accounts')->firstOrFail();
        $this->assertSame('warn', $f->severity);
        $this->assertSame(1, $f->evidence['details']['account_count']);
        $this->assertSame('target-member', $f->evidence['details']['accounts'][0]['login']);
        $this->assertSame(120, $f->evidence['details']['accounts'][0]['attempts']);
        $this->assertStringContainsString('not proof of compromise', $f->evidence['details']['interpretation']);
    }

    public function test_historical_alerts_keep_metadata_deleted_actors_and_utc_but_never_bodies(): void
    {
        [$pdo, $p] = $this->fixture();
        $this->wpUser($pdo, 'operator', 'it@exotic-online.com', '2024-01-01 00:00:00');
        $insert = $pdo->prepare('INSERT INTO wp_email_log (subject,message,sent_date) VALUES (?,?,?)');
        foreach ([['operator', '185.174.136.197'], ['deleted-operator', '203.0.113.15'], ['operator', '41.139.154.203']] as [$name, $ip]) {
            $insert->execute(['[Wordfence Alert] update.test Admin Login', '<p>A user with username &quot;'.$name.'&quot; who has administrator access signed in to your WordPress site.</p><p>User IP: '.$ip.'</p><p>User location: Test City, Test Country</p><p>private-email-body-marker password=not-to-persist</p>', '2023-08-15 12:00:00']);
        }
        $insert->execute(['Customer message', 'private-non-alert-body-marker', '2023-08-15 12:00:00']);
        $this->scan($p);
        $hits = DbScanFinding::query()->where('rule_key', 'access.historical_privileged_logins')->get();
        $this->assertCount(2, $hits);
        $known = $hits->firstWhere('severity', 'critical');
        $this->assertSame('operator', $known->evidence['details']['login']);
        $this->assertSame('2023-08-15T12:00:00Z', $known->evidence['details']['at_utc'], 'Email TIMESTAMP is UTC, not activity site-local.');
        $this->assertSame('Test City, Test Country', $known->evidence['details']['samples'][0]['location']);
        $this->assertSame('needs_review', $known->confidence, 'Emails are stored claims, not verified successful logins.');
        $deleted = $hits->firstWhere('severity', 'warn');
        $this->assertTrue($deleted->evidence['details']['actor_account_missing']);
        $this->assertNull($deleted->evidence['details']['user_id']);
        $stored = json_encode([DbScanSnapshot::query()->get(), DbScanFinding::query()->get()]);
        foreach (['private-email-body-marker', 'private-non-alert-body-marker', 'not-to-persist', 'synthetic-private-key-hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    public function test_malformed_or_truncated_alerts_leave_explicit_coverage_gap(): void
    {
        [$pdo, $p] = $this->fixture();
        $pdo->prepare('INSERT INTO wp_email_log (subject,message,sent_date) VALUES (?,?,?)')->execute(['[Wordfence Alert] update.test Admin Login', str_repeat('x', 20000), '2023-08-15 12:00:00']);
        $this->scan($p);
        $run = DbScanMarketRun::query()->firstOrFail();
        $this->assertSame('incomplete', $run->cursor['inventory']['surfaces']['email.wordfence_logins']['status']);
        $this->assertSame('alert_metadata_incomplete', $run->cursor['inventory']['surfaces']['email.wordfence_logins']['reason']);
        $this->assertFalse(DbScanFinding::query()->where('rule_key', 'access.historical_privileged_logins')->exists());
    }

    public function test_file_coverage_gap_is_explicit_and_database_completion_keeps_its_scope(): void
    {
        [$pdo, $p] = $this->fixture();
        $this->scan($p);
        $run = DbScanMarketRun::query()->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(['status' => 'excluded', 'reason' => 'filesystem_not_inspected'], $run->cursor['inventory']['surfaces']['filesystem.visibility']);
        Sanctum::actingAs($this->adminUser());
        $this->getJson('/api/crm/db-observatory/market-runs/'.$run->id.'/coverage')->assertOk()->assertJsonPath('coverage_scope.filesystem_inspected', false)->assertJsonPath('scope_gaps.0.reason', 'filesystem_not_inspected');
        $this->getJson('/api/crm/db-observatory/market-runs/'.$run->id)->assertOk()->assertJsonPath('run.coverage_scope.scope', 'database');
    }

    public function test_old_subsidiary_crm_key_exception_is_exact_and_requires_trusted_ip(): void
    {
        foreach ([['exotic-tz.net', '198.38.92.95', false], ['other.test', '198.38.92.95', true], ['bad.exotic-tz.net', '198.38.92.95', true], ['exotic-tz.net', '203.0.113.15', true]] as [$host, $ip, $expect]) {
            $rules = new RuleSet(['site_host' => $host, 'lists' => ['allow.crm_ips' => ['198.38.92.95']]]);
            $hits = (new \App\Services\DbScanner\Rules\InventoryMatchers)->run('application_passwords', 'access.application_passwords', $rules, ['app_passwords' => ['data' => [['name' => 'tzz', 'last_ip' => $ip, 'privileged' => true, 'user_id' => 1, 'row_id' => 1]]]], null);
            $this->assertSame($expect, $hits !== []);
        }
    }

    public function test_last_used_key_ip_clusters_different_key_names_without_login_evidence(): void
    {
        [$pdoA, $a] = $this->fixture('key-a.test');
        [$pdoB, $b] = $this->fixture('key-b.test');
        foreach ([[$pdoA, $a, 'auto-bootstrap'], [$pdoB, $b, 'xr-auto']] as [$pdo, $p, $name]) {
            $id = $this->wpUser($pdo, 'member', 'member@example.test', '2024-01-01 00:00:00', ['subscriber' => true]);
            $this->key($pdo, $id, $name, '176.53.159.25');
            $this->scan($p);
        }
        $cluster = DbScanFinding::query()->where('platform_id', $b->id)->where('behavior', 'cross_market_campaign')->firstOrFail();
        $this->assertSame('application_password_last_ip', $cluster->evidence['details']['indicator_type']);
        $this->assertSame(2, $cluster->evidence['details']['markets']);
        $this->assertTrue($cluster->evidence['details']['within_ten_minutes']);
        $this->assertSame('critical', $cluster->severity);
    }

    public function test_alert_parser_rejects_non_admin_claims_and_invalid_ips(): void
    {
        $parser = new WordfenceAlertCollector;
        $this->assertNull($parser->parse('username "member" signed in. User IP: 203.0.113.1'));
        $this->assertNull($parser->parse('username "member" who has administrator access signed in. User IP: 203.0.113.1.evil'));
        $this->assertNull($parser->parse('username "member" who has administrator access signed in. User IP: not-an-ip'));
    }
}
