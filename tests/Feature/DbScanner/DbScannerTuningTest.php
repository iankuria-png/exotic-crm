<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanAuditEvent;
use App\Models\DbScanFinding;
use App\Models\DbScanList;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\PackSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

/**
 * Regressions from the 2 Oct 2026 review of the production Kenya dump:
 * false negatives that were upgraded and false positives that were
 * downgraded, each reproduced with synthetic rows.
 */
class DbScannerTuningTest extends TestCase
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

    private function baseMarket(): PDO
    {
        $pdo = $this->newFixture();
        $this->createWordPressSchema($pdo);
        $roles = [
            'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true, 'edit_users' => true]],
            'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true, 'level_0' => true]],
            'customerservice' => ['name' => 'CS', 'capabilities' => ['read' => true, 'administrator' => true, 'level_10' => true]],
            'seo_manager' => ['name' => 'SEO', 'capabilities' => ['read' => true, 'edit_themes' => true, 'manage_options' => true]],
        ];
        $this->wpOption($pdo, 'siteurl', 'https://kenya-market.test');
        $this->wpOption($pdo, 'home', 'https://kenya-market.test');
        $this->wpOption($pdo, 'wp_user_roles', serialize($roles));
        $this->wpOption($pdo, 'active_plugins', serialize(['wordpress-seo/wp-seo.php']));
        $this->wpUser($pdo, 'ian', 'ian@exotic-online.com', '2024-01-01 00:00:00');

        return $pdo;
    }

    private function scan(PDO $pdo, string $profile = 'standard'): void
    {
        $platform = $this->marketPlatform('Kenya', 'kenya-market.test');
        $this->connectFixture($platform, $this->fixturePath($pdo));
        app(PassController::class)->start([$platform->id], $profile, 'manual');
        $this->drain();
    }

    public function test_newest_admins_are_seen_beyond_the_old_capability_row_cap(): void
    {
        $pdo = $this->baseMarket();
        $pdo->beginTransaction();
        $users = $pdo->prepare('INSERT INTO wp_users (user_login, user_pass, user_nicename, user_email, user_registered, display_name) VALUES (?, ?, ?, ?, ?, ?)');
        $meta = $pdo->prepare('INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
        for ($i = 0; $i < 2600; $i++) {
            $users->execute(['member'.$i, 'x', 'm', 'member'.$i.'@kenya-market.test', '2025-01-01 00:00:00', 'm']);
            $id = (int) $pdo->lastInsertId();
            $meta->execute([$id, 'wp_capabilities', serialize(['subscriber' => true])]);
            $meta->execute([$id, 'wp_user_level', '0']);
        }
        $pdo->commit();
        $this->wpUser($pdo, 'anytg', 'someone@gmail.com', '2026-10-01 09:54:52');

        $this->scan($pdo, 'quick');

        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'access.admin_unexpected_email')->get()
            ->contains(fn ($f) => ($f->evidence['details']['login'] ?? null) === 'anytg'), 'An admin created after 5,200 capability rows is still found.');
    }

    public function test_known_widget_scripts_and_dormant_option_loaders_are_review_items_not_malware(): void
    {
        $pdo = $this->baseMarket();
        $form = $this->wpPost($pdo, '<form action="https://x.us1.list-manage.com/subscribe/post"></form><script type="text/javascript" src="//s3.amazonaws.com/downloads.mailchimp.com/js/mc-validate.js"></script>');
        $injected = $this->wpPost($pdo, '<script src="https://evil-cdn.test/i.js"></script>');
        $dormant = $this->wpOption($pdo, 'alexacertify_certify', '<script>var as=document.createElement("script");as.src="https://d31qbv1cthcecs.cloudfront.net/atrk.js";</script>');
        $widget = $this->wpOption($pdo, 'widget_custom_html', serialize([2 => ['content' => '<script>var s=document.createElement("script");s.src="https://cdn-evil.test/w.js";</script>']]));
        $hotjar = $this->wpOption($pdo, 'hefo', serialize(['head' => "<script>(function(h,o,t,j,a,r){r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j;a.appendChild(r);})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');</script>"]));

        $this->scan($pdo);
        $loader = fn (int $row) => DbScanFinding::query()->where('rule_key', 'malware.remote_loader')->get()->first(fn ($f) => ($f->subject['row_id'] ?? null) === $row);

        $this->assertSame(['needs_review', 'warn', 'third_party_widget'], [$loader($form)->confidence, $loader($form)->severity, $loader($form)->behavior]);
        $this->assertSame(['strong', 'critical'], [$loader($injected)->confidence, $loader($injected)->severity], 'A real injected script stays strong.');
        $this->assertSame(['needs_review', 'stored_loader'], [$loader($dormant)->confidence, $loader($dormant)->behavior]);
        $this->assertSame('strong', $loader($widget)->confidence, 'A loader in a rendered widget stays strong.');
        $this->assertSame(['needs_review', 'third_party_widget'], [$loader($hotjar)->confidence, $loader($hotjar)->behavior], 'Runtime-built loader URLs are no longer missed.');
    }

    public function test_risky_roles_lookalike_email_domains_and_bulk_autoloaded_tokens_are_reported(): void
    {
        $pdo = $this->baseMarket();
        $this->wpUser($pdo, 'cs_agent', 'cs@exotic-online.com', '2025-05-01 00:00:00', ['customerservice' => true]);
        $this->wpUser($pdo, 'seo_agent', 'seo@exotic-online.com', '2025-05-01 00:00:00', ['seo_manager' => true]);
        $pdo->beginTransaction();
        $users = $pdo->prepare('INSERT INTO wp_users (user_login, user_pass, user_nicename, user_email, user_registered, display_name) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (['www.kenya-market.test' => 12, 'kenya-markat.test' => 11, 'exotic.com' => 15, 'gmail.com' => 40] as $domain => $n) {
            for ($i = 0; $i < $n; $i++) {
                $users->execute(['p'.md5($domain.$i), 'x', 'p', 'p'.$i.'@'.$domain, '2026-01-01 00:00:00', 'p']);
            }
        }
        $options = $pdo->prepare("INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'yes')");
        for ($i = 0; $i < 150; $i++) {
            $options->execute(['logintoken'.(5000 + $i), 'TOKEN-'.bin2hex(random_bytes(16))]);
        }
        $pdo->commit();

        $this->scan($pdo);

        $roles = DbScanFinding::query()->where('rule_key', 'access.role_grants_admin_capability')->get()->keyBy(fn ($f) => $f->evidence['details']['role']);
        $this->assertSame('critical', $roles['seo_manager']->severity, 'A staff role that can edit theme code is admin-equivalent.');
        $this->assertTrue($roles->has('customerservice'));
        $this->assertFalse(DbScanFinding::query()->where('rule_key', 'access.hidden_admin_capabilities')->exists(), 'Role-granted level_10 is not a stale-level anomaly.');

        $domains = DbScanFinding::query()->where('rule_key', 'access.lookalike_account_email_domains')->get()->map(fn ($f) => $f->evidence['details']['domain'])->sort()->values()->all();
        $this->assertSame(['exotic.com', 'kenya-markat.test', 'www.kenya-market.test'], $domains);

        $tokens = DbScanFinding::query()->where('rule_key', 'hygiene.autoloaded_secret_options')->firstOrFail();
        $this->assertSame(150, $tokens->evidence['details']['options']);
        $this->assertStringNotContainsString('TOKEN-', json_encode(DbScanFinding::query()->get()->pluck('evidence')), 'Token values are never read.');
    }

    public function test_failed_login_pressure_is_read_from_the_activity_log(): void
    {
        $pdo = $this->baseMarket();
        $pdo->exec('CREATE TABLE wp_aryo_activity_log (histid INTEGER PRIMARY KEY AUTOINCREMENT, user_caps TEXT, action TEXT, object_type TEXT, object_subtype TEXT, object_name TEXT, object_id INTEGER, user_id INTEGER, hist_ip TEXT, hist_time INTEGER)');
        $pdo->beginTransaction();
        $log = $pdo->prepare("INSERT INTO wp_aryo_activity_log (action, object_type, object_name, user_id, hist_ip, hist_time) VALUES ('failed_login', 'Users', ?, 0, ?, ?)");
        for ($i = 0; $i < 1200; $i++) {
            $log->execute([$i % 3 ? 'admin' : 'lyanju', '10.0.'.intdiv($i, 250).'.'.($i % 250), time() - 3600]);
        }
        $pdo->commit();

        $this->scan($pdo);

        $finding = DbScanFinding::query()->where('rule_key', 'access.login_attack_pressure')->firstOrFail();
        $this->assertSame(1200, $finding->evidence['details']['failed_logins']);
        $this->assertStringNotContainsString('lyanju', json_encode($finding->evidence), 'Targeted usernames are masked.');
    }

    public function test_pack_sync_merges_new_shipped_entries_but_respects_admin_removals(): void
    {
        $list = DbScanList::query()->where('key', 'allow.cron_hooks')->where('scope_key', 'network')->firstOrFail();
        $list->entries = array_values(array_filter($list->entries, fn ($e) => ! in_array($e['value'], ['wordfence*', 'wsal*'], true)));
        $list->save();
        DbScanAuditEvent::query()->create([
            'actor_type' => 'user', 'actor_id' => 1, 'scope_key' => 'network', 'entity' => 'list', 'entity_id' => 'allow.cron_hooks',
            'action' => 'update', 'after' => ['removed' => ['wsal*']], 'created_at' => now(),
        ]);

        $summary = app(PackSynchronizer::class)->sync();

        $values = array_column(DbScanList::query()->where('key', 'allow.cron_hooks')->where('scope_key', 'network')->first()->entries, 'value');
        $this->assertContains('wordfence*', $values, 'A new shipped default is merged.');
        $this->assertNotContains('wsal*', $values, 'A value an admin removed stays removed.');
        $this->assertGreaterThanOrEqual(1, $summary['list_entries_added'] ?? 0);
    }
}
