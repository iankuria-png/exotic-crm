<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_starts_with((string) config('database.connections.mysql.database'), 'crm_daily_review_test_')) {
    exit(9);
}
config(['cache.default' => 'array', 'queue.default' => 'sync', 'client_lifecycle.lock_store' => 'array']);
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$until = microtime(true) + 20;
while (! is_file($in['barrier'])) {
    if (microtime(true) > $until) {
        exit(8);
    }usleep(10000);
}
try {
    $actor = App\Models\User::findOrFail($in['actor']);
    Illuminate\Support\Facades\Auth::setUser($actor);
    request()->setUserResolver(fn () => $actor);
    if ($in['mode'] === 'review') {
        $value = app(App\Services\Reconciliation\DailyPaymentReviewService::class)->commit($actor, $in['token'], $in['key']);
    } elseif ($in['mode'] === 'rotate') {
        app(App\Services\WalletSettingsService::class)->updateFreeTrialPin('5678', $actor->id);
        $value = true;
    } elseif ($in['mode'] === 'settings') {
        app(App\Services\WalletSettingsService::class)->saveSystemConfig($in['payload'], $actor->id);
        $value = true;
    } elseif ($in['mode'] === 'trial') {
        Illuminate\Support\Facades\Http::preventStrayRequests();
        $expiry = (int) $in['expiry'];
        Illuminate\Support\Facades\Http::fake(['https://race.example.test/*' => function ($req) use ($in, $expiry) {
            if ($req->method() === 'POST') {
                file_put_contents($in['mutations'], "sent\n", FILE_APPEND | LOCK_EX);

                return Illuminate\Support\Facades\Http::response(['success' => true, 'escort_expire' => $expiry]);
            }
            $sent = is_file($in['mutations']);

            return Illuminate\Support\Facades\Http::response(['wp_post_id' => 77, 'wp_user_id' => 55, 'post_status' => $sent ? 'publish' : 'private', 'escort_expire' => $sent ? $expiry : 0]);
        }]);
        $value = app(App\Services\Trials\TrialActivationService::class)->execute(App\Models\FreeTrialRequest::findOrFail($in['request_id']))->status;
    } else {
        throw new RuntimeException('Unknown worker mode');
    }
    echo json_encode(['status' => 200, 'result' => $value]),"\n";
} catch (Throwable $e) {
    echo json_encode(['status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500, 'class' => get_class($e)]),"\n";
}
