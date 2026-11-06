<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Collections\CollectionPrivacy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CollectionReviewConcurrencyTest extends TestCase
{
    public function test_concurrent_identical_review_retries_save_one_choice_and_one_checked_payer_link(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'collection_automation_test'
            || config('database.connections.mysql.unix_socket') !== '/tmp/exotic-collection-mysql-20261030/mysql.sock') {
            $this->markTestSkipped('Requires the dedicated disposable collection MySQL socket.');
        }
        config(['collection.payer_fingerprint_key' => str_repeat('f', 32)]);
        $actor = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $market = Platform::factory()->create(['currency_code' => 'TZS', 'phone_prefix' => '255']);
        $client = Client::factory()->create(['platform_id' => $market->id, 'wp_post_id' => 777, 'wp_user_id' => 555, 'is_high_risk' => false]);
        $product = Product::factory()->create(['platform_id' => $market->id, 'tier' => 'vip', 'name' => 'Review race VIP', 'currency' => 'TZS', 'is_active' => true, 'is_archived' => false]);
        $price = ProductPrice::create(['product_id' => $product->id, 'duration_key' => 'custom_8', 'duration_label' => '8 days', 'duration_days' => 8, 'price' => '35000.00', 'currency' => 'TZS', 'is_active' => true]);
        $account = CollectionAccount::create(['id' => (string) Str::uuid(), 'label' => 'Review concurrency fixture', 'country' => 'TZ', 'provider_key' => 'vodacom_mpesa_tz',
            'account_kind' => 'merchant', 'account_identifier' => 'fixture', 'account_fingerprint' => hash('sha256', Str::uuid()),
            'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'ownership' => 'company', 'mode' => 'shadow', 'verified_at' => now()]);
        DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $market->id, 'destination_snapshot' => '{}', 'valid_from' => now()->subDays(100)]);
        $receipt = CollectionReceipt::create(['account_id' => $account->id, 'reference_raw' => 'RACEREF0001', 'reference_norm' => 'RACEREF0001', 'principal_minor' => 3500000,
            'currency' => 'TZS', 'provider_at' => now()->subMinute(), 'enrichment_deadline' => now(), 'historical_only' => false, 'conflicts' => [],
            'payer_phone' => '255700000001', 'payer_fingerprint' => app(CollectionPrivacy::class)->fingerprint('255700000001')]);
        $dir = sys_get_temp_dir().'/collection-review-race-'.Str::uuid();
        mkdir($dir, 0700);
        $input = ['client_id' => $client->id, 'product_price_id' => $price->id, 'remember_payer' => true, 'expected_version' => 1, 'request_key' => (string) Str::uuid()];
        file_put_contents($dir.'/fixture.json', json_encode(['receipt_id' => $receipt->id, 'actor_id' => $actor->id, 'input' => $input], JSON_THROW_ON_ERROR));
        $processes = [];
        try {
            for ($n = 0; $n < 2; $n++) {
                $process = new Process(['/usr/local/opt/php@8.2/bin/php', base_path('tests/Support/collection-review-worker.php'), $dir.'/fixture.json', $dir.'/go', $dir.'/ready-'.$n], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'collection_automation_test', 'DB_HOST' => 'localhost',
                    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'DB_SOCKET' => '/tmp/exotic-collection-mysql-20261030/mysql.sock',
                    'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                ]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($dir.'/ready-*')) < 2 && microtime(true) < $deadline) {
                usleep(5000);
            }
            touch($dir.'/go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertNotSame($results[0]['replayed'], $results[1]['replayed']);
            $this->assertSame(1, DB::table('collection_purchase_intents')->where('account_id', $account->id)->count());
            $this->assertSame(1, DB::table('collection_payer_links')->where('account_id', $account->id)->count());
            $this->assertSame(1, DB::table('collection_audit')->where('receipt_id', $receipt->id)->where('action', 'purchase_choice_saved')->count());
            $this->assertSame(0, DB::table('collection_allocations')->where('receipt_id', $receipt->id)->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
            foreach (['collection_audit', 'collection_purchase_intents', 'collection_payer_links', 'collection_receipts', 'collection_account_platforms'] as $table) {
                DB::table($table)->where('account_id', $account->id)->delete();
            }
            DB::table('collection_accounts')->where('id', $account->id)->delete();
            DB::table('product_prices')->where('id', $price->id)->delete();
            DB::table('products')->where('id', $product->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('platforms')->where('id', $market->id)->delete();
            DB::table('users')->where('id', $actor->id)->delete();
        }
    }
}
