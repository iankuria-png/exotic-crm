<?php
/** Local-only scratch migration, concurrent budget, and settlement-failure checks.
 * Run: /usr/local/opt/php@8.2/bin/php scripts/qa/wallet-rebates-innodb.php
 * Uses a disposable schema; never mutates the source schema or calls providers.
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\WalletRebate;
use App\Models\WalletTransaction;
use App\Services\PaymentCompletionService;
use App\Services\Rebates\RebateCalculator;
use App\Services\Rebates\RebateChannelResolver;
use App\Services\Rebates\RebateGrantService;
use App\Services\Rebates\RebateProgramService;
use App\Services\WalletService;
use App\Services\WalletSettingsService;
use App\Services\WalletSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (!app()->environment('local') || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Refusing a non-local database.');
}
Http::fake();
app()->instance(WalletSyncService::class, Mockery::mock(WalletSyncService::class)->shouldReceive('syncClientBalanceById', 'syncClientBalance')->andReturn(['status' => 'skipped'])->getMock());
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } echo "PASS: {$message}\n"; }
function connectScratch(string $name): void {
    if (!preg_match('/^exotic_rebate_qa_\d+$/', $name)) { throw new RuntimeException('Unsafe scratch name.'); }
    config(['database.connections.rebate_qa' => array_merge(config('database.connections.mysql'), ['database' => $name])]);
    DB::purge('rebate_qa'); DB::setDefaultConnection('rebate_qa');
}
if (($argv[1] ?? null) === '--worker') {
    connectScratch($argv[2]);
    $row = app(RebateGrantService::class)->grantFor(Payment::findOrFail((int) $argv[3]));
    check((bool) $row, 'Concurrent settlement produced a durable decision.');
    exit(0);
}
$source = DB::connection()->getDatabaseName();
$scratch = 'exotic_rebate_qa_'.getmypid();
$originalConnection = DB::getDefaultConnection();
DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $tables = DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$source, 'BASE TABLE']);
    foreach ($tables as $table) {
        $name = $table->TABLE_NAME;
        if (in_array($name, ['rebate_programs', 'rebate_program_revisions', 'rebate_budget_periods', 'wallet_rebates'], true)) { continue; }
        DB::statement("CREATE TABLE `{$scratch}`.`{$name}` LIKE `{$source}`.`{$name}`");
    }
    // Copy only the existing Local QA companion and its market, not customer histories.
    DB::statement("INSERT INTO `{$scratch}`.platforms SELECT * FROM `{$source}`.platforms WHERE id=12");
    DB::statement("INSERT INTO `{$scratch}`.clients SELECT * FROM `{$source}`.clients WHERE id=2514 AND platform_id=12");
    DB::statement("INSERT INTO `{$scratch}`.users SELECT * FROM `{$source}`.users WHERE role='admin' AND status='active' LIMIT 1");
    connectScratch($scratch);
    $migration = require __DIR__.'/../../database/migrations/2026_10_05_000000_create_wallet_rebate_tables.php';
    $migration->up(); $migration->down(); $migration->up();
    check(count(DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND ENGINE=? AND TABLE_NAME IN (?,?,?,?)', [$scratch, 'InnoDB', 'rebate_programs', 'rebate_program_revisions', 'rebate_budget_periods', 'wallet_rebates'])) === 4, 'Migrate → rollback → migrate creates four InnoDB tables.');
    foreach (['clients', 'client_wallet_balances', 'wallet_transactions', 'payments'] as $name) {
        check(DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$scratch, $name])->ENGINE === 'InnoDB', "{$name} supports transaction rollback and row locks.");
    }
    $c = Client::findOrFail(2514); $c->update(['wallet_balance' => 0, 'wallet_currency' => 'KES']);
    $p = Platform::findOrFail(12); $actor = User::firstOrFail();
    $svc = app(RebateProgramService::class); $s = $svc->forPlatform($p); $d = $svc->defaults(); $d['rollout_mode'] = 'live';
    $s = $svc->save($p, $d, $s->draft_revision, $actor->id); $svc->publish($p, $s->draft_revision, 'Scratch contract QA only', $actor->id);
    $normalWallet = app(WalletService::class);
    $throwingWallet = new class(app(WalletSettingsService::class)) extends WalletService {
        public function credit(Client $client, string|float|int $currency, float|array $amount = 0, array $options = []): array {
            parent::credit($client, $currency, $amount, $options);
            throw new RuntimeException('Forced failure after rebate wallet mutation.');
        }
    };
    app()->instance(RebateGrantService::class, new RebateGrantService($svc, app(RebateChannelResolver::class), app(RebateCalculator::class), $throwingWallet));
    $payment = Payment::withoutEvents(fn () => Payment::create(['platform_id' => 12, 'client_id' => 2514, 'amount' => 5000, 'currency' => 'KES', 'purpose' => 'wallet_topup', 'source' => 'gateway', 'provider_environment' => 'production', 'provider_key' => 'kopokopo', 'transaction_uuid' => (string) Illuminate\Support\Str::uuid(), 'reference_number' => 'SCRATCH-REBATE-1', 'status' => 'pending', 'payment_data' => ['initiator' => 'companion']]));
    app(PaymentCompletionService::class)->completeTopupPayment($payment, ['amount' => 5000, 'currency' => 'KES']);
    check($payment->fresh()->status === 'completed' && $normalWallet->balanceFor($c, 'KES') === 5000.0, 'A rebate failure leaves the base top-up settled and credited.');
    check(WalletRebate::where('status', 'failed')->count() === 1 && WalletTransaction::where('reference_type', 'wallet_rebate')->count() === 0, 'The failed wallet mutation rolled back; one retry decision remains.');
    app()->forgetInstance(RebateGrantService::class);
    $row = app(RebateGrantService::class)->grantFor($payment->fresh());
    check($row->status === 'credited' && $normalWallet->balanceFor($c, 'KES') === 5600.0, 'Retry credits the original 600 quote exactly once.');
    $row2 = app(RebateGrantService::class)->grantFor($payment->fresh());
    check($row2->id === $row->id && WalletTransaction::where('reference_type', 'wallet_rebate')->count() === 1, 'Repeated settlement/retry does not double-credit.');
    DB::table('rebate_budget_periods')->update(['budget_amount' => 650]);
    $ids = [];
    for ($i = 0; $i < 2; $i++) {
        $ids[] = Payment::withoutEvents(fn () => Payment::create(['platform_id' => 12, 'client_id' => 2514, 'amount' => 5000, 'currency' => 'KES', 'purpose' => 'wallet_topup', 'source' => 'gateway', 'provider_environment' => 'production', 'transaction_uuid' => (string) Illuminate\Support\Str::uuid(), 'status' => 'completed', 'completed_at' => now(), 'payment_data' => ['initiator' => 'companion']]))->id;
    }
    $processes = [];
    foreach ($ids as $id) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __FILE__, '--worker', $scratch, (string) $id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $processes[] = [$proc, $pipes];
    }
    foreach ($processes as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc) === 0, 'Concurrent worker completed. '.$out.$err);
    }
    DB::purge('rebate_qa'); connectScratch($scratch);
    check((float) DB::table('rebate_budget_periods')->sum('issued_amount') === 650.0 && (float) WalletRebate::whereIn('status', ['credited', 'capped'])->sum('amount') === 650.0, 'Two concurrent settlements cannot overspend the 650 budget.');
    $migration->down(); $migration->up();
    check(DB::table('wallet_rebates')->count() === 0, 'Rollback/reapply is clean after grants.');
} finally {
    DB::setDefaultConnection($originalConnection); DB::purge('rebate_qa');
    DB::statement("DROP DATABASE `{$scratch}`");
    echo "Scratch schema removed. No source payments or wallets changed.\n";
}
