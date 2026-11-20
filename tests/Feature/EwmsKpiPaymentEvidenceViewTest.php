<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EwmsKpiPaymentEvidenceViewTest extends TestCase
{
    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        // Never migrate or truncate the application's configured database.
        $database = getenv('EWMS_TEST_MYSQL_DATABASE');
        if ($database !== false) {
            $this->assertMatchesRegularExpression('/^ewms_kpi_test_[a-z0-9_]+$/', $database);
            $connection = array_replace(config('database.connections.mysql'), ['database' => $database, 'url' => null]);
        } else {
            $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }
        config(['database.default' => 'ewms_test', 'database.connections.ewms_test' => $connection]);
        DB::purge('ewms_test');

        Schema::create('manual_payment_bundles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('status');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('platform_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->decimal('amount', 12, 2);
            foreach (['currency', 'transaction_reference', 'status', 'reconciliation_state', 'resolution_code', 'record_classification', 'provider_environment', 'purpose'] as $column) {
                $table->string($column)->nullable();
            }
            $table->unsignedBigInteger('manual_payment_bundle_id')->nullable();
            $table->string('phone')->nullable();
            $table->text('payment_data')->nullable();
        });

        $this->migration = require database_path('migrations/2026_11_20_100000_create_ewms_kpi_payment_evidence_view.php');
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->migration)) {
            $this->migration->down();
            Schema::dropIfExists('payments');
            Schema::dropIfExists('manual_payment_bundles');
            DB::purge('ewms_test');
        }
        parent::tearDown();
    }

    public function test_exact_columns_ownership_currency_and_live_updates(): void
    {
        $id = $this->payment(['user_id' => 73, 'platform_id' => 5, 'currency' => 'kes', 'phone' => 'PRIVATE', 'payment_data' => '{"private":"secret"}']);
        $row = (array) DB::table('ewms_kpi_payment_evidence')->first();
        $this->assertSame(['payment_id', 'crm_user_id', 'platform_id', 'recorded_at', 'amount', 'currency', 'transaction_reference', 'status', 'reconciliation_state', 'resolution_code', 'is_reportable_success'], array_keys($row));
        $this->assertEquals(73, $row['crm_user_id']);
        $this->assertEquals(5, $row['platform_id']);
        $this->assertSame('2026-11-18 08:50:00', $row['recorded_at']);
        $this->assertSame('KES', $row['currency']);
        $this->assertSame('EWMS-SYNTHETIC-REFERENCE', $row['transaction_reference']);
        $this->assertEquals(125.50, $row['amount']);

        DB::table('payments')->where('id', $id)->update(['amount' => 200, 'currency' => null, 'user_id' => null]);
        $updated = DB::table('ewms_kpi_payment_evidence')->first();
        $this->assertEquals(200, $updated->amount);
        $this->assertSame('USD', $updated->currency);
        $this->assertNull($updated->crm_user_id);
        DB::table('payments')->where('id', $id)->update(['record_classification' => 'test']);
        $this->assertSame(0, DB::table('ewms_kpi_payment_evidence')->count());
    }

    public function test_exclusions_are_null_safe_and_do_not_filter_candidate_statuses(): void
    {
        $included = [$this->payment(), $this->payment(['provider_environment' => 'live', 'record_classification' => 'business', 'purpose' => 'subscription'])];
        foreach (['pending', 'failed', 'cancelled', 'expired', 'completed', null] as $status) {
            $included[] = $this->payment(['status' => $status]);
        }
        foreach ([['record_classification' => 'test'], ['provider_environment' => 'sandbox'], ['provider_environment' => 'SaNdBoX'], ['purpose' => 'wallet_topup']] as $attributes) {
            $this->payment($attributes);
        }
        foreach (['committing', 'compensation_failed', 'voided', 'committed'] as $index => $status) {
            $bundle = $index + 1;
            DB::table('manual_payment_bundles')->insert(['id' => $bundle, 'status' => $status]);
            $id = $this->payment(['manual_payment_bundle_id' => $bundle]);
            if ($status === 'committed') {
                $included[] = $id;
            }
        }
        // Matches the requested NOT EXISTS rule, even for a legacy dangling bundle.
        $included[] = $this->payment(['manual_payment_bundle_id' => 999]);
        $this->assertEquals($included, DB::table('ewms_kpi_payment_evidence')->orderBy('payment_id')->pluck('payment_id')->all());
    }

    public function test_success_flag_covers_status_review_and_resolution_combinations(): void
    {
        $expected = [];
        foreach (['completed', 'expired', 'pending', 'failed', 'cancelled', null] as $status) {
            foreach ([null, 'manual_review', 'reconciled'] as $review) {
                foreach ([null, 'reversed', 'invalid_reference', 'approved'] as $resolution) {
                    $id = $this->payment(['status' => $status, 'reconciliation_state' => $review, 'resolution_code' => $resolution]);
                    $expected[$id] = (int) (in_array($status, ['completed', 'expired'], true) && $review !== 'manual_review' && ! in_array($resolution, ['reversed', 'invalid_reference'], true));
                }
            }
        }
        $this->assertEquals($expected, DB::table('ewms_kpi_payment_evidence')->pluck('is_reportable_success', 'payment_id')->all());
    }

    public function test_rollback_and_reapply_preserve_source_records(): void
    {
        $this->payment();
        $before = DB::table('payments')->get()->toJson();
        $this->migration->down();
        $this->migration->down();
        $this->assertEquals($before, DB::table('payments')->get()->toJson());
        $this->migration->up();
        $this->assertSame(1, DB::table('ewms_kpi_payment_evidence')->count());
    }

    public function test_existing_view_is_preserved_when_creation_is_repeated(): void
    {
        $this->payment();
        try {
            $this->migration->up();
            $this->fail('Creating an existing view must fail without dropping it.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('ewms_kpi_payment_evidence', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('ewms_kpi_payment_evidence')->count());
    }

    private function payment(array $attributes = []): int
    {
        return DB::table('payments')->insertGetId(array_replace([
            'amount' => 125.50,
            'status' => 'completed',
            'created_at' => '2026-11-18 08:50:00',
            'transaction_reference' => 'EWMS-SYNTHETIC-REFERENCE',
        ], $attributes));
    }
}
