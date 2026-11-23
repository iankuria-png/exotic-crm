<?php

if (is_file(dirname(__DIR__, 2).'/bootstrap/testing-release-mysql.php')) {
    require dirname(__DIR__, 2).'/bootstrap/testing-release-mysql.php';
} else {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'collection_kopokopo_release_test' || config('database.connections.mysql.unix_socket') !== '/tmp/exotic-kopo-mysql-20261123/mysql.sock') {
    exit(3);
}
$feed = App\Models\Collections\CollectionProviderFeed::findOrFail($argv[1]);
config(['collection_kopokopo.account_id' => $feed->account_id, 'collection.capture_enabled' => true, 'collection_kopokopo.enabled' => true, 'collection.payer_fingerprint_key' => str_repeat('f', 32)]);
$body = file_get_contents($argv[2]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[3]) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($argv[3])) {
    exit(4);
}
echo json_encode(app(App\Services\Collections\Kopokopo\KopokopoIngestService::class)->webhook($feed, $body, hash_hmac('sha256', $body, 'fixture-api-signing-key')));
