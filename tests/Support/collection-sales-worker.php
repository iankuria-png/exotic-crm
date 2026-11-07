<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('DB_DATABASE') !== 'collection_automation_test' || getenv('DB_SOCKET') !== '/tmp/exotic-collection-mysql-20261030/mysql.sock') {
    exit(90);
}
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
Illuminate\Support\Carbon::setTestNow($fixture['now']);
config(['collection.payer_fingerprint_key' => str_repeat('f', 32), 'collection_sales.verified_accounts' => [$fixture['account']],
    'collection_sales.verified_platforms' => [$fixture['platform']], 'client_lifecycle.lock_store' => 'database', 'client_lifecycle.lock_ttl_seconds' => $fixture['ttl'] ?? 600,
    'cache.stores.database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks', 'connection' => null]]);
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) use ($fixture) {
    if ($request->method() === 'POST') {
        file_put_contents($fixture['directory'].'/posts', $request['operation_id']."\n", FILE_APPEND | LOCK_EX);
        throw new Illuminate\Http\Client\ConnectionException('Uncertain fixture dispatch');
    }
    if (($fixture['crash_after_claim'] ?? false) && str_contains($request->url(), 'operation_id=')) {
        exit(73);
    }
    preg_match('#/clients/(\d+)/#', $request->url(), $match);
    if (! str_contains($request->url(), 'operation_id=') && ! empty($fixture['slow_snapshot'])) {
        usleep((int) ($fixture['slow_snapshot'] * 1000000));
    }
    if (! empty($fixture['steal_lease'])) {
        Illuminate\Support\Facades\DB::table('cache_locks')->where('key', 'like', '%client-lifecycle-mutation:%')->update(['owner' => 'other-owner']);
    }

    return Illuminate\Support\Facades\Http::response(['version' => 1, 'snapshot' => $fixture['snapshots'][$match[1]], 'operation' => null]);
});
file_put_contents($argv[3], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[2]) && microtime(true) < $deadline) {
    usleep(5000);
}
try {
    $service = app(App\Services\Collections\CollectionSalesFulfillmentService::class);
    if ($fixture['action'] === 'prepare') {
        $result = $service->prepare(App\Models\Collections\CollectionReceipt::findOrFail($fixture['receipt']), App\Models\User::findOrFail($fixture['actor']), $fixture['input']);
        echo json_encode(['state' => 'prepared', 'id' => $result['session']['id'], 'replayed' => $result['replayed'] ?? false], JSON_THROW_ON_ERROR);
    } elseif ($fixture['action'] === 'legacy') {
        $receipt = App\Models\Collections\CollectionReceipt::findOrFail($fixture['receipt']);
        $clientId = $fixture['input']['items'][0]['client_id'];
        $payment = App\Models\Payment::factory()->create(['platform_id' => $fixture['platform'], 'client_id' => $clientId,
            'transaction_reference' => $receipt->reference_norm, 'reference_root' => $receipt->reference_norm,
            'amount' => '35000.00', 'currency' => 'TZS', 'purpose' => 'subscription']);
        echo json_encode(['state' => 'legacy', 'payment_id' => $payment->id], JSON_THROW_ON_ERROR);
    } else {
        $result = $service->resume($fixture['item'], $fixture['action'] === 'check');
        echo json_encode(['state' => $result->state], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    echo json_encode(['state' => $e instanceof App\Services\Collections\CollectionException ? $e->reason : get_class($e)], JSON_THROW_ON_ERROR);
}
