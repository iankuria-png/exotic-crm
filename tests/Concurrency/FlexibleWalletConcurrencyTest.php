<?php

namespace Tests\Concurrency;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingSubscriptionRule;
use App\Models\ClientWalletBalance;
use App\Models\FlexibleCheckoutAttempt;
use App\Models\Payment;
use App\Models\WalletTransaction;
use App\Services\FlexibleAmounts\FlexiblePaymentMethodsService;
use App\Services\FlexibleAmounts\FlexibleSelfCheckoutService;
use App\Services\PaymentCompletionService;
use App\Services\Routing\ProviderRoutingDispatcher;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FlexibleWalletConcurrencyTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\FlexibleAmountsFixture;

    private function enableWallet(string $balance = '2000.00'): void
    {
        app(WalletSettingsService::class)->saveSystemConfig(['mode' => 'production']);
        app(WalletSettingsService::class)->savePlatformConfig($this->market, ['enabled' => true, 'mode_override' => 'production', 'currency_code' => 'KES']);
        $this->client->update(['wallet_balance' => $balance, 'wallet_currency' => 'KES']);
        $this->mock(\App\Services\WalletSyncService::class)->shouldReceive('syncClientBalanceById')->andReturn([]);
    }

    public function test_concurrent_native_database_lock_replays_produce_one_wallet_debit_and_completion(): void
    {
        // This explicit suite commits its disposable fixture so two child connections can see it.
        if (! function_exists('pcntl_fork') || \Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql'
            || ! str_contains(\Illuminate\Support\Facades\DB::connection()->getDatabaseName(), 'test')) {
            $this->markTestSkipped('Requires pcntl and a dedicated MySQL test database.');
        }
        $this->enableWallet();
        config(['client_lifecycle.lock_store'=>'database','cache.stores.database.connection'=>'mysql']);
        $request=$this->request(['payment_method'=>'wallet']);
        $proof=tempnam(sys_get_temp_dir(),'flex-wallet-');
        $this->mock(PaymentCompletionService::class)->shouldReceive('completeSubscriptionPayment')->andReturnUsing(function(Payment $payment)use($proof){
            file_put_contents($proof,"completion\n",FILE_APPEND|LOCK_EX);usleep(600000);
            $payment->update(['payment_data'=>array_merge($payment->payment_data,['provisioning_status'=>'completed'])]);
            app(\App\Services\FlexibleAmounts\FlexibleAttemptGuard::class)->finish($payment);
            return ['payment'=>$payment,'provisioned'=>true];
        });
        \Illuminate\Support\Facades\DB::commit();
        $children=[];
        for($i=0;$i<2;$i++){
            $pid=pcntl_fork();
            if($pid===0){
                \Illuminate\Support\Facades\DB::purge('mysql');
                try{$response=app(FlexibleSelfCheckoutService::class)->start($request);file_put_contents($proof.'.'.$i,json_encode($response));exit(0);}
                catch(\Throwable $e){file_put_contents($proof.'.'.$i,$e::class.': '.$e->getMessage());exit(1);}
            }
            $children[]=$pid;
        }
        $statuses=[];foreach($children as$pid){pcntl_waitpid($pid,$status);$statuses[]=pcntl_wexitstatus($status);}
        foreach($statuses as$status)$this->assertSame(0,$status,file_get_contents($proof.'.0').file_get_contents($proof.'.1'));
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::beginTransaction();
        $a=json_decode(file_get_contents($proof.'.0'),true);$b=json_decode(file_get_contents($proof.'.1'),true);
        $this->assertSame($a['payment_id'],$b['payment_id']);
        $this->assertSame("completion\n",file_get_contents($proof));
        $this->assertDatabaseCount('payments',1);$this->assertDatabaseCount('wallet_transactions',1);$this->assertDatabaseCount('flexible_checkout_attempts',1);
        $this->assertSame('1120.00',ClientWalletBalance::where('client_id',$this->client->id)->value('balance'));
        foreach([$proof,$proof.'.0',$proof.'.1']as$p)unlink($p);
    }
}
