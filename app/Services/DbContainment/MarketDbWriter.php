<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentMarket;
use App\Models\DbScanConnection;
use App\Models\Platform;
use PDO;

/** This class is never injected into MarketDbReader or its query compiler. */
class MarketDbWriter
{
    private PDO $pdo;

    private ?PDO $catalog = null;

    private string $prefix;

    private string $schema;

    private string $lock;

    private int $platformId;

    public array $identity;

    public function connect(Platform $platform, DbContainmentMarket $market): void
    {
        $this->platformId = (int) $platform->id;
        $this->prefix = (string) $platform->db_prefix;
        $this->schema = (string) $platform->db_name;
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $this->prefix) || ! preg_match('/^[A-Za-z0-9_]+$/D', $this->schema)) {
            throw new ContainmentException('unsafe_database_identity');
        }
        $cfg = $market->configuration ?? [];
        $host = (string) $platform->db_host;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
        $connection = DbScanConnection::query()->where('platform_id', $platform->id)->first();
        $hostname = preg_replace('/:\d+$/', '', $host);
        if (! in_array($hostname, ['localhost', '127.0.0.1', '::1'], true) && ! str_contains($host, ':/')) {
            if (! $connection?->tls_ca || $connection->tls_mode !== 'verify') {
                throw new ContainmentException('verified_remote_tls_required');
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $connection->tls_ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $dsn = $this->dsn($host);
        try {
            $this->pdo = new PDO($dsn, $platform->db_user, $platform->db_pass, $options);
            $this->pdo->exec("SET SESSION time_zone='+00:00'");
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
            $this->pdo->exec('SET SESSION lock_wait_timeout=5');
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            if (! preg_match('/^8\./', $version) && ! (str_contains(strtolower($version), 'mariadb') && preg_match('/^11\.4\./', $version))) {
                throw new ContainmentException('unaccepted_database_engine_version');
            }
            $this->pdo->exec(str_contains(strtolower($version), 'mariadb') ? 'SET SESSION max_statement_time=10' : 'SET SESSION max_execution_time=10000');
            if (! empty($cfg['catalog_user'])) {
                $this->catalog = new PDO($dsn, $cfg['catalog_user'], $cfg['catalog_password'] ?? '', $options);
            }
            $this->identity = $this->readIdentity($platform);
        } catch (ContainmentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ContainmentException('market_writer_connection_failed');
        }
        $this->lock = 'db-containment:'.substr(hash('sha256', $this->schema.'|'.$this->prefix), 0, 40);
    }

    private function dsn(string $host): string
    {
        if (preg_match('#^([^:]*):(/.+)$#', $host, $m)) {
            return 'mysql:unix_socket='.$m[2].';dbname='.$this->schema.';charset=utf8mb4';
        }
        if (preg_match('/^([^:]+):(\d+)$/D', $host, $m)) {
            return 'mysql:host='.$m[1].';port='.$m[2].';dbname='.$this->schema.';charset=utf8mb4';
        }
        if (! preg_match('/^[A-Za-z0-9.:-]+$/D', $host)) {
            throw new ContainmentException('unsafe_database_host');
        }

        return 'mysql:host='.$host.';dbname='.$this->schema.';charset=utf8mb4';
    }

    private function readIdentity(Platform $platform): array
    {
        $rows = $this->select('SELECT option_name, option_value FROM '.$this->table('options')." WHERE option_name IN ('siteurl','home') ORDER BY option_name");
        if (count($rows) !== 2) {
            throw new ContainmentException('ambiguous_site_identity');
        }
        $host = strtolower(preg_replace('/^www\./i', '', parse_url(str_contains($platform->domain, '://') ? $platform->domain : 'https://'.$platform->domain, PHP_URL_HOST) ?? ''));
        foreach ($rows as $row) {
            if (strtolower(preg_replace('/^www\./i', '', parse_url($row['option_value'], PHP_URL_HOST) ?? '')) !== $host || ! in_array(parse_url($row['option_value'], PHP_URL_PATH), ['', '/', null], true)) {
                throw new ContainmentException('site_identity_mismatch');
            }
        }
        $multisite = $this->select('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (?,?)', [$this->schema, $this->prefix.'blogs', $this->prefix.'sitemeta']);
        if ((int) $multisite[0]['n'] > 0) {
            throw new ContainmentException('multisite_unsupported');
        }

        return ['host' => $host, 'schema' => $this->schema, 'prefix' => $this->prefix, 'urls' => $rows];
    }

    public function staffIds(): array
    {
        $rows = $this->select('SELECT meta_value,user_id FROM '.$this->table('usermeta').' WHERE meta_key=? ORDER BY umeta_id LIMIT 5001', [$this->prefix.'capabilities']);
        if (count($rows) > 5000) {
            throw new ContainmentException('staff_inventory_limit');
        }
        $options = $this->select('SELECT option_value FROM '.$this->table('options').' WHERE option_name=?', [$this->prefix.'user_roles']);
        if (count($options) !== 1) {
            throw new ContainmentException('ambiguous_role_catalog');
        }
        $catalog = app(ActionCatalog::class);
        $roles = $catalog->parse($options[0]['option_value']);
        $ids = [];
        foreach ($rows as $row) {
            foreach ($catalog->parse($row['meta_value']) as $role => $granted) {
                if (! $granted) {
                    continue;
                }
                $caps = $roles[$role]['capabilities'] ?? [$role => true];
                foreach (['manage_options', 'edit_users', 'create_users', 'delete_users', 'edit_plugins', 'install_plugins', 'edit_themes', 'unfiltered_html', 'unfiltered_upload', 'administrator', 'level_10'] as $power) {
                    if (! empty($caps[$power])) {
                        $ids[] = (int) $row['user_id'];
                    }
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        if (! $ids || count($ids) > 100) {
            throw new ContainmentException('staff_set_empty_or_over_limit');
        }

        return $ids;
    }

    public function optionName(int $id): string
    {
        $rows = $this->select('SELECT option_name FROM '.$this->table('options').' WHERE option_id=?', [$id]);
        $name = $rows[0]['option_name'] ?? '';
        if (! in_array($name, ['widget_text', 'widget_custom_html', 'widget_block'], true)) {
            throw new ContainmentException('unsupported_widget_target');
        }

        return $name;
    }

    public function advisory(): void
    {
        if ((int) $this->select('SELECT GET_LOCK(?, 0) AS held', [$this->lock])[0]['held'] !== 1) {
            throw new ContainmentException('market_advisory_busy');
        }
    }

    public function begin(array $selector): array
    {
        $this->advisory();
        $this->pdo->beginTransaction();
        $this->sideEffects(array_values(array_filter(['users', 'usermeta', 'options'], fn ($table) => $table === 'options' ? ! empty($selector['option_names']) : ! empty($selector['user_ids']))));
        $state = $this->snapshot($selector, true);
        $currentIdentity = $this->readIdentity(\App\Models\Platform::query()->findOrFail($this->platformId));
        if ($currentIdentity !== $this->identity) {
            throw new ContainmentException('site_identity_changed_during_preview');
        }
        $this->sideEffects(array_keys($state));

        return $state;
    }

    private function sideEffects(array $tables): void
    {
        $catalog = $this->catalog ?? $this->pdo;
        $grants = $catalog->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        // A privilege-filtered empty metadata view is not exhaustive evidence.
        $globalSelect = $globalTrigger = false;
        foreach ($grants as $grant) {
            if (preg_match('/GRANT (.+) ON \*\.\* TO /i', $grant, $m)) {
                $privileges = array_map('trim', explode(',', strtoupper($m[1])));
                $globalSelect = $globalSelect || in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
                $globalTrigger = $globalTrigger || in_array('TRIGGER', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
            }
        }
        if (! $globalSelect || ! $globalTrigger) {
            throw new ContainmentException('exhaustive_catalog_visibility_required');
        }
        foreach ($tables as $suffix) {
            $name = $this->prefix.$suffix;
            $columns = $this->select('SHOW FULL COLUMNS FROM '.$this->table($suffix));
            $allowed = ['users' => ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name'], 'usermeta' => ['umeta_id', 'user_id', 'meta_key', 'meta_value'], 'options' => ['option_id', 'option_name', 'option_value', 'autoload']][$suffix];
            foreach ($columns as $column) {
                if (! in_array($column['Field'], $allowed, true) || ($column['Extra'] !== '' && $column['Extra'] !== 'auto_increment')) {
                    throw new ContainmentException('unsupported_column_side_effects');
                }
            }
            $pk = ['users' => 'ID', 'usermeta' => 'umeta_id', 'options' => 'option_id'][$suffix];
            $indexes = $this->select('SHOW INDEX FROM '.$this->table($suffix));
            $primary = array_values(array_filter($indexes, fn ($i) => $i['Key_name'] === 'PRIMARY'));
            if (count($primary) !== 1 || $primary[0]['Column_name'] !== $pk) {
                throw new ContainmentException('expected_primary_key_required');
            }
            $q = $catalog->prepare('SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
            $q->execute([$this->schema, $name]);
            $info = $q->fetchAll();
            if (count($info) !== 1 || strtoupper($info[0]['ENGINE'] ?? '') !== 'INNODB' || $info[0]['TABLE_TYPE'] !== 'BASE TABLE') {
                throw new ContainmentException('transactional_base_tables_required');
            }
            $q = $catalog->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=?');
            $q->execute([$this->schema, $name]);
            if ((int) $q->fetchColumn()) {
                throw new ContainmentException('trigger_side_effects_unsupported');
            }
            $q = $catalog->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_SCHEMA=? AND TABLE_NAME=?) OR (REFERENCED_TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME=?))');
            $q->execute([$this->schema, $name, $this->schema, $name]);
            if ((int) $q->fetchColumn()) {
                throw new ContainmentException('foreign_key_side_effects_unsupported');
            }
        }
    }

    public function snapshot(array $selector, bool $lock = false): array
    {
        $state = [];
        $suffix = $lock ? ' FOR UPDATE' : '';
        if (! empty($selector['user_ids'])) {
            $ids = array_values(array_unique(array_map('intval', $selector['user_ids'])));
            sort($ids);
            if (count($ids) > 100 || min($ids) < 1) {
                throw new ContainmentException('target_limit');
            }
            // Indexed user_id range includes every existing row and absent membership.
            $indexes = $this->select('SHOW INDEX FROM '.$this->table('usermeta'));
            if (! collect($indexes)->contains(fn ($i) => $i['Column_name'] === 'user_id' && (int) $i['Seq_in_index'] === 1)) {
                throw new ContainmentException('usermeta_range_index_required');
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $metaClause = '';
            $metaParams = [];
            if (array_key_exists('meta_keys', $selector)) {
                $metaParams = $selector['meta_keys'];
                foreach ($metaParams as $key) {
                    if (! in_array($key, ['session_tokens', '_application_passwords', $this->prefix.'user_level'], true)) {
                        throw new ContainmentException('unsupported_metadata_key');
                    }
                }
                $metaClause = $metaParams ? ' AND meta_key IN ('.implode(',', array_fill(0, count($metaParams), '?')).')' : ' AND 1=0';
            }
            $columns = $selector['user_columns'] ?? ['ID', 'user_login', 'user_email', 'user_registered', 'user_pass', 'user_activation_key'];
            if (array_diff($columns, ['ID', 'user_login', 'user_email', 'user_registered', 'user_pass', 'user_activation_key'])) {
                throw new ContainmentException('unsupported_user_columns');
            }
            $state['usermeta'] = $this->select('SELECT * FROM '.$this->table('usermeta').' WHERE user_id IN ('.$marks.')'.$metaClause.' ORDER BY umeta_id LIMIT 5001'.$suffix, [...$ids, ...$metaParams]);
            $state['users'] = $this->select('SELECT `'.implode('`,`', $columns).'` FROM '.$this->table('users').' WHERE ID IN ('.$marks.') ORDER BY ID'.$suffix, $ids);
            if (count($state['users']) !== count($ids) || count($state['usermeta']) > 5000) {
                throw new ContainmentException('missing_or_oversized_target_set');
            }
        }
        $names = $selector['option_names'] ?? [];
        if ($names) {
            foreach ($names as $name) {
                if (! in_array($name, ['siteurl', 'home', $this->prefix.'user_roles', 'sidebars_widgets', 'widget_text', 'widget_custom_html', 'widget_block', 'active_plugins'], true)) {
                    throw new ContainmentException('unsupported_option');
                }
            }
            $marks = implode(',', array_fill(0, count($names), '?'));
            $state['options'] = $this->select('SELECT * FROM '.$this->table('options').' WHERE option_name IN ('.$marks.') ORDER BY option_id'.$suffix, $names);
            foreach ($names as $name) {
                if (count(array_filter($state['options'], fn ($r) => $r['option_name'] === $name)) !== 1) {
                    throw new ContainmentException('missing_or_duplicate_option');
                }
            }
        }
        if (strlen(json_encode(self::encode($state))) > 4 * 1024 * 1024) {
            throw new ContainmentException('target_manifest_limit');
        }

        return $state;
    }

    public function mutate(array $before, array $after): void
    {
        foreach ($before as $suffix => $oldRows) {
            $pk = ['users' => 'ID', 'usermeta' => 'umeta_id', 'options' => 'option_id'][$suffix] ?? throw new ContainmentException('unsupported_table');
            $old = array_column($oldRows, null, $pk);
            $new = array_column($after[$suffix], null, $pk);
            foreach ($old as $id => $row) {
                if (! isset($new[$id])) {
                    if ($suffix !== 'usermeta') {
                        throw new ContainmentException('unsupported_delete');
                    }
                    if (! in_array($row['meta_key'], ['session_tokens', '_application_passwords', $this->prefix.'user_level'], true)) {
                        throw new ContainmentException('unsupported_metadata_delete');
                    }
                    [$predicate,$params] = $this->oldPredicate($row);
                    $this->execute('DELETE FROM '.$this->table($suffix).' WHERE '.$predicate, $params);
                } else {
                    $changes = array_filter($new[$id], fn ($v, $k) => $v !== $row[$k], ARRAY_FILTER_USE_BOTH);
                    if (! $changes) {
                        continue;
                    }
                    $allowed = ['users' => ['user_pass', 'user_activation_key'], 'usermeta' => ['meta_value'], 'options' => ['option_value']][$suffix];
                    if (array_diff(array_keys($changes), $allowed)) {
                        throw new ContainmentException('unsupported_column');
                    }
                    $sets = array_map(fn ($k) => '`'.$k.'`=?', array_keys($changes));
                    [$predicate,$params] = $this->oldPredicate($row);
                    $this->execute('UPDATE '.$this->table($suffix).' SET '.implode(',', $sets).' WHERE '.$predicate, [...array_values($changes), ...$params]);
                }
            }
            foreach (array_diff_key($new, $old) as $row) {
                if ($suffix !== 'usermeta') {
                    throw new ContainmentException('unsupported_insert');
                }
                $cols = ['umeta_id', 'user_id', 'meta_key', 'meta_value'];
                if (array_diff(array_keys($row), $cols)) {
                    throw new ContainmentException('unexpected_metadata_columns');
                }
                $this->execute('INSERT INTO '.$this->table($suffix).' (`'.implode('`,`', $cols).'`) VALUES (?,?,?,?)', array_map(fn ($k) => $row[$k], $cols));
            }
        }
    }

    private function oldPredicate(array $row): array
    {
        return [implode(' AND ', array_map(fn ($column) => 'BINARY `'.$column.'` <=> BINARY ?', array_keys($row))), array_values($row)];
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function close(): void
    {
        $this->rollback();
        if (isset($this->lock)) {
            try {
                $this->select('SELECT RELEASE_LOCK(?)', [$this->lock]);
            } catch (\Throwable) {
            }
        }
    }

    public function select(string $sql, array $params = []): array
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($params);

        return $q->fetchAll();
    }

    private function execute(string $sql, array $params): void
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($params);
        if ($q->rowCount() !== 1) {
            throw new ContainmentException('optimistic_row_conflict');
        }
    }

    private function table(string $suffix): string
    {
        if (! in_array($suffix, ['users', 'usermeta', 'options'], true)) {
            throw new ContainmentException('unsupported_table');
        }

        return '`'.$this->prefix.$suffix.'`';
    }

    public static function encode(array $state): array
    {
        ksort($state);
        foreach ($state as &$sortedRows) {
            foreach ($sortedRows as &$sortedRow) {
                ksort($sortedRow);
            }
        }
        unset($sortedRows,$sortedRow);
        foreach ($state as &$rows) {
            foreach ($rows as &$row) {
                foreach ($row as &$v) {
                    $v = $v === null ? null : base64_encode((string) $v);
                }
            }
        }

        return $state;
    }

    public static function decode(array $state): array
    {
        foreach ($state as &$rows) {
            foreach ($rows as &$row) {
                foreach ($row as &$v) {
                    $v = $v === null ? null : base64_decode($v, true);
                }
            }
        }

        return $state;
    }
}
