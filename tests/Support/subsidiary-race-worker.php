<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_starts_with(config('database.connections.mysql.database'), 'crm_daily_review_test_')) {
    exit(9);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$deadline = microtime(true) + 20;
while (! is_file($input['barrier'])) {
    if (microtime(true) > $deadline) {
        exit(8);
    } usleep(10000);
}
try {
    $actor = App\Models\User::findOrFail($input['actor']);
    Illuminate\Support\Facades\Auth::setUser($actor);
    request()->setUserResolver(fn () => $actor);
    $r = app(App\Services\Trials\TrialApprovalService::class)->create($actor, $input['terms']);
    echo json_encode(['status' => 200, 'id' => $r->id]), "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500, 'class' => get_class($e)]), "\n";
}
