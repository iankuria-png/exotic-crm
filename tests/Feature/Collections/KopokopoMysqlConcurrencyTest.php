<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionProviderResource;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Disposable MySQL only: external processes must see committed setup, with genuine InnoDB account locks. */
class KopokopoMysqlConcurrencyTest extends KopokopoCollectionTestCase
{
    protected $dropViews = true;

    /** Guard BEFORE RefreshDatabase can migrate, never after it has touched a connection. */
    public function refreshDatabase(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'collection_kopokopo_release_test'
            || config('database.connections.mysql.unix_socket') !== '/tmp/exotic-kopo-mysql-20261123/mysql.sock') {
            $this->markTestSkipped('Requires explicitly isolated KopoKopo MySQL release database.');
        }
        parent::refreshDatabase();
    }

    public function beginDatabaseTransaction(): void {}

    public function test_parallel_delivery_retry_and_reversal_create_one_receipt_and_keep_reversal_sticky(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'collection_kopokopo_release_test'
            || config('database.connections.mysql.unix_socket') !== '/tmp/exotic-kopo-mysql-20261123/mysql.sock') {
            $this->markTestSkipped('Requires explicitly isolated KopoKopo MySQL release database.');
        }
        [$feed] = $this->kenya();
        $dir = sys_get_temp_dir().'/kopo-race-'.Str::uuid();
        mkdir($dir, 0700);
        $credit = $this->providerBody();
        $reversal = $this->providerBody([], 'parallel-reversal', 'buygoods_transaction_reversed');
        $processes = [];
        foreach ([$credit, $credit, $reversal, $credit] as $n => $body) {
            file_put_contents($dir.'/body-'.$n, $body);
            $p = new Process(['/usr/local/opt/php@8.2/bin/php', base_path('tests/Support/kopokopo-race-worker.php'), $feed->id, $dir.'/body-'.$n, $dir.'/go', $dir.'/ready-'.$n], base_path());
            $p->setTimeout(30);
            $p->start();
            $processes[] = $p;
        }
        $deadline = microtime(true) + 15;
        do {
            $ready = count(glob($dir.'/ready-*')) === 4;
            if (! $ready) {
                usleep(10000);
            }
        } while (! $ready && microtime(true) < $deadline);
        touch($dir.'/go');
        foreach ($processes as $p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
            $this->assertJson($p->getOutput(), $p->getErrorOutput().' RAW: '.$p->getOutput());
            $this->assertContains(json_decode($p->getOutput(), true)['status'], ['accepted', 'duplicate']);
        }
        $this->assertTrue($ready, 'Workers did not all reach the gate');
        $this->assertSame(1, CollectionReceipt::count());
        $this->assertSame(1, CollectionProviderResource::count());
        $this->assertSame('reversed', CollectionReceipt::firstOrFail()->funds_state);
        $this->assertDatabaseCount('payments', 0);
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
