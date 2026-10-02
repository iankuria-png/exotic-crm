<?php

namespace App\Services\DbScanner\Engine;

use App\Services\DbScanner\Evidence\EvidenceSanitizer;
use App\Services\DbScanner\Malware\HostContext;
use App\Services\DbScanner\Malware\SerializedParser;
use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Reader\ReaderException;
use App\Services\DbScanner\Surfaces\SchemaInfo;
use App\Services\DbScanner\Surfaces\Surface;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;

/**
 * Reads the small, bounded inventory surfaces and turns them into snapshot
 * components. Each component records whether it is complete; a timeout,
 * denied read or unverifiable metadata view is never reported as empty.
 */
class InventoryCollector
{
    public const CORE_OPTION_NAMES = [
        'siteurl', 'home', 'blog_public', 'admin_email', 'users_can_register', 'default_role', 'upload_path',
        'upload_url_path', 'permalink_structure', 'template', 'stylesheet', 'db_version', 'active_plugins', 'cron',
        'wpseo', 'taxonomy_profile_url', 'wpseo-premium-redirects-base',
    ];

    /**
     * Capabilities that amount to site control. `administrator` as a granted
     * capability and `level_10` matter because many plugins gate admin screens
     * with current_user_can('administrator') or the legacy level checks.
     */
    public const PRIVILEGED_CAPS = [
        'manage_options', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'activate_plugins',
        'install_plugins', 'edit_plugins', 'edit_themes', 'edit_files', 'update_core', 'unfiltered_upload',
        'manage_network', 'administrator', 'level_10',
    ];

    /** Capabilities that let a user run PHP or take over accounts directly. */
    public const CODE_EXECUTION_CAPS = ['edit_plugins', 'edit_themes', 'edit_files', 'install_plugins', 'update_core', 'unfiltered_upload'];

    private const CAPABILITY_ROW_LIMIT = 200000;

    private const VALUE_CAP = 65536;

    private SerializedParser $parser;

    public function __construct(private readonly EvidenceSanitizer $sanitizer = new EvidenceSanitizer)
    {
        $this->parser = new SerializedParser;
    }

    /**
     * @param  array<string, Surface>  $surfaces  inventory surfaces in this profile
     * @param  array<string, mixed>  $capabilities  preflight capability proof
     * @return array{components: array, coverage: array<string, array{status: string, reason: ?string}>}
     */
    public function collect(MarketDbReader $reader, SchemaInfo $schema, array $surfaces, SurfaceRegistry $registry, string $siteHost, array $capabilities = []): array
    {
        $components = [];
        $coverage = [];

        foreach ($surfaces as $key => $surface) {
            foreach ($registry->inventoryTables($key) as $suffix) {
                if (! $schema->has($suffix)) {
                    $coverage[$key] = $surface->core
                        ? ['status' => 'incomplete', 'reason' => 'unsupported_schema']
                        : ['status' => 'not_applicable', 'reason' => 'table_absent'];

                    continue 2;
                }
            }

            try {
                [$component, $status] = $this->read($key, $reader, $schema, $siteHost, $capabilities);
                if ($component !== null) {
                    $components[$this->componentName($key)] = $component;
                }
                $coverage[$key] = $status;
            } catch (ReaderException $e) {
                if (in_array($e->errorCode, [ReaderException::CONNECTION_LOST, ReaderException::CONTROL_ABORT], true)) {
                    throw $e;
                }
                $coverage[$key] = ['status' => 'incomplete', 'reason' => match ($e->errorCode) {
                    ReaderException::TIMEOUT => 'timeout',
                    ReaderException::ACCESS_DENIED => 'access_denied',
                    ReaderException::REJECTED_TEMPLATE => 'unsupported_schema',
                    default => 'query_failed',
                }];
            }
        }

        // Application-password metadata is read with the privileged accounts.
        if (isset($coverage['usermeta.app_passwords'])) {
            $coverage['usermeta.app_passwords'] = $coverage['users.privileged'] ?? ['status' => 'incomplete', 'reason' => 'not_reached'];
        }

        return ['components' => $components, 'coverage' => $coverage];
    }

    public function componentName(string $surfaceKey): string
    {
        return match ($surfaceKey) {
            'schema.tables' => 'tables',
            'schema.triggers' => 'triggers',
            'schema.events_routines' => 'events_routines',
            'options.core' => 'core_options',
            'users.privileged' => 'administrators',
            'usermeta.app_passwords' => 'app_passwords',
            'options.autoload' => 'autoload',
            'posts.daily_volume' => 'daily_volume',
            'posts.orphan_authors' => 'orphan_authors',
            'options.transients_expired' => 'expired_transients',
            'posts.revision_counts' => 'revision_counts',
            'postmeta.slug_aliases' => 'slug_aliases',
            'postmeta.orphans' => 'orphan_postmeta',
            'actionscheduler.summary' => 'action_scheduler',
            'users.email_domains' => 'email_domains',
            'activity.failed_logins' => 'failed_logins',
            default => $surfaceKey,
        };
    }

    /**
     * @return array{0: ?array, 1: array{status: string, reason: ?string}}
     */
    private function read(string $key, MarketDbReader $reader, SchemaInfo $schema, string $siteHost, array $capabilities): array
    {
        $c = $reader->compiler();
        $complete = ['status' => 'complete', 'reason' => null];

        switch ($key) {
            case 'schema.tables':
                $noPk = [];
                $pkTables = array_column($reader->select($c->primaryKeyTables()), 'tbl');
                foreach (array_keys($schema->tables) as $table) {
                    if (! in_array($table, $pkTables, true)) {
                        $noPk[] = $table;
                    }
                }

                return [[
                    'complete' => true,
                    'data' => [
                        'tables' => $schema->tables,
                        'no_primary_key' => array_slice($noPk, 0, 100),
                        'custom' => array_slice($schema->customTables, 0, 200),
                        'unprefixed' => array_slice($schema->unprefixed, 0, 200),
                        'other_prefixes' => $schema->otherPrefixes,
                        'prefix' => $schema->prefix,
                    ],
                ], $complete];

            case 'schema.triggers':
                $rows = $reader->select($c->triggers());
                $items = [];
                foreach ($rows as $row) {
                    $body = (string) ($row['body'] ?? '');
                    $items[] = [
                        'name' => mb_substr((string) $row['name'], 0, 120),
                        'timing' => $row['timing'] ?? null,
                        'event' => $row['event'] ?? null,
                        'table' => $row['tbl'] ?? null,
                        'definition_hash' => hash('sha256', implode('|', [$row['name'] ?? '', $row['timing'] ?? '', $row['event'] ?? '', $row['tbl'] ?? '', $body])),
                        'excerpt' => $this->sanitizer->clean($body),
                        'harmful' => (bool) preg_match('/\b(insert\s+into|update|delete\s+from|replace\s+into)\b[^;]*\b\w*(users|usermeta|options)\b|signal\s+sqlstate|user_pass|capabilities/i', $body),
                        'body_complete' => strlen($body) < 65536,
                    ];
                }
                $exhaustive = (bool) ($capabilities['trigger_visibility_exhaustive'] ?? false) || $reader->compiler()->dialect() === 'sqlite';

                return [[
                    'complete' => $exhaustive,
                    'visibility' => $exhaustive ? 'exhaustive' : 'unverified',
                    'data' => $items,
                ], $exhaustive ? $complete : ['status' => 'incomplete', 'reason' => 'visibility_unverified']];

            case 'schema.events_routines':
                $events = $c->events();
                $routines = $c->routines();
                if ($events === null || $routines === null) {
                    return [['complete' => true, 'data' => []], ['status' => 'not_applicable', 'reason' => 'engine']];
                }
                $items = [];
                foreach ($reader->select($events) as $row) {
                    $items[] = ['type' => 'EVENT', 'name' => mb_substr((string) $row['name'], 0, 120), 'status' => $row['status'] ?? null, 'excerpt' => $this->sanitizer->clean((string) ($row['body'] ?? ''))];
                }
                foreach ($reader->select($routines) as $row) {
                    $items[] = ['type' => (string) ($row['type'] ?? 'ROUTINE'), 'name' => mb_substr((string) $row['name'], 0, 120)];
                }
                $exhaustive = (bool) ($capabilities['routine_visibility_exhaustive'] ?? false);

                return [[
                    'complete' => $exhaustive,
                    'visibility' => $exhaustive ? 'exhaustive' : 'unverified',
                    'data' => $items,
                ], $exhaustive ? $complete : ['status' => 'incomplete', 'reason' => 'visibility_unverified']];

            case 'options.core':
                return [$this->coreOptions($reader, $schema), $complete];

            case 'users.privileged':
                return [$this->administrators($reader, $schema), $complete];

            case 'usermeta.app_passwords':
                return [null, $complete]; // read together with administrators; see administrators()

            case 'options.autoload':
                $summary = $reader->select($c->autoloadSummary($schema->table('options')))[0] ?? [];
                $top = $reader->select($c->autoloadTop($schema->table('options')));

                return [[
                    'complete' => true,
                    'data' => [
                        'count' => (int) ($summary['n'] ?? 0),
                        'bytes' => (int) ($summary['bytes'] ?? 0),
                        'top' => array_map(fn ($r) => ['name' => mb_substr((string) $r['name'], 0, 191), 'bytes' => (int) $r['len']], $top),
                    ],
                ], $complete];

            case 'posts.daily_volume':
                $rows = $reader->select($c->dailyPostVolume($schema->table('posts'), now()->subDays(400)->toDateString(), SurfaceRegistry::EXCLUDED_POST_TYPES));

                return [[
                    'complete' => true,
                    'data' => array_map(fn ($r) => ['d' => (string) $r['d'], 'type' => (string) $r['post_type'], 'n' => (int) $r['n']], $rows),
                ], $complete];

            case 'posts.orphan_authors':
                $rows = $reader->select($c->orphanAuthors($schema->table('posts'), $schema->table('users'), array_merge(SurfaceRegistry::EXCLUDED_POST_TYPES, SurfaceRegistry::SYSTEM_POST_TYPES)));

                return [['complete' => true, 'data' => array_map(fn ($r) => ['type' => (string) $r['post_type'], 'n' => (int) $r['n']], $rows)], $complete];

            case 'options.transients_expired':
                $n = (int) ($reader->fetchScalar($c->expiredTransients($schema->table('options'), time()), 'n') ?? 0);

                return [['complete' => true, 'data' => ['count' => $n]], $complete];

            case 'posts.revision_counts':
                $rows = $reader->select($c->revisionCounts($schema->table('posts'), 25));

                return [['complete' => true, 'data' => array_map(fn ($r) => ['parent' => (int) $r['parent'], 'n' => (int) $r['n']], $rows)], $complete];

            case 'postmeta.slug_aliases':
                $profileType = 'escort';
                $n = (int) ($reader->fetchScalar($c->slugAliases($schema->table('postmeta'), $schema->table('posts'), $profileType), 'n') ?? 0);

                return [['complete' => true, 'data' => ['shared' => $n, 'post_type' => $profileType]], $complete];

            case 'postmeta.orphans':
                $n = (int) ($reader->fetchScalar($c->orphanPostmeta($schema->table('postmeta'), $schema->table('posts')), 'n') ?? 0);

                return [['complete' => true, 'data' => ['count' => $n]], $complete];

            case 'users.email_domains':
                $rows = $reader->select($c->emailDomainCounts($schema->table('users')));

                return [['complete' => true, 'data' => array_map(fn ($r) => ['domain' => mb_substr((string) $r['d'], 0, 120), 'accounts' => (int) $r['n']], $rows)], $complete];

            case 'activity.failed_logins':
                $rows = $reader->select($c->failedLoginSummary($schema->table('aryo_activity_log'), now()->subDays(7)->timestamp));
                $total = array_sum(array_map(fn ($r) => (int) $r['n'], $rows));

                return [['complete' => true, 'data' => [
                    'window_days' => 7,
                    'failed' => $total,
                    'usernames' => count($rows),
                    // Usernames are masked; only counts and IP spread are kept.
                    'top' => array_map(fn ($r) => [
                        'username' => self::maskLogin((string) $r['username']),
                        'failed' => (int) $r['n'],
                        'ips' => (int) $r['ips'],
                    ], array_slice($rows, 0, 8)),
                    'max_ips_per_username' => $rows === [] ? 0 : max(array_map(fn ($r) => (int) $r['ips'], $rows)),
                ]], $complete];

            case 'actionscheduler.summary':
                $rows = $reader->select($c->actionSchedulerSummary($schema->table('actionscheduler_actions'), now()->subDays(7)->format('Y-m-d H:i:s')));
                $by = [];
                foreach ($rows as $r) {
                    $by[(string) $r['status']] = ['n' => (int) $r['n'], 'old' => (int) $r['old']];
                }

                return [['complete' => true, 'data' => $by], $complete];
        }

        return [null, ['status' => 'not_applicable', 'reason' => 'unknown_surface']];
    }

    private function coreOptions(MarketDbReader $reader, SchemaInfo $schema): array
    {
        $names = array_merge(self::CORE_OPTION_NAMES, [$schema->prefix.'user_roles']);
        $rows = $reader->select($reader->compiler()->namedRows($schema->table('options'), 'option_id', 'option_name', 'option_value', $names, self::VALUE_CAP));

        $raw = [];
        $truncated = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $raw[$name] = (string) ($row['v'] ?? '');
            if ((int) $row['len'] > self::VALUE_CAP) {
                $truncated[] = $name;
            }
        }

        $data = [];
        foreach (['siteurl', 'home', 'blog_public', 'users_can_register', 'default_role', 'upload_path', 'upload_url_path', 'permalink_structure', 'template', 'stylesheet', 'db_version', 'taxonomy_profile_url'] as $name) {
            $data[$name] = isset($raw[$name]) ? mb_substr($raw[$name], 0, 300) : null;
        }

        $adminEmail = strtolower(trim((string) ($raw['admin_email'] ?? '')));
        $data['admin_email_masked'] = EvidenceSanitizer::maskEmail($adminEmail);
        $data['admin_email_token'] = $adminEmail === '' ? null : self::hmac($adminEmail);

        $plugins = $this->parseSerialized($raw['active_plugins'] ?? null);
        $data['active_plugins'] = is_array($plugins) ? array_values(array_map(fn ($p) => mb_substr((string) $p, 0, 200), array_filter($plugins, 'is_scalar'))) : [];

        $data['cron'] = $this->cronSummary($raw['cron'] ?? null);

        $wpseo = $this->parseSerialized($raw['wpseo'] ?? null);
        $data['wpseo'] = is_array($wpseo)
            ? array_intersect_key($wpseo, array_flip(['remove_feed_search', 'deny_search_crawling', 'redirect_search_pretty_urls', 'search_cleanup_emoji', 'search_cleanup_patterns', 'clean_permalinks']))
            : null;

        $data['user_roles'] = $this->privilegedRoles($raw[$schema->prefix.'user_roles'] ?? null);

        $redirects = $this->parseSerialized($raw['wpseo-premium-redirects-base'] ?? null);
        $external = [];
        if (is_array($redirects)) {
            foreach ($redirects as $originKey => $redirect) {
                $origin = is_array($redirect) && isset($redirect['origin']) ? $redirect['origin'] : $originKey;
                $url = is_array($redirect) ? (string) ($redirect['url'] ?? '') : '';
                $host = HostContext::hostOf($url);
                if ($host !== '') {
                    $external[] = ['origin' => mb_substr((string) $origin, 0, 200), 'url' => $this->sanitizer->clean($url), 'host' => $host];
                }
                if (count($external) >= 500) {
                    break;
                }
            }
        }
        $data['redirects'] = $external;

        return ['complete' => $truncated === [], 'truncated' => $truncated, 'data' => $data];
    }

    private function cronSummary(?string $value): array
    {
        $cron = $this->parseSerialized($value);
        if (! is_array($cron)) {
            return ['parsed' => false, 'events' => 0, 'hooks' => [], 'overdue' => 0, 'payload_hooks' => []];
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
                $hook = mb_substr((string) $hook, 0, 120);
                $count = is_array($instances) ? count($instances) : 1;
                $events += $count;
                $hooks[$hook] = ($hooks[$hook] ?? 0) + $count;
                if (is_numeric($timestamp) && (int) $timestamp < time() - 86400) {
                    $overdue += $count;
                }
                $flat = json_encode($instances) ?: '';
                if (preg_match('/eval\(|base64_decode|gzinflate|str_rot13|create_function|<script/i', $flat)) {
                    $payload[$hook] = $this->sanitizer->clean($flat);
                }
            }
        }

        return ['parsed' => true, 'events' => $events, 'hooks' => $hooks, 'overdue' => $overdue, 'payload_hooks' => $payload];
    }

    /**
     * @return array<string, array{privileged: bool, caps: array<int, string>}>|null
     */
    private function privilegedRoles(?string $value): ?array
    {
        $roles = $this->parseSerialized($value);
        if (! is_array($roles)) {
            return null;
        }

        $out = [];
        foreach ($roles as $role => $definition) {
            $caps = is_array($definition['capabilities'] ?? null) ? $definition['capabilities'] : [];
            $granted = array_keys(array_filter($caps, fn ($v) => $v === true || $v === 1 || $v === '1'));
            $privileged = array_values(array_intersect($granted, self::PRIVILEGED_CAPS));
            $out[mb_substr((string) $role, 0, 80)] = ['privileged' => $privileged !== [], 'caps' => $privileged];
        }

        return $out;
    }

    private function administrators(MarketDbReader $reader, SchemaInfo $schema): array
    {
        $c = $reader->compiler();
        $capKey = $schema->prefix.'capabilities';
        $levelKey = $schema->prefix.'user_level';

        $rolesRow = $reader->select($c->namedRows($schema->table('options'), 'option_id', 'option_name', 'option_value', [$schema->prefix.'user_roles'], self::VALUE_CAP));
        $roles = $this->privilegedRoles($rolesRow[0]['v'] ?? null);
        $privilegedRoles = $roles === null
            ? ['administrator']
            : array_keys(array_filter($roles, fn ($r) => $r['privileged']));
        if (! in_array('administrator', $privilegedRoles, true)) {
            $privilegedRoles[] = 'administrator';
        }

        // Page through every capability row: a single bounded read silently
        // dropped the newest accounts on large markets.
        $rows = [];
        $after = 0;
        $truncated = false;
        do {
            $page = $reader->select($c->capabilityRows($schema->table('usermeta'), self::VALUE_CAP, $after));
            foreach ($page as $row) {
                $rows[] = $row;
                $after = (int) $row['__k'];
            }
            if (count($rows) >= self::CAPABILITY_ROW_LIMIT) {
                $truncated = true;
                break;
            }
        } while (count($page) === 2000);
        $siteRoles = [];
        $foreign = [];
        $levels = [];
        foreach ($rows as $row) {
            $metaKey = (string) $row['meta_key'];
            $userId = (int) $row['user_id'];
            if ($metaKey === $levelKey) {
                $levels[$userId] = (int) $row['v'];

                continue;
            }
            if (! str_ends_with($metaKey, 'capabilities')) {
                continue;
            }
            $parsed = $this->parseSerialized((string) $row['v']);
            $granted = is_array($parsed) ? array_keys(array_filter($parsed, fn ($v) => $v === true || $v === 1 || $v === '1')) : [];
            $priv = array_values(array_intersect($granted, array_merge($privilegedRoles, self::PRIVILEGED_CAPS)));

            if ($metaKey === $capKey) {
                $siteRoles[$userId] = ['roles' => $granted, 'privileged' => $priv];
            } elseif ($priv !== []) {
                $foreign[] = ['umeta_id' => (int) $row['__k'], 'user_id' => $userId, 'meta_key' => mb_substr($metaKey, 0, 120), 'grants' => $priv];
            }
        }

        $adminIds = array_keys(array_filter($siteRoles, fn ($r) => $r['privileged'] !== []));
        $lookupIds = array_values(array_unique(array_merge($adminIds, array_column($foreign, 'user_id'), array_keys(array_filter($levels, fn ($l) => $l >= 10)))));

        $users = [];
        if ($lookupIds !== []) {
            foreach (array_chunk($lookupIds, 500) as $chunk) {
                foreach ($reader->select($c->usersByIds($schema->table('users'), $chunk)) as $u) {
                    $users[(int) $u['id']] = $u;
                }
            }
        }

        $admins = [];
        foreach ($adminIds as $id) {
            $u = $users[$id] ?? null;
            $email = strtolower((string) ($u['user_email'] ?? ''));
            $registered = (string) ($u['user_registered'] ?? '');
            $later = 0;
            if ($u && $registered !== '' && strtotime($registered)) {
                $after = date('Y-m-d H:i:s', strtotime($registered) + 30 * 86400);
                $later = (int) ($reader->fetchScalar($c->lowerIdUsersRegisteredAfter($schema->table('users'), $id, $after), 'n') ?? 0);
            }
            $admins[] = [
                'id' => $id,
                'exists' => $u !== null,
                'login' => mb_substr((string) ($u['user_login'] ?? ''), 0, 60),
                'email_masked' => EvidenceSanitizer::maskEmail($email),
                'email_domain' => substr(strrchr($email, '@') ?: '', 1),
                'registered' => $registered,
                'roles' => array_slice($siteRoles[$id]['roles'] ?? [], 0, 10),
                'lower_ids_registered_later' => $later,
            ];
        }

        $hidden = [];
        foreach ($foreign as $row) {
            $hasSite = in_array($row['user_id'], $adminIds, true);
            $hidden[] = $row + [
                'login' => mb_substr((string) ($users[$row['user_id']]['user_login'] ?? ''), 0, 60),
                'user_exists' => isset($users[$row['user_id']]),
                'has_site_privilege' => $hasSite,
            ];
        }

        $legacyLevel = [];
        foreach ($levels as $userId => $level) {
            // A role that grants level_10 already makes the user privileged
            // (above); only a stale level on an ordinary role is anomalous.
            if ($level >= 10 && ! in_array($userId, $adminIds, true)) {
                $legacyLevel[] = ['user_id' => $userId, 'login' => mb_substr((string) ($users[$userId]['user_login'] ?? ''), 0, 60)];
            }
        }

        $appPasswords = [];
        if ($adminIds !== [] && $schema->hasColumn('usermeta', 'meta_value')) {
            foreach ($reader->select($c->applicationPasswordLengths($schema->table('usermeta'), $adminIds)) as $row) {
                if ((int) $row['len'] > 6) {
                    $appPasswords[(int) $row['user_id']] = (int) $row['len'];
                }
            }
        }

        $members = [];
        foreach ($siteRoles as $info) {
            foreach ($info['roles'] as $role) {
                $members[$role] = ($members[$role] ?? 0) + 1;
            }
        }
        $roleRisk = [];
        foreach ((array) $roles as $role => $info) {
            if ($role === 'administrator' || ! $info['privileged']) {
                continue;
            }
            $roleRisk[] = [
                'role' => $role,
                'members' => (int) ($members[$role] ?? 0),
                'privileged_caps' => $info['caps'],
                'code_execution_caps' => array_values(array_intersect($info['caps'], self::CODE_EXECUTION_CAPS)),
                'administrator_capability' => in_array('administrator', $info['caps'], true),
            ];
        }

        return [
            'complete' => ! $truncated,
            'roles_known' => $roles !== null,
            'privileged_roles' => $privilegedRoles,
            'data' => [
                'admins' => $admins,
                'hidden' => $hidden,
                'legacy_level' => $legacyLevel,
                'app_passwords' => $appPasswords,
                'role_risk' => $roleRisk,
                'capability_rows' => count($rows),
            ],
        ];
    }

    private function parseSerialized(?string $value): mixed
    {
        if ($value === null || $value === '' || ! SerializedParser::looksSerialized($value)) {
            return null;
        }

        return $this->parser->parse($value);
    }

    public static function maskLogin(string $login): string
    {
        $login = trim($login);
        if ($login === '') {
            return '(blank)';
        }
        if (in_array(strtolower($login), ['admin', 'administrator', 'root', 'test', 'wordpress'], true)) {
            return strtolower($login);
        }

        return mb_substr($login, 0, 2).'***';
    }

    public static function hmac(string $value): string
    {
        return 'v1:'.hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
