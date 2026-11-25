<?php

namespace Tests\Feature\FlexibleAmounts;

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

class FlexiblePaymentMethodsTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\FlexibleAmountsFixture;

    private function enableWallet(string $balance = '2000.00'): void
    {
        app(WalletSettingsService::class)->saveSystemConfig(['mode' => 'production']);
        app(WalletSettingsService::class)->savePlatformConfig($this->market, ['enabled' => true, 'mode_override' => 'production', 'currency_code' => 'KES']);
        $this->client->update(['wallet_balance' => $balance, 'wallet_currency' => 'KES']);
        $this->mock(\App\Services\WalletSyncService::class)->shouldReceive('syncClientBalanceById')->andReturn([]);
    }

    private function kopokopo(): BillingMarketProviderBinding
    {
        $profile = BillingProviderProfile::create(['provider_type_key' => 'kopokopo', 'profile_name' => 'Fixture M-Pesa',
            'country_code' => 'KE', 'market_id' => $this->market->id, 'environment' => 'sandbox', 'active' => true,
            'config_json' => ['base_url' => 'https://sandbox.kopokopo.test', 'till_number' => 'K123456', 'callback_base_url' => 'https://billing.test'],
            'secrets_json' => ['client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'api_key' => 'fixture-key']]);

        return BillingMarketProviderBinding::create(['market_id' => $this->market->id, 'provider_profile_id' => $profile->id,
            'billing_surface' => 'subscription_push', 'enabled' => true, 'self_service_enabled' => true, 'execution_mode' => 'direct', 'priority' => 1]);
    }

    private function fakeWalletCompletion(int &$calls): void
    {
        $this->mock(PaymentCompletionService::class)->shouldReceive('completeSubscriptionPayment')->andReturnUsing(function (Payment $payment) use (&$calls) {
            $calls++;
            $this->assertSame('completed', $payment->status);
            $this->assertDatabaseHas('wallet_transactions', ['payment_id' => $payment->id, 'type' => 'debit', 'amount' => $payment->amount]);
            $payment->update(['payment_data' => array_merge($payment->payment_data, ['provisioning_status' => 'completed'])]);
            app(\App\Services\FlexibleAmounts\FlexibleAttemptGuard::class)->finish($payment);

            return ['payment' => $payment, 'provisioned' => true];
        });
    }

    public function test_available_methods_use_actual_configured_bindings_and_read_legacy_balance_without_creating_rows(): void
    {
        $this->enableWallet();
        $binding = $this->kopokopo();
        $service = app(FlexiblePaymentMethodsService::class);
        $methods = collect($service->choices($this->market, $this->client, $this->state))->keyBy('key');
        $this->assertSame(['pawapay', 'kopokopo', 'wallet'], $methods->keys()->all());
        $this->assertSame('2000.00', $methods['wallet']['balance']);
        $this->assertDatabaseCount('client_wallet_balances', 0);
        $this->assertArrayNotHasKey('context', $methods['kopokopo']);
        $binding->update(['self_service_enabled' => false]);
        $this->assertNotContains('kopokopo', array_column($service->choices($this->market, $this->client, $this->state), 'key'));
        BillingSubscriptionRule::where('market_id', $this->market->id)->update(['renewal_method_json' => ['methods' => ['payment_link']], 'activation_method_json' => ['methods' => ['payment_link']]]);
        $this->assertSame(['pawapay'], array_column($service->choices($this->market, $this->client, $this->state), 'key'));
    }

    public function test_existing_hosted_customer_gate_and_legacy_mpesa_binding_shape_are_preserved(): void
    {
        $binding=$this->kopokopo();$binding->update(['execution_mode'=>'proxy']);
        BillingSubscriptionRule::where('market_id',$this->market->id)->update(['renewal_method_json'=>['methods'=>['wallet_balance','payment_link','manual']]]);
        BillingMarketProviderBinding::where('market_id',$this->market->id)->where('billing_surface','self_checkout')->update(['self_service_enabled'=>false]);
        $methods=app(FlexiblePaymentMethodsService::class)->choices($this->market,$this->client,$this->state);
        $this->assertContains('pawapay',array_column($methods,'key'));
        $this->assertContains('kopokopo',array_column($methods,'key'));
        BillingMarketProviderBinding::where('market_id',$this->market->id)->where('billing_surface','proxy_hosted_checkout')->update(['self_service_enabled'=>false]);
        $this->assertNotContains('pawapay',array_column(app(FlexiblePaymentMethodsService::class)->choices($this->market,$this->client,$this->state),'key'));
    }

    public function test_wallet_debits_the_confirmed_quote_once_and_raw_browser_key_cannot_reuse_another_wallet_debit(): void
    {
        $this->enableWallet();
        $calls = 0;
        $this->fakeWalletCompletion($calls);
        $request = $this->request(['payment_method' => 'wallet', 'phone' => null, 'nudge_used' => null]);
        $rawKey = $request->header('X-Idempotency-Key');
        WalletTransaction::create(['client_id' => $this->client->id, 'platform_id' => $this->market->id, 'type' => 'debit',
            'currency_code' => 'KES', 'amount' => '1.00', 'balance_after' => '2000.00', 'description' => 'Other historic operation', 'idempotency_key' => $rawKey]);
        $one = app(FlexibleSelfCheckoutService::class)->start($request);
        $two = app(FlexibleSelfCheckoutService::class)->start($request);
        $this->assertSame($one['payment_id'], $two['payment_id']);
        $this->assertSame(1, $calls);
        $this->assertSame('completed', $one['payment_state']);
        $this->assertSame('wallet', $one['payment_method']);
        $this->assertSame('1120.00', ClientWalletBalance::where('client_id', $this->client->id)->value('balance'));
        $this->assertDatabaseCount('payments', 1);
        $attempt = FlexibleCheckoutAttempt::firstOrFail();
        $debit = app(FlexiblePaymentMethodsService::class)->assertDebit($attempt);
        $this->assertSame('880.00', $debit->amount);
        $this->assertSame('flexible-wallet:'.$attempt->operation_key, $debit->idempotency_key);
        $this->assertNotSame($rawKey, $debit->idempotency_key);
        $this->assertSame('wallet_balance', $attempt->payment->latestPinnedRoutingDecision()->provider_type_key);
    }

    public function test_wallet_shortfall_rejects_before_any_payment_debit_or_attempt_and_a_different_currency_is_not_spent(): void
    {
        $this->enableWallet('0.00');
        ClientWalletBalance::create(['client_id' => $this->client->id, 'currency' => 'USD', 'balance' => '10000.00']);
        try {
            app(FlexibleSelfCheckoutService::class)->start($this->request(['payment_method' => 'wallet']));
            $this->fail('Insufficient wallet should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('flexible_checkout_attempts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame('10000.00', ClientWalletBalance::where('currency', 'USD')->value('balance'));
    }

    public function test_mpesa_dispatches_only_the_pinned_configured_kopokopo_binding_and_preserves_entered_number(): void
    {
        $binding = $this->kopokopo();
        $this->mock(ProviderRoutingDispatcher::class)->shouldReceive('dispatch')->once()->withArgs(function ($payment, $context, $options) use ($binding) {
            $this->assertSame('kopokopo', $payment->provider_key);
            $this->assertSame($binding->id, $payment->latestPinnedRoutingDecision()->chosen_binding_id);
            $this->assertSame('subscription_push', $payment->latestPinnedRoutingDecision()->billing_surface);
            $this->assertSame('254769912227', $options['phone']);
            $this->assertSame('880.00', number_format((float) $payment->amount, 2, '.', ''));

            return $context['provider_key'] === 'kopokopo';
        })->andReturn(['type' => 'stk_pending', 'provider_reference' => 'fixture-mpesa-push']);
        $request = $this->request(['payment_method' => 'kopokopo', 'phone' => '0769912227', 'nudge_used' => null]);
        $first = app(FlexibleSelfCheckoutService::class)->start($request);
        $replayed = app(FlexibleSelfCheckoutService::class)->start($request);
        $this->assertSame('stk_pending', $first['action']['type']);
        $this->assertSame($first['payment_id'], $replayed['payment_id']);
    }

    public function test_disabled_method_cannot_be_submitted_and_does_not_fall_back_to_pawapay(): void
    {
        $binding = $this->kopokopo();
        $binding->update(['enabled' => false]);
        $this->expectException(ValidationException::class);
        try {
            app(FlexibleSelfCheckoutService::class)->start($this->request(['payment_method' => 'kopokopo']));
        } finally {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('flexible_checkout_attempts', 0);
        }
    }

    public function test_corrupt_debit_identity_is_never_used_for_activation(): void
    {
        $this->enableWallet();
        $calls = 0;
        $this->fakeWalletCompletion($calls);
        $result = app(FlexibleSelfCheckoutService::class)->start($this->request(['payment_method' => 'wallet']));
        $attempt = FlexibleCheckoutAttempt::first();
        $debit = WalletTransaction::where('payment_id', $result['payment_id'])->first();
        $debit->update(['amount' => '1.00']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(FlexiblePaymentMethodsService::class)->completeWallet($attempt);
    }
    public function test_committed_wallet_debit_recovers_same_attempt_before_current_method_gate_without_a_second_debit(): void
    {
        $this->enableWallet();
        $service=$this->partialMock(FlexiblePaymentMethodsService::class);
        $service->shouldReceive('completeWallet')->once()->andReturnUsing(fn($a)=>$a);
        $request=$this->request(['payment_method'=>'wallet','nudge_used'=>null]);
        $one=app(FlexibleSelfCheckoutService::class)->start($request);
        $this->assertSame('wallet_debited',FlexibleCheckoutAttempt::first()->state);
        $this->assertSame('completed',$one['payment_state']);
        $this->app->instance(FlexiblePaymentMethodsService::class,new FlexiblePaymentMethodsService());
        app(WalletSettingsService::class)->saveSystemConfig(['mode'=>'disabled']);
        $calls=0;$this->fakeWalletCompletion($calls);
        $two=app(FlexibleSelfCheckoutService::class)->start($request);
        $this->assertSame($one['payment_id'],$two['payment_id']);
        $this->assertSame(1,$calls);
        $this->assertDatabaseCount('wallet_transactions',1);
        $this->assertSame('1120.00',ClientWalletBalance::where('client_id',$this->client->id)->value('balance'));
    }

    public function test_a_saved_attempt_cannot_switch_rails_and_confirmed_activation_review_remains_held(): void
    {
        $this->enableWallet();
        $this->mock(PaymentCompletionService::class)->shouldReceive('completeSubscriptionPayment')->once()->andThrow(new \RuntimeException('Uncertain native outcome'));
        $request=$this->request(['payment_method'=>'wallet']);
        $one=app(FlexibleSelfCheckoutService::class)->start($request);
        $two=app(FlexibleSelfCheckoutService::class)->start($request);
        $this->assertSame('needs_review',FlexibleCheckoutAttempt::first()->state);
        $this->assertSame('needs_review',$two['activation_state']);
        $this->assertSame($one['payment_id'],$two['payment_id']);
        $this->assertNotNull(FlexibleCheckoutAttempt::first()->blocking_client_id);
        try {
            app(FlexibleSelfCheckoutService::class)->start($this->request(['payment_method'=>'pawapay'],$request->header('X-Idempotency-Key')));
            $this->fail('Saved wallet attempt switched rail');
        }catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertDatabaseCount('wallet_transactions',1);
        $this->assertDatabaseCount('payments',1);
    }

    public function test_native_wallet_completion_recovers_applied_wp_operation_after_a_process_exit_before_crm_completion(): void
    {
        $this->enableWallet();
        $service=$this->partialMock(FlexiblePaymentMethodsService::class);
        $service->shouldReceive('completeWallet')->once()->andReturnUsing(fn($a)=>$a);
        $request=$this->request(['payment_method'=>'wallet']);
        $first=app(FlexibleSelfCheckoutService::class)->start($request);
        $attempt=FlexibleCheckoutAttempt::first();$attempt->update(['state'=>'completing','dispatch_started_at'=>now()]);$payment=$attempt->payment;$deal=$payment->deal;
        $expiry=now('Africa/Nairobi')->addDays(10)->endOfDay()->timestamp;
        $this->wp->shouldReceive('payLinkOperation')->with($attempt->operation_key)->once()->andReturn(['operation'=>[
            'state'=>'applied','operation_key'=>$attempt->operation_key,'post_id'=>$this->client->wp_post_id,'crm_payment_id'=>$payment->id,'crm_deal_id'=>$deal->id,
            'product_type'=>'vip','duration_days'=>4,'escort_expire'=>$expiry,'profile_url'=>'https://flexible.test/escort/fixture/']]);
        $this->wp->shouldNotReceive('activatePayLink');
        $this->app->instance(FlexiblePaymentMethodsService::class,new FlexiblePaymentMethodsService());
        $second=app(FlexibleSelfCheckoutService::class)->start($request);
        $this->assertSame($first['payment_id'],$second['payment_id']);
        $this->assertSame('completed',$second['activation_state'],json_encode(['response'=>$second,'attempt'=>$attempt->fresh()->toArray(),'payment'=>$payment->fresh()->toArray()]));
        $this->assertSame('finished',$attempt->fresh()->state);
        $this->assertNull($attempt->fresh()->blocking_client_id);
        $this->assertSame($expiry,$this->client->fresh()->escort_expire);
        $this->assertSame(4,$deal->fresh()->duration_days);
        $this->assertDatabaseCount('wallet_transactions',1);
        $this->assertDatabaseCount('payments',1);
        $this->assertDatabaseCount('flexible_payment_receipts',1);
    }

    public function test_sandbox_wallet_completes_its_attempt_without_live_wordpress_activation_or_receipt(): void
    {
        $this->enableWallet();
        app(WalletSettingsService::class)->saveSystemConfig(['mode'=>'sandbox']);
        app(WalletSettingsService::class)->savePlatformConfig($this->market,['enabled'=>true,'mode_override'=>'sandbox','currency_code'=>'KES']);
        $this->wp->shouldNotReceive('activatePayLink');
        $result=app(FlexibleSelfCheckoutService::class)->start($this->request(['payment_method'=>'wallet']));
        $this->assertSame('completed',$result['payment_state']);
        $this->assertSame('suppressed_sandbox',$result['activation_state']);
        $this->assertSame('finished',FlexibleCheckoutAttempt::first()->state);
        $this->assertDatabaseCount('flexible_payment_receipts',0);
    }

    public function test_legacy_self_checkout_customer_gate_keeps_benin_pawapay_available(): void
    {
        BillingMarketProviderBinding::where('market_id',$this->market->id)->where('billing_surface','proxy_hosted_checkout')->update(['self_service_enabled'=>false]);
        $methods=app(FlexiblePaymentMethodsService::class)->choices($this->market,$this->client,$this->state);
        $this->assertContains('pawapay',array_column($methods,'key'));
        BillingMarketProviderBinding::where('market_id',$this->market->id)->where('billing_surface','self_checkout')->update(['self_service_enabled'=>false]);
        $this->assertNotContains('pawapay',array_column(app(FlexiblePaymentMethodsService::class)->choices($this->market,$this->client,$this->state),'key'));
    }

}
