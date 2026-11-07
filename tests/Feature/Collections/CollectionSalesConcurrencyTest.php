<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionSalesItem;
use App\Services\Collections\CollectionSalesFulfillmentService;
use App\Services\Collections\CollectionSalesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Cross-process claims against the dedicated disposable InnoDB database, never a fixture transaction. */
class CollectionSalesConcurrencyTest extends CollectionWorkflowTestCase
{
    private array $fixture = [];

    private string $deviceExternal = 'fixture-phone';

    private array $processes = [];

    private ?string $directory = null;

    public function refreshDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'collection_automation_test'
            || config('database.connections.mysql.unix_socket') !== '/tmp/exotic-collection-mysql-20261030/mysql.sock') {
            $this->markTestSkipped('Requires the dedicated disposable collection MySQL socket.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function ($t) {
                $t->string('key')->primary();
                $t->string('owner');
                $t->integer('expiration');
            });
        }
        config(['client_lifecycle.lock_store' => 'database', 'cache.stores.database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks', 'connection' => null]]);
    }

    protected function inbox(): array
    {
        [$account, $device] = parent::inbox();
        $account->update(['account_fingerprint' => hash('sha256', (string) Str::uuid())]);
        $this->deviceExternal = 'sales-race-'.Str::uuid();
        $device->update(['external_device_id' => $this->deviceExternal, 'key_id' => 'sales-key-'.Str::uuid()]);

        return [$account, $device];
    }

    protected function envelope(string $message, string $id = 'fixture-event', array $extra = []): string
    {
        $input = json_decode(parent::envelope($message, $id, $extra), true);
        $input['deviceId'] = $this->deviceExternal;

        return json_encode($input, JSON_THROW_ON_ERROR);
    }

    private function fixture(bool $shared = false): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase(false, random_int(300000, 900000));
        $account->update(['mode' => 'shadow']);
        $receipt = $this->received($device, 'RACE'.strtoupper(Str::random(7)));
        if ($shared) {
            $receipt->update(['principal_minor' => 7000000]);
        }
        $input = ['receipt_version' => $receipt->version, 'session_version' => 0, 'request_key' => (string) Str::uuid(),
            'items' => [['client_id' => $client->id, 'product_price_id' => $price->id]]];
        if ($shared) {
            $second = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 78, 'wp_user_id' => 56, 'is_high_risk' => false]);
            $input['items'][] = ['client_id' => $second->id, 'product_price_id' => $price->id];
            $this->remote[78] = $this->snapshot(78, 56);
        }
        config(['collection_sales.verified_accounts' => [$account->id], 'collection_sales.verified_platforms' => [$client->platform_id]]);
        $preview = app(CollectionSalesService::class)->preview($receipt, $actor, $input);
        $input['preview_token'] = $preview['preview_token'];
        $this->directory = sys_get_temp_dir().'/collection-sales-race-'.Str::uuid();
        mkdir($this->directory, 0700);
        $this->fixture = ['account' => $account->id, 'platform' => $client->platform_id, 'actor' => $actor->id, 'receipt' => $receipt->id,
            'input' => $input, 'snapshots' => $this->remote, 'directory' => $this->directory, 'action' => 'prepare', 'now' => now()->toIso8601String()];

        return [$receipt, $actor, $input];
    }

    private function worker(array $patch = []): Process
    {
        $id = count($this->processes);
        $path = $this->directory.'/input-'.$id;
        file_put_contents($path, json_encode(array_replace($this->fixture, $patch), JSON_THROW_ON_ERROR));
        $process = new Process(['/usr/local/opt/php@8.2/bin/php', base_path('tests/Support/collection-sales-worker.php'), $path,
            $this->directory.'/go', $this->directory.'/ready-'.$id], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'collection_automation_test', 'DB_HOST' => 'localhost', 'DB_USERNAME' => 'root',
                'DB_PASSWORD' => '', 'DB_URL' => '', 'DB_SOCKET' => '/tmp/exotic-collection-mysql-20261030/mysql.sock', 'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        $process->setTimeout(25);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function results(): array
    {
        $deadline = microtime(true) + 10;
        while (count(glob($this->directory.'/ready-*')) < count($this->processes) && microtime(true) < $deadline) {
            usleep(5000);
        }
        touch($this->directory.'/go');
        $rows = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            $rows[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    public function test_same_request_race_owns_one_bundle_payment_and_cash_principal(): void
    {
        $this->fixture();
        $this->worker();
        $this->worker();
        $results = $this->results();
        $this->assertSame($results[0]['id'] ?? null, $results[1]['id'] ?? null, json_encode($results));
        $this->assertSame('prepared', $results[0]['state']);
        $this->assertSame('prepared', $results[1]['state']);
        $this->assertSame(1, DB::table('collection_sales_sessions')->where('receipt_id', $this->fixture['receipt'])->count());
        $this->assertSame(1, DB::table('payments')->where('platform_id', $this->fixture['platform'])->count());
    }

    public function test_different_requests_race_cannot_claim_one_receipt_twice(): void
    {
        $this->fixture();
        $this->worker();
        $other = $this->fixture['input'];
        $other['request_key'] = (string) Str::uuid();
        $this->worker(['input' => $other]);
        $results = $this->results();
        $this->assertCount(1, array_filter($results, fn ($r) => $r['state'] === 'prepared'), json_encode($results));
        $this->assertSame(1, DB::table('payments')->where('platform_id', $this->fixture['platform'])->count());
    }

    public function test_two_workers_for_different_siblings_dispatch_at_most_one(): void
    {
        [$receipt, $actor, $input] = $this->fixture(true);
        $this->fakeWordPress();
        app(CollectionSalesFulfillmentService::class)->prepare($receipt, $actor, $input);
        $items = CollectionSalesItem::whereHas('session', fn ($q) => $q->where('receipt_id', $receipt->id))->orderBy('position')->get();
        $this->worker(['action' => 'dispatch', 'item' => $items[0]->id]);
        $this->worker(['action' => 'dispatch', 'item' => $items[1]->id]);
        $results = $this->results();
        $this->assertSame(1, count(file($this->directory.'/posts')));
        $this->assertSame('unknown', $items[0]->fresh()->state);
        $this->assertNull($items[1]->fresh()->dispatch_started_at);
        $this->assertContains('sale_predecessor_not_confirmed', array_column($results, 'state'));
    }

    public function test_legacy_cash_creation_and_receipt_preparation_cannot_both_claim_the_root(): void
    {
        $this->fixture();
        $this->worker();
        $this->worker(['action' => 'legacy']);
        $results = $this->results();
        $this->assertCount(1, array_filter($results, fn ($r) => in_array($r['state'], ['prepared', 'legacy'])), json_encode($results));
        $this->assertSame(1, DB::table('payments')->where('platform_id', $this->fixture['platform'])->count());
    }

    public function test_lost_cache_owner_during_snapshot_cannot_commit_cash_or_durable_holds(): void
    {
        $this->fixture();
        $this->worker(['steal_lease' => true]);
        $results = $this->results();
        $this->assertNotSame('prepared', $results[0]['state']);
        $this->assertSame(0, DB::table('payments')->where('platform_id', $this->fixture['platform'])->count());
        $this->assertSame(0, DB::table('collection_sales_sessions')->where('receipt_id', $this->fixture['receipt'])->count());
    }

    public function test_slow_multiple_profile_preparation_is_bounded_by_the_actual_short_lease(): void
    {
        $this->fixture(true);
        $this->worker(['ttl' => 30, 'slow_snapshot' => 6]);
        $results = $this->results();
        $this->assertNotSame('prepared', $results[0]['state']);
        $this->assertSame(0, DB::table('payments')->where('platform_id', $this->fixture['platform'])->count());
        $this->assertSame(0, DB::table('collection_sales_sessions')->where('receipt_id', $this->fixture['receipt'])->count());
    }

    public function test_worker_death_after_claim_keeps_siblings_blocked_and_only_reads_the_original_operation(): void
    {
        [$receipt, $actor, $input] = $this->fixture(true);
        $this->fakeWordPress();
        app(CollectionSalesFulfillmentService::class)->prepare($receipt, $actor, $input);
        $items = CollectionSalesItem::whereHas('session', fn ($q) => $q->where('receipt_id', $receipt->id))->orderBy('position')->get();
        $process = $this->worker(['action' => 'dispatch', 'item' => $items[0]->id, 'crash_after_claim' => true]);
        touch($this->directory.'/go');
        $process->wait();
        $this->assertSame(73, $process->getExitCode(), $process->getErrorOutput());
        $this->assertNotNull($items[0]->fresh()->dispatch_started_at);
        $this->assertNull($items[1]->fresh()->dispatch_started_at);
        // The process is proven dead; simulate its real lease reaching expiration on this disposable database only.
        DB::table('cache_locks')->where('key', 'like', '%client-lifecycle-mutation:'.$items[0]->client_id)->update(['expiration' => time() - 1]);
        $this->assertSame('unknown', app(CollectionSalesFulfillmentService::class)->resume($items[0]->id)->state);
        $this->assertSame(0, $this->posts);
        try {
            app(CollectionSalesFulfillmentService::class)->resume($items[1]->id);
            $this->fail('A dead predecessor is still an unresolved business outcome.');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('sale_predecessor_not_confirmed', $e->reason);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        if ($this->directory) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            } rmdir($this->directory);
        }
        if ($this->fixture) {
            $account = $this->fixture['account'];
            $platform = $this->fixture['platform'];
            $sessions = DB::table('collection_sales_sessions')->where('account_id', $account)->pluck('id');
            $itemIds = DB::table('collection_sales_items')->whereIn('session_id', $sessions)->pluck('id');
            DB::table('collection_outbox')->whereIn('subject_id', $itemIds)->delete();
            DB::table('collection_sales_profile_holds')->whereIn('session_id', $sessions)->delete();
            DB::table('collection_sales_items')->whereIn('session_id', $sessions)->delete();
            DB::table('collection_sales_sessions')->whereIn('id', $sessions)->delete();
            DB::table('deals')->where('platform_id', $platform)->update(['payment_id' => null]);
            $paymentIds = DB::table('payments')->where('platform_id', $platform)->pluck('id');
            DB::table('payment_recovery_actions')->whereIn('case_id', DB::table('payment_recovery_cases')->whereIn('payment_id', $paymentIds)->select('id'))->delete();
            foreach (['payment_attributions', 'payment_receipt_observations', 'payment_recovery_cases', 'payment_fulfillment_operations'] as $table) {
                DB::table($table)->whereIn('payment_id', $paymentIds)->delete();
            }
            DB::table('payments')->where('platform_id', $platform)->delete();
            DB::table('deals')->where('platform_id', $platform)->delete();
            DB::table('manual_payment_bundles')->where('platform_id', $platform)->delete();
            $devices = DB::table('collection_sim_bindings')->where('account_id', $account)->pluck('device_id');
            $events = DB::table('collection_events')->whereIn('device_id', $devices)->pluck('id');
            $receiptIds = DB::table('collection_receipts')->where('account_id', $account)->pluck('id');
            DB::table('collection_outbox')->where('kind', 'parse')->whereIn('subject_id', $events->map('strval'))->delete();
            DB::table('collection_outbox')->where('kind', 'decide')->whereIn('subject_id', $receiptIds->map('strval'))->delete();
            foreach (['collection_events', 'collection_audit', 'collection_purchase_intents', 'collection_payer_links', 'collection_receipt_aliases', 'collection_receipts', 'collection_sim_bindings', 'collection_account_platforms'] as $table) {
                DB::table($table)->where('account_id', $account)->delete();
            }
            DB::table('collection_accounts')->where('id', $account)->delete();
            $events = DB::table('collection_events')->whereIn('device_id', $devices)->pluck('id');
            DB::table('collection_outbox')->whereIn('kind', ['parse', 'decide'])->whereIn('subject_id', $events->map('strval'))->delete();
            DB::table('collection_events')->whereIn('device_id', $devices)->delete();
            DB::table('collection_devices')->whereIn('id', $devices)->delete();
            DB::table('timeline_events')->where('platform_id', $platform)->delete();
            DB::table('clients')->where('platform_id', $platform)->delete();
            $products = DB::table('products')->where('platform_id', $platform)->pluck('id');
            DB::table('product_prices')->whereIn('product_id', $products)->delete();
            DB::table('products')->whereIn('id', $products)->delete();
            DB::table('platforms')->where('id', $platform)->delete();
            DB::table('users')->where('id', $this->fixture['actor'])->delete();
        }
        parent::tearDown();
    }
}
