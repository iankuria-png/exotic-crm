<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('DB_DATABASE') !== 'collection_automation_test' || getenv('DB_SOCKET') !== '/tmp/exotic-collection-mysql-20261030/mysql.sock') {
    exit(90);
}
config(['collection.payer_fingerprint_key' => str_repeat('f', 32)]);
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($argv[3], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[2]) && microtime(true) < $deadline) {
    usleep(5000);
}
$result = app(App\Services\Collections\CollectionPurchaseService::class)->saveChoice(
    App\Models\Collections\CollectionReceipt::findOrFail($fixture['receipt_id']),
    App\Models\User::findOrFail($fixture['actor_id']), $fixture['input']);
echo json_encode(['id' => $result['intent']->id, 'replayed' => $result['replayed']], JSON_THROW_ON_ERROR);
