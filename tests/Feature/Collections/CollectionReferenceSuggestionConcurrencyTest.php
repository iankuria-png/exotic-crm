<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Deal;
use App\Models\Payment;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Native row locks and two real PHP processes; never runs on Local or production databases. */
class CollectionReferenceSuggestionConcurrencyTest extends CollectionWorkflowTestCase
{
    protected $dropViews = true;

    public function refreshDatabase(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'reference_suggestions_test'
            || (string) config('database.connections.mysql.port') !== '21625'
            || config('database.connections.mysql.host') !== '127.0.0.1') {
            $this->markTestSkipped('Requires the disposable localhost MariaDB reference-suggestion database.');
        }
        parent::refreshDatabase();
    }

    public function beginDatabaseTransaction(): void {}

    public function test_two_manual_extensions_racing_for_one_code_dispatch_once(): void
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase(false);
        $account->update(['mode' => 'shadow']);
        $receipt = $this->received($device);
        CollectionPurchaseIntent::query()->delete();
        $deal = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => $client->platform_id,
            'product_id' => $price->product_id, 'product_price_id' => $price->id, 'amount' => 35000, 'currency' => 'TZS',
            'status' => 'active', 'plan_type' => 'vip', 'duration' => 'weekly', 'duration_days' => 7,
            'activated_at' => now()->subDays(2), 'expires_at' => now()->addDays(5)]);
        // Parent fixture runs at a fixed date; workers must use the same clock for binding/expiry semantics.
        $directory = sys_get_temp_dir().'/reference-race-'.Str::uuid();
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach (['a', 'b'] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/reference-extension-race-worker.php'),
                    (string) $deal->id, (string) $actor->id, $receipt->reference_norm, $directory, $worker], base_path(),
                    ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '21625',
                        'DB_DATABASE' => 'reference_suggestions_test', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_SOCKET' => '',
                        'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync']);
                $process->setTimeout(30)->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/a.ready') || ! is_file($directory.'/b.ready')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/a.ready');
            $this->assertFileExists($directory.'/b.ready');
            file_put_contents($directory.'/go', 'go');
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $decoded = json_decode($worker->getOutput(), true);
                $this->assertIsArray($decoded, 'Worker output: '.$worker->getOutput().' STDERR: '.$worker->getErrorOutput());
                $statuses[] = $decoded['status'];
            }
            sort($statuses);
            $this->assertSame([200, 422], $statuses);
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame(1, Payment::where('transaction_reference', $receipt->reference_norm)->count());
            $this->assertSame("extend\n", file_get_contents($directory.'/dispatches'));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
