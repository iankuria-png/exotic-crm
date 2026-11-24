<?php

namespace Tests\Feature\Ewms;

use App\Models\User;
use App\Services\Ewms\EwmsKpiAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\TestCase;

abstract class EwmsKpiTestCase extends TestCase
{
    protected ?string $sqlitePath = null;

    protected ?PDO $admin = null;

    protected ?string $fixtureDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (($port = getenv('EWMS_TEST_MYSQL_PORT')) !== false) {
            $this->assertMatchesRegularExpression('/^[0-9]{4,5}$/D', $port);
            $this->admin = new PDO('mysql:host=127.0.0.1;port='.$port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $version = $this->admin->query('SELECT VERSION()')->fetchColumn();
            $this->assertStringStartsWith('11.4.13-MariaDB', $version);
            $this->fixtureDatabase = 'ewms_kpi_test_'.bin2hex(random_bytes(6));
            $this->admin->exec('CREATE DATABASE `'.$this->fixtureDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $connection = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $port, 'database' => $this->fixtureDatabase, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00'];
        } else {
            $this->sqlitePath = tempnam(sys_get_temp_dir(), 'ewms-kpi-');
            $connection = ['driver' => 'sqlite', 'database' => $this->sqlitePath, 'prefix' => '', 'foreign_key_constraints' => true];
        }
        config(['database.default' => 'ewms_fixture', 'database.connections.ewms_fixture' => $connection, 'ewms.enabled' => true, 'ewms.allowed_platform_ids' => [1, 2]]);
        DB::purge('ewms_fixture');
        DB::purge('ewms_evidence');
        $this->schema();
        $this->originalMigration()->up();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        DB::purge('ewms_evidence');
        DB::purge('ewms_fixture');
        if ($this->admin && $this->fixtureDatabase) {
            $this->admin->exec('DROP DATABASE `'.$this->fixtureDatabase.'`');
        }
        if ($this->sqlitePath && is_file($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }
        parent::tearDown();
    }

    protected function originalMigration(): object
    {
        return require database_path('migrations/2026_11_20_100000_create_ewms_kpi_payment_evidence_view.php');
    }

    protected function migration(): object
    {
        return require database_path('migrations/2026_11_24_140000_update_ewms_kpi_payment_evidence_contract.php');
    }

    protected function payment(array $attributes = []): int
    {
        return DB::table('payments')->insertGetId(array_replace([
            'platform_id' => 1, 'amount' => '125.50', 'status' => 'completed',
            'created_at' => '2026-11-13 09:00:00', 'completed_at' => '2026-11-14 10:00:00',
            'currency' => 'KES', 'purpose' => 'subscription', 'payment_data' => '{}',
            'transaction_reference' => 'EWMS-SYNTHETIC-REFERENCE',
        ], $attributes));
    }

    protected function token(array $abilities = [EwmsKpiAccess::ABILITY], string $role = EwmsKpiAccess::ROLE, string $status = 'active', bool $expired = false): string
    {
        $user = User::create(['name' => 'Synthetic integration', 'email' => bin2hex(random_bytes(6)).'@example.test', 'password' => bcrypt('fixture-only'), 'role' => $role, 'status' => $status]);

        return $user->createToken('fixture', $abilities, $expired ? now()->subMinute() : now()->addHour())->plainTextToken;
    }

    protected function evidence(?string $token, array $input = [], bool $summary = false): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/'.($summary ? EwmsKpiAccess::SUMMARY_PATH : EwmsKpiAccess::RECORDS_PATH), $input ?: ['platform_ids' => [1], 'from' => '2026-11-14', 'to' => '2026-11-21'], $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
    }

    private function schema(): void
    {
        Schema::create('platforms', function (Blueprint $t) {
            $t->id();
            $t->string('currency_code')->nullable();
        });
        DB::table('platforms')->insert([['id' => 1, 'currency_code' => 'KES'], ['id' => 2, 'currency_code' => 'NGN'], ['id' => 3, 'currency_code' => null]]);
        Schema::create('manual_payment_bundles', function (Blueprint $t) {
            $t->id();
            $t->string('status');
        });
        Schema::create('collection_sales_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bundle_id')->index();
        });
        Schema::create('collection_sales_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('payment_id')->index();
            $t->string('state');
            $t->timestamp('verified_at')->nullable();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('platform_id')->nullable();
            $t->timestamp('created_at', 6)->nullable();
            $t->timestamp('completed_at', 6)->nullable();
            $t->decimal('amount', 12, 2);
            foreach (['currency', 'transaction_reference', 'status', 'reconciliation_state', 'resolution_code', 'record_classification', 'provider_environment', 'purpose', 'phone'] as $c) {
                $t->string($c)->nullable();
            }
            $t->unsignedBigInteger('manual_payment_bundle_id')->nullable();
            $t->text('payment_data')->nullable();
            $t->index(['platform_id', 'completed_at']);
            $t->index(['platform_id', 'status', 'created_at']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role');
            $t->string('status')->nullable();
            $t->boolean('is_ceo')->default(false);
            $t->json('assigned_market_ids')->nullable();
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('platform_id')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action');
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id');
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->text('reason')->nullable();
            $t->string('ip_address')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }
}
