<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanFinding;
use App\Models\DbScanSnapshot;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\FleetCampaignCorrelator;
use App\Services\DbScanner\FleetTriage;
use App\Services\DbScanner\ObservatoryPresenter;
use App\Services\DbScanner\Rules\NetworkIndicators;
use App\Services\DbScanner\Rules\RuleSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

/** Synthetic equivalents only. No imported market rows or secrets. */
class DbScannerMarketRegressionTest extends TestCase
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

    private function fixture(string $domain = 'regression.test'): array
    {
        $pdo = $this->newFixture();
        $this->createWordPressSchema($pdo);
        foreach (['siteurl' => 'https://'.$domain, 'home' => 'https://'.$domain, 'wp_user_roles' => serialize(['administrator' => ['capabilities' => ['manage_options' => true]], 'subscriber' => ['capabilities' => ['read' => true]]])] as $k => $v) {
            $this->wpOption($pdo, $k, $v);
        }
        $pdo->exec('CREATE TABLE wp_aryo_activity_log (histid INTEGER PRIMARY KEY AUTOINCREMENT, hist_time INTEGER, hist_ip TEXT, user_id INTEGER, object_type TEXT, object_name TEXT, action TEXT, request_source TEXT)');
        $platform = $this->marketPlatform($domain, $domain);
        $this->connectFixture($platform, $this->fixturePath($pdo));

        return [$pdo, $platform];
    }

    private function event($pdo, int $time, string $ip, int $id, string $name, string $action, string $type = 'Users', string $source = 'web'): void
    {
        $pdo->prepare('INSERT INTO wp_aryo_activity_log (hist_time,hist_ip,user_id,object_type,object_name,action,request_source) VALUES (?,?,?,?,?,?,?)')->execute([$time, $ip, $id, $type, $name, $action, $source]);
    }

    private function scan($platform): void
    {
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();
    }

    public function test_staff_installs_office_logins_system_updates_and_deleted_actors(): void
    {
        [$pdo, $p] = $this->fixture();
        $it = $this->wpUser($pdo, 'installer', 'dancun.owino@exotic-online.com', '2026-01-01 00:00:00');
        $t = strtotime('2026-09-09T20:00:00Z');
        for ($i = 0; $i < 49; $i++) {
            $this->event($pdo, $t - 50 + $i, '203.0.113.99', 0, 'installer', 'failed_login');
        }
        $this->event($pdo, $t - 1, '41.139.154.203', 0, 'installer', 'failed_login');
        $this->event($pdo, $t, '41.139.154.203', $it, 'installer', 'logged_in');
        $this->event($pdo, $t + 12, '41.139.154.203', $it, 'safe-draft-purger.zip', 'installed', 'Plugins');
        $this->event($pdo, $t + 13, '127.0.0.1', 0, 'System update', 'installed', 'Plugins', '');
        $this->event($pdo, $t + 14, '41.139.154.203', 999, 'deleted-actor.zip', 'uploaded', 'Attachments');
        $this->event($pdo, $t + 20, '212.18.127.44', $it, 'installer', 'logged_in');
        $this->event($pdo, $t + 32, '212.18.127.44', $it, 'Unknown dropped plugin', 'installed', 'Plugins');
        $this->scan($p);
        $hits = DbScanFinding::query()->where('rule_key', 'access.activity_behaviour')->get();
        $this->assertFalse($hits->contains(fn ($f) => str_contains($f->title, 'System update')));
        $approved = $hits->first(fn ($f) => ($f->evidence['details']['approved_install'] ?? false));
        $this->assertSame('info', $approved->severity);
        $this->assertSame('info', $hits->first(fn ($f) => ($f->evidence['details']['kind'] ?? '') === 'new_ip_login' && ($f->evidence['details']['ip'] ?? '') === '41.139.154.203')->severity);
        $this->assertTrue($hits->contains(fn ($f) => ($f->evidence['details']['user_id'] ?? 0) === 999 && ($f->evidence['details']['actor_account_missing'] ?? false) && str_contains($f->title, 'no longer exists')));
        $this->assertTrue($hits->contains(fn ($f) => ($f->evidence['details']['name'] ?? '') === 'Unknown dropped plugin' && $f->severity === 'critical'));
    }

    public function test_prior_login_and_approved_install_do_not_create_a_false_takeover(): void
    {
        [$pdo, $p] = $this->fixture();
        $it = $this->wpUser($pdo, 'installer', 'dancun.owino@exotic-online.com', '2026-01-01 00:00:00');
        $t = strtotime('2026-09-09T20:00:00Z');
        $this->event($pdo, $t, '203.0.113.55', $it, 'installer', 'logged_in');
        $this->event($pdo, $t + 86400, '203.0.113.55', $it, 'installer', 'logged_in');
        $this->event($pdo, $t + 86415, '203.0.113.55', $it, 'Safe Draft Purger', 'installed', 'Plugins');
        $this->scan($p);
        $hits = DbScanFinding::query()->where('rule_key', 'access.activity_behaviour')->get();
        $this->assertFalse($hits->contains(fn ($f) => $f->severity === 'critical'));
        $this->assertTrue($hits->contains(fn ($f) => ($f->evidence['details']['approved_install'] ?? false) && $f->severity === 'info'));
    }

    public function test_all_account_spam_daily_pressure_md5_and_email_health_are_aggregated(): void
    {
        [$pdo, $p] = $this->fixture();
        $this->wpOption($pdo, 'users_can_register', '1');
        $pdo->beginTransaction();
        for ($i = 0; $i < 1001; $i++) {
            $this->wpUser($pdo, 'www.scam.test - USD '.$i.' COINBASE', 'member'.$i.'@example.test', '2026-01-01 00:00:00', ['subscriber' => true]);
        }
        for ($i = 0; $i < 3; $i++) {
            $id = $this->wpUser($pdo, 'staff'.$i, 'staff'.$i.'@exotic-online.com', '2026-01-01 00:00:00');
            $pdo->exec("UPDATE wp_users SET user_pass='0123456789abcdef0123456789abcdef' WHERE ID=".$id);
        }
        $t = strtotime('2026-09-09T20:00:00Z');
        for ($i = 0; $i < 240; $i++) {
            $this->event($pdo, $t + $i, $i % 2 ? '192.0.2.1' : '192.0.2.2', 0, 'admin', 'failed_login');
        }
        for ($i = 0; $i < 40; $i++) {
            $this->event($pdo, $t + $i, '91.92.240.119', 0, 'not-stored-user', 'registered');
            $this->event($pdo, $t + $i, '', 0, 'SMTP Error: Could not authenticate', 'failed', 'Emails');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->event($pdo, $t + $i, '', 0, 'not-stored-message', 'sent', 'Emails');
        }
        $pdo->commit();
        $this->scan($p);
        $spam = DbScanFinding::query()->where('rule_key', 'access.registration_spam')->firstOrFail();
        $this->assertSame(1001, $spam->evidence['details']['suspicious_accounts']);
        $this->assertSame(40, $spam->evidence['details']['ioc_registration_count']);
        $this->assertStringNotContainsString('COINBASE', json_encode(DbScanSnapshot::query()->get()));
        $md5 = DbScanFinding::query()->where('rule_key', 'hygiene.md5_admin_passwords')->get();
        $this->assertCount(1, $md5);
        $this->assertSame(3, $md5[0]->evidence['details']['count']);
        $burst = DbScanFinding::query()->where('rule_key', 'access.activity_behaviour')->get();
        $this->assertCount(1, $burst);
        $this->assertSame(240, $burst[0]->evidence['details']['attempts']);
        $this->assertSame(2, $burst[0]->evidence['details']['ip_count']);
        $health = DbScanFinding::query()->where('rule_key', 'hygiene.email_delivery_health')->firstOrFail();
        $this->assertSame(0.8, $health->evidence['details']['failure_rate']);
        $this->assertSame(40, $health->evidence['details']['smtp_auth']);
    }

    public function test_low_volume_smtp_authentication_failure_is_visible_without_generic_email_noise(): void
    {
        [$pdoA, $a] = $this->fixture('smtp-a.test');
        [$pdoB, $b] = $this->fixture('smtp-b.test');
        $t = strtotime('2026-09-09T20:00:00Z');
        $this->event($pdoA, $t, '', 0, 'SMTP Error: Could not authenticate', 'failed', 'Emails');
        $this->event($pdoB, $t, '', 0, 'One unrelated delivery failure', 'failed', 'Emails');
        for ($i = 0; $i < 12; $i++) {
            $this->event($pdoA, $t + $i, '', 0, 'Message', 'sent', 'Emails');
            $this->event($pdoB, $t + $i, '', 0, 'Message', 'sent', 'Emails');
        }
        $this->scan($a);
        $this->scan($b);
        $finding = DbScanFinding::query()->where('platform_id', $a->id)->where('rule_key', 'hygiene.email_delivery_health')->firstOrFail();
        $this->assertSame('warn', $finding->severity);
        $this->assertFalse($finding->evidence['details']['high_failure_rate']);
        $this->assertTrue($finding->evidence['details']['low_sample_volume']);
        $this->assertSame(1, $finding->evidence['details']['smtp_auth']);
        $this->assertFalse(DbScanFinding::query()->where('platform_id', $b->id)->where('rule_key', 'hygiene.email_delivery_health')->exists());
    }

    public function test_subscriber_rotating_proxy_and_control_ip_sequences(): void
    {
        [$pdo, $p] = $this->fixture();
        $id = $this->wpUser($pdo, 'member', 'member@example.test', '2026-01-01 00:00:00', ['subscriber' => true]);
        $t = strtotime('2026-09-09T20:00:00Z');
        for ($i = 0; $i < 30; $i++) {
            $this->event($pdo, $t + $i * 10, '203.0.113.'.($i % 10 + 1), $id, 'member', 'logged_in');
        }
        $this->event($pdo, $t + 300, '91.92.240.119', $id, 'member', 'logged_in');
        $this->event($pdo, $t + 310, '91.92.240.119', 0, 'admin', 'failed_login');
        $this->event($pdo, $t + 320, '91.92.240.119', 0, 'other', 'failed_login');
        $this->scan($p);
        $hit = DbScanFinding::query()->where('rule_key', 'access.account_campaign')->firstOrFail();
        $this->assertSame('critical', $hit->severity);
        $this->assertSame(1, $hit->evidence['details']['ioc_successes']);
        $this->assertSame(1, $hit->evidence['details']['rotating_windows']);
        $this->assertSame(2, $hit->evidence['details']['control_failures']);
    }

    public function test_crm_key_family_partner_redirects_public_plugins_and_banner_exposure(): void
    {
        [$pdo, $p] = $this->fixture();
        $id = $this->wpUser($pdo, 'integration', 'integration@exotic-online.com', '2026-01-01 00:00:00');
        $pdo->prepare('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)')->execute([$id, '_application_passwords', serialize([['name' => 'Exotic-CRM Sync', 'password' => 'synthetic-secret', 'last_ip' => '198.38.92.95']])]);
        $this->wpOption($pdo, 'active_plugins', serialize(['easy-table-of-contents/main.php']));
        $this->wpOption($pdo, 'wpseo-premium-redirects-base', serialize([['origin' => 'partners/test', 'url' => 'https://partner.test'], ['origin' => 'login', 'url' => 'https://untrusted.test']]));
        $link = $this->wpPost($pdo, '<a href="https://nikkiexxxads.com">Link only</a>');
        $script = $this->wpPost($pdo, str_repeat('x', 71219).'<script>document.write(\'<iframe src="https://nikkiexxxads.com/banner">\');</script>');
        $iframe = $this->wpPost($pdo, '<iframe src="https://nikkiexxxads.com/banner"></iframe>');
        $this->scan($p);
        $this->assertFalse(DbScanFinding::query()->where('rule_key', 'access.application_passwords')->exists());
        $this->assertSame('warn', DbScanFinding::query()->where('rule_key', 'persistence.plugin_not_allowlisted')->firstOrFail()->severity);
        $redirects = DbScanFinding::query()->where('rule_key', 'content.external_redirects')->get();
        $this->assertTrue($redirects->contains(fn ($f) => $f->severity === 'info'));
        $this->assertTrue($redirects->contains(fn ($f) => $f->severity === 'critical'));
        $banners = DbScanFinding::query()->where('rule_key', 'content.banner_exchange_links')->get()->keyBy(fn ($f) => (int) $f->subject['row_id']);
        $this->assertSame('info', $banners[$link]->severity);
        $this->assertSame('critical', $banners[$script]->severity);
        $this->assertSame('critical', $banners[$iframe]->severity);
    }

    public function test_cidrs_are_exact_and_ioc_bounds_include_the_whole_range(): void
    {
        foreach (['91.92.240.0', '91.92.241.12', '91.92.243.255'] as $ip) {
            $this->assertTrue(NetworkIndicators::matches($ip, ['91.92.240.0/22']));
        }
        foreach (['91.92.244.1', '91.92.239.255', '91.92.24', '91.92.240.1.evil'] as $ip) {
            $this->assertFalse(NetworkIndicators::matches($ip, ['91.92.240.0/22']));
        }
        $this->assertTrue(NetworkIndicators::matches('2001:db8::1', ['2001:db8::/32']));
        $this->assertFalse(NetworkIndicators::matches('2001:db9::1', ['2001:db8::/32']));
    }

    public function test_fleet_key_ip_time_and_role_definition_fixtures(): void
    {
        $rules = new RuleSet(['rules' => ['malware.reinfection_cluster' => ['enabled' => true, 'in_profile' => true]], 'lists' => ['ioc.ips' => ['91.92.240.0/22']]]);
        $findings = [];
        foreach ([1, 2] as $p) {
            $findings[] = new DbScanFinding(['id' => $p, 'platform_id' => $p, 'rule_key' => 'access.application_passwords', 'severity' => 'critical', 'evidence' => ['details' => ['name' => 'xr-auto']]]);
            $findings[] = new DbScanFinding(['id' => $p + 2, 'platform_id' => $p, 'rule_key' => 'access.activity_behaviour', 'severity' => 'warn', 'evidence' => ['details' => ['kind' => 'new_ip_login', 'ip' => '185.174.136.197', 'at_utc' => $p === 1 ? '2023-08-15T12:00:00Z' : '2023-08-15T12:05:00Z']]]);
        }
        $hits = (new FleetCampaignCorrelator)->correlate($findings, 1, $rules);
        $this->assertCount(2, $hits);
        $this->assertSame('critical', $hits[0]->severity);
        $this->assertSame('warn', $hits[1]->severity);
        $this->assertTrue($hits[1]->evidence['details']['within_ten_minutes']);
        $this->assertCount(0, (new FleetCampaignCorrelator)->correlate($findings, 3, $rules));
        $rows = [];
        foreach ([1 => 'a', 2 => 'a', 3 => 'b'] as $p => $hash) {
            $rows[] = new DbScanFinding(['platform_id' => $p, 'rule_key' => 'access.role_grants_admin_capability', 'severity' => 'warn', 'evidence' => ['details' => ['role' => 'sales', 'definition_hash' => $hash]]]);
        }
        $groups = (new FleetTriage)->summarize($rows, [1 => 'A', 2 => 'B', 3 => 'C'])['groups'];
        $this->assertCount(2, $groups);
        $this->assertSame(2, $groups[0]['markets']);
    }

    public function test_staff_history_uses_fleet_mailbox_identity_without_sharing_emails(): void
    {
        [$pdoA, $a] = $this->fixture('a.test');
        [$pdoB, $b] = $this->fixture('b.test');
        $idA = $this->wpUser($pdoA, 'operator-a', 'same-staff@exotic-online.com', '2026-01-01 00:00:00');
        $idB = $this->wpUser($pdoB, 'operator-b', 'same-staff@exotic-online.com', '2026-01-01 00:00:00');
        $t = strtotime('2026-09-09T20:00:00Z');
        $this->event($pdoA, $t, '203.0.113.55', $idA, 'operator-a', 'logged_in');
        $this->scan($a);
        $this->event($pdoB, $t + 300, '203.0.113.55', $idB, 'operator-b', 'logged_in');
        $this->scan($b);
        $this->assertFalse(DbScanFinding::query()->where('platform_id', $b->id)->where('rule_key', 'access.activity_behaviour')->exists());
        $this->assertStringNotContainsString('same-staff@', json_encode(DbScanSnapshot::query()->get()));
    }

    public function test_real_engine_persists_cross_market_key_and_success_ip_correlations(): void
    {
        [$pdoA, $a] = $this->fixture('campaign-a.test');
        [$pdoB, $b] = $this->fixture('campaign-b.test');
        foreach ([[$pdoA, $a], [$pdoB, $b]] as [$pdo, $market]) {
            $id = $this->wpUser($pdo, 'member', 'member@example.test', '2026-01-01 00:00:00', ['subscriber' => true]);
            $pdo->prepare('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)')->execute([$id, '_application_passwords', serialize([['name' => 'xr-auto', 'password' => 'synthetic-private-secret']])]);
            $this->event($pdo, strtotime('2026-09-09T20:00:00Z'), '91.92.240.119', $id, 'member', 'logged_in');
            $this->scan($market);
        }
        $clusters = DbScanFinding::query()->where('platform_id', $b->id)->where('behavior', 'cross_market_campaign')->get();
        $this->assertCount(2, $clusters);
        $this->assertTrue($clusters->every(fn ($f) => $f->severity === 'critical' && $f->evidence['details']['markets'] === 2));
        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser('sub_admin', [$b->id]));
        foreach ($clusters as $f) {
            $response = $this->getJson('/api/crm/db-observatory/findings/'.$f->id)->assertOk();
            $response->assertJsonPath('finding.evidence.details.platform_ids', [$b->id]);
            $response->assertJsonPath('finding.evidence.details.markets', 1);
            $this->assertTrue(DbScanFinding::query()->whereIn('id', $response->json('finding.evidence.details.linked_finding_ids'))->get()->every(fn ($linked) => $linked->platform_id === $b->id));
        }
    }

    public function test_remote_connection_requires_tls_and_keeps_the_remote_host_in_site_mode(): void
    {
        $market = $this->marketPlatform('Remote', 'remote.test');
        $market->forceFill(['db_host' => 'd14866.test', 'db_name' => 'account_wp123', 'db_user' => 'account_user', 'db_pass' => 'private-secret', 'db_prefix' => 'site_'])->save();
        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser());
        $payload = ['credential_source' => 'site_login', 'site_login_acknowledged' => true, 'tls_mode' => 'none'];
        $this->putJson('/api/crm/db-observatory/connections/'.$market->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('tls_mode');
        $payload['tls_mode'] = 'verify';
        $this->putJson('/api/crm/db-observatory/connections/'.$market->id, $payload)->assertOk();
        $connection = \App\Models\DbScanConnection::query()->firstOrFail();
        $target = app(\App\Services\DbScanner\Reader\ScannerCredentialResolver::class)->forConnection($connection);
        $this->assertSame('d14866.test', $target->host);
        $this->assertSame('verify', $target->tlsMode);
        $this->assertNull($target->socket);
        $this->assertFalse($target->isLocal());
    }

    public function test_fleet_role_definition_drilldown_is_exact_and_scoped(): void
    {
        [$pdoA, $a] = $this->fixture('roles-a.test');
        [$pdoB, $b] = $this->fixture('roles-b.test');
        foreach ([[$pdoA, $a, 'install_plugins'], [$pdoB, $b, 'edit_files']] as [$pdo, $market, $cap]) {
            $pdo->exec("DELETE FROM wp_options WHERE option_name = 'wp_user_roles'");
            $this->wpOption($pdo, 'wp_user_roles', serialize(['administrator' => ['capabilities' => ['manage_options' => true]], 'sales' => ['capabilities' => [$cap => true]]]));
            $this->scan($market);
        }
        $finding = DbScanFinding::query()->where('platform_id', $a->id)->where('rule_key', 'access.role_grants_admin_capability')->firstOrFail();
        $hash = $finding->evidence['details']['definition_hash'];
        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser());
        $url = '/api/crm/db-observatory/findings?status=active&rule_key=access.role_grants_admin_capability&identity=sales&definition_hash='.$hash;
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.platform_id', $a->id);
        $this->getJson(str_replace($hash, 'invalid', $url))->assertUnprocessable();
        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser('sub_admin', [$b->id]));
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_fleet_correlation_details_are_filtered_to_viewer_market_scope(): void
    {
        $finding = new DbScanFinding(['platform_id' => 1, 'behavior' => 'cross_market_campaign', 'evidence' => ['details' => ['platform_ids' => [1, 2], 'markets' => 2, 'linked_finding_ids' => [], 'within_ten_minutes' => true]]]);
        $details = (new ObservatoryPresenter)->finding($finding, [1 => 'A'])['evidence']['details'];
        $this->assertSame([1], $details['platform_ids']);
        $this->assertSame(1, $details['markets']);
        $this->assertNull($details['within_ten_minutes']);
        $this->assertTrue($details['scope_restricted']);
    }
}
