<?php

namespace Tests\Feature\Ewms;

use App\Services\Ewms\EwmsEvidenceConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;

class EwmsKpiMariaDbTest extends EwmsKpiTestCase
{
    public function test_non_utc_source_and_exact_money_use_an_independent_utc_evidence_connection(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires isolated MariaDB 11.4.13.');
        }
        if ($capture = getenv('EWMS_CAPTURE_CANONICAL_VIEW')) {
            $definition = DB::selectOne("SELECT VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ewms_kpi_payment_evidence'")->definition;
            $definition = str_replace('`'.$this->fixtureDatabase.'`.', '', $definition);
            file_put_contents($capture, hash('sha256', preg_replace('/\s+/', '', $definition)));
        }
        $this->payment(['amount' => '9999999999.99', 'completed_at' => '2026-11-14 00:00:00']);
        $this->payment(['amount' => '0.01', 'completed_at' => '2026-11-14 23:59:59.999999']);
        $this->payment(['amount' => '0.01', 'completed_at' => '2026-11-15 00:00:00']);
        DB::statement("SET SESSION time_zone = '+03:00'");
        $this->evidence($this->token(), ['platform_ids' => [1], 'from' => '2026-11-14', 'to' => '2026-11-14'], true)
            ->assertOk()->assertJsonPath('data.0.reportable_count', 2)->assertJsonPath('data.0.reportable_amount', '10000000000.00');
        $this->assertSame('+03:00', DB::selectOne('SELECT @@session.time_zone AS timezone')->timezone);
        $this->assertSame('+00:00', app(EwmsEvidenceConnection::class)->connection()->selectOne('SELECT @@session.time_zone AS timezone')->timezone);
    }

    #[Group('ewms-benchmark')]
    public function test_maximum_request_bounds_at_retained_production_scale(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires isolated MariaDB 11.4.13.');
        }
        for ($i = 4; $i <= 100; $i++) {
            DB::table('platforms')->insert(['id' => $i, 'currency_code' => 'KES']);
        }
        config(['ewms.allowed_platform_ids' => range(1, 100)]);
        $batch = [];
        for ($i = 0; $i < 45000; $i++) {
            $batch[] = [
                'platform_id' => ($i % 100) + 1, 'currency' => 'KES', 'amount' => '125.50',
                'status' => $i % 11 === 0 ? 'failed' : 'completed', 'purpose' => 'subscription',
                'record_classification' => $i % 19 === 0 ? 'test' : null,
                'created_at' => '2026-07-30 00:00:00',
                'completed_at' => \Carbon\CarbonImmutable::parse('2026-08-01', 'UTC')->addDays($i % 93)->format('Y-m-d').' 12:00:00',
            ];
            if (count($batch) === 500) {
                DB::table('payments')->insert($batch);
                $batch = [];
            }
        }
        $timings = [];
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$timings): void {
            $timings[] = ['connection' => $query->connectionName, 'ms' => $query->time,
                'operation' => str_contains($query->sql, 'candidate_count') ? 'summary' : (str_contains($query->sql, 'ewms_kpi_payment_evidence') ? 'evidence' : 'metadata')];
        });
        $token = $this->token();
        $input = ['platform_ids' => range(1, 100), 'from' => '2026-08-01', 'to' => '2026-11-01'];
        $start = hrtime(true);
        $summary = $this->evidence($token, $input, true)->assertOk()->assertJsonPath('meta.complete', true);
        $summaryMs = (hrtime(true) - $start) / 1e6;
        $start = hrtime(true);
        $this->evidence($token, $input + ['limit' => 500])->assertOk()->assertJsonCount(500, 'data')->assertJsonPath('meta.truncated', true);
        $recordsMs = (hrtime(true) - $start) / 1e6;
        $this->assertSame(\App\Models\Payment::query()->businessVisible()->excludingWalletTopups()->count(), array_sum(array_column($summary->json('data'), 'candidate_count')));
        $this->assertSame(\App\Models\Payment::query()->reportableSuccessful()->excludingWalletTopups()->count(), array_sum(array_column($summary->json('data'), 'reportable_count')));
        if ($output = getenv('EWMS_BENCHMARK_OUTPUT')) {
            file_put_contents($output, json_encode(['engine' => DB::selectOne('SELECT VERSION() AS version')->version, 'fixture_payments' => 45000, 'platforms' => 100, 'days' => 93, 'summary_rows' => count($summary->json('data')), 'summary_ms' => round($summaryMs, 2), 'records_500_ms' => round($recordsMs, 2), 'query_timings' => $timings], JSON_PRETTY_PRINT));
        }
        $this->assertLessThan(2000, $summaryMs, 'Maximum complete summary must meet the local fixture performance target.');
        $this->assertLessThan(2000, $recordsMs, 'Maximum recent evidence must meet the local fixture performance target.');
    }

    public function test_view_replacement_preserves_grants_definer_security_and_source_denial(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires isolated MariaDB 11.4.13.');
        }
        $user = 'ewms_view_'.bin2hex(random_bytes(6));
        $this->admin->exec("CREATE USER '$user'@'%' IDENTIFIED BY 'fixture-only'");
        try {
            $this->admin->exec("GRANT SELECT ON `{$this->fixtureDatabase}`.ewms_kpi_payment_evidence TO '$user'@'%'");
            $pdo = new PDO('mysql:host=127.0.0.1;port='.getenv('EWMS_TEST_MYSQL_PORT').';dbname='.$this->fixtureDatabase, $user, 'fixture-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->payment();
            $before = DB::selectOne("SELECT DEFINER,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ewms_kpi_payment_evidence'");
            $this->migration()->down();
            $this->migration()->up();
            $this->assertEquals($before, DB::selectOne("SELECT DEFINER,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ewms_kpi_payment_evidence'"));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ewms_kpi_payment_evidence')->fetchColumn());
            try {
                $pdo->query('SELECT * FROM payments');
                $this->fail('View-only fixture must not read the source table.');
            } catch (\PDOException $exception) {
                $this->assertSame('42000', $exception->getCode());
            }
        } finally {
            $this->admin->exec("DROP USER '$user'@'%'");
        }
    }
}
