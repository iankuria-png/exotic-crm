<?php

// Dedicated disposable-MySQL concurrency fixture. Never accept a shared database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('DB_DATABASE') !== 'collection_automation_test' || getenv('DB_SOCKET') !== '/tmp/exotic-collection-mysql-20261030/mysql.sock') {
    exit(90);
}
$receiptId = (int) $argv[1];
$gate = $argv[2];
$ready = $argv[3];
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($gate)) {
    if (microtime(true) > $deadline) {
        exit(91);
    }
    usleep(5000);
}
try {
    $status = Illuminate\Support\Facades\DB::transaction(function () use ($receiptId) {
        $r = App\Models\Collections\CollectionReceipt::findOrFail($receiptId);
        App\Models\Collections\CollectionAccount::whereKey($r->account_id)->lockForUpdate()->firstOrFail();
        if (App\Models\Collections\CollectionAllocation::where('receipt_id', $receiptId)->lockForUpdate()->first() !== null) {
            return 'duplicate';
        }
        $a = App\Models\Collections\CollectionAllocation::create(['id' => (string) Illuminate\Support\Str::uuid(), 'receipt_id' => $receiptId,
            'intent_id' => (string) Illuminate\Support\Str::uuid(), 'intent_version' => 1, 'purchase_snapshot' => [], 'decision_source' => 'automatic']);
        app(App\Services\Collections\CollectionExposureService::class)->reserve($r, $a);
        usleep(50000);

        return 'reserved';
    }, 3);
    echo json_encode(['status' => $status]);
} catch (App\Services\Collections\CollectionException $e) {
    echo json_encode(['status' => $e->reason]);
}
