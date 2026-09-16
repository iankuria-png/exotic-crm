<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Support\WordPressSiteConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only audit of market WordPress databases, written as a plain-text report.
 *
 * Looks for the problems found by hand on Exotic Zimbabwe (an unexplained
 * administrator, a plugin nobody installed, injected or malformed links) and
 * the database persistence tricks documented in WordPress incident write-ups:
 * triggers, hidden capability rows, payloads in look-alike options, snippet
 * stores, SEO spam and hygiene thresholds borrowed from `wp doctor`.
 *
 * Usage on the CRM server:
 *   php artisan crm:db-scan-report                      every market with database credentials
 *   php artisan crm:db-scan-report --platform=12        one market (repeat --platform for more)
 *   php artisan crm:db-scan-report --quick              skip the heavier content scans
 *   php artisan crm:db-scan-report --host=… --database=… --user=… --password=… --prefix=wp_ --domain=https://site
 *                                                       any database directly, e.g. an imported dump
 * The report is written to storage/app/db-scan-reports/ unless --output is given.
 *
 * Safety: every session is READ ONLY with a statement timeout, statements are
 * limited to SELECT and SHOW, markets are scanned one at a time with a pause in
 * between, password hashes, session tokens and secret-looking options are never
 * read into the report, and emails are masked.
 */
class DbScanReport extends Command
{
    protected $signature = 'crm:db-scan-report
        {--platform=* : Platform IDs to scan (default: every market with database credentials)}
        {--skip=* : Platform IDs to leave out}
        {--host= : Scan one database directly instead of platforms}
        {--port=3306 : Port for --host}
        {--socket= : Unix socket for --host}
        {--database= : Database name for --host}
        {--user= : Database user for --host}
        {--password= : Database password for --host}
        {--prefix=wp_ : Table prefix for --host}
        {--domain= : Site domain for --host, used by the site URL check}
        {--name= : Label for --host}
        {--company-domains=exotic-online.com,exotic-africa.com : Email domains expected for administrators}
        {--network-domains=exotic-ads.com,exotic-online.com,exotic-africa.com,erotic-africa.com : Extra hosts treated as our own (market domains are added automatically)}
        {--known-plugins= : Extra plugin directory names to treat as known, comma separated}
        {--recent-days=90 : Administrators registered within this many days are listed}
        {--timeout=15 : Per-statement timeout in seconds}
        {--pause=2 : Seconds to wait between markets}
        {--quick : Skip the heavier content scans (posts, postmeta, comments)}
        {--output= : Report path (default storage/app/db-scan-reports/db-scan-<timestamp>.txt)}';

    protected $description = 'Read-only audit of market WordPress databases for compromise, injected spam, SEO integrity and hygiene problems; writes a text report.';

    private const CONNECTION = 'db_scan_report';

    private const LINE = 100;

    private const EXCLUDED_POST_TYPES = [
        'revision', 'attachment', 'nav_menu_item', 'oembed_cache', 'od_url_metrics',
        'customize_changeset', 'wp_global_styles', 'acf-field', 'acf-field-group', 'user_request',
    ];

    private const IOC_OPTION_NAMES = [
        'default_mont_options', 'ad_code', 'hide_admin', 'hide_logged_in', 'display_ad', 'search_engines',
        'ip_admin', 'cookies_admin', 'logged_admin', 'log_install', '_pre_user_id',
        'wds_protected_links', 'wds_protected_posts', 'wds_protection_enabled',
    ];

    /** Plugin directories deployed across the network; anything else is reported for review. */
    private const KNOWN_PLUGIN_DIRS = [
        'advanced-access-manager', 'advanced-ads', 'advanced-custom-fields', 'aryo-activity-log', 'auto-sizes', 'backwpup',
        'better-search-replace', 'broken-link-checker', 'classic-editor', 'classic-widgets', 'dominant-color-images',
        'email-log', 'embed-optimizer', 'epe-push', 'exotic-age-gate', 'exotic-campaigns', 'exotic-chat-landing',
        'exotic-crm-sync', 'exotic-font-manager', 'exotic-kyc', 'exotic-legal-pages', 'golden-star-hunt',
        'image-prioritizer', 'loginizer', 'loginizer-security', 'nextend-facebook-connect', 'ninjateam-telegram',
        'optimization-detective', 'performance-lab', 'speculation-rules', 'support-board-cloud', 'web-worker-offloading',
        'webp-uploads', 'webpushr-web-push-notifications', 'wordpress-seo', 'wordpress-seo-premium', 'wp-laravel-sso',
        'wpseo-video',
    ];

    /** Post types created by our own plugins with no author by design. */
    private const SYSTEM_POST_TYPES = ['engagement_invite', 'campaign', 'advanced_ads', 'telegram-accounts', 'whatsapp-accounts'];

    private const SUSPICIOUS_ADMIN_DOMAINS = ['wordpress.com', 'wordpress.org', 'example.com', 'admin.com', 'test.com'];

    private const SUSPICIOUS_USERNAMES = [
        'admin', 'administrator', 'support', 'support_admin', 'supportadmin', 'wpadmin', 'wp-admin', 'wp_admin',
        'developer', 'dev', 'test', 'tester', 'root', 'sysadmin', 'backup', 'adm1n', 'webmaster', 'wordpress',
    ];

    private const SPAM_TERMS = [
        'viagra', 'cialis', 'levitra', 'tramadol', 'phentermine', 'xanax', 'online casino', 'slot gacor', 'togel',
        'judi online', 'payday loan', 'replica watches', 'wechat', 'weixin', 'telegram: @',
    ];

    private const SHORTENERS = ['bit.ly', 'rb.gy', 'tinyurl.com', 'is.gd', 'cutt.ly', 'shorturl.at', 't.co', 'ow.ly', 'rebrand.ly'];

    /** Third-party script hosts commonly embedded on purpose (analytics, social, push, CDNs). */
    private const SCRIPT_HOST_ALLOW = [
        'apis.google.com', 'googletagmanager.com', 'google-analytics.com', 'gstatic.com', 'google.com', 'recaptcha.net',
        'connect.facebook.net', 'platform.twitter.com', 'static.cloudflareinsights.com', 'cdnjs.cloudflare.com',
        'cdn.jsdelivr.net', 'cdn.webpushr.com', 'telegram.org', 'chimpstatic.com', 'list-manage.com', 'cloud.board.support',
    ];

    /** Triggers our own stack creates; reported as context, not as compromise. */
    private const KNOWN_TRIGGER_PATTERN = '/^escort_live_url_sync/';

    private const IFRAME_HOST_ALLOW = ['youtube.com', 'youtube-nocookie.com', 'player.vimeo.com', 'vimeo.com', 'google.com', 'maps.google.com', 'facebook.com', 'instagram.com', 'tiktok.com', 'twitter.com', 'x.com', 't.me', 'telegram.org'];

    private const SECRET_NAME_PATTERN = '/(pass(word)?|secret|token|api[_-]?key|private[_-]?key|auth[_-]?key|salt|smtp|credential|license[_-]?key)/i';

    private const SERIALIZED_CLASS_ALLOW = '/^(stdClass|WP_|WpOrg\\\\|Requests_|Yoast|WPSEO_|ActionScheduler|ArrayObject|DateTime|Elementor\\\\|FS_|__PHP_Incomplete_Class)/';

    private string $prefix = 'wp_';

    private array $companyDomains = [];

    private array $networkHosts = [];

    private array $knownPlugins = [];

    public function handle(): int
    {
        $this->companyDomains = array_values(array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', (string) $this->option('company-domains'))
        )));

        $targets = $this->resolveTargets();
        if (empty($targets)) {
            $this->error('No markets with database credentials matched the options.');

            return self::FAILURE;
        }

        $this->networkHosts = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($t) => $this->hostOf($t['domain']), $targets),
            array_map(fn ($d) => $this->hostOf($d), explode(',', (string) $this->option('network-domains')))
        ))));
        $this->knownPlugins = array_values(array_unique(array_merge(
            self::KNOWN_PLUGIN_DIRS,
            array_filter(array_map('trim', explode(',', (string) $this->option('known-plugins'))))
        )));

        $startedAt = gmdate('Y-m-d H:i:s');
        $results = [];
        $pause = max(0, (int) $this->option('pause'));

        foreach (array_values($targets) as $index => $target) {
            $this->line(sprintf('[%d/%d] %s …', $index + 1, count($targets), $target['name']));
            $result = $this->scanTarget($target);
            $counts = $this->severityCounts($result['findings']);
            $this->line(sprintf(
                '        %s · CRITICAL %d · WARN %d · INFO %d · %.1fs',
                $result['status'],
                $counts['CRITICAL'],
                $counts['WARN'],
                $counts['INFO'],
                $result['duration']
            ));
            $results[] = $result;

            if ($pause > 0 && $index < count($targets) - 1) {
                sleep($pause);
            }
        }

        $network = $this->networkFindings($results);
        $report = $this->renderReport($results, $network, $startedAt);

        $path = (string) ($this->option('output') ?: storage_path('app/db-scan-reports/db-scan-'.gmdate('Ymd-His').'.txt'));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $report);

        $this->newLine();
        $this->info('Report written to '.$path);

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Targets and connection
    // ------------------------------------------------------------------

    private function resolveTargets(): array
    {
        if ($this->option('host')) {
            return [[
                'platform_id' => null,
                'name' => (string) ($this->option('name') ?: $this->option('database')),
                'domain' => (string) $this->option('domain'),
                'prefix' => (string) $this->option('prefix'),
                'host_label' => (string) ($this->option('socket') ? 'socket' : $this->option('host')),
                'database' => (string) $this->option('database'),
                'config' => [
                    'driver' => 'mysql',
                    'host' => (string) $this->option('host'),
                    'port' => (int) $this->option('port'),
                    'unix_socket' => (string) ($this->option('socket') ?? ''),
                    'database' => (string) $this->option('database'),
                    'username' => (string) $this->option('user'),
                    'password' => (string) ($this->option('password') ?? ''),
                ],
            ]];
        }

        $only = array_map('intval', array_filter((array) $this->option('platform')));
        $skip = array_map('intval', array_filter((array) $this->option('skip')));

        return Platform::query()
            ->when($only, fn ($q) => $q->whereIn('id', $only))
            ->when($skip, fn ($q) => $q->whereNotIn('id', $skip))
            ->orderBy('name')
            ->get()
            ->filter(fn (Platform $p) => $p->db_host && $p->db_name && $p->db_user && $p->db_pass)
            ->map(function (Platform $p) {
                $site = WordPressSiteConnection::fromPlatform($p);

                return [
                    'platform_id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'domain' => $site->baseUrl,
                    'prefix' => (string) ($p->db_prefix ?? 'wp_'),
                    'host_label' => (string) $p->db_host,
                    'database' => (string) $p->db_name,
                    'config' => [
                        'driver' => 'mysql',
                        'host' => (string) $p->db_host,
                        'port' => 3306,
                        'unix_socket' => '',
                        'database' => (string) $p->db_name,
                        'username' => (string) $p->db_user,
                        'password' => (string) $p->db_pass,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function connect(array $config): string
    {
        config(['database.connections.'.self::CONNECTION => $config + [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'options' => [PDO::ATTR_TIMEOUT => 8],
        ]]);
        DB::purge(self::CONNECTION);

        $connection = DB::connection(self::CONNECTION);
        $version = (string) ($connection->selectOne('SELECT VERSION() AS v')->v ?? '');
        $timeout = max(2, min(120, (int) $this->option('timeout')));

        $connection->statement('SET SESSION TRANSACTION READ ONLY');
        if (stripos($version, 'mariadb') !== false) {
            $connection->statement('SET SESSION max_statement_time = '.$timeout);
        } else {
            $connection->statement('SET SESSION max_execution_time = '.($timeout * 1000));
        }

        return $version;
    }

    private function disconnect(): void
    {
        try {
            DB::disconnect(self::CONNECTION);
        } catch (Throwable) {
        }
        DB::purge(self::CONNECTION);
    }

    /** SELECT/SHOW only; everything the checks run goes through here. */
    private function q(string $sql, array $bindings = []): array
    {
        if (! preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
            throw new RuntimeException('Refusing to run a non-read statement.');
        }

        return array_map(fn ($row) => (array) $row, DB::connection(self::CONNECTION)->select($sql, $bindings));
    }

    private function t(string $table): string
    {
        return '`'.str_replace('`', '', $this->prefix.$table).'`';
    }

    // ------------------------------------------------------------------
    // Scan one market
    // ------------------------------------------------------------------

    private function scanTarget(array $target): array
    {
        $started = microtime(true);
        $result = [
            'target' => $target,
            'status' => 'scanned',
            'error' => null,
            'engine' => null,
            'duration' => 0.0,
            'findings' => [],
            'inventory' => [],
            'check_errors' => [],
            'checks_ok' => 0,
        ];

        $this->prefix = $target['prefix'] !== '' ? $target['prefix'] : 'wp_';

        try {
            $result['engine'] = $this->connect($target['config']);
        } catch (Throwable $e) {
            $result['status'] = 'unreachable';
            $result['error'] = $this->cleanError($e->getMessage());
            $result['duration'] = microtime(true) - $started;
            $this->disconnect();

            return $result;
        }

        $ctx = [
            'domain' => $target['domain'],
            'site_host' => $this->hostOf($target['domain']),
            'tables' => [],
            'options' => [],
            'admins' => [],
        ];

        $checks = [
            'schema.tables' => 'checkTables',
            'config.core_options' => 'checkCoreOptions',
            'access.administrators' => 'checkAdministrators',
            'access.hidden_capabilities' => 'checkHiddenCapabilities',
            'access.application_passwords' => 'checkApplicationPasswords',
            'persistence.schema_objects' => 'checkSchemaObjects',
            'persistence.plugins_theme' => 'checkPluginsAndTheme',
            'persistence.cron' => 'checkCron',
            'persistence.ioc_options' => 'checkIocOptions',
            'persistence.autoload_payloads' => 'checkAutoloadPayloads',
            'persistence.encoded_payloads' => 'checkEncodedPayloads',
            'persistence.snippet_stores' => 'checkSnippetStores',
            'content.widgets_terms' => 'checkWidgetsAndTerms',
            'content.posts_signatures' => 'checkPostSignatures',
            'content.foreign_scripts' => 'checkForeignScripts',
            'content.spam_lexicon' => 'checkSpamLexicon',
            'content.seo_meta' => 'checkSeoMeta',
            'content.mass_created' => 'checkMassCreatedPosts',
            'content.orphan_authors' => 'checkOrphanAuthors',
            'content.outbound_links' => 'checkOutboundLinks',
            'content.comments' => 'checkComments',
            'content.yoast_redirects' => 'checkYoastRedirects',
            'seo.slug_aliases' => 'checkSlugAliases',
            'hygiene.autoload' => 'checkAutoload',
            'hygiene.transients_revisions' => 'checkTransientsAndRevisions',
            'hygiene.action_scheduler' => 'checkActionScheduler',
            'inventory.plugin_updates' => 'checkPluginUpdates',
        ];

        $heavy = ['content.posts_signatures', 'content.foreign_scripts', 'content.spam_lexicon', 'content.seo_meta', 'persistence.encoded_payloads', 'content.outbound_links', 'content.comments'];

        foreach ($checks as $key => $check) {
            if ($this->option('quick') && in_array($key, $heavy, true)) {
                continue;
            }
            try {
                $this->{$check}($ctx, $result);
                $result['checks_ok']++;
            } catch (Throwable $e) {
                $result['check_errors'][$key] = $this->cleanError($e->getMessage());
            }
        }

        if (! empty($result['check_errors'])) {
            $result['status'] = 'partial';
        }

        $this->disconnect();
        $result['duration'] = microtime(true) - $started;

        return $result;
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    private function checkTables(array &$ctx, array &$result): void
    {
        $rows = $this->q(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_estimate, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_COLLATION AS collation
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        $ctx['tables'] = array_column($rows, null, 'name');

        foreach (['options', 'users', 'usermeta', 'posts', 'postmeta'] as $core) {
            if (! isset($ctx['tables'][$this->prefix.$core])) {
                throw new RuntimeException("Core table {$this->prefix}{$core} not found — check the table prefix.");
            }
        }

        $foreign = [];
        $parallel = [];
        $myisam = [];
        foreach ($rows as $row) {
            if (! str_starts_with($row['name'], $this->prefix)) {
                $foreign[] = $row['name'].' ('.$this->bytes((int) $row['bytes']).')';
                if (preg_match('/^(backup|bak|old|copy|tmp)[_a-z0-9]*_(posts|options|users)$/i', $row['name'])) {
                    $parallel[] = $row['name'];
                }
            }
            if (strtolower((string) $row['engine']) === 'myisam') {
                $myisam[] = $row['name'];
            }
        }

        if ($parallel) {
            $this->add($result, 'WARN', 'hygiene.parallel_tables', 'Parallel copies of WordPress tables', $parallel);
        }
        if ($foreign) {
            $this->add($result, 'INFO', 'hygiene.unexpected_tables', 'Tables without the site prefix '.$this->prefix, array_slice($foreign, 0, 15), count($foreign));
        }
        if ($myisam) {
            $this->add($result, 'INFO', 'hygiene.myisam_tables', 'MyISAM tables (no transactions or crash recovery)', array_slice($myisam, 0, 15), count($myisam));
        }

        usort($rows, fn ($a, $b) => (int) $b['bytes'] <=> (int) $a['bytes']);
        $result['inventory']['Tables'] = sprintf(
            '%d tables, %s total; largest: %s',
            count($rows),
            $this->bytes(array_sum(array_map(fn ($r) => (int) $r['bytes'], $rows))),
            implode(', ', array_map(fn ($r) => $r['name'].' '.$this->bytes((int) $r['bytes']), array_slice($rows, 0, 5)))
        );
    }

    private function checkCoreOptions(array &$ctx, array &$result): void
    {
        $names = [
            'siteurl', 'home', 'blog_public', 'admin_email', 'users_can_register', 'default_role', 'upload_path',
            'upload_url_path', 'permalink_structure', 'template', 'stylesheet', 'db_version', 'active_plugins',
            'cron', 'wpseo', 'taxonomy_profile_url', 'recently_activated',
        ];
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        foreach ($this->q("SELECT option_name, option_value FROM {$this->t('options')} WHERE option_name IN ($placeholders)", $names) as $row) {
            $ctx['options'][$row['option_name']] = $row['option_value'];
        }
        $o = $ctx['options'];

        foreach (['siteurl', 'home'] as $key) {
            $host = $this->hostOf((string) ($o[$key] ?? ''));
            if ($ctx['site_host'] !== '' && $host !== '' && $host !== $ctx['site_host']) {
                $this->add($result, 'CRITICAL', 'config.site_url_mismatch', "`$key` points to a different host", ["$key = ".($o[$key] ?? '').' (expected '.$ctx['site_host'].')']);
            }
        }

        if ((string) ($o['blog_public'] ?? '1') === '0') {
            $this->add($result, 'CRITICAL', 'config.search_engines_blocked', 'Search engines are discouraged (blog_public = 0)', ['Settings → Reading → Discourage search engines is ticked']);
        }

        if ((string) ($o['users_can_register'] ?? '0') === '1' && ($o['default_role'] ?? 'subscriber') !== 'subscriber') {
            $this->add($result, 'CRITICAL', 'access.open_registration', 'Open registration with a privileged default role', ['users_can_register = 1, default_role = '.$o['default_role']]);
        }

        foreach (['upload_path', 'upload_url_path'] as $key) {
            if (trim((string) ($o[$key] ?? '')) !== '') {
                $this->add($result, 'WARN', 'config.upload_path_changed', "`$key` is set", ["$key = ".$this->snippet((string) $o[$key])]);
            }
        }

        $adminEmail = (string) ($o['admin_email'] ?? '');
        if ($adminEmail !== '' && ! $this->isCompanyEmail($adminEmail)) {
            $this->add($result, 'INFO', 'config.admin_email', 'Site admin email is outside the company domains', [$this->maskEmail($adminEmail)]);
        }

        $wpseo = $this->unserializeSafe($o['wpseo'] ?? null);
        if (is_array($wpseo)) {
            // The theme answers front-end searches with a real 404. Yoast's emoji and
            // spam-pattern search filters run earlier and 301 those searches to the
            // homepage instead, which Google reads as soft 404s, so they stay off.
            $expected = [
                'remove_feed_search' => true,
                'deny_search_crawling' => true,
                'redirect_search_pretty_urls' => true,
                'search_cleanup_emoji' => false,
                'search_cleanup_patterns' => false,
                'clean_permalinks' => true,
            ];
            $drift = [];
            foreach ($expected as $key => $value) {
                if (array_key_exists($key, $wpseo) && (bool) $wpseo[$key] !== $value) {
                    $drift[] = "$key is ".var_export((bool) $wpseo[$key], true).' (recommended '.var_export($value, true).')';
                }
            }
            if ($drift) {
                $this->add($result, 'WARN', 'config.yoast_crawl_baseline', 'Yoast crawl-optimisation settings differ from the network baseline', $drift);
            }
        }

        $result['inventory']['Site'] = sprintf(
            'siteurl %s · home %s · permalinks %s · db_version %s',
            $o['siteurl'] ?? '?',
            $o['home'] ?? '?',
            $o['permalink_structure'] ?? '?',
            $o['db_version'] ?? '?'
        );
        $result['inventory']['Theme'] = ($o['stylesheet'] ?? '?').(($o['template'] ?? '') !== ($o['stylesheet'] ?? '') ? ' (parent '.($o['template'] ?? '?').')' : '');
        $result['inventory']['_theme'] = (string) ($o['stylesheet'] ?? '');
    }

    private function checkAdministrators(array &$ctx, array &$result): void
    {
        $capKey = $this->prefix.'capabilities';
        $rows = $this->q(
            "SELECT u.ID, u.user_login, u.user_email, u.user_registered, u.display_name
             FROM {$this->t('usermeta')} m JOIN {$this->t('users')} u ON u.ID = m.user_id
             WHERE m.meta_key = ? AND m.meta_value LIKE ?
             ORDER BY u.user_registered",
            [$capKey, '%"administrator"%']
        );
        $ctx['admins'] = $rows;
        $recentCutoff = time() - max(1, (int) $this->option('recent-days')) * 86400;

        $unexpected = [];
        $outside = [];
        $recent = [];
        $names = [];
        $forged = [];
        $inventory = [];

        foreach ($rows as $u) {
            $email = strtolower((string) $u['user_email']);
            $domain = substr(strrchr($email, '@') ?: '', 1);
            $line = sprintf('#%d %s · %s · registered %s', $u['ID'], $u['user_login'], $this->maskEmail($email), $u['user_registered']);
            $inventory[] = sprintf('#%d %s (%s)', $u['ID'], $u['user_login'], $this->maskEmail($email));
            $result['inventory']['_admin_emails'][] = $email;

            $siteLike = $ctx['site_host'] !== '' && in_array($domain, ['www.'.$ctx['site_host'], 'mail.'.$ctx['site_host']], true);
            if (in_array($domain, self::SUSPICIOUS_ADMIN_DOMAINS, true) || $siteLike) {
                $unexpected[] = $line;
            } elseif (! $this->isCompanyEmail($email)) {
                $outside[] = $line;
            }

            if (strtotime((string) $u['user_registered']) >= $recentCutoff) {
                $recent[] = $line;
            }

            if (in_array(strtolower((string) $u['user_login']), self::SUSPICIOUS_USERNAMES, true)) {
                $names[] = $line;
            }

            $later = $this->q(
                "SELECT COUNT(*) AS n FROM {$this->t('users')} WHERE ID < ? AND user_registered > DATE_ADD(?, INTERVAL 30 DAY)",
                [$u['ID'], $u['user_registered']]
            )[0]['n'] ?? 0;
            if ((int) $later > 0 || strtotime((string) $u['user_registered']) > time() + 86400) {
                $forged[] = $line.' · '.(int) $later.' lower-ID users registered 30+ days later';
            }
        }

        if ($unexpected) {
            $this->add($result, 'CRITICAL', 'access.admin_fake_domain', 'Administrator using a fake or support-style email domain', $unexpected);
        }
        if ($outside) {
            $this->add($result, 'WARN', 'access.admin_outside_company', 'Administrator email outside the company domains ('.implode(', ', $this->companyDomains).')', $outside);
        }
        if ($names) {
            $this->add($result, 'WARN', 'access.suspicious_admin_username', 'Administrator with a generic or attacker-style username', $names);
        }
        if ($forged) {
            $this->add($result, 'WARN', 'access.forged_registration', 'Administrator registration date out of sequence (backdated accounts)', $forged);
        }
        if ($recent) {
            $this->add($result, 'INFO', 'access.recent_admins', 'Administrators registered in the last '.(int) $this->option('recent-days').' days', $recent);
        }

        $result['inventory']['Administrators'] = count($rows).': '.implode(', ', $inventory);
    }

    private function checkHiddenCapabilities(array &$ctx, array &$result): void
    {
        $capKey = $this->prefix.'capabilities';
        $levelKey = $this->prefix.'user_level';

        $rows = $this->q(
            "SELECT m.user_id, m.meta_key, u.user_login, u.user_email,
                    (SELECT COUNT(*) FROM {$this->t('usermeta')} c WHERE c.user_id = m.user_id AND c.meta_key = ? AND c.meta_value LIKE ?) AS real_admin
             FROM {$this->t('usermeta')} m LEFT JOIN {$this->t('users')} u ON u.ID = m.user_id
             WHERE m.meta_key LIKE ? AND m.meta_key <> ? AND m.meta_value LIKE ?",
            [$capKey, '%"administrator"%', '%capabilities', $capKey, '%"administrator"%']
        );

        $hidden = [];
        $legacy = [];
        foreach ($rows as $r) {
            $line = sprintf('user #%d %s · meta_key %s · %s', $r['user_id'], $r['user_login'] ?? '(no user row)', $r['meta_key'], $this->maskEmail((string) ($r['user_email'] ?? '')));
            if ((int) $r['real_admin'] === 0) {
                $hidden[] = $line;
            } else {
                $legacy[] = $line;
            }
        }
        if ($hidden) {
            $this->add($result, 'CRITICAL', 'access.hidden_admin_capabilities', 'Administrator capability stored under a non-site meta key', $hidden);
        }
        if ($legacy) {
            $this->add($result, 'INFO', 'access.legacy_capability_rows', 'Leftover administrator capability rows from another table prefix', $legacy);
        }

        $levels = $this->q(
            "SELECT l.user_id, u.user_login,
                    (SELECT LEFT(c.meta_value, 200) FROM {$this->t('usermeta')} c WHERE c.user_id = l.user_id AND c.meta_key = ? LIMIT 1) AS caps
             FROM {$this->t('usermeta')} l LEFT JOIN {$this->t('users')} u ON u.ID = l.user_id
             WHERE l.meta_key = ? AND l.meta_value = '10'
               AND NOT EXISTS (SELECT 1 FROM {$this->t('usermeta')} c WHERE c.user_id = l.user_id AND c.meta_key = ? AND c.meta_value LIKE ?)",
            [$capKey, $levelKey, $capKey, '%"administrator"%']
        );
        if ($levels) {
            $this->add($result, 'WARN', 'access.user_level_mismatch', 'user_level 10 (legacy full-admin level) on accounts without the administrator role', array_map(function ($r) {
                $caps = $this->unserializeSafe($r['caps']);
                $roles = is_array($caps) ? implode(', ', array_keys(array_filter($caps))) : 'no roles';

                return '#'.$r['user_id'].' '.($r['user_login'] ?? '(no user row)').' · roles: '.($roles !== '' ? $roles : 'none');
            }, $levels));
        }
    }

    private function checkApplicationPasswords(array &$ctx, array &$result): void
    {
        if (empty($ctx['admins'])) {
            return;
        }
        $ids = array_map(fn ($u) => (int) $u['ID'], $ctx['admins']);
        $rows = $this->q(
            "SELECT user_id, LENGTH(meta_value) AS len FROM {$this->t('usermeta')} WHERE meta_key = '_application_passwords' AND user_id IN (".implode(',', $ids).') AND LENGTH(meta_value) > 6'
        );
        if ($rows) {
            $logins = array_column($ctx['admins'], 'user_login', 'ID');
            $this->add($result, 'WARN', 'access.application_passwords', 'Administrators with application passwords (API access that bypasses login)', array_map(
                fn ($r) => '#'.$r['user_id'].' '.($logins[$r['user_id']] ?? '?'),
                $rows
            ));
        }
    }

    private function checkSchemaObjects(array &$ctx, array &$result): void
    {
        $triggers = $this->q(
            'SELECT TRIGGER_NAME AS name, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS tbl, LEFT(ACTION_STATEMENT, 200) AS body
             FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        );
        $describe = fn ($r) => sprintf('%s · %s %s on %s · %s', $r['name'], $r['timing'], $r['event'], $r['tbl'], $this->snippet((string) $r['body']));
        $known = array_filter($triggers, fn ($r) => preg_match(self::KNOWN_TRIGGER_PATTERN, (string) $r['name']));
        $unknown = array_filter($triggers, fn ($r) => ! preg_match(self::KNOWN_TRIGGER_PATTERN, (string) $r['name']));
        if ($unknown) {
            $this->add($result, 'CRITICAL', 'persistence.mysql_triggers', 'Unknown database triggers (a clean WordPress install has none)', array_map($describe, $unknown));
        }
        if ($known) {
            $this->add($result, 'INFO', 'persistence.known_triggers', 'Known network triggers (escort live URL sync)', array_map(fn ($r) => $r['name'].' · '.$r['timing'].' '.$r['event'].' on '.$r['tbl'], $known));
        }

        $events = $this->q('SELECT EVENT_NAME AS name, STATUS AS status, LEFT(EVENT_DEFINITION, 200) AS body FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()');
        $routines = $this->q('SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()');
        $items = array_merge(
            array_map(fn ($r) => 'EVENT '.$r['name'].' ('.$r['status'].') · '.$this->snippet((string) $r['body']), $events),
            array_map(fn ($r) => $r['type'].' '.$r['name'], $routines)
        );
        if ($items) {
            $this->add($result, 'CRITICAL', 'persistence.mysql_events_routines', 'Scheduled events or stored routines in the database', $items);
        }
    }

    private function checkPluginsAndTheme(array &$ctx, array &$result): void
    {
        $plugins = $this->unserializeSafe($ctx['options']['active_plugins'] ?? null);
        $plugins = is_array($plugins) ? array_values(array_map('strval', $plugins)) : [];
        $result['inventory']['_plugins'] = $plugins;
        $result['inventory']['Active plugins'] = count($plugins).': '.implode(', ', $plugins);

        $suspicious = [];
        foreach ($plugins as $path) {
            $dir = str_contains($path, '/') ? strstr($path, '/', true) : '';
            if ($dir === '' && ! in_array($path, ['hello.php'], true)) {
                $suspicious[] = $path.' · single file at the plugins root';
            } elseif (str_starts_with($dir, '.')) {
                $suspicious[] = $path.' · hidden directory';
            } elseif (preg_match('/^(wp-?(compat|core|config|system|update|security-core|cache-core)|wordpress-?(core|system)|core-?(update|compat))/i', $dir)) {
                $suspicious[] = $path.' · name imitates WordPress core';
            } elseif (! preg_match('/\.php$/i', $path)) {
                $suspicious[] = $path.' · entry is not a PHP file';
            }
        }
        if ($suspicious) {
            $this->add($result, 'CRITICAL', 'persistence.plugin_path_suspicious', 'Active plugin entries with suspicious paths', $suspicious);
        }

        $unknown = array_values(array_filter($plugins, function ($path) {
            $dir = str_contains($path, '/') ? strstr($path, '/', true) : $path;

            return ! in_array($dir, $this->knownPlugins, true);
        }));
        if ($unknown) {
            $this->add($result, 'WARN', 'persistence.plugin_not_known', 'Active plugins not on the network plugin list (confirm who installed them)', $unknown);
        }
    }

    private function checkCron(array &$ctx, array &$result): void
    {
        $cron = $this->unserializeSafe($ctx['options']['cron'] ?? null);
        if (! is_array($cron)) {
            return;
        }

        $events = 0;
        $overdue = 0;
        $hooks = [];
        $payload = [];
        foreach ($cron as $timestamp => $entries) {
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $hook => $instances) {
                $count = is_array($instances) ? count($instances) : 1;
                $events += $count;
                $hooks[$hook] = ($hooks[$hook] ?? 0) + $count;
                if (is_numeric($timestamp) && (int) $timestamp < time() - 86400) {
                    $overdue += $count;
                }
                $serialized = @serialize($instances);
                if (preg_match('/eval\(|base64_decode|gzinflate|str_rot13|create_function/i', (string) $serialized)) {
                    $payload[] = $hook.' · '.$this->snippet((string) $serialized);
                }
            }
        }

        $result['inventory']['_cron_hooks'] = array_keys($hooks);
        arsort($hooks);
        $result['inventory']['Cron'] = sprintf('%d events across %d hooks, %d overdue by 24h+', $events, count($hooks), $overdue);

        if ($payload) {
            $this->add($result, 'CRITICAL', 'persistence.cron_payload', 'Cron events carrying code-like arguments', $payload);
        }
        $odd = array_filter(array_keys($hooks), fn ($h) => preg_match('/^[a-f0-9]{16,}$|eval|base64|shell|backdoor/i', (string) $h));
        if ($odd) {
            $this->add($result, 'WARN', 'persistence.cron_odd_hooks', 'Cron hooks with random or code-like names', array_values($odd));
        }
        if ($events > 50) {
            $this->add($result, 'WARN', 'hygiene.cron_volume', 'More than 50 scheduled cron events (wp doctor threshold)', [$events.' events']);
        }
        $dupes = array_filter($hooks, fn ($n) => $n > 10);
        if ($dupes) {
            $this->add($result, 'WARN', 'hygiene.cron_duplicates', 'Cron hooks scheduled more than 10 times', array_map(fn ($h, $n) => "$h × $n", array_keys($dupes), $dupes));
        }
        if ($overdue > 0) {
            $this->add($result, 'WARN', 'hygiene.cron_overdue', 'Cron events overdue by more than 24 hours (WP-Cron may not be running)', [$overdue.' overdue events']);
        }
    }

    private function checkIocOptions(array &$ctx, array &$result): void
    {
        $placeholders = implode(',', array_fill(0, count(self::IOC_OPTION_NAMES), '?'));
        $rows = $this->q(
            "SELECT option_name, LENGTH(option_value) AS len, autoload FROM {$this->t('options')}
             WHERE option_name IN ($placeholders) OR option_name LIKE 'wds\\_protect%'",
            self::IOC_OPTION_NAMES
        );
        if ($rows) {
            $this->add($result, 'CRITICAL', 'persistence.ioc_option_names', 'Option names used by known WordPress malware families', array_map(
                fn ($r) => $r['option_name'].' · '.$this->bytes((int) $r['len']).' · autoload '.$r['autoload'],
                $rows
            ));
        }
    }

    private function checkAutoloadPayloads(array &$ctx, array &$result): void
    {
        $rows = $this->q(
            "SELECT option_name, autoload, LENGTH(option_value) AS len, LEFT(option_value, 300000) AS val FROM {$this->t('options')}
             WHERE autoload IN ('yes','on','auto-on','auto') AND LENGTH(option_value) > 1024
               AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'
             ORDER BY LENGTH(option_value) DESC LIMIT 400"
        );

        $lookalike = [];
        $base64 = [];
        $objects = [];
        foreach ($rows as $r) {
            $name = (string) $r['option_name'];
            $val = (string) $r['val'];

            if (preg_match('/^(_core_|_site_(?!transient)|_wp_(?!session)|wp_[0-9a-f]{8,}_)/i', $name)) {
                $lookalike[] = sprintf('%s · %s', $name, $this->bytes((int) $r['len']));
            }

            if (! preg_match(self::SECRET_NAME_PATTERN, $name) && preg_match('/[A-Za-z0-9+\/]{2000,}={0,2}/', $val)) {
                $base64[] = sprintf('%s · %s', $name, $this->bytes((int) $r['len']));
            }

            if (preg_match_all('/O:\d+:"([^"]{1,120})"/', $val, $m)) {
                $classes = array_values(array_unique(array_filter($m[1], fn ($c) => ! preg_match(self::SERIALIZED_CLASS_ALLOW, $c))));
                if ($classes) {
                    $objects[] = $name.' · '.implode(', ', array_slice($classes, 0, 5));
                }
            }
        }

        if ($lookalike) {
            $this->add($result, 'WARN', 'persistence.lookalike_options', 'Large autoloaded options named like WordPress core internals', $lookalike);
        }
        if ($base64) {
            $this->add($result, 'WARN', 'persistence.base64_blobs', 'Autoloaded options containing long base64 runs (encoded payloads or embedded assets)', $base64);
        }
        if ($objects) {
            $this->add($result, 'WARN', 'persistence.serialized_objects', 'Autoloaded options holding serialized objects of unexpected classes', $objects);
        }
    }

    private function checkEncodedPayloads(array &$ctx, array &$result): void
    {
        $needles = ['eval(', 'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13', 'create_function', 'assert($', 'shell_exec', 'passthru('];
        $likes = implode(' OR ', array_fill(0, count($needles), 'v LIKE ?'));
        $bindings = array_map(fn ($n) => '%'.$this->escapeLike($n).'%', $needles);

        $sources = [
            'options' => "SELECT option_name AS k, NULL AS obj, LEFT(option_value, 20000) AS v FROM {$this->t('options')} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'",
            'postmeta' => "SELECT meta_key AS k, post_id AS obj, LEFT(meta_value, 20000) AS v FROM {$this->t('postmeta')} WHERE meta_key NOT LIKE '\\_oembed\\_%'",
            'usermeta' => "SELECT meta_key AS k, user_id AS obj, LEFT(meta_value, 20000) AS v FROM {$this->t('usermeta')} WHERE meta_key NOT IN ('session_tokens','_application_passwords')",
        ];

        $hits = [];
        foreach ($sources as $label => $sql) {
            $rows = $this->q("SELECT k, obj, v FROM ($sql) src WHERE ($likes) LIMIT 50", $bindings);
            foreach ($rows as $r) {
                $needle = $this->firstNeedle((string) $r['v'], $needles);
                $withheld = preg_match(self::SECRET_NAME_PATTERN, (string) $r['k']);
                $hits[] = sprintf(
                    '%s %s%s · matched %s · %s',
                    $label,
                    $r['k'],
                    $r['obj'] !== null ? ' (#'.$r['obj'].')' : '',
                    $needle,
                    $withheld ? '[value withheld]' : $this->snippetAround((string) $r['v'], $needle)
                );
            }
        }
        if ($hits) {
            $this->add($result, 'CRITICAL', 'persistence.encoded_payloads', 'PHP execution or decoding functions stored in the database', $hits);
        }
    }

    private function checkSnippetStores(array &$ctx, array &$result): void
    {
        $items = [];
        $rows = $this->q(
            "SELECT ID, post_type, post_status, post_title, LEFT(post_content, 20000) AS c FROM {$this->t('posts')}
             WHERE post_type IN ('wpcode','elementor_snippet','wp_custom_css','custom_css','code-snippets','ihaf_snippet') LIMIT 200"
        );
        foreach ($rows as $r) {
            $issues = $this->contentIssues((string) $r['c'], $ctx);
            $note = $issues ? implode('; ', array_keys($issues)) : 'stored code';
            $items[] = sprintf('%s #%d "%s" (%s) · %s', $r['post_type'], $r['ID'], $this->snippet((string) $r['post_title'], 60), $r['post_status'], $note);
        }

        $options = $this->q(
            "SELECT option_name, LEFT(option_value, 20000) AS v FROM {$this->t('options')}
             WHERE (option_name LIKE 'ihaf\\_%' OR option_name LIKE 'wpcode\\_%' OR option_name LIKE 'elementor\\_custom\\_code%' OR option_name LIKE 'insert\\_headers%')
               AND (option_value LIKE '%<script%' OR option_value LIKE '%<iframe%' OR option_value LIKE '%eval(%')"
        );
        foreach ($options as $o) {
            $items[] = 'option '.$o['option_name'].' · '.$this->snippet((string) $o['v']);
        }

        foreach (array_keys($ctx['tables']) as $table) {
            if (preg_match('/snippets$/i', $table) && str_starts_with($table, $this->prefix)) {
                $code = $this->q('SELECT id, name, LEFT(code, 20000) AS code, active FROM `'.$table.'` WHERE code LIKE ? OR code LIKE ? OR code LIKE ? LIMIT 50', ['%eval(%', '%base64_decode%', '%<script%']);
                foreach ($code as $s) {
                    $items[] = sprintf('%s #%d "%s" active=%s · %s', $table, $s['id'], $this->snippet((string) $s['name'], 60), $s['active'], $this->snippet((string) $s['code']));
                }
            }
        }

        if ($items) {
            $flagged = array_filter($items, fn ($i) => ! str_ends_with($i, '· stored code'));
            $this->add($result, $flagged ? 'CRITICAL' : 'INFO', 'persistence.code_snippet_stores', 'Code stored in snippet or custom-code stores', $items);
        }
    }

    private function checkWidgetsAndTerms(array &$ctx, array &$result): void
    {
        $sources = [];
        foreach ($this->q("SELECT option_name, option_value FROM {$this->t('options')} WHERE option_name LIKE 'widget\\_%' OR option_name IN ('theme_mods_escortwp-child','theme_mods_escortwp')") as $r) {
            $sources[] = ['option '.$r['option_name'], (string) $r['option_value']];
        }
        if (isset($ctx['tables'][$this->prefix.'term_taxonomy'])) {
            foreach ($this->q("SELECT tt.term_taxonomy_id, tt.taxonomy, LEFT(tt.description, 20000) AS d FROM {$this->t('term_taxonomy')} tt WHERE tt.description <> ''") as $r) {
                $sources[] = ['term '.$r['taxonomy'].' #'.$r['term_taxonomy_id'], (string) $r['d']];
            }
        }

        $grouped = [];
        foreach ($sources as [$label, $text]) {
            foreach ($this->contentIssues($text, $ctx) as $code => $evidence) {
                $grouped[$code][] = $label.' · '.$evidence;
            }
            foreach ($this->scriptCounts($text) as $script => $n) {
                $grouped['content.foreign_script_'.$script][] = $label.' · '.$n.' '.$script.' characters · '.$this->snippet($this->firstScriptRun($text, $script));
            }
        }
        $this->emitContentGroups($result, $grouped, 'widgets and term descriptions');
    }

    private function checkPostSignatures(array &$ctx, array &$result): void
    {
        $needles = [
            '<script', '<iframe', 'document.write', 'atob(', 'fromcharcode', 'eval(', 'window.location',
            'http:/https:', '”https:', '“https:', '&#8221;https', 'escortwp_profile_image_link',
            'display:none', 'display: none', 'visibility:hidden', 'visibility: hidden', 'text-indent:-', 'font-size:0', 'left:-9999',
        ];
        $likes = implode(' OR ', array_fill(0, count($needles), 'post_content LIKE ?'));
        $rows = $this->q(
            "SELECT ID, post_type, post_status, LEFT(post_content, 60000) AS c FROM {$this->t('posts')}
             WHERE post_status IN ('publish','private','future') AND post_type NOT IN ({$this->excludedTypes()}) AND ($likes)
             LIMIT 3000",
            array_map(fn ($n) => '%'.$this->escapeLike($n).'%', $needles)
        );

        $grouped = [];
        foreach ($rows as $r) {
            foreach ($this->contentIssues((string) $r['c'], $ctx) as $code => $evidence) {
                $grouped[$code][] = sprintf('%s #%d (%s) · %s', $r['post_type'], $r['ID'], $r['post_status'], $evidence);
            }
        }

        $revisions = $this->q(
            "SELECT COUNT(*) AS n FROM {$this->t('posts')} WHERE post_type = 'revision' AND (post_content LIKE ? OR post_content LIKE ? OR post_content LIKE ?)",
            ['%”https:%', '%http:/https:%', '%<script%']
        );
        if ((int) ($revisions[0]['n'] ?? 0) > 0) {
            $grouped['seo.revisions_malformed'][] = (int) $revisions[0]['n'].' revisions contain curly-quote links, http:/https: or script tags (not public; clean up to avoid restoring them)';
        }

        $this->emitContentGroups($result, $grouped, 'posts, pages and profiles');
    }

    private function checkForeignScripts(array &$ctx, array &$result): void
    {
        $rows = $this->q(
            "SELECT ID, post_type, post_status, LEFT(post_title, 300) AS t, LEFT(post_content, 8000) AS c FROM {$this->t('posts')}
             WHERE post_status IN ('publish','private','future') AND post_type NOT IN ({$this->excludedTypes()})
               AND (LENGTH(post_content) <> CHAR_LENGTH(post_content) OR LENGTH(post_title) <> CHAR_LENGTH(post_title))
             ORDER BY ID DESC LIMIT 20000"
        );
        $grouped = [];
        foreach ($rows as $r) {
            $text = $r['t'].' '.$r['c'];
            foreach ($this->scriptCounts($text) as $script => $n) {
                $grouped['content.foreign_script_'.$script][] = sprintf('%s #%d (%s) · %d %s characters · %s', $r['post_type'], $r['ID'], $r['post_status'], $n, $script, $this->snippet($this->firstScriptRun($text, $script)));
            }
        }

        $users = $this->q(
            "SELECT ID, user_login, display_name FROM {$this->t('users')}
             WHERE LENGTH(user_login) <> CHAR_LENGTH(user_login) OR LENGTH(display_name) <> CHAR_LENGTH(display_name) LIMIT 2000"
        );
        foreach ($users as $u) {
            foreach ($this->scriptCounts($u['user_login'].' '.$u['display_name'], 2) as $script => $n) {
                $grouped['content.foreign_script_'.$script][] = sprintf('user #%d · %s characters in login or display name', $u['ID'], $script);
            }
        }

        $this->emitContentGroups($result, $grouped, 'content and user names');
    }

    private function checkSpamLexicon(array &$ctx, array &$result): void
    {
        $likes = implode(' OR ', array_fill(0, count(self::SPAM_TERMS), 'post_content LIKE ?'));
        $rows = $this->q(
            "SELECT ID, post_type, post_status, LEFT(post_content, 30000) AS c FROM {$this->t('posts')}
             WHERE post_status IN ('publish','private','future') AND post_type NOT IN ({$this->excludedTypes()}) AND ($likes) LIMIT 500",
            array_map(fn ($t) => '%'.$this->escapeLike($t).'%', self::SPAM_TERMS)
        );
        $hits = [];
        foreach ($rows as $r) {
            $text = strip_tags((string) $r['c']);
            foreach (self::SPAM_TERMS as $term) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $text)) {
                    $hits[] = sprintf('%s #%d (%s) · "%s" · %s', $r['post_type'], $r['ID'], $r['post_status'], $term, $this->snippetAround($text, $term));
                    break;
                }
            }
        }
        if ($hits) {
            $this->add($result, 'WARN', 'content.spam_lexicon', 'Spam vocabulary in published content (pharma, gambling, loans, WeChat)', $hits);
        }
    }

    private function checkSeoMeta(array &$ctx, array &$result): void
    {
        $terms = array_merge(self::SPAM_TERMS, ['http://', 'https://', '<a ']);
        $likes = implode(' OR ', array_fill(0, count($terms), 'meta_value LIKE ?'));
        $rows = $this->q(
            "SELECT post_id, meta_key, LEFT(meta_value, 1000) AS v FROM {$this->t('postmeta')}
             WHERE meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw','rank_math_title','rank_math_description') AND ($likes) LIMIT 200",
            array_map(fn ($t) => '%'.$this->escapeLike($t).'%', $terms)
        );
        $foreign = $this->q(
            "SELECT post_id, meta_key, LEFT(meta_value, 1000) AS v FROM {$this->t('postmeta')}
             WHERE meta_key IN ('_yoast_wpseo_title','_yoast_wpseo_metadesc') AND LENGTH(meta_value) <> CHAR_LENGTH(meta_value) LIMIT 5000"
        );
        foreach ($foreign as $f) {
            if ($this->scriptCounts((string) $f['v'])) {
                $rows[] = $f;
            }
        }
        if ($rows) {
            $this->add($result, 'CRITICAL', 'content.seo_meta_injection', 'Links, spam words or foreign-script text in SEO titles and descriptions', array_map(
                fn ($r) => sprintf('post #%d %s · %s', $r['post_id'], $r['meta_key'], $this->snippet((string) $r['v'])),
                $rows
            ));
        }
    }

    private function checkMassCreatedPosts(array &$ctx, array &$result): void
    {
        $rows = $this->q(
            "SELECT DATE(post_date) AS d, post_type, COUNT(*) AS n FROM {$this->t('posts')}
             WHERE post_status = 'publish' AND post_type NOT IN ({$this->excludedTypes()}) AND post_date > DATE_SUB(NOW(), INTERVAL 365 DAY)
             GROUP BY DATE(post_date), post_type"
        );
        $byType = [];
        foreach ($rows as $r) {
            $byType[$r['post_type']][] = (int) $r['n'];
        }
        $spikes = [];
        foreach ($rows as $r) {
            $counts = $byType[$r['post_type']];
            sort($counts);
            $median = $counts[intdiv(count($counts), 2)] ?? 0;
            $n = (int) $r['n'];
            if ($n >= 50 && $n >= 10 * max(1, $median)) {
                $spikes[] = sprintf('%s · %d %s published (daily median %d)', $r['d'], $n, $r['post_type'], $median);
            }
        }
        if ($spikes) {
            $max = max(array_map(fn ($s) => (int) preg_replace('/^\S+ · (\d+).*/', '$1', $s), $spikes));
            $this->add($result, $max >= 200 ? 'CRITICAL' : 'WARN', 'content.mass_created_posts', 'Unusual bursts of published posts in one day', $spikes);
        }
    }

    private function checkOrphanAuthors(array &$ctx, array &$result): void
    {
        $rows = $this->q(
            "SELECT p.post_type, COUNT(*) AS n FROM {$this->t('posts')} p LEFT JOIN {$this->t('users')} u ON u.ID = p.post_author
             WHERE p.post_status = 'publish' AND p.post_type NOT IN ({$this->excludedTypes()}) AND p.post_type NOT IN ('".implode("','", self::SYSTEM_POST_TYPES)."') AND u.ID IS NULL
             GROUP BY p.post_type"
        );
        if ($rows) {
            $this->add($result, 'WARN', 'content.orphan_authors', 'Published content whose author account does not exist', array_map(fn ($r) => $r['n'].' '.$r['post_type'], $rows));
        }
    }

    private function checkOutboundLinks(array &$ctx, array &$result): void
    {
        $domains = [];
        $shortened = [];
        $lastId = PHP_INT_MAX;
        $scanned = 0;
        do {
            $rows = $this->q(
                "SELECT ID, LEFT(post_content, 60000) AS c FROM {$this->t('posts')}
                 WHERE ID < ? AND post_status = 'publish' AND post_type NOT IN ({$this->excludedTypes()}) AND post_content LIKE '%http%'
                 ORDER BY ID DESC LIMIT 1000",
                [$lastId]
            );
            foreach ($rows as $r) {
                $lastId = (int) $r['ID'];
                foreach ($this->linkHosts((string) $r['c']) as $host) {
                    if ($this->isNetworkHost($host, $ctx)) {
                        continue;
                    }
                    $domains[$host] = ($domains[$host] ?? 0) + 1;
                    if (in_array($host, self::SHORTENERS, true)) {
                        $shortened[$host][] = $r['ID'];
                    }
                }
            }
            $scanned += count($rows);
        } while (count($rows) === 1000 && $scanned < 60000);

        foreach ($this->q("SELECT option_value FROM {$this->t('options')} WHERE option_name LIKE 'widget\\_%'") as $w) {
            foreach ($this->linkHosts((string) $w['option_value']) as $host) {
                if (! $this->isNetworkHost($host, $ctx)) {
                    $domains[$host] = ($domains[$host] ?? 0) + 1;
                }
            }
        }

        arsort($domains);
        $result['inventory']['_outbound_domains'] = array_keys($domains);
        $result['inventory']['Outbound domains'] = count($domains).' distinct; top: '.implode(', ', array_map(fn ($h, $n) => "$h ($n)", array_slice(array_keys($domains), 0, 12), array_slice($domains, 0, 12)));

        if ($shortened) {
            $this->add($result, 'INFO', 'content.shortener_links', 'Links through URL shorteners (destination hidden)', array_map(
                fn ($h, $ids) => $h.' · '.count($ids).' posts, e.g. #'.implode(', #', array_slice(array_unique($ids), 0, 5)),
                array_keys($shortened),
                $shortened
            ));
        }
    }

    private function checkComments(array &$ctx, array &$result): void
    {
        if (! isset($ctx['tables'][$this->prefix.'comments'])) {
            return;
        }
        $rows = $this->q(
            "SELECT comment_approved AS status, COUNT(*) AS n, SUM(comment_content LIKE '%http%') AS with_links
             FROM {$this->t('comments')} GROUP BY comment_approved"
        );
        $approvedLinks = 0;
        $summary = [];
        foreach ($rows as $r) {
            $summary[] = sprintf('%s: %d (%d with links)', $r['status'], $r['n'], $r['with_links']);
            if ((string) $r['status'] === '1') {
                $approvedLinks = (int) $r['with_links'];
            }
        }
        $result['inventory']['Comments'] = $summary ? implode(' · ', $summary) : 'none';
        if ($approvedLinks > 0) {
            $this->add($result, 'WARN', 'content.comment_links', 'Approved comments containing links', [$approvedLinks.' approved comments with links']);
        }
    }

    private function checkYoastRedirects(array &$ctx, array &$result): void
    {
        $rows = $this->q("SELECT option_name, option_value FROM {$this->t('options')} WHERE option_name IN ('wpseo-premium-redirects-base','wpseo-premium-redirects-export-plain','wpseo-premium-redirects-export-regex')");
        $external = [];
        foreach ($rows as $row) {
            $data = $this->unserializeSafe($row['option_value']);
            if (! is_array($data)) {
                continue;
            }
            foreach ($data as $key => $redirect) {
                $origin = is_array($redirect) && isset($redirect['origin']) ? $redirect['origin'] : $key;
                $url = is_array($redirect) ? (string) ($redirect['url'] ?? '') : '';
                $host = $this->hostOf($url);
                if ($host !== '' && preg_match('#^https?://#i', $url) && ! $this->isNetworkHost($host, $ctx)) {
                    $external[$origin.' → '.$url] = true;
                }
            }
        }
        if ($external) {
            $this->add($result, 'WARN', 'content.external_redirects', 'Yoast redirects sending site paths to external domains', array_keys($external));
        }
    }

    private function checkSlugAliases(array &$ctx, array &$result): void
    {
        $profileType = (string) ($ctx['options']['taxonomy_profile_url'] ?? 'escort') ?: 'escort';
        $rows = $this->q(
            "SELECT COUNT(*) AS n FROM (
                SELECT pm.meta_value FROM {$this->t('postmeta')} pm JOIN {$this->t('posts')} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_wp_old_slug' AND p.post_type = ? AND p.post_status NOT IN ('trash','auto-draft','inherit')
                GROUP BY pm.meta_value HAVING COUNT(DISTINCT p.ID) > 1
             ) shared",
            [$profileType]
        );
        $n = (int) ($rows[0]['n'] ?? 0);
        if ($n > 0) {
            $this->add($result, 'INFO', 'seo.ambiguous_slug_aliases', 'Old profile slugs held by more than one profile (their old URLs return 404)', [$n.' shared old slugs on post type '.$profileType]);
        }
    }

    private function checkAutoload(array &$ctx, array &$result): void
    {
        $total = $this->q("SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM {$this->t('options')} WHERE autoload IN ('yes','on','auto-on','auto')")[0];
        $top = $this->q("SELECT option_name, LENGTH(option_value) AS len FROM {$this->t('options')} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY LENGTH(option_value) DESC LIMIT 10");
        $bytes = (int) $total['bytes'];
        $result['inventory']['Autoload'] = sprintf('%s across %d options', $this->bytes($bytes), $total['n']);
        if ($bytes > 900 * 1024) {
            $this->add($result, 'WARN', 'hygiene.autoload_size', 'Autoloaded options exceed 900 KB (loaded on every request)', array_merge(
                [$this->bytes($bytes).' total'],
                array_map(fn ($r) => $r['option_name'].' · '.$this->bytes((int) $r['len']), $top)
            ));
        }
    }

    private function checkTransientsAndRevisions(array &$ctx, array &$result): void
    {
        $expired = (int) ($this->q("SELECT COUNT(*) AS n FROM {$this->t('options')} WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < UNIX_TIMESTAMP()")[0]['n'] ?? 0);
        if ($expired > 1000) {
            $this->add($result, 'INFO', 'hygiene.expired_transients', 'More than 1,000 expired transients', [$expired.' expired transients']);
        }

        $revisions = $this->q(
            "SELECT post_parent, COUNT(*) AS n FROM {$this->t('posts')} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > 25 ORDER BY n DESC LIMIT 10"
        );
        if ($revisions) {
            $this->add($result, 'INFO', 'hygiene.revision_bloat', 'Posts with more than 25 revisions', array_map(fn ($r) => 'post #'.$r['post_parent'].' · '.$r['n'].' revisions', $revisions));
        }
    }

    private function checkActionScheduler(array &$ctx, array &$result): void
    {
        $table = $this->prefix.'actionscheduler_actions';
        if (! isset($ctx['tables'][$table])) {
            return;
        }
        $rows = $this->q("SELECT status, COUNT(*) AS n, SUM(scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS old FROM `$table` GROUP BY status");
        $by = array_column($rows, null, 'status');
        $failed = (int) ($by['failed']['n'] ?? 0);
        $stalePending = (int) ($by['pending']['old'] ?? 0);
        if ($failed > 100 || $stalePending > 100) {
            $this->add($result, 'WARN', 'hygiene.action_scheduler_backlog', 'Action Scheduler backlog', [$failed.' failed actions', $stalePending.' pending actions older than 7 days']);
        }
    }

    private function checkPluginUpdates(array &$ctx, array &$result): void
    {
        $row = $this->q("SELECT option_value FROM {$this->t('options')} WHERE option_name = '_site_transient_update_plugins' LIMIT 1");
        $data = $this->unserializeSafe($row[0]['option_value'] ?? null);
        if (! is_object($data) && ! is_array($data)) {
            return;
        }
        $data = (array) $data;
        $response = isset($data['response']) ? (array) $data['response'] : [];
        $updates = [];
        foreach ($response as $file => $info) {
            $info = (array) $info;
            $current = ((array) ($data['checked'] ?? []))[$file] ?? '?';
            $updates[] = sprintf('%s %s → %s', $file, $current, $info['new_version'] ?? '?');
        }
        if ($updates) {
            $this->add($result, 'INFO', 'inventory.plugin_updates', 'Plugins with updates available (as of WordPress\'s last update check)', $updates);
        }
    }

    // ------------------------------------------------------------------
    // Content classification
    // ------------------------------------------------------------------

    /** @return array<string,string> finding code => evidence */
    private function contentIssues(string $text, array $ctx): array
    {
        $issues = [];
        $lower = strtolower($text);

        if (preg_match('/<script\b[^>]*\bsrc\s*=\s*["\']?([^"\'\s>]+)/i', $text, $m)) {
            $host = $this->hostOf($m[1]);
            if ($host !== '' && ! $this->isNetworkHost($host, $ctx) && ! $this->hostMatches($host, self::SCRIPT_HOST_ALLOW)) {
                $issues['content.external_script'] = 'script src '.$this->snippet($m[1], 120);
            }
        }
        if (preg_match('/<script\b(?![^>]*application\/ld\+json)[^>]*>(.{0,400})/is', $text, $m) && ! isset($issues['content.external_script'])) {
            $body = $m[1];
            if (preg_match('/eval\(|atob\(|fromcharcode|document\.write|window\.location|unescape\(/i', $body)) {
                $issues['content.obfuscated_script'] = $this->snippet($body);
            } elseif (trim(strip_tags($body)) !== '') {
                $issues['content.inline_script'] = $this->snippet($body);
            }
        }
        if (preg_match_all('/<iframe\b[^>]*\bsrc\s*=\s*["\']?([^"\'\s>]+)/i', $text, $m)) {
            foreach ($m[1] as $src) {
                $host = $this->hostOf($src);
                if ($host !== '' && ! $this->isNetworkHost($host, $ctx) && ! $this->hostMatches($host, self::IFRAME_HOST_ALLOW)) {
                    $issues['content.unknown_iframe'] = 'iframe '.$this->snippet($src, 120);
                    break;
                }
            }
        }
        if (! isset($issues['content.obfuscated_script']) && preg_match('/document\.write\s*\(|atob\s*\(|fromcharcode\s*\(/i', $text, $m)) {
            $issues['content.obfuscated_script'] = $this->snippetAround($text, $m[0]);
        }
        if (preg_match('/<([a-z][a-z0-9]*+)\b[^>]*style\s*=\s*["\'][^"\']*(display\s*:\s*none|visibility\s*:\s*hidden|text-indent\s*:\s*-\d{3,}|font-size\s*:\s*0(px)?\s*[;"\']|left\s*:\s*-\d{3,}px)[^"\']*["\'][^>]*>(?:(?!<\/\1>).){0,2000}<a\s[^>]*href/is', $text, $m)) {
            $issues['content.hidden_links'] = $this->snippet($m[0]);
        }
        if (str_contains($lower, 'http:/https:')) {
            $issues['seo.malformed_links'] = $this->snippetAround($text, 'http:/https:');
        } elseif (preg_match('/href\s*=\s*["\']?(”|“|&#822[01];|\\\\?"")\s*https?:/iu', $text, $m)) {
            $issues['seo.malformed_links'] = $this->snippetAround($text, $m[0]);
        }
        if (str_contains($lower, 'escortwp_profile_image_link')) {
            $issues['seo.retired_parameters'] = $this->snippetAround($text, 'escortwp_profile_image_link');
        }

        return $issues;
    }

    /** @return array<string,int> script name => character count, only above threshold */
    private function scriptCounts(string $text, int $cjkMin = 5): array
    {
        $out = [];
        $patterns = [
            'CJK' => ['/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $cjkMin],
            'Cyrillic' => ['/\p{Cyrillic}/u', 20],
            'Greek' => ['/\p{Greek}/u', 20],
        ];
        foreach ($patterns as $name => [$pattern, $min]) {
            $n = @preg_match_all($pattern, $text);
            if ($n !== false && $n >= $min) {
                $out[$name] = $n;
            }
        }

        return $out;
    }

    private function firstScriptRun(string $text, string $script): string
    {
        $class = ['CJK' => '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}', 'Cyrillic' => '\p{Cyrillic}', 'Greek' => '\p{Greek}'][$script] ?? '\p{Han}';
        if (preg_match('/.{0,30}['.$class.'][^<]{0,90}/u', $text, $m)) {
            return $m[0];
        }

        return '';
    }

    private function emitContentGroups(array &$result, array $grouped, string $where): void
    {
        $meta = [
            'content.external_script' => ['CRITICAL', 'Script tags loading code from outside the network'],
            'content.obfuscated_script' => ['CRITICAL', 'Obfuscated or redirecting JavaScript'],
            'content.inline_script' => ['WARN', 'Inline script tags'],
            'content.unknown_iframe' => ['WARN', 'Iframes from unrecognised hosts'],
            'content.hidden_links' => ['CRITICAL', 'Links hidden with CSS'],
            'content.foreign_script_CJK' => ['CRITICAL', 'Chinese, Japanese or Korean text'],
            'content.foreign_script_Cyrillic' => ['WARN', 'Cyrillic text'],
            'content.foreign_script_Greek' => ['WARN', 'Greek text'],
            'seo.malformed_links' => ['WARN', 'Malformed links (http:/https:, curly-quote or doubled-quote hrefs)'],
            'seo.retired_parameters' => ['INFO', 'Retired escortwp_profile_image_link URLs stored in content'],
            'seo.revisions_malformed' => ['INFO', 'Old revisions carrying malformed links or scripts'],
        ];
        foreach ($grouped as $code => $items) {
            [$severity, $title] = $meta[$code] ?? ['WARN', $code];
            $this->add($result, $severity, $code, $title.' in '.$where, $items);
        }
    }

    // ------------------------------------------------------------------
    // Network analysis and report
    // ------------------------------------------------------------------

    private function networkFindings(array &$results): array
    {
        $scanned = array_values(array_filter($results, fn ($r) => $r['status'] !== 'unreachable'));
        $network = [];
        if (count($scanned) < 2) {
            return $network;
        }

        $emails = [];
        foreach ($scanned as $r) {
            foreach (array_unique($r['inventory']['_admin_emails'] ?? []) as $email) {
                if (! $this->isCompanyEmail($email)) {
                    $emails[$email][] = $r['target']['name'];
                }
            }
        }
        $shared = array_filter($emails, fn ($markets) => count($markets) > 1);
        if ($shared) {
            $network[] = ['CRITICAL', 'Same non-company administrator email on several markets', array_map(
                fn ($email, $markets) => $this->maskEmail($email).' → '.implode(', ', $markets),
                array_keys($shared),
                $shared
            )];
        }

        if (count($scanned) >= 5) {
            foreach (['_plugins' => ['WARN', 'network.rare_plugin', 'Plugins active on only one market'], '_cron_hooks' => ['INFO', 'network.rare_cron_hook', 'Cron hooks present on only one market']] as $key => [$severity, $code, $title]) {
                $where = [];
                foreach ($scanned as $r) {
                    foreach (array_unique($r['inventory'][$key] ?? []) as $item) {
                        $where[$item][] = $r['target']['name'];
                    }
                }
                $rare = array_filter($where, fn ($m) => count($m) === 1);
                if ($rare) {
                    $network[] = [$severity, $title, array_map(fn ($item, $m) => $item.' → '.$m[0], array_keys($rare), $rare)];
                    foreach ($results as &$r) {
                        $mine = array_keys(array_filter($rare, fn ($m) => $m[0] === $r['target']['name']));
                        if ($mine) {
                            $this->add($r, $severity, $code, $title.' (compared across '.count($scanned).' scanned markets)', $mine);
                        }
                    }
                    unset($r);
                }
            }

            $themes = array_count_values(array_filter(array_map(fn ($r) => $r['inventory']['_theme'] ?? '', $scanned)));
            arsort($themes);
            $majority = array_key_first($themes);
            $odd = array_filter($scanned, fn ($r) => ($r['inventory']['_theme'] ?? '') !== '' && $r['inventory']['_theme'] !== $majority);
            if ($odd) {
                $network[] = ['INFO', 'Markets on a different active theme than the majority ('.$majority.')', array_map(fn ($r) => $r['target']['name'].' → '.$r['inventory']['_theme'], $odd)];
            }
        }

        return $network;
    }

    private function renderReport(array $results, array $network, string $startedAt): string
    {
        $out = [];
        $rule = str_repeat('=', self::LINE);
        $thin = str_repeat('-', self::LINE);
        $timeout = max(2, min(120, (int) $this->option('timeout')));

        $statuses = array_count_values(array_map(fn ($r) => $r['status'], $results));
        $totals = ['CRITICAL' => 0, 'WARN' => 0, 'INFO' => 0];
        foreach ($results as $r) {
            foreach ($this->severityCounts($r['findings']) as $s => $n) {
                $totals[$s] += $n;
            }
        }
        foreach ($network as [$s]) {
            $totals[$s]++;
        }

        $out[] = $rule;
        $out[] = 'EXOTIC NETWORK — WORDPRESS DATABASE AUDIT';
        $out[] = $rule;
        $out[] = 'Started:        '.$startedAt.' UTC';
        $out[] = 'Finished:       '.gmdate('Y-m-d H:i:s').' UTC';
        $out[] = 'Mode:           read-only session, SELECT/SHOW only, '.$timeout.'s statement timeout, '.($this->option('quick') ? 'quick (heavy content scans skipped)' : 'full');
        $out[] = sprintf(
            'Markets:        %d targeted · %d scanned · %d partial · %d unreachable',
            count($results),
            $statuses['scanned'] ?? 0,
            $statuses['partial'] ?? 0,
            $statuses['unreachable'] ?? 0
        );
        $out[] = 'Company emails: '.implode(', ', $this->companyDomains);
        $out[] = sprintf('Findings:       CRITICAL %d · WARN %d · INFO %d', $totals['CRITICAL'], $totals['WARN'], $totals['INFO']);
        $out[] = '';
        $out[] = 'Severity guide: CRITICAL = likely compromise or live damage, act today · WARN = needs review ·';
        $out[] = '                INFO = housekeeping or context. Emails are masked; secrets are never read.';
        $out[] = '';

        $out[] = 'SUMMARY — MARKETS BY SEVERITY';
        $out[] = $thin;
        $sorted = $results;
        usort($sorted, function ($a, $b) {
            $ca = $this->severityCounts($a['findings']);
            $cb = $this->severityCounts($b['findings']);

            return [$cb['CRITICAL'], $cb['WARN'], $a['target']['name']] <=> [$ca['CRITICAL'], $ca['WARN'], $b['target']['name']];
        });
        foreach ($sorted as $r) {
            $c = $this->severityCounts($r['findings']);
            $status = $r['status'] === 'scanned' ? '' : strtoupper($r['status']);
            $out[] = sprintf(
                '  %-24s CRITICAL %-3d WARN %-3d INFO %-3d %-12s %s',
                $this->truncate($r['target']['name'], 24),
                $c['CRITICAL'],
                $c['WARN'],
                $c['INFO'],
                $status,
                $this->hostOf($r['target']['domain'])
            );
        }
        $unreachable = array_filter($results, fn ($r) => $r['status'] === 'unreachable');
        if ($unreachable) {
            $out[] = '';
            $out[] = '  Unreachable:';
            foreach ($unreachable as $r) {
                foreach ($this->wrap($r['target']['name'].' — '.$r['error'], 6) as $l) {
                    $out[] = $l;
                }
            }
        }
        $out[] = '';

        if ($network) {
            $out[] = 'NETWORK-WIDE PATTERNS';
            $out[] = $thin;
            foreach ($network as [$severity, $title, $items]) {
                $out[] = sprintf('[%s] %s', $severity, $title);
                foreach (array_slice($items, 0, 40) as $item) {
                    foreach ($this->wrap('- '.$item, 6) as $l) {
                        $out[] = $l;
                    }
                }
                if (count($items) > 40) {
                    $out[] = '      … '.(count($items) - 40).' more';
                }
                $out[] = '';
            }
        }

        $out[] = $rule;
        $out[] = 'MARKET DETAILS';
        $out[] = $rule;
        foreach ($sorted as $r) {
            $t = $r['target'];
            $c = $this->severityCounts($r['findings']);
            $out[] = '';
            $out[] = sprintf('%s%s — %s', $t['platform_id'] ? '['.$t['platform_id'].'] ' : '', $t['name'], $t['domain'] ?: '(no domain)');
            $out[] = sprintf(
                '  Database: %s (prefix %s) on %s · %s · %.1fs',
                $t['database'],
                $t['prefix'],
                $t['host_label'],
                $r['engine'] ?? 'not connected',
                $r['duration']
            );
            $out[] = sprintf('  Result:   %s · CRITICAL %d · WARN %d · INFO %d · %d checks ok, %d failed', strtoupper($r['status']), $c['CRITICAL'], $c['WARN'], $c['INFO'], $r['checks_ok'], count($r['check_errors']));
            $out[] = $thin;

            if ($r['status'] === 'unreachable') {
                foreach ($this->wrap('Could not connect: '.$r['error'], 2) as $l) {
                    $out[] = $l;
                }
                continue;
            }

            if (! $r['findings']) {
                $out[] = '  No findings.';
            }
            foreach (['CRITICAL', 'WARN', 'INFO'] as $severity) {
                foreach (array_filter($r['findings'], fn ($f) => $f['severity'] === $severity) as $f) {
                    $out[] = sprintf('  %-8s  %s', $severity, $f['title']);
                    $out[] = '            ['.$f['code'].']'.($f['total'] > count($f['items']) ? ' showing '.count($f['items']).' of '.$f['total'] : '');
                    foreach ($f['items'] as $item) {
                        foreach ($this->wrap('- '.$item, 12) as $l) {
                            $out[] = $l;
                        }
                    }
                    $out[] = '';
                }
            }

            $out[] = '  Inventory';
            foreach ($r['inventory'] as $label => $value) {
                if (str_starts_with((string) $label, '_')) {
                    continue;
                }
                foreach ($this->wrap(sprintf('%-17s %s', $label.':', $value), 4) as $l) {
                    $out[] = $l;
                }
            }

            if ($r['check_errors']) {
                $out[] = '';
                $out[] = '  Checks that did not complete';
                foreach ($r['check_errors'] as $key => $error) {
                    foreach ($this->wrap($key.': '.$error, 4) as $l) {
                        $out[] = $l;
                    }
                }
            }
        }

        $out[] = '';
        $out[] = $rule;
        $out[] = 'END OF REPORT';
        $out[] = $rule;

        return implode(PHP_EOL, $out).PHP_EOL;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function add(array &$result, string $severity, string $code, string $title, array $items, ?int $total = null): void
    {
        $items = array_values(array_unique(array_map('strval', $items)));
        $result['findings'][] = [
            'severity' => $severity,
            'code' => $code,
            'title' => $title,
            'items' => array_slice($items, 0, 25),
            'total' => $total ?? count($items),
        ];
    }

    private function severityCounts(array $findings): array
    {
        $counts = ['CRITICAL' => 0, 'WARN' => 0, 'INFO' => 0];
        foreach ($findings as $f) {
            $counts[$f['severity']]++;
        }

        return $counts;
    }

    private function excludedTypes(): string
    {
        return implode(',', array_map(fn ($t) => "'".$t."'", self::EXCLUDED_POST_TYPES));
    }

    private function unserializeSafe($value)
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $data = @unserialize($value, ['allowed_classes' => false]);

        return $data === false && $value !== 'b:0;' ? null : $data;
    }

    private function hostOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = str_starts_with($url, '//') ? 'https:'.$url : 'https://'.$url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) ?? '';
    }

    private function isNetworkHost(string $host, array $ctx): bool
    {
        $host = preg_replace('/^www\./', '', strtolower($host));
        if ($host === '' || ($ctx['site_host'] !== '' && ($host === $ctx['site_host'] || str_ends_with($host, '.'.$ctx['site_host'])))) {
            return true;
        }

        return $this->hostMatches($host, $this->networkHosts);
    }

    private function hostMatches(string $host, array $list): bool
    {
        foreach ($list as $candidate) {
            $candidate = preg_replace('/^www\./', '', strtolower((string) $candidate));
            if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.'.$candidate))) {
                return true;
            }
        }

        return false;
    }

    private function linkHosts(string $text): array
    {
        if (! preg_match_all('#https?://([a-z0-9.-]+\.[a-z]{2,})#i', $text, $m)) {
            return [];
        }

        return array_values(array_unique(array_map(fn ($h) => preg_replace('/^www\./', '', strtolower($h)), $m[1])));
    }

    private function isCompanyEmail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && $this->hostMatches($domain, $this->companyDomains);
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email === '' ? '(no email)' : $email;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function firstNeedle(string $haystack, array $needles): string
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return $needle;
            }
        }

        return $needles[0];
    }

    private function snippet(string $text, int $max = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    private function snippetAround(string $text, string $needle, int $radius = 70): string
    {
        $pos = mb_stripos($text, $needle);
        if ($pos === false) {
            return $this->snippet($text);
        }
        $start = max(0, $pos - $radius);

        return ($start > 0 ? '…' : '').$this->snippet(mb_substr($text, $start, $radius * 2 + mb_strlen($needle)), $radius * 2 + 40);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function bytes(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private function wrap(string $text, int $indent): array
    {
        $width = self::LINE - $indent;
        $lines = [];
        foreach (explode("\n", wordwrap($text, $width, "\n", true)) as $i => $line) {
            $lines[] = str_repeat(' ', $indent + ($i > 0 ? 2 : 0)).$line;
        }

        return $lines;
    }

    private function cleanError(string $message): string
    {
        $message = preg_replace('/\s*\(Connection: .*$/s', '', $message) ?? $message;
        $message = preg_replace('/password=[^\s;,)]+/i', 'password=***', $message) ?? $message;

        return $this->snippet($message, 300);
    }
}
