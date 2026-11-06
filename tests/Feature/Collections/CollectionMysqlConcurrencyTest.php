<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real InnoDB locks, intentionally outside RefreshDatabase's outer transaction. */
class CollectionMysqlConcurrencyTest extends TestCase
{
    private array $fixtureAccounts = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtureAccounts as $id) {
            $receipts = DB::table('collection_receipts')->where('account_id', $id)->pluck('id');
            DB::table('collection_exposure_reservations')->where('account_id', $id)->delete();
            DB::table('collection_allocations')->whereIn('receipt_id', $receipts)->delete();
            DB::table('collection_exposure_buckets')->where('account_id', $id)->delete();
            DB::table('collection_receipts')->where('account_id', $id)->delete();
            DB::table('collection_accounts')->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'collection_automation_test'
            || config('database.connections.mysql.unix_socket') !== '/tmp/exotic-collection-mysql-20261030/mysql.sock') {
            $this->markTestSkipped('Requires the disposable collection MySQL database and its isolated socket.');
        }
    }

    public function test_concurrent_receipts_cannot_exceed_aggregate_daily_and_outstanding_budget(): void
    {
        $account = $this->account();
        $first = $this->receipt($account);
        $second = $this->receipt($account);
        $statuses = $this->race([$first->id, $second->id]);
        sort($statuses);
        $this->assertSame(['automatic_exposure_limit', 'reserved'], $statuses);
        $this->assertSame(1, DB::table('collection_exposure_reservations')->where('account_id', $account->id)->count());
        $this->assertSame(3500000, (int) DB::table('collection_exposure_buckets')->where('account_id', $account->id)->where('bucket', 'outstanding')->value('unreviewed_minor'));
        $this->assertSame(3500000, (int) DB::table('collection_exposure_buckets')->where('account_id', $account->id)->where('bucket', '!=', 'outstanding')->value('daily_minor'));
    }

    public function test_two_workers_claiming_one_receipt_reserve_principal_once(): void
    {
        $account = $this->account();
        $receipt = $this->receipt($account);
        $statuses = $this->race([$receipt->id, $receipt->id]);
        sort($statuses);
        $this->assertSame(['duplicate', 'reserved'], $statuses);
        $this->assertSame(1, DB::table('collection_allocations')->where('receipt_id', $receipt->id)->count());
        $this->assertSame(1, DB::table('collection_exposure_reservations')->where('receipt_id', $receipt->id)->count());
    }

    private function account(): CollectionAccount
    {
        $account = CollectionAccount::create(['id' => (string) Str::uuid(), 'label' => 'Disposable concurrency fixture', 'country' => 'TZ', 'provider_key' => 'vodacom_mpesa_tz',
            'account_kind' => 'merchant', 'account_identifier' => 'fixture', 'account_fingerprint' => hash('sha256', Str::uuid()),
            'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'ownership' => 'company', 'mode' => 'shadow',
            'policy' => ['version' => 1, 'single_cap_minor' => 4000000, 'daily_cap_minor' => 6000000, 'unreviewed_cap_minor' => 6000000, 'unverified_cap_minor' => 6000000]]);
        $this->fixtureAccounts[] = $account->id;

        return $account;
    }

    private function receipt(CollectionAccount $a): CollectionReceipt
    {
        return CollectionReceipt::create(['account_id' => $a->id, 'reference_raw' => (string) Str::uuid(), 'reference_norm' => (string) Str::uuid(),
            'principal_minor' => 3500000, 'currency' => 'TZS', 'enrichment_deadline' => now(), 'historical_only' => true, 'evidence_level' => 'phone']);
    }

    private function race(array $receipts): array
    {
        $dir = sys_get_temp_dir().'/collection-race-'.Str::uuid();
        mkdir($dir, 0700);
        $gate = $dir.'/go';
        $processes = [];
        foreach ($receipts as $n => $receipt) {
            $process = new Process(['/usr/local/opt/php@8.2/bin/php', base_path('tests/Support/collection-exposure-worker.php'), (string) $receipt, $gate, $dir.'/ready-'.$n], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'collection_automation_test', 'DB_HOST' => 'localhost',
                'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'DB_SOCKET' => '/tmp/exotic-collection-mysql-20261030/mysql.sock',
                'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ]);
            $process->setTimeout(20);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10;
        while (count(glob($dir.'/ready-*')) < count($receipts) && microtime(true) < $deadline) {
            usleep(5000);
        }
        touch($gate);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $output = json_decode($process->getOutput(), true);
            $this->assertIsArray($output, $process->getOutput());
            $results[] = $output['status'];
        }
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);

        return $results;
    }
}
