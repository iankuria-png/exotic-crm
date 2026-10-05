<?php

namespace Tests\Feature\DbScanner;

use App\Models\DbScanConnection;
use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\Engine\Preflight;
use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Reader\ReaderTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\Feature\DbScanner\Concerns\BuildsWordPressFixture;
use Tests\TestCase;

/**
 * Opt-in engine suite against a real MySQL/MariaDB server with a throwaway
 * schema and a least-privilege reader account. Never points at a market or
 * the CRM database.
 *
 *   DB_SCANNER_ENGINE_IT=1 DB_SCANNER_IT_SOCKET=/path/mysqld.sock \
 *   DB_SCANNER_IT_ROOT_USER=root DB_SCANNER_IT_ROOT_PASSWORD=… \
 *   php artisan test --filter=DbScannerMysqlEngineTest
 */
class DbScannerMysqlEngineTest extends TestCase
{
    use BuildsWordPressFixture;
    use RefreshDatabase;

    private ?PDO $root = null;

    private string $schema = '';

    private string $reader = '';

    private string $readerPassword = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('DB_SCANNER_ENGINE_IT')) {
            $this->markTestSkipped('Opt-in engine suite: set DB_SCANNER_ENGINE_IT=1 and the root connection variables.');
        }

        Queue::fake();
        $this->bootScanner();
        $this->root = new PDO(
            'mysql:unix_socket='.getenv('DB_SCANNER_IT_SOCKET').';charset=utf8mb4',
            getenv('DB_SCANNER_IT_ROOT_USER') ?: 'root',
            getenv('DB_SCANNER_IT_ROOT_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $suffix = strtolower(Str::random(8));
        $this->schema = 'dbscan_it_'.$suffix;
        $this->reader = 'dbscan_ro_'.$suffix;
        $this->readerPassword = Str::random(24);

        $this->root->exec("CREATE DATABASE `{$this->schema}` CHARACTER SET utf8mb4");
        $this->root->exec("CREATE USER '{$this->reader}'@'localhost' IDENTIFIED BY '{$this->readerPassword}'");
        $this->root->exec("GRANT SELECT ON `{$this->schema}`.* TO '{$this->reader}'@'localhost'");
        $this->root->exec("USE `{$this->schema}`");
        $this->seedMysqlMarket();
    }

    protected function tearDown(): void
    {
        if ($this->root && $this->schema !== '') {
            $this->root->exec("DROP DATABASE IF EXISTS `{$this->schema}`");
            $this->root->exec("DROP USER IF EXISTS '{$this->reader}'@'localhost'");
        }
        parent::tearDown();
    }

    private function seedMysqlMarket(): void
    {
        $r = $this->root;
        $r->exec('CREATE TABLE wp_options (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) UNIQUE, option_value LONGTEXT, autoload VARCHAR(20) DEFAULT "yes") ENGINE=InnoDB');
        $r->exec('CREATE TABLE wp_users (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_login VARCHAR(60), user_pass VARCHAR(255), user_nicename VARCHAR(50), user_email VARCHAR(100), user_url VARCHAR(100) DEFAULT "", user_registered DATETIME, user_activation_key VARCHAR(255) DEFAULT "", user_status INT DEFAULT 0, display_name VARCHAR(250)) ENGINE=InnoDB');
        $r->exec('CREATE TABLE wp_usermeta (umeta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT) ENGINE=InnoDB');
        $r->exec('CREATE TABLE wp_posts (ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_author BIGINT UNSIGNED DEFAULT 1, post_date DATETIME, post_content LONGTEXT, post_title TEXT, post_excerpt TEXT, post_status VARCHAR(20) DEFAULT "publish", post_name VARCHAR(200) DEFAULT "", post_parent BIGINT UNSIGNED DEFAULT 0, post_type VARCHAR(20) DEFAULT "post", post_password VARCHAR(255) DEFAULT "") ENGINE=InnoDB');
        $r->exec('CREATE TABLE wp_postmeta (meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT) ENGINE=InnoDB');
        $r->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, term_id BIGINT UNSIGNED, taxonomy VARCHAR(32), description LONGTEXT, parent BIGINT UNSIGNED DEFAULT 0, count BIGINT DEFAULT 0) ENGINE=InnoDB');

        $opt = $r->prepare('INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)');
        foreach ([
            ['siteurl', 'https://mysql-market.test', 'yes'],
            ['home', 'https://mysql-market.test', 'yes'],
            ['blog_public', '1', 'yes'],
            ['active_plugins', serialize(['wordpress-seo/wp-seo.php', 'crawl-page-optimizer/crawl-page-optimizer.php']), 'yes'],
            ['wp_user_roles', serialize(['administrator' => ['capabilities' => ['manage_options' => true]]]), 'yes'],
            ['smtp_password', '<script src="https://never-read.test/x.js"></script>', 'yes'],
            ['ihaf_insert_header', '<script>if(navigator.userAgent.indexOf("Mobile")>-1){location.replace("https://mobile-scam.test/")}</script>', 'yes'],
        ] as $row) {
            $opt->execute($row);
        }
        $r->exec("INSERT INTO wp_users (user_login, user_pass, user_nicename, user_email, user_registered, display_name) VALUES ('support_admin', 'hash', 'x', 'support_admin@wordpress.com', '2024-01-01 00:00:00', 'Support')");
        $r->exec("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_capabilities', '".serialize(['administrator' => true])."')");
        $post = $r->prepare('INSERT INTO wp_posts (post_date, post_content, post_title, post_excerpt, post_type) VALUES (?, ?, ?, ?, ?)');
        $post->execute(['2026-01-01 00:00:00', '<?php eval($_POST["x"]); ?>', 'x', '', 'oembed_x']);
        $post->execute(['2026-01-01 00:00:00', str_repeat('é', 70000).'<script src="https://beyond-cap.test/a.js"></script>', 'Long', '', 'post']);
        $post->execute(['2026-01-01 00:00:00', '<script src="https://after-long.test/a.js"></script>', 'After', '', 'post']);
        $r->exec('CREATE TRIGGER wp_posts_lock BEFORE UPDATE ON wp_posts FOR EACH ROW SET NEW.post_title = OLD.post_title');
    }

    private function target(?string $user = null, ?string $password = null): ReaderTarget
    {
        return new ReaderTarget(0, 'mysql', 'localhost', 3306, (string) getenv('DB_SCANNER_IT_SOCKET'), $this->schema, $user ?? $this->reader, $password ?? $this->readerPassword, 'wp_', 'none', null, 'local', 1, 3, 5);
    }

    public function test_reader_session_is_read_only_with_a_verified_timeout(): void
    {
        $reader = new MarketDbReader($this->target());
        $reader->open();
        $this->assertStringStartsWith('8.', $reader->engine());
        $this->assertSame('1', (string) $reader->fetchScalar($reader->compiler()->readOnlyState('transaction_read_only'), 'ro'));
        $this->assertSame(3000.0, (float) $reader->fetchScalar($reader->compiler()->timeoutState(false), 't'));
        $reader->close();
    }

    public function test_full_scan_through_a_select_only_account(): void
    {
        $platform = $this->marketPlatform('MySQL market', 'mysql-market.test');
        DbScanConnection::query()->create([
            'platform_id' => $platform->id, 'driver' => 'mysql', 'host' => 'localhost', 'socket' => getenv('DB_SCANNER_IT_SOCKET'),
            'database' => $this->schema, 'username' => $this->reader, 'password' => $this->readerPassword, 'prefix' => 'wp_',
            'host_group' => 'local', 'config_version' => 1, 'enabled' => true, 'preflight_status' => 'never',
        ]);
        $connection = DbScanConnection::query()->firstOrFail();

        $result = app(Preflight::class)->run($connection, null);
        $this->assertSame('passed', $result['status'], json_encode($result));
        $this->assertFalse($result['capabilities']['trigger_visibility_exhaustive']);

        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();

        $run = DbScanMarketRun::query()->where('mode', 'scan')->firstOrFail();
        $this->assertSame('completed_with_gaps', $run->status, 'Trigger visibility is unverified for a SELECT-only account.');
        $rules = DbScanFinding::query()->pluck('rule_key')->all();
        $this->assertContains('access.admin_unexpected_email', $rules);
        $this->assertContains('persistence.plugin_not_allowlisted', $rules);
        $this->assertContains('malware.db_webshell', $rules);
        $this->assertContains('malware.redirect_or_overlay', $rules);
        $this->assertTrue(DbScanFinding::query()->where('rule_key', 'malware.remote_loader')->get()->contains(fn ($f) => str_contains(json_encode($f->evidence), 'after-long.test')));
        $this->assertTrue(DbScanFinding::query()->get()->contains(fn ($f) => str_contains(json_encode($f->evidence), 'beyond-cap.test')));
        $this->assertFalse(DbScanFinding::query()->get()->contains(fn ($f) => str_contains(json_encode($f->evidence), 'never-read.test')));
    }

    public function test_preflight_rejects_a_writable_account(): void
    {
        $platform = $this->marketPlatform('MySQL market', 'mysql-market.test');
        $connection = DbScanConnection::query()->create([
            'platform_id' => $platform->id, 'driver' => 'mysql', 'host' => 'localhost', 'socket' => getenv('DB_SCANNER_IT_SOCKET'),
            'database' => $this->schema, 'username' => getenv('DB_SCANNER_IT_ROOT_USER') ?: 'root', 'password' => getenv('DB_SCANNER_IT_ROOT_PASSWORD') ?: '',
            'prefix' => 'wp_', 'host_group' => 'local', 'config_version' => 1, 'enabled' => true, 'preflight_status' => 'never',
        ]);

        $result = app(Preflight::class)->run($connection, null);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('grants_not_select_only', $result['code']);
        $this->assertFalse($connection->fresh()->preflightValid());
    }

    public function test_identity_mismatch_is_rejected_and_www_identity_is_equivalent(): void
    {
        $platform = $this->marketPlatform('Wrong site', 'other-market.test');
        $c = DbScanConnection::query()->create([
            'platform_id' => $platform->id, 'driver' => 'mysql', 'host' => 'localhost', 'socket' => getenv('DB_SCANNER_IT_SOCKET'),
            'database' => $this->schema, 'username' => $this->reader, 'password' => $this->readerPassword,
            'prefix' => 'wp_', 'host_group' => 'local', 'config_version' => 1, 'enabled' => true, 'preflight_status' => 'never',
        ]);
        $this->assertSame('site_identity_mismatch', app(Preflight::class)->run($c, null)['code']);
        $platform->forceFill(['domain' => 'www.mysql-market.test'])->save();
        $this->assertSame('passed', app(Preflight::class)->run($c->fresh(), null)['status']);
    }

    public function test_schema_writable_site_login_still_uses_a_verified_read_only_session(): void
    {
        $siteUser = 'dbo_site_'.bin2hex(random_bytes(5));
        $sitePassword = bin2hex(random_bytes(24));
        $this->root->exec("CREATE USER '".$siteUser."'@'localhost' IDENTIFIED BY '".$sitePassword."'");
        try {
            $this->root->exec('GRANT ALL PRIVILEGES ON `'.$this->schema."`.* TO '".$siteUser."'@'localhost'");
            $platform = $this->marketPlatform('Site login', 'mysql-market.test');
            $platform->forceFill(['db_host' => 'localhost', 'db_name' => $this->schema, 'db_user' => $siteUser, 'db_pass' => $sitePassword, 'db_prefix' => 'wp_'])->save();
            $c = DbScanConnection::query()->create([
                'platform_id' => $platform->id, 'driver' => 'mysql', 'credential_source' => 'site_login', 'host' => 'localhost', 'socket' => getenv('DB_SCANNER_IT_SOCKET'),
                'database' => $this->schema, 'username' => '', 'prefix' => 'wp_', 'host_group' => 'local', 'config_version' => 1, 'enabled' => true, 'preflight_status' => 'never',
            ]);
            $result = app(Preflight::class)->run($c, null);
            $this->assertSame('passed', $result['status']);
            $this->assertTrue($result['capabilities']['read_only_verified']);
            $this->assertSame(1, $result['capabilities']['max_scanner_connections']);
            $this->assertTrue($c->fresh()->preflightValid());
            $reader = new MarketDbReader(app(\App\Services\DbScanner\Reader\ScannerCredentialResolver::class)->forConnection($c));
            $reader->open();
            $this->assertSame('1', (string) $reader->fetchScalar($reader->compiler()->readOnlyState('transaction_read_only'), 'ro'));
            $reader->close();
        } finally {
            $this->root->exec("DROP USER IF EXISTS '".$siteUser."'@'localhost'");
        }
    }

    public function test_historical_alert_targeting_and_keyed_rotation_templates_on_mysql(): void
    {
        $r = $this->root;
        $r->exec('CREATE TABLE wp_aryo_activity_log (histid BIGINT AUTO_INCREMENT PRIMARY KEY, hist_time BIGINT, hist_ip VARCHAR(45), user_id BIGINT, object_type VARCHAR(40), object_name VARCHAR(200), action VARCHAR(40), request_source VARCHAR(40)) ENGINE=InnoDB');
        $r->exec('CREATE TABLE wp_email_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, subject VARCHAR(500), message TEXT, sent_date TIMESTAMP) ENGINE=InnoDB');
        $r->exec('ALTER TABLE wp_aryo_activity_log MODIFY object_name VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
        $r->exec('ALTER TABLE wp_users MODIFY user_login VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $r->exec("INSERT INTO wp_users (user_login,user_pass,user_email,user_registered,display_name) VALUES ('keyed-member','hash','member@example.test','2024-01-01','Member'),('target-member','hash','target@example.test','2024-01-01','Target')");
        $r->prepare("INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (2,'_application_passwords',?)")->execute([serialize([['name' => 'auto-bootstrap', 'password' => 'synthetic-key-private', 'last_ip' => '176.53.159.25']])]);
        $e = $r->prepare("INSERT INTO wp_aryo_activity_log (hist_time,hist_ip,user_id,object_type,object_name,action,request_source) VALUES (?,?,?,'Users',?,?,'web')");
        $t = strtotime('2026-09-06T10:00:00Z');
        for ($n = 0; $n < 19; $n++) {
            $e->execute([$t + $n * 86400, '203.0.113.'.($n + 1), 2, 'keyed-member', 'logged_in']);
        }
        for ($n = 0; $n < 120; $n++) {
            $e->execute([$t + $n, '91.92.241.12', 0, 'target-member', 'failed_login']);
        }
        $r->exec("SET time_zone='+03:00'");
        $r->prepare('INSERT INTO wp_email_log (subject,message,sent_date) VALUES (?,?,?)')->execute(['[Wordfence Alert] mysql-market.test Admin Login', 'A user with username "deleted-admin" who has administrator access signed in to your WordPress site.
User IP: 185.174.136.197
User location: Test City
private-body-never-stored', '2023-08-15 15:00:00']);
        $r->exec("SET time_zone='+00:00'");
        $platform = $this->marketPlatform('MySQL update', 'mysql-market.test');
        $connection = DbScanConnection::query()->create([
            'platform_id' => $platform->id, 'driver' => 'mysql', 'host' => 'localhost', 'socket' => getenv('DB_SCANNER_IT_SOCKET'),
            'database' => $this->schema, 'username' => $this->reader, 'password' => $this->readerPassword, 'prefix' => 'wp_',
            'host_group' => 'local', 'config_version' => 1, 'enabled' => true, 'preflight_status' => 'never',
        ]);
        $this->assertSame('passed', app(Preflight::class)->run($connection, null)['status']);
        app(PassController::class)->start([$platform->id], 'standard', 'manual');
        $this->drain();
        $this->assertSame(19, DbScanFinding::query()->where('rule_key', 'access.account_campaign')->firstOrFail()->evidence['details']['keyed_rotation']['distinct_ips']);
        $this->assertSame('warn', DbScanFinding::query()->where('rule_key', 'access.targeted_accounts')->firstOrFail()->severity);
        $f = DbScanFinding::query()->where('rule_key', 'access.historical_privileged_logins')->firstOrFail();
        $this->assertSame('2023-08-15T12:00:00Z', $f->evidence['details']['at_utc']);
        $this->assertTrue($f->evidence['details']['actor_account_missing']);
        $this->assertStringNotContainsString('private-body-never-stored', json_encode(DbScanFinding::query()->get()));
    }
}
