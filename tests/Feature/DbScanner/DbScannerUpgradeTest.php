<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanAuditEvent;
use App\Models\DbScanConnection;
use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\Engine\Preflight;
use App\Services\DbScanner\FleetTriage;
use App\Services\DbScanner\Reader\ScannerCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

class DbScannerUpgradeTest extends TestCase
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

    private function fixture(): array
    {
        $pdo = $this->newFixture();
        $this->createWordPressSchema($pdo);
        foreach (['siteurl' => 'https://upgrade.test', 'home' => 'https://upgrade.test', 'template' => 'escortwp', 'stylesheet' => 'escortwp-child', 'wp_user_roles' => serialize(['administrator' => ['capabilities' => ['manage_options' => true]], 'subscriber' => ['capabilities' => ['read' => true]]])] as $k => $v) {
            $this->wpOption($pdo, $k, $v);
        }
        $platform = $this->marketPlatform('Upgrade', 'upgrade.test');
        $this->connectFixture($platform, $this->fixturePath($pdo));

        return [$pdo, $platform];
    }

    private function scan($platform): void
    {
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();
    }

    public function test_all_user_keys_are_checked_and_hashes_never_enter_evidence(): void
    {
        [$pdo, $platform] = $this->fixture();
        $id = $this->wpUser($pdo, 'ordinary-member', 'member@upgrade.test', '2026-01-01 00:00:00', ['subscriber' => true]);
        $meta = $pdo->prepare('INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
        $meta->execute([$id, '_application_passwords', serialize([
            ['name' => 'Website System Key (DO NOT DELETE)', 'password' => 'synthetic-private-hash-marker', 'last_ip' => '203.0.113.17'],
            ['name' => 'EWMS', 'password' => 'synthetic-trusted-hash-marker', 'last_ip' => '65.98.29.11'],
            ['name' => 'Exotic CRM', 'password' => 'hash', 'last_ip' => '203.0.113.19'],
        ])]);
        $this->scan($platform);
        $findings = DbScanFinding::query()->where('rule_key', 'access.application_passwords')->get();
        $this->assertCount(2, $findings);
        $this->assertSame(['critical'], $findings->pluck('severity')->unique()->all());
        $this->assertStringNotContainsString('hash-marker', json_encode(\App\Models\DbScanSnapshot::query()->get()));
        $this->assertStringNotContainsString('hash-marker', json_encode($findings));
        $this->assertStringNotContainsString('password"', json_encode($findings->pluck('evidence')));
    }

    public function test_public_exposure_and_long_published_content_are_detected_without_honeypot_noise(): void
    {
        [$pdo, $platform] = $this->fixture();
        $this->wpOption($pdo, 'sidebars_widgets', serialize(['footer-home-only' => ['text-16'], 'wp_inactive_widgets' => ['text-17']]));
        $this->wpOption($pdo, 'widget_text', serialize([
            16 => ['text' => '<div style="position:absolute;left:-5000px"><a href="https://betting.test">aviator</a></div>'],
            17 => ['text' => '<div style="display:none"><a href="https://betting.test">aviator</a></div>'],
            18 => ['text' => '<div style="left:-5000px"><input name="b_mailchimp" /></div>'],
        ]));
        $public = $this->wpPost($pdo, '<a href="https://betting.test" style="color:#fff;background:#fff;">Hidden</a>');
        $legitimate = $this->wpPost($pdo, '<a href="https://upgrade.test" style="color:#fff;">Visible on a dark theme</a><div style="background-color:transparent"><a href="https://upgrade.test">Visible</a></div>');
        $private = $this->wpPost($pdo, '<div style="display:none"><a href="https://betting.test">Hidden</a></div>');
        $pdo->exec("UPDATE wp_posts SET post_status='private' WHERE ID=".$private);
        $long = $this->wpPost($pdo, str_repeat('x', 71219).'<script src="https://late-banner.test/x.js"></script>');
        $this->scan($platform);
        $hidden = DbScanFinding::query()->where('rule_key', 'content.hidden_text')->get();
        $this->assertTrue($hidden->contains(fn ($f) => ($f->evidence['exposure'] ?? null) === 'rendered_widget:footer-home-only' && $f->severity === 'critical'));
        $this->assertTrue($hidden->contains(fn ($f) => (int) ($f->subject['row_id'] ?? 0) === $public && $f->severity === 'critical'));
        $this->assertTrue($hidden->contains(fn ($f) => (int) ($f->subject['row_id'] ?? 0) === $private && $f->severity === 'warn'));
        $this->assertFalse($hidden->contains(fn ($f) => (int) ($f->subject['row_id'] ?? 0) === $legitimate && $f->severity === 'critical'));
        $this->assertFalse($hidden->contains(fn ($f) => str_contains(json_encode($f->subject), '[18]')));
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'malware.remote_loader')->get()->contains(fn ($f) => (int) ($f->subject['row_id'] ?? 0) === $long && str_contains(json_encode($f->evidence), 'late-banner.test')));
        $this->assertLessThan(4 * 1024 * 1024, DbScanMarketRun::query()->where('mode', 'scan')->first()->metrics['bytes_read']);
    }

    public function test_invisible_plugins_hygiene_and_exact_allowlists(): void
    {
        [$pdo, $platform] = $this->fixture();
        $admin = $this->wpUser($pdo, 'director', 'joolyan@gmail.com', '2026-01-01 00:00:00');
        $other = $this->wpUser($pdo, 'other', 'other@gmail.com', '2026-01-01 00:00:00');
        $pdo->exec("UPDATE wp_users SET user_pass='0123456789abcdef0123456789abcdef' WHERE ID=".$admin);
        $this->wpOption($pdo, 'active_plugins', serialize(['unknown-panel/panel.php', 'tanzania-sms-triggers/main.php', 'safe-draft-purger/main.php']));
        $this->wpOption($pdo, '_site_transient_update_plugins', serialize(['checked' => ['tanzania-sms-triggers/main.php' => '1.0', 'safe-draft-purger/main.php' => '1.0']]));
        $this->wpOption($pdo, '__mnx_versions', json_encode(['plugins' => ['unknown-panel/panel.php' => ['version' => null]]]));
        $this->wpOption($pdo, 'backwpup_jobs', serialize(['token' => 'never-output-token-marker']));
        $this->scan($platform);
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'persistence.invisible_plugins')->where('severity', 'critical')->exists());
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'hygiene.md5_admin_passwords')->exists());
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'hygiene.stored_integration_secrets')->exists());
        $emails = DbScanFinding::query()->where('rule_key', 'access.admin_unexpected_email')->get();
        $this->assertFalse($emails->contains(fn ($f) => ($f->evidence['details']['login'] ?? null) === 'director'));
        $this->assertTrue($emails->contains(fn ($f) => ($f->evidence['details']['login'] ?? null) === 'other'));
        $this->assertFalse(DbScanFinding::query()->where('rule_key', 'persistence.plugin_not_allowlisted')->get()->contains(fn ($f) => str_contains(json_encode($f->evidence), 'tanzania-sms') || str_contains(json_encode($f->evidence), 'safe-draft')));
        $this->assertStringNotContainsString('never-output-token-marker', json_encode(DbScanFinding::query()->get()));
    }

    public function test_activity_login_storm_install_sequence_and_timezone(): void
    {
        [$pdo, $platform] = $this->fixture();
        $id = $this->wpUser($pdo, 'operator', 'operator@exotic-online.com', '2026-01-01 00:00:00');
        $this->wpOption($pdo, 'gmt_offset', '3');
        $pdo->exec('CREATE TABLE wp_aryo_activity_log (histid INTEGER PRIMARY KEY AUTOINCREMENT, hist_time INTEGER, hist_ip TEXT, user_id INTEGER, object_type TEXT, object_name TEXT, action TEXT, request_source TEXT)');
        $stmt = $pdo->prepare('INSERT INTO wp_aryo_activity_log (hist_time,hist_ip,user_id,object_type,object_name,action,request_source) VALUES (?,?,?,?,?,?,?)');
        $t = strtotime('2026-09-09 23:00:00 UTC');
        $stmt->execute([$t - 86400, '192.0.2.1', $id, 'Users', 'operator', 'logged_in', 'web']);
        for ($i = 0; $i < 130; $i++) {
            $stmt->execute([$t - 130 + $i, '203.0.113.9', 0, 'Users', 'operator', 'failed_login', 'web']);
        }
        $stmt->execute([$t, '203.0.113.9', $id, 'Users', 'operator', 'logged_in', 'web']);
        $stmt->execute([$t + 15, '203.0.113.9', $id, 'Plugins', 'Unrecognised plugin', 'installed', 'web']);
        $stmt->execute([$t + 30, '', $id, 'Plugins', 'Legitimate automation', 'installed', 'cli']);
        $this->scan($platform);
        $hits = DbScanFinding::query()->where('rule_key', 'access.activity_behaviour')->get();
        $this->assertCount(4, $hits);
        $this->assertSame(2, $hits->where('severity', 'critical')->count());
        $this->assertTrue($hits->contains(fn ($f) => ($f->evidence['details']['at_utc'] ?? null) === '2026-09-09T20:00:00Z' && ($f->evidence['details']['failed_before'] ?? 0) === 130));
        $this->assertFalse($hits->contains(fn ($f) => str_contains(json_encode($f->evidence), 'Legitimate automation')));
    }

    public function test_site_login_is_explicit_audited_encrypted_and_invalidates_on_profile_change(): void
    {
        $market = $this->marketPlatform('Site login', 'site-login.test');
        $market->forceFill(['db_host' => 'localhost', 'db_name' => 'account_wp123', 'db_user' => 'account_user', 'db_pass' => 'private-secret', 'db_prefix' => 'site_'])->save();
        Sanctum::actingAs($this->adminUser());
        $payload = ['credential_source' => 'site_login', 'tls_mode' => 'none'];
        $this->putJson('/api/crm/db-observatory/connections/'.$market->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('site_login_acknowledged');
        $this->putJson('/api/crm/db-observatory/connections/'.$market->id, $payload + ['site_login_acknowledged' => true])->assertOk();
        $c = DbScanConnection::query()->firstOrFail();
        $this->assertNull($c->password);
        $target = app(ScannerCredentialResolver::class)->forConnection($c);
        $this->assertSame('private-secret', $target->password);
        $this->assertSame('site_', $target->prefix);
        $c->forceFill(['preflight_status' => 'passed', 'preflight_config_version' => $c->config_version, 'preflight_credential_fingerprint' => $c->credentialFingerprint()])->save();
        $this->assertTrue($c->preflightValid());
        $market->forceFill(['db_pass' => 'rotated-secret'])->save();
        $this->assertFalse($c->preflightValid());
        $this->assertTrue(DbScanAuditEvent::query()->where('entity', 'connection')->exists());
        $this->assertStringNotContainsString('private-secret', $this->getJson('/api/crm/db-observatory/connections')->assertOk()->getContent());
        $this->putJson('/api/crm/db-observatory/connections/'.$market->id, ['credential_source' => 'dedicated', 'host' => 'localhost', 'database' => 'account_wp123', 'prefix' => 'site_', 'tls_mode' => 'none'])->assertUnprocessable()->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_site_grant_policy_still_rejects_global_other_schema_and_roles(): void
    {
        $p = app(Preflight::class);
        $this->assertTrue($p->evaluateGrants(['GRANT USAGE ON *.* TO `u`@`localhost`', 'GRANT ALL PRIVILEGES ON `account_wp123`.* TO `u`@`localhost`'], 'account_wp123', 'mysql', 'site_login')[0]);
        foreach (['GRANT ALL PRIVILEGES ON *.* TO `u`@`localhost`', 'GRANT SELECT ON `other`.* TO `u`@`localhost`', 'GRANT FILE ON `account_wp123`.* TO `u`@`localhost`', 'GRANT `role` TO `u`@`localhost`', 'GRANT SELECT ON `account_wp123`.* TO `u`@`localhost` WITH GRANT OPTION'] as $grant) {
            $this->assertFalse($p->evaluateGrants([$grant], 'account_wp123', 'mysql', 'site_login')[0]);
        }
    }

    public function test_fleet_scope_inventory_coverage_and_run_provenance(): void
    {
        [$pdo, $platform] = $this->fixture();
        $this->wpPost($pdo, '<script src="https://shared.test/x.js"></script>');
        $this->scan($platform);
        $this->marketPlatform('Unconnected', 'unconnected.test');
        Sanctum::actingAs($this->adminUser());
        $this->getJson('/api/crm/db-observatory/overview')->assertOk()->assertJsonPath('coverage.markets_total', 2)->assertJsonPath('coverage.markets_unconnected', 1);
        $run = DbScanMarketRun::query()->where('mode', 'scan')->firstOrFail();
        $this->getJson('/api/crm/db-observatory/market-runs/'.$run->id)->assertOk()->assertJsonStructure(['run' => ['provenance' => ['code', 'pack_hash', 'config_hash']]]);
        $rows = collect([new DbScanFinding(['platform_id' => 1, 'rule_key' => 'access.activity_behaviour', 'title' => 'Login', 'severity' => 'critical', 'subject' => [], 'evidence' => ['details' => ['ip' => '203.0.113.9', 'login' => 'operator', 'at_utc' => '2026-09-09T20:00:00Z']]]), new DbScanFinding(['platform_id' => 2, 'rule_key' => 'access.activity_behaviour', 'title' => 'Login', 'severity' => 'critical', 'subject' => [], 'evidence' => ['details' => ['ip' => '203.0.113.9', 'login' => 'operator', 'at_utc' => '2026-09-09T20:05:00Z']]])]);
        $summary = (new FleetTriage)->summarize($rows, [1 => 'A', 2 => 'B']);
        $this->assertSame(2, $summary['correlations'][0]['same_day_markets']);
        $this->assertSame(2, $summary['groups'][0]['markets']);
        Sanctum::actingAs($this->adminUser('sub_admin', [$platform->id]));
        $this->getJson('/api/crm/db-observatory/overview')->assertOk()->assertJsonPath('coverage.markets_total', 1)->assertJsonPath('coverage.markets_unconnected', 0);
    }

    public function test_long_exchange_links_placeholders_and_unplaced_loaders_keep_their_context(): void
    {
        [$pdo, $platform] = $this->fixture();
        $long = $this->wpPost($pdo, str_repeat('x', 71219).'<a href="https://nikkiexxxads.com/">Exchange</a>');
        $this->wpOption($pdo, 'widget_custom_html', serialize([9 => ['content' => '<script src="https://unplaced.test/x.js"></script>']]));
        $this->wpOption($pdo, 'sidebars_widgets', serialize(['wp_inactive_widgets' => ['custom_html-9']]));
        for ($i = 0; $i < 15; $i++) {
            $this->wpUser($pdo, 'onboard-'.$i, 'onboard+'.$i.'@www.upgrade.test', '2026-01-01 00:00:00', ['subscriber' => true]);
        }
        $id = $this->wpPost($pdo, '<a style="color:#fff" href="https://upgrade.test">Visible</a><div style="display:none"><a href="https://betting.test">Hidden</a></div>');
        $this->scan($platform);
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'content.banner_exchange_links')->get()->contains(fn ($f) => (int) ($f->subject['row_id'] ?? 0) === $long));
        $this->assertFalse(DbScanFinding::query()->where('rule_key', 'access.lookalike_account_email_domains')->exists());
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'malware.remote_loader')->get()->contains(fn ($f) => str_contains(json_encode($f->evidence), 'unplaced.test') && $f->severity === 'warn'));
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'content.hidden_text')->get()->contains(fn ($f) => (int) ($f->subject['row_id'] ?? 0) === $id && $f->severity === 'critical'));
        Sanctum::actingAs($this->adminUser());
        $this->getJson('/api/crm/db-observatory/findings?status=active&rule_key=malware.remote_loader&identity=widget_custom_html')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
