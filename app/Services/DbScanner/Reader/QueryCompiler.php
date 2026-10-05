<?php

namespace App\Services\DbScanner\Reader;

/**
 * The closed set of statements the scanner may send to a market database.
 *
 * Rules never carry SQL. Every statement here is a reviewed template: table
 * and column identifiers are validated against discovered base-table
 * metadata and a strict grammar, values are bound, integers are cast, and
 * the result is always a single SELECT/SHOW. There are no stored functions,
 * views, locking reads, FILE/OUTFILE or multi-statements.
 *
 * Two dialects exist: MySQL/MariaDB for real markets and SQLite for the
 * test fixture runner (test-only; see config db_scanner.allow_sqlite_fixtures).
 */
class QueryCompiler
{
    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,64}$/';

    /** @var array<string, array<string, string>> table => [column => type] */
    private array $schema = [];

    public function __construct(private readonly string $dialect)
    {
        if (! in_array($dialect, ['mysql', 'sqlite'], true)) {
            throw new ReaderException(ReaderException::UNSUPPORTED_DRIVER);
        }
    }

    public function dialect(): string
    {
        return $this->dialect;
    }

    /**
     * Bind the discovered base-table/column metadata. Data templates refuse any
     * identifier that is not in this map.
     *
     * @param  array<string, array<string, string>>  $schema
     */
    public function bindSchema(array $schema): void
    {
        $clean = [];
        foreach ($schema as $table => $columns) {
            if (! preg_match(self::IDENTIFIER, (string) $table)) {
                continue;
            }
            foreach ((array) $columns as $column => $type) {
                if (preg_match(self::IDENTIFIER, (string) $column)) {
                    $clean[$table][$column] = (string) $type;
                }
            }
        }
        $this->schema = $clean;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->schema[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->schema[$table][$column]);
    }

    // ------------------------------------------------------------------
    // Metadata templates (allowed before schema binding)
    // ------------------------------------------------------------------

    public function version(): CompiledQuery
    {
        return $this->make('version', $this->dialect === 'sqlite' ? 'SELECT sqlite_version() AS v' : 'SELECT VERSION() AS v');
    }

    public function currentUser(): CompiledQuery
    {
        return $this->make('current_user', $this->dialect === 'sqlite' ? "SELECT 'sqlite' AS u" : 'SELECT CURRENT_USER() AS u');
    }

    public function grants(): CompiledQuery
    {
        if ($this->dialect === 'sqlite') {
            return $this->make('grants', "SELECT 'GRANT SELECT ON fixture' AS g");
        }

        return $this->make('grants', 'SHOW GRANTS FOR CURRENT_USER()');
    }

    public function readOnlyState(string $variable): CompiledQuery
    {
        $variable = in_array($variable, ['transaction_read_only', 'tx_read_only'], true) ? $variable : 'transaction_read_only';
        if ($this->dialect === 'sqlite') {
            return $this->make('read_only_state', 'SELECT 1 AS ro');
        }

        return $this->make('read_only_state', 'SELECT @@session.'.$variable.' AS ro');
    }

    public function timeoutState(bool $mariadb): CompiledQuery
    {
        if ($this->dialect === 'sqlite') {
            return $this->make('timeout_state', 'SELECT 0 AS t');
        }

        return $this->make('timeout_state', $mariadb ? 'SELECT @@session.max_statement_time AS t' : 'SELECT @@session.max_execution_time AS t');
    }

    public function databaseName(): CompiledQuery
    {
        return $this->make('database_name', $this->dialect === 'sqlite' ? "SELECT 'main' AS db" : 'SELECT DATABASE() AS db');
    }

    public function baseTables(): CompiledQuery
    {
        if ($this->dialect === 'sqlite') {
            return $this->make('base_tables', "SELECT name, 'sqlite' AS engine, NULL AS row_estimate, NULL AS bytes, NULL AS collation FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!'");
        }

        return $this->make('base_tables', "SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_estimate, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_COLLATION AS collation
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    }

    /**
     * @param  array<int, string>  $tables
     */
    public function columns(array $tables): CompiledQuery
    {
        $tables = array_values(array_filter($tables, fn ($t) => preg_match(self::IDENTIFIER, (string) $t)));
        if ($tables === []) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }

        if ($this->dialect === 'sqlite') {
            $parts = [];
            foreach ($tables as $table) {
                $parts[] = "SELECT '".$table."' AS tbl, name AS col, type AS type, CASE WHEN pk > 0 THEN 'PRI' ELSE '' END AS ckey FROM pragma_table_info('".$table."')";
            }

            return $this->make('columns', implode(' UNION ALL ', $parts));
        }

        return $this->make(
            'columns',
            'SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, DATA_TYPE AS type, COLUMN_KEY AS ckey FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.$this->placeholders($tables).')',
            $tables
        );
    }

    public function primaryKeyTables(): CompiledQuery
    {
        if ($this->dialect === 'sqlite') {
            return $this->make('primary_key_tables', "SELECT m.name AS tbl FROM sqlite_master m WHERE m.type = 'table' AND EXISTS (SELECT 1 FROM pragma_table_info(m.name) p WHERE p.pk > 0)");
        }

        return $this->make('primary_key_tables', "SELECT TABLE_NAME AS tbl FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'PRIMARY KEY'");
    }

    public function triggers(): CompiledQuery
    {
        if ($this->dialect === 'sqlite') {
            return $this->make('triggers', "SELECT name, NULL AS timing, NULL AS event, tbl_name AS tbl, sql AS body FROM sqlite_master WHERE type = 'trigger'");
        }

        return $this->make('triggers', 'SELECT TRIGGER_NAME AS name, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS tbl, LEFT(ACTION_STATEMENT, 65536) AS body
            FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()');
    }

    public function events(): ?CompiledQuery
    {
        if ($this->dialect === 'sqlite') {
            return null;
        }

        return $this->make('events', 'SELECT EVENT_NAME AS name, STATUS AS status, LEFT(EVENT_DEFINITION, 4000) AS body FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()');
    }

    public function routines(): ?CompiledQuery
    {
        if ($this->dialect === 'sqlite') {
            return null;
        }

        return $this->make('routines', 'SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()');
    }

    // ------------------------------------------------------------------
    // Keyset traversal templates
    // ------------------------------------------------------------------

    public function maxKey(string $table, string $pk): CompiledQuery
    {
        $this->assertColumns($table, [$pk]);

        return $this->make('max_key', 'SELECT MAX('.$this->q($pk).') AS k FROM '.$this->q($table));
    }

    /**
     * The end of the next bounded key range. Index-only: no value columns.
     */
    public function rangeEnd(string $table, string $pk, int $after, int $highWater, int $limit): CompiledQuery
    {
        $this->assertColumns($table, [$pk]);
        $limit = max(1, min(1000, $limit));

        return $this->make(
            'range_end',
            'SELECT MAX(x.k) AS range_end, COUNT(*) AS n FROM (SELECT '.$this->q($pk).' AS k FROM '.$this->q($table)
            .' WHERE '.$this->q($pk).' > ? AND '.$this->q($pk).' <= ? ORDER BY '.$this->q($pk).' LIMIT '.$limit.') x',
            [$after, $highWater]
        );
    }

    /**
     * Keys, label columns and value byte lengths inside a range — never values.
     *
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $lengthColumns
     * @param  array<int, array>  $predicates
     */
    public function enumerate(string $table, string $pk, array $labels, array $lengthColumns, int $start, int $end, array $predicates): CompiledQuery
    {
        $this->assertColumns($table, array_merge([$pk], $labels, $lengthColumns));

        $select = [$this->q($pk).' AS __k'];
        foreach ($labels as $label) {
            $select[] = $this->q($label).' AS '.$this->q($label);
        }
        foreach ($lengthColumns as $column) {
            $select[] = $this->octetLength($this->q($column)).' AS '.$this->q('__len_'.$column);
        }

        [$where, $bindings] = $this->compilePredicates($table, $predicates);
        $sql = 'SELECT '.implode(', ', $select).' FROM '.$this->q($table).' WHERE '.$this->q($pk).' > ? AND '.$this->q($pk).' <= ?'
            .($where !== '' ? ' AND ('.$where.')' : '')
            .' ORDER BY '.$this->q($pk);

        return $this->make('enumerate', $sql, array_merge([$start, $end], $bindings));
    }

    /**
     * Byte-bounded value fetch for keys already enumerated.
     *
     * @param  array<int, string>  $valueColumns
     * @param  array<int, int>  $keys
     */
    public function fetchValues(string $table, string $pk, array $valueColumns, array $keys, int $maxBytes): CompiledQuery
    {
        $this->assertColumns($table, array_merge([$pk], $valueColumns));
        $keys = array_values(array_map('intval', $keys));
        if ($keys === [] || count($keys) > 1000) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }
        $maxBytes = max(1, min(1048576, $maxBytes));

        $select = [$this->q($pk).' AS __k'];
        foreach ($valueColumns as $column) {
            $select[] = $this->byteSlice($this->q($column), $maxBytes).' AS '.$this->q($column);
        }

        return $this->make(
            'fetch_values',
            'SELECT '.implode(', ', $select).' FROM '.$this->q($table).' WHERE '.$this->q($pk).' IN ('.$this->placeholders($keys).')',
            $keys
        );
    }

    /**
     * Named rows (options by name) with their byte length and a bounded value.
     *
     * @param  array<int, string>  $names
     */
    public function namedRows(string $table, string $pk, string $nameColumn, string $valueColumn, array $names, int $maxBytes, ?string $extraLabel = null): CompiledQuery
    {
        $columns = array_filter([$pk, $nameColumn, $valueColumn, $extraLabel]);
        $this->assertColumns($table, $columns);
        $names = array_values(array_unique(array_map('strval', $names)));
        if ($names === [] || count($names) > 200) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }
        $maxBytes = max(1, min(65536, $maxBytes));

        $select = [
            $this->q($pk).' AS __k',
            $this->q($nameColumn).' AS name',
            $this->octetLength($this->q($valueColumn)).' AS len',
            $this->byteSlice($this->q($valueColumn), $maxBytes).' AS v',
        ];
        if ($extraLabel) {
            $select[] = $this->q($extraLabel).' AS '.$this->q($extraLabel);
        }

        return $this->make(
            'named_rows',
            'SELECT '.implode(', ', $select).' FROM '.$this->q($table).' WHERE '.$this->q($nameColumn).' IN ('.$this->placeholders($names).')',
            $names
        );
    }

    // ------------------------------------------------------------------
    // Inventory templates
    // ------------------------------------------------------------------

    /**
     * One keyset page of capability/user-level rows after a umeta_id.
     * Callers page until exhausted: large markets hold more rows than any
     * single bounded read, and the newest (most interesting) accounts sort last.
     */
    public function capabilityRows(string $usermeta, int $maxBytes, int $afterId = 0, int $limit = 2000): CompiledQuery
    {
        $this->assertColumns($usermeta, ['umeta_id', 'user_id', 'meta_key', 'meta_value']);
        $limit = max(1, min(2000, $limit));

        return $this->make(
            'capability_rows',
            'SELECT umeta_id AS __k, user_id, meta_key, '.$this->octetLength('meta_value').' AS len, '.$this->byteSlice('meta_value', $maxBytes).' AS v
             FROM '.$this->q($usermeta)." WHERE umeta_id > ? AND (meta_key LIKE ? ESCAPE '!' OR meta_key LIKE ? ESCAPE '!') ORDER BY umeta_id LIMIT ".$limit,
            [$afterId, '%capabilities', '%user!_level']
        );
    }

    /**
     * Account counts per email domain — aggregate only, never an address.
     */
    public function emailDomainCounts(string $users): CompiledQuery
    {
        $this->assertColumns($users, ['user_email']);

        return $this->make(
            'email_domain_counts',
            $this->dialect === 'sqlite'
                ? "SELECT LOWER(SUBSTR(user_email, INSTR(user_email, '@') + 1)) AS d, COUNT(*) AS n, SUM(CASE WHEN user_email LIKE 'onboard+%@%' THEN 1 ELSE 0 END) AS onboard FROM ".$this->q($users)." WHERE user_email LIKE '%@%' GROUP BY d ORDER BY n DESC LIMIT 200"
                : "SELECT LOWER(SUBSTRING_INDEX(user_email, '@', -1)) AS d, COUNT(*) AS n, SUM(CASE WHEN user_email LIKE 'onboard+%@%' THEN 1 ELSE 0 END) AS onboard FROM ".$this->q($users)." WHERE user_email LIKE '%@%' GROUP BY d ORDER BY n DESC LIMIT 200"
        );
    }

    /**
     * Failed-login pressure from the Activity Log plugin table (aggregate).
     */
    public function failedLoginSummary(string $table, int $since): CompiledQuery
    {
        $this->assertColumns($table, ['action', 'hist_time', 'hist_ip', 'object_name']);

        return $this->make(
            'failed_login_summary',
            'SELECT object_name AS username, COUNT(*) AS n, COUNT(DISTINCT hist_ip) AS ips FROM '.$this->q($table)
            ." WHERE action = 'failed_login' AND hist_time >= ? GROUP BY object_name ORDER BY n DESC LIMIT 200",
            [$since]
        );
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function usersByIds(string $users, array $ids): CompiledQuery
    {
        $this->assertColumns($users, ['ID', 'user_login', 'user_email', 'user_registered', 'display_name']);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > 1000) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }

        // user_pass, user_activation_key and session data are never selected.
        return $this->make(
            'users_by_ids',
            'SELECT ID AS id, user_login, user_email, user_registered, display_name FROM '.$this->q($users).' WHERE ID IN ('.$this->placeholders($ids).')',
            $ids
        );
    }

    /** Hash format is computed inside MySQL; password values never leave the DB. */
    public function md5PasswordUsers(string $users): CompiledQuery
    {
        $this->assertColumns($users, ['ID', 'user_pass']);
        $format = $this->dialect === 'sqlite'
            ? "LENGTH(user_pass) = 32 AND user_pass NOT GLOB '*[^0-9a-fA-F]*'"
            : "user_pass REGEXP '^[0-9a-fA-F]{32}$'";

        return $this->make('md5_password_users', 'SELECT ID AS id FROM '.$this->q($users).' WHERE '.$format.' ORDER BY ID LIMIT 10000');
    }

    /** Bounded, dedicated metadata extraction; caller discards all password/hash fields. */
    public function applicationPasswordMetadata(string $usermeta, int $after): CompiledQuery
    {
        $this->assertColumns($usermeta, ['umeta_id', 'user_id', 'meta_key', 'meta_value']);

        return $this->make('application_password_metadata', 'SELECT umeta_id AS __k, user_id, '.$this->octetLength('meta_value').' AS len, '.$this->byteSlice('meta_value', 65536).' AS v FROM '.$this->q($usermeta)." WHERE meta_key = '_application_passwords' AND umeta_id > ? ORDER BY umeta_id LIMIT 50", [$after]);
    }

    /** Interesting web events; never request bodies or arbitrary log payloads. */
    public function activityEvents(string $table, int $after): CompiledQuery
    {
        $this->assertColumns($table, ['histid', 'hist_time', 'hist_ip', 'user_id', 'object_type', 'object_name', 'action', 'request_source']);

        return $this->make('activity_events', 'SELECT histid AS id, hist_time, hist_ip, user_id, object_type, '.$this->byteSlice('object_name', 200).' AS object_name, action, request_source FROM '.$this->q($table)." WHERE histid > ? AND ((object_type IN ('Plugins','Themes') AND action IN ('uploaded','installed','activated')) OR (object_type = 'Attachments' AND action = 'uploaded' AND object_name LIKE '%.zip') OR action = 'logged_in') ORDER BY histid LIMIT 500", [$after]);
    }

    /** Hourly aggregates include historic storms, so old compromise evidence survives. */
    public function activityBursts(string $table): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'action', 'object_name']);
        $hour = $this->dialect === 'sqlite' ? 'CAST(hist_time / 3600 AS INTEGER)' : 'FLOOR(hist_time / 3600)';

        return $this->make('activity_bursts', 'SELECT hist_ip AS ip, '.$hour.' AS hour_bucket, COUNT(*) AS n FROM '.$this->q($table)." WHERE action = 'failed_login' GROUP BY hist_ip, hour_bucket HAVING COUNT(*) >= 100 ORDER BY n DESC LIMIT 100");
    }

    public function activityFailuresBefore(string $table, string $username, int $timestamp): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'object_name', 'action', 'hist_ip']);

        return $this->make('activity_failures_before', 'SELECT COUNT(*) AS n FROM '.$this->q($table)." WHERE action = 'failed_login' AND LOWER(object_name) = ? AND hist_time >= ? AND hist_time <= ?", [strtolower($username), $timestamp - 86400, $timestamp]);
    }

    public function activityFailureContext(string $table, string $username, string $ip, int $timestamp): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'object_name', 'action', 'hist_ip']);

        return $this->make('activity_failure_context', 'SELECT COUNT(*) AS n, SUM(CASE WHEN hist_ip = ? THEN 1 ELSE 0 END) AS same_ip, MAX(hist_time) AS last_failure FROM '.$this->q($table)." WHERE action = 'failed_login' AND LOWER(object_name) = ? AND hist_time >= ? AND hist_time <= ?", [$ip, strtolower($username), $timestamp - 86400, $timestamp]);
    }

    /** Counts only: scam account names never cross the reader boundary. */
    public function registrationSpamSummary(string $users): CompiledQuery
    {
        $this->assertColumns($users, ['user_login']);
        $pattern = $this->dialect === 'sqlite'
            ? "(LOWER(user_login) LIKE 'www.%' OR LOWER(user_login) LIKE 'www-%' OR LOWER(user_login) LIKE '%coinbase%' OR LOWER(user_login) LIKE '%bitcoin%' OR LOWER(user_login) LIKE '%crypto%' OR LOWER(user_login) LIKE '%usd %' OR LOWER(user_login) LIKE '%-usd-%')"
            : "LOWER(user_login) REGEXP '^www[-.]|coinbase|usd[ -]?[0-9]|crypto|bitcoin'";

        return $this->make('registration_spam_summary', 'SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN '.$pattern.' THEN 1 ELSE 0 END), 0) AS suspicious FROM '.$this->q($users));
    }

    /** One day's pressure per IP, normalized with the site's offset. */
    public function activityDailyBursts(string $table, int $offset): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'action']);
        $day = $this->dialect === 'sqlite' ? 'CAST((hist_time - ?) / 86400 AS INTEGER)' : 'FLOOR((hist_time - ?) / 86400)';

        return $this->make('activity_daily_bursts', 'SELECT hist_ip AS ip, '.$day.' AS day_bucket, COUNT(*) AS n FROM '.$this->q($table)." WHERE action = 'failed_login' GROUP BY ip, day_bucket HAVING COUNT(*) >= 100 ORDER BY n DESC LIMIT 1001", [$offset]);
    }

    /** Successful accounts, not only privileged accounts; bounded hourly aggregate. */
    public function activityLoginWindows(string $table): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'action', 'user_id']);
        $hour = $this->dialect === 'sqlite' ? 'CAST(hist_time / 3600 AS INTEGER)' : 'FLOOR(hist_time / 3600)';

        return $this->make('activity_login_windows', 'SELECT user_id, '.$hour.' AS hour_bucket, COUNT(*) AS n, COUNT(DISTINCT hist_ip) AS ips, MIN(hist_time) AS first_time, MAX(hist_time) AS last_time FROM '.$this->q($table)." WHERE action = 'logged_in' AND user_id > 0 GROUP BY user_id, hour_bucket HAVING COUNT(*) >= 10 AND COUNT(DISTINCT hist_ip) >= 5 ORDER BY n DESC LIMIT 501");
    }

    public function activitySuccessIps(string $table): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'action', 'user_id']);

        return $this->make('activity_success_ips', 'SELECT user_id, hist_ip AS ip, COUNT(*) AS n, MIN(hist_time) AS first_time, MAX(hist_time) AS last_time FROM '.$this->q($table)." WHERE action = 'logged_in' AND user_id > 0 GROUP BY user_id, hist_ip ORDER BY n DESC LIMIT 2001");
    }

    /** A success and failures on different names from that exact IP within a minute. */
    public function activityControlIps(string $table): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'object_name', 'action', 'user_id', 'histid']);
        $t = $this->q($table);

        return $this->make('activity_control_ips', 'SELECT s.user_id, s.hist_ip AS ip, MIN(s.hist_time) AS first_time, COUNT(DISTINCT f.histid) AS failures, COUNT(DISTINCT f.object_name) AS targets FROM '.$t.' s JOIN '.$t." f ON f.hist_ip = s.hist_ip AND f.hist_time > s.hist_time AND f.hist_time <= s.hist_time + 60 AND f.action = 'failed_login' AND LOWER(f.object_name) <> LOWER(s.object_name) WHERE s.action = 'logged_in' AND s.user_id > 0 GROUP BY s.user_id, s.hist_ip HAVING COUNT(DISTINCT f.histid) >= 2 AND COUNT(DISTINCT f.object_name) >= 2 ORDER BY failures DESC LIMIT 501");
    }

    public function activityRegistrationBursts(string $table): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'action', 'object_type']);
        $t = $this->q($table);

        return $this->make('activity_registration_bursts', 'SELECT hist_ip AS ip, COUNT(*) AS n FROM '.$t." WHERE object_type = 'Users' AND action IN ('registered','created') AND hist_time >= (SELECT MAX(hist_time) FROM ".$t.') - 2592000 GROUP BY hist_ip HAVING COUNT(*) >= 20 ORDER BY n DESC LIMIT 501');
    }

    public function activityEmailHealth(string $table): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'object_type', 'object_name', 'action']);
        $t = $this->q($table);

        return $this->make('activity_email_health', "SELECT SUM(CASE WHEN action = 'failed' THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN action = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN action = 'failed' AND LOWER(object_name) LIKE '%could not authenticate%' THEN 1 ELSE 0 END) AS smtp_auth, MIN(hist_time) AS first_time, MAX(hist_time) AS last_time FROM ".$t." WHERE object_type = 'Emails' AND action IN ('failed','sent') AND hist_time >= (SELECT MAX(hist_time) FROM ".$t.') - 2592000');
    }

    /** Long-window rotation for keyed accounts, independent of the IOC pool. */
    public function keyedAccountLogins(string $table, array $ids): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'user_id', 'action']);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > 500) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }
        $t = $this->q($table);

        return $this->make('keyed_account_logins', 'SELECT user_id, COUNT(*) AS n, COUNT(DISTINCT hist_ip) AS ips, MIN(hist_time) AS first_time, MAX(hist_time) AS last_time FROM '.$t." WHERE action = 'logged_in' AND user_id IN (".$this->placeholders($ids).') AND hist_time >= (SELECT MAX(hist_time) FROM '.$t.') - 2592000 GROUP BY user_id HAVING COUNT(*) >= 10 AND COUNT(DISTINCT hist_ip) >= 5 ORDER BY user_id', $ids);
    }

    /** Only existing accounts with concentrated failures are identified. */
    public function targetedAccounts(string $table, string $users): CompiledQuery
    {
        $this->assertColumns($table, ['hist_time', 'hist_ip', 'user_id', 'action', 'object_name']);
        $this->assertColumns($users, ['ID', 'user_login', 'user_registered']);
        $t = $this->q($table);
        $epoch = $this->dialect === 'sqlite' ? "CAST(strftime('%s', u.user_registered) AS INTEGER)" : 'UNIX_TIMESTAMP(u.user_registered)';

        $login = $this->dialect === 'sqlite' ? 'LOWER(object_name)' : 'LOWER(CONVERT(object_name USING utf8mb4)) COLLATE utf8mb4_bin';
        $userLogin = $this->dialect === 'sqlite' ? 'LOWER(u.user_login)' : 'LOWER(CONVERT(u.user_login USING utf8mb4)) COLLATE utf8mb4_bin';
        // Aggregate before joining users; mixed import collations cannot break comparison.
        $failures = '(SELECT '.$login.' AS login, hist_ip AS ip, COUNT(*) AS n, MIN(hist_time) AS first_time, MAX(hist_time) AS last_time FROM '.$t." WHERE action = 'failed_login' AND hist_time >= (SELECT MAX(hist_time) FROM ".$t.') - 2592000 GROUP BY login, hist_ip HAVING COUNT(*) >= 100)';

        return $this->make('targeted_accounts', 'SELECT u.ID AS user_id, f.ip, f.n, f.first_time, f.last_time FROM '.$failures.' f JOIN '.$this->q($users).' u ON '.$userLogin.' = f.login WHERE '.$epoch.' < (SELECT MAX(hist_time) FROM '.$t.') - 2592000 AND NOT EXISTS (SELECT 1 FROM '.$t." s WHERE s.action = 'logged_in' AND s.user_id = u.ID AND s.hist_time >= (SELECT MAX(hist_time) FROM ".$t.') - 2592000) ORDER BY f.n DESC, u.ID, f.ip LIMIT 501');
    }

    /** Dedicated bounded alert extraction. No recipients, headers or attachments. */
    public function wordfenceLoginAlerts(string $table, int $after): CompiledQuery
    {
        $this->assertColumns($table, ['id', 'subject', 'message', 'sent_date']);
        $epoch = $this->dialect === 'sqlite' ? "CAST(strftime('%s', sent_date) AS INTEGER)" : 'UNIX_TIMESTAMP(sent_date)';

        return $this->make('wordfence_login_alerts', 'SELECT id, '.$epoch.' AS sent_utc, '.$this->octetLength('message').' AS len, '.$this->byteSlice('message', 16384).' AS alert FROM '.$this->q($table)." WHERE id > ? AND subject LIKE '[Wordfence Alert]%Admin Login%' ORDER BY id LIMIT 100", [$after]);
    }

    public function usersByLogins(string $users, array $logins): CompiledQuery
    {
        $this->assertColumns($users, ['ID', 'user_login']);
        if ($logins === [] || count($logins) > 500) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }

        return $this->make('users_by_logins', 'SELECT ID AS id, user_login FROM '.$this->q($users).' WHERE LOWER(user_login) IN ('.$this->placeholders($logins).')', array_map('strtolower', $logins));
    }

    public function lowerIdUsersRegisteredAfter(string $users, int $id, string $after): CompiledQuery
    {
        $this->assertColumns($users, ['ID', 'user_registered']);

        return $this->make('lower_id_users_after', 'SELECT COUNT(*) AS n FROM '.$this->q($users).' WHERE ID < ? AND user_registered > ?', [$id, $after]);
    }

    /**
     * Application-password metadata: ID, user and octet length only. The value
     * (and therefore any hash of it) is never selected.
     *
     * @param  array<int, int>  $userIds
     */
    public function applicationPasswordLengths(string $usermeta, array $userIds): CompiledQuery
    {
        $this->assertColumns($usermeta, ['umeta_id', 'user_id', 'meta_key', 'meta_value']);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === [] || count($userIds) > 1000) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }

        return $this->make(
            'application_password_lengths',
            'SELECT umeta_id AS __k, user_id, '.$this->octetLength('meta_value').' AS len FROM '.$this->q($usermeta)
            ." WHERE meta_key = '_application_passwords' AND user_id IN (".$this->placeholders($userIds).')',
            $userIds
        );
    }

    public function autoloadSummary(string $options): CompiledQuery
    {
        $this->assertColumns($options, ['option_value', 'autoload']);

        return $this->make(
            'autoload_summary',
            'SELECT COUNT(*) AS n, COALESCE(SUM('.$this->octetLength('option_value').'), 0) AS bytes FROM '.$this->q($options)
            .' WHERE autoload IN (?, ?, ?, ?)',
            ['yes', 'on', 'auto-on', 'auto']
        );
    }

    public function autoloadTop(string $options): CompiledQuery
    {
        $this->assertColumns($options, ['option_name', 'option_value', 'autoload']);

        return $this->make(
            'autoload_top',
            'SELECT option_name AS name, '.$this->octetLength('option_value').' AS len FROM '.$this->q($options)
            .' WHERE autoload IN (?, ?, ?, ?) ORDER BY '.$this->octetLength('option_value').' DESC LIMIT 10',
            ['yes', 'on', 'auto-on', 'auto']
        );
    }

    /**
     * @param  array<int, string>  $excludedTypes
     */
    public function dailyPostVolume(string $posts, string $since, array $excludedTypes): CompiledQuery
    {
        $this->assertColumns($posts, ['post_date', 'post_type', 'post_status']);

        return $this->make(
            'daily_post_volume',
            'SELECT DATE(post_date) AS d, post_type, COUNT(*) AS n FROM '.$this->q($posts)
            ." WHERE post_status = 'publish' AND post_date >= ? AND post_type NOT IN (".$this->placeholders($excludedTypes).')'
            .' GROUP BY DATE(post_date), post_type',
            array_merge([$since], $excludedTypes)
        );
    }

    /**
     * @param  array<int, string>  $excludedTypes
     */
    public function orphanAuthors(string $posts, string $users, array $excludedTypes): CompiledQuery
    {
        $this->assertColumns($posts, ['ID', 'post_author', 'post_type', 'post_status']);
        $this->assertColumns($users, ['ID']);

        return $this->make(
            'orphan_authors',
            'SELECT p.post_type AS post_type, COUNT(*) AS n FROM '.$this->q($posts).' p LEFT JOIN '.$this->q($users).' u ON u.ID = p.post_author'
            ." WHERE p.post_status = 'publish' AND p.post_type NOT IN (".$this->placeholders($excludedTypes).') AND u.ID IS NULL GROUP BY p.post_type',
            $excludedTypes
        );
    }

    public function expiredTransients(string $options, int $now): CompiledQuery
    {
        $this->assertColumns($options, ['option_name', 'option_value']);
        $cast = $this->dialect === 'sqlite' ? 'CAST(option_value AS INTEGER)' : 'CAST(option_value AS UNSIGNED)';

        return $this->make(
            'expired_transients',
            'SELECT COUNT(*) AS n FROM '.$this->q($options)." WHERE option_name LIKE ? ESCAPE '!' AND ".$cast.' < ?',
            ['!_transient!_timeout!_%', $now]
        );
    }

    public function revisionCounts(string $posts, int $minimum): CompiledQuery
    {
        $this->assertColumns($posts, ['post_parent', 'post_type']);

        return $this->make(
            'revision_counts',
            'SELECT post_parent AS parent, COUNT(*) AS n FROM '.$this->q($posts)." WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > ? ORDER BY n DESC LIMIT 10",
            [$minimum]
        );
    }

    public function slugAliases(string $postmeta, string $posts, string $profileType): CompiledQuery
    {
        $this->assertColumns($postmeta, ['post_id', 'meta_key', 'meta_value']);
        $this->assertColumns($posts, ['ID', 'post_type', 'post_status']);

        return $this->make(
            'slug_aliases',
            'SELECT COUNT(*) AS n FROM (SELECT pm.meta_value FROM '.$this->q($postmeta).' pm JOIN '.$this->q($posts).' p ON p.ID = pm.post_id'
            ." WHERE pm.meta_key = '_wp_old_slug' AND p.post_type = ? AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')"
            .' GROUP BY pm.meta_value HAVING COUNT(DISTINCT p.ID) > 1) shared',
            [$profileType]
        );
    }

    public function orphanPostmeta(string $postmeta, string $posts): CompiledQuery
    {
        $this->assertColumns($postmeta, ['post_id']);
        $this->assertColumns($posts, ['ID']);

        return $this->make(
            'orphan_postmeta',
            'SELECT COUNT(*) AS n FROM '.$this->q($postmeta).' pm LEFT JOIN '.$this->q($posts).' p ON p.ID = pm.post_id WHERE p.ID IS NULL'
        );
    }

    public function actionSchedulerSummary(string $table, string $cutoff): CompiledQuery
    {
        $this->assertColumns($table, ['status', 'scheduled_date_gmt']);

        return $this->make(
            'action_scheduler_summary',
            'SELECT status, COUNT(*) AS n, SUM(CASE WHEN scheduled_date_gmt < ? THEN 1 ELSE 0 END) AS old FROM '.$this->q($table).' GROUP BY status',
            [$cutoff]
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Predicate DSL: [column, op, value] with op in = != in not_in like not_like,
     * or ['or' => [...clauses]]. Columns must exist on the table.
     *
     * @return array{0: string, 1: array}
     */
    private function compilePredicates(string $table, array $predicates): array
    {
        $parts = [];
        $bindings = [];

        foreach ($predicates as $clause) {
            if (isset($clause['or']) && is_array($clause['or'])) {
                [$sql, $b] = $this->compileOr($table, $clause['or']);
                if ($sql !== '') {
                    $parts[] = '('.$sql.')';
                    $bindings = array_merge($bindings, $b);
                }

                continue;
            }

            [$sql, $b] = $this->compileClause($table, $clause);
            $parts[] = $sql;
            $bindings = array_merge($bindings, $b);
        }

        return [implode(' AND ', $parts), $bindings];
    }

    private function compileOr(string $table, array $clauses): array
    {
        $parts = [];
        $bindings = [];
        foreach ($clauses as $clause) {
            [$sql, $b] = $this->compileClause($table, $clause);
            $parts[] = $sql;
            $bindings = array_merge($bindings, $b);
        }

        return [implode(' OR ', $parts), $bindings];
    }

    private function compileClause(string $table, mixed $clause): array
    {
        if (! is_array($clause) || count($clause) !== 3) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }
        [$column, $op, $value] = $clause;
        $this->assertColumns($table, [(string) $column]);
        $col = $this->q((string) $column);

        return match ($op) {
            '=' => [$col.' = ?', [$value]],
            '!=' => [$col.' <> ?', [$value]],
            'in' => $this->listClause($col, 'IN', (array) $value),
            'not_in' => $this->listClause($col, 'NOT IN', (array) $value),
            'like' => [$col." LIKE ? ESCAPE '!'", [(string) $value]],
            'not_like' => [$col." NOT LIKE ? ESCAPE '!'", [(string) $value]],
            default => throw new ReaderException(ReaderException::REJECTED_TEMPLATE),
        };
    }

    private function listClause(string $column, string $op, array $values): array
    {
        $values = array_values($values);
        if ($values === [] || count($values) > 200) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }

        return [$column.' '.$op.' ('.$this->placeholders($values).')', $values];
    }

    private function assertColumns(string $table, array $columns): void
    {
        if (! preg_match(self::IDENTIFIER, $table) || ! isset($this->schema[$table])) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }
        foreach ($columns as $column) {
            if (! preg_match(self::IDENTIFIER, (string) $column) || ! isset($this->schema[$table][$column])) {
                throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
            }
        }
    }

    private function q(string $identifier): string
    {
        if (! preg_match(self::IDENTIFIER, $identifier) && ! preg_match('/^__len_[A-Za-z0-9_]{1,58}$/', $identifier)) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }

        return $this->dialect === 'sqlite' ? '"'.$identifier.'"' : '`'.$identifier.'`';
    }

    private function octetLength(string $expression): string
    {
        return $this->dialect === 'sqlite' ? 'LENGTH(CAST('.$expression.' AS BLOB))' : 'OCTET_LENGTH('.$expression.')';
    }

    /**
     * Binary byte slicing: character-count LEFT() would over-read multibyte
     * values past the byte budget.
     */
    private function byteSlice(string $expression, int $maxBytes): string
    {
        $maxBytes = max(1, min(1048576, $maxBytes));

        return $this->dialect === 'sqlite'
            ? 'substr(CAST('.$expression.' AS BLOB), 1, '.$maxBytes.')'
            : 'SUBSTRING(CAST('.$expression.' AS BINARY), 1, '.$maxBytes.')';
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function make(string $template, string $sql, array $bindings = []): CompiledQuery
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        if (str_contains($sql, ';') || ! preg_match('/^(SELECT|SHOW)\b/i', $sql)) {
            throw new ReaderException(ReaderException::REJECTED_TEMPLATE);
        }

        return CompiledQuery::compiled($this, $template, $sql, $bindings);
    }
}
