<?php

namespace Tests\Feature\DbContainment;

use App\Models\DbContainmentMarket;
use App\Models\Platform;
use App\Services\DbContainment\ContainmentException;
use App\Services\DbContainment\MarketDbWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class ContainmentMySqlTest extends TestCase
{
    use RefreshDatabase;

    private ?PDO $root = null;

    private string $schema = '';

    private string $limited = '';

    private string $dependentSchema = '';

    private MarketDbWriter $writer;

    private Platform $platform;

    private DbContainmentMarket $market;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('DB_CONTAINMENT_ENGINE_IT')) {
            $this->markTestSkipped('Disposable engine acceptance opt-in required.');
        }
        $host = getenv('DB_CONTAINMENT_IT_HOST') ?: ('localhost:'.getenv('DB_SCANNER_IT_SOCKET'));
        $dsn = getenv('DB_CONTAINMENT_IT_HOST') ? 'mysql:host=127.0.0.1;port='.getenv('DB_CONTAINMENT_IT_PORT') : 'mysql:unix_socket='.getenv('DB_SCANNER_IT_SOCKET');
        $this->root = new PDO($dsn, getenv('DB_SCANNER_IT_ROOT_USER') ?: 'root', getenv('DB_SCANNER_IT_ROOT_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'contain_it_'.strtolower(Str::random(8));
        $this->root->exec('CREATE DATABASE `'.$this->schema.'`');
        $this->root->exec('USE `'.$this->schema.'`');
        $this->root->exec('CREATE TABLE wp_options(option_id BIGINT PRIMARY KEY,option_name VARCHAR(191) UNIQUE,option_value LONGTEXT,autoload VARCHAR(20)) ENGINE=InnoDB');
        $this->root->exec('CREATE TABLE wp_users(ID BIGINT PRIMARY KEY,user_login VARCHAR(60),user_email VARCHAR(100),user_registered DATETIME,user_pass VARCHAR(255),user_activation_key VARCHAR(255)) ENGINE=InnoDB');
        $this->root->exec('CREATE TABLE wp_usermeta(umeta_id BIGINT PRIMARY KEY,user_id BIGINT,meta_key VARCHAR(191),meta_value LONGTEXT, KEY user_id(user_id)) ENGINE=InnoDB');
        $q = $this->root->prepare('INSERT INTO wp_options VALUES(?,?,?,?)');
        foreach ([[1, 'home', 'https://engine.test', 'yes'], [2, 'siteurl', 'https://engine.test', 'yes'], [3, 'wp_user_roles', serialize([]), 'yes']] as $row) {
            $q->execute($row);
        }
        $this->root->exec("INSERT INTO wp_users VALUES(42,'member','member@example.test','2020-01-01','original','reset')");
        $q = $this->root->prepare('INSERT INTO wp_usermeta VALUES(?,?,?,?)');
        $q->execute([10, 42, 'session_tokens', serialize(['synthetic' => ['expiration' => 2000000000]])]);
        $this->platform = Platform::query()->create(['name' => 'Synthetic', 'domain' => 'engine.test', 'country' => 'Synthetic', 'currency_code' => 'USD', 'is_active' => true, 'db_name' => $this->schema, 'db_prefix' => 'wp_', 'db_host' => $host, 'db_user' => getenv('DB_SCANNER_IT_ROOT_USER') ?: 'root', 'db_pass' => getenv('DB_SCANNER_IT_ROOT_PASSWORD') ?: '']);
        $this->market = DbContainmentMarket::query()->create(['platform_id' => $this->platform->id, 'configuration' => []]);
        $this->writer = new MarketDbWriter;
        $this->writer->connect($this->platform, $this->market);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            $this->writer->close();
        }if ($this->root && $this->schema) {
            if ($this->dependentSchema) {
                $this->root->exec('DROP DATABASE `'.$this->dependentSchema.'`');
            }
            $this->root->exec('DROP DATABASE `'.$this->schema.'`');
            if ($this->limited) {
                $this->root->exec("DROP USER IF EXISTS '{$this->limited}'@'%'");
            }
        } parent::tearDown();
    }

    private function selector(): array
    {
        return ['user_ids' => [42], 'option_names' => ['wp_user_roles']];
    }

    public function test_transaction_rolls_back_then_exact_bytes_restore_with_original_ids(): void
    {
        $before = $this->writer->begin($this->selector());
        $after = $before;
        $after['users'][0]['user_pass'] = 'changed';
        $after['users'][0]['user_activation_key'] = '';
        $after['usermeta'] = [];
        $this->writer->mutate($before, $after);
        $this->assertSame(MarketDbWriter::encode($after), MarketDbWriter::encode($this->writer->snapshot($this->selector(), true)));
        $this->writer->rollback();
        $this->assertSame(MarketDbWriter::encode($before), MarketDbWriter::encode($this->writer->snapshot($this->selector())));
        $this->writer->begin($this->selector());
        $this->writer->mutate($before, $after);
        $this->writer->commit();
        $this->writer->begin($this->selector());
        $this->writer->mutate($after, $before);
        $this->writer->commit();
        $this->assertSame(MarketDbWriter::encode($before), MarketDbWriter::encode($this->writer->snapshot($this->selector())));
    }

    public function test_trigger_and_cross_schema_dependency_refuse_before_write(): void
    {
        $this->root->exec('CREATE TRIGGER touched BEFORE UPDATE ON wp_users FOR EACH ROW SET NEW.user_pass="side-effect"');
        try {
            $this->writer->begin($this->selector());
            $this->fail('Trigger allowed');
        } catch (ContainmentException $e) {
            $this->assertSame('trigger_side_effects_unsupported', $e->reason);
        } finally {
            $this->writer->rollback();
        }
        $this->root->exec('DROP TRIGGER touched');
        $this->dependentSchema = $this->schema.'_side';
        $this->root->exec('CREATE DATABASE `'.$this->dependentSchema.'`');
        $this->root->exec('CREATE TABLE `'.$this->dependentSchema.'`.child(id BIGINT PRIMARY KEY,uid BIGINT, FOREIGN KEY(uid) REFERENCES `'.$this->schema.'`.wp_users(ID) ON DELETE CASCADE) ENGINE=InnoDB');
        $this->expectExceptionMessage('foreign_key_side_effects_unsupported');
        $this->writer->begin($this->selector());
    }

    public function test_metadata_lock_blocks_concurrent_trigger_ddl(): void
    {
        $this->writer->begin($this->selector());
        $this->root->exec('SET SESSION lock_wait_timeout=1');
        try {
            $this->root->exec('CREATE TRIGGER concurrent_touch BEFORE UPDATE ON wp_users FOR EACH ROW SET NEW.user_pass="unexpected"');
            $this->fail('DDL escaped held metadata lock');
        } catch (\PDOException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
        }$this->writer->rollback();
    }

    public function test_stale_optimistic_predicate_cannot_mutate_and_advisory_is_exclusive(): void
    {
        $before = $this->writer->begin($this->selector());
        $changed = $before;
        $changed['users'][0]['user_pass'] = 'target';
        $stale = $before;
        $stale['users'][0]['user_pass'] = 'wrong-old';
        try {
            $this->writer->mutate($stale, $changed);
            $this->fail('Stale old value accepted');
        } catch (ContainmentException $e) {
            $this->assertSame('optimistic_row_conflict', $e->reason);
        }
        $this->assertSame('0', (string) $this->root->query("SELECT GET_LOCK('db-containment:".substr(hash('sha256', $this->schema.'|wp_'), 0, 40)."',0)")->fetchColumn());
    }

    public function test_non_exhaustive_grants_and_reader_dml_refuse(): void
    {
        $this->limited = 'contain_ro_'.strtolower(Str::random(6));
        $this->root->exec("CREATE USER '{$this->limited}'@'%' IDENTIFIED BY 'fixture-only'");
        $this->root->exec("GRANT SELECT ON `{$this->schema}`.* TO '{$this->limited}'@'%'");
        $this->writer->close();
        $this->platform->update(['db_user' => $this->limited, 'db_pass' => 'fixture-only']);
        $this->writer->connect($this->platform, $this->market);
        try {
            $this->writer->select('UPDATE wp_users SET user_pass=? WHERE ID=?', ['never', 42]);
            $this->fail('Reader mutated');
        } catch (\PDOException $e) {
            $this->assertSame(1142, (int) $e->errorInfo[1]);
        }
        $this->expectExceptionMessage('exhaustive_catalog_visibility_required');
        $this->writer->begin($this->selector());
    }

    public function test_full_service_backup_verify_restore_on_real_engine(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['db_scanner.enabled' => false, 'db_containment.enabled' => true, 'db_containment.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $vault = sys_get_temp_dir().'/contain-engine-'.Str::uuid();
        config(['db_containment.vault' => $vault]);
        $this->market->update(['enabled' => true]);
        $cache = \Mockery::mock(\App\Services\DbContainment\CacheVerificationAdapter::class);
        $cache->shouldReceive('call')->andReturn(['verified' => true]);
        $this->app->instance(\App\Services\DbContainment\CacheVerificationAdapter::class, $cache);
        $actor = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $finding = \App\Models\DbScanFinding::query()->create(['platform_id' => $this->platform->id, 'fingerprint' => str_repeat('a', 64), 'subject_hash' => str_repeat('b', 64), 'rule_key' => 'access.activity_behaviour', 'rule_version_hash' => str_repeat('c', 64), 'pack' => 'core', 'pack_version' => '1', 'category' => 'access', 'severity' => 'critical', 'confidence' => 'strong', 'title' => 'Synthetic sessions', 'subject' => ['table' => 'users', 'object_id' => 42], 'evidence' => ['details' => ['user_id' => 42, 'login' => 'member']], 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(), 'occurrences' => 1]);
        $before = MarketDbWriter::encode($this->writer->snapshot($this->selector()));
        $this->writer->close();
        try {
            $service = app(\App\Services\DbContainment\ContainmentService::class);
            $op = $service->preview($actor, $finding, ['end_sessions'], (string) Str::uuid());
            $service->confirm($actor, $op, 'engine.test', null, $op->preview_digest);
            app(\App\Services\DbContainment\ContainmentExecutor::class)->execute($op->id);
            $this->assertSame('verified', $op->fresh()->status);
            $this->assertSame(0, (int) $this->root->query('SELECT COUNT(*) FROM wp_usermeta')->fetchColumn());
            $restore = $service->restorePreview($actor, $op->fresh(), (string) Str::uuid());
            $service->confirm($actor, $restore, 'engine.test', null, $restore->preview_digest);
            app(\App\Services\DbContainment\ContainmentExecutor::class)->execute($restore->id);
            $this->assertSame('verified', $restore->fresh()->status);
            $this->writer->connect($this->platform, $this->market);
            $this->assertSame($before, MarketDbWriter::encode($this->writer->snapshot($this->selector())));
        } finally {
            foreach (glob($vault.'/*') ?: [] as $file) {
                unlink($file);
            }if (is_dir($vault)) {
                rmdir($vault);
            }
        }
    }

    public function test_staff_logout_resolves_current_ids_and_requires_second_confirmation(): void
    {
        $q = $this->root->prepare('INSERT INTO wp_usermeta VALUES(?,?,?,?)');
        $q->execute([11, 42, 'wp_capabilities', serialize(['administrator' => true])]);
        $this->assertSame([42], $this->writer->staffIds());
        $this->writer->close();
        \Illuminate\Support\Facades\Queue::fake();
        config(['db_containment.enabled' => true, 'db_containment.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->market->update(['enabled' => true]);
        $cache = \Mockery::mock(\App\Services\DbContainment\CacheVerificationAdapter::class);
        $cache->shouldReceive('call')->andReturn(['verified' => true]);
        $this->app->instance(\App\Services\DbContainment\CacheVerificationAdapter::class, $cache);
        $actor = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $service = app(\App\Services\DbContainment\ContainmentService::class);
        $op = $service->staffPreview($actor, $this->platform, (string) Str::uuid());
        $this->assertSame('PRIVILEGED engine.test 1', $op->preview['privilege_confirmation']);
        $this->expectExceptionMessage('confirmation_does_not_match_preview');
        $service->confirm($actor, $op, 'engine.test', null, $op->preview_digest);
    }
}
