<?php

// Test-only worker. Refuse every connection except the disposable local MariaDB database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'reference_suggestions_test'
    || (string) config('database.connections.mysql.port') !== '21625'
    || config('database.connections.mysql.host') !== '127.0.0.1') {
    exit(3);
}
Illuminate\Support\Carbon::setTestNow('2026-10-30 12:00:00');
[$script, $dealId, $actorId, $reference, $directory, $worker] = $argv;
$deal = App\Models\Deal::findOrFail($dealId);
$client = $deal->client;
$actor = App\Models\User::findOrFail($actorId);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) use ($directory, $client, $reference) {
    if (str_ends_with($request->url(), '/extend')) {
        if (App\Models\Payment::where('transaction_reference', $reference)->count() !== 1) {
            throw new RuntimeException('Site call before payment preparation');
        }
        file_put_contents($directory.'/dispatches', "extend\n", FILE_APPEND | LOCK_EX);
        usleep(200000);

        return Illuminate\Support\Facades\Http::response(['success' => true, 'escort_expire' => now()->addDays(12)->timestamp]);
    }

    return Illuminate\Support\Facades\Http::response(['wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id,
        'name' => $client->name, 'phone' => $client->phone_normalized, 'email' => $client->email,
        'post_status' => 'publish', 'escort_expire' => now()->addDays(12)->timestamp]);
});
Illuminate\Support\Facades\Queue::fake();
file_put_contents($directory.'/'.$worker.'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! is_file($directory.'/go') && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($directory.'/go')) {
    exit(4);
}
$request = Illuminate\Http\Request::create('/api/crm/deals/'.$dealId.'/extend', 'POST', ['payment_method' => 'manual',
    'payment_reference' => $reference, 'additional_days' => 7, 'reason' => 'Concurrent fixture extension']);
$app->instance('request', $request);
Illuminate\Support\Facades\Auth::setUser($actor);
$request->setUserResolver(fn () => $actor);
try {
    $response = app(App\Http\Controllers\CRM\DealController::class)->extend($request, $deal);
    echo json_encode(['status' => $response->getStatusCode()]);
} catch (Illuminate\Validation\ValidationException $e) {
    echo json_encode(['status' => 422]);
}
