<?php

namespace Tests\Feature;

use App\Models\{Platform, Client, Product, ProductPrice, PayLinkSetting, PayLinkCharge, PayLinkReceipt, Payment, Deal, BillingProviderProfile, BillingMarketProviderBinding, BillingProviderTransaction, BillingRoutingDecision};
use App\Services\PayLink\{LinkService, CheckoutService, ReconciliationService};
use App\Services\{WpSyncFactory, WpSyncService, PaymentCompletionService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class PayLinkFlowTest extends TestCase
{
    use RefreshDatabase;
    private Platform $market;
    private Client $client;
    private ProductPrice $price;
    private array $input;
    private array $wpState;
    private $wp;
    private string $providerState = 'COMPLETED';
    private bool $badIdentity = false;
    private string $initState = 'ACCEPTED';
    private int $depositCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->market = Platform::factory()->create(['domain'=>'www.pay-link-ke.test','country'=>'Kenya','currency_code'=>'KES','phone_prefix'=>'254','timezone'=>'Africa/Nairobi','is_active'=>true]);
        $this->client = Client::factory()->create(['platform_id'=>$this->market->id,'phone_normalized'=>'254712345678','escort_expire'=>now()->addDays(3)->timestamp,'featured'=>true,'premium'=>true]);
        $product = Product::factory()->create(['platform_id'=>$this->market->id,'tier'=>'vip','currency'=>'KES']);
        $this->price = ProductPrice::create(['product_id'=>$product->id,'duration_key'=>'weekly','duration_label'=>'1 week','duration_days'=>7,'price'=>'1500.00','currency'=>'KES','is_active'=>true]);
        $profile = BillingProviderProfile::create(['provider_type_key'=>'pawapay','profile_name'=>'Pay-link isolated fixture','market_id'=>$this->market->id,'country_code'=>'KE','environment'=>'production','active'=>true,'config_json'=>[],'secrets_json'=>['api_key'=>'fixture-only-not-a-real-credential']]);
        BillingMarketProviderBinding::create(['market_id'=>$this->market->id,'provider_profile_id'=>$profile->id,'billing_surface'=>'pay_link','enabled'=>true,'self_service_enabled'=>true,'operator_enabled'=>false,'execution_mode'=>'direct','priority'=>1]);
        PayLinkSetting::create(['platform_id'=>$this->market->id,'rollout_mode'=>'live','offers_json'=>[['product_price_id'=>$this->price->id]],'provider_order'=>['pawapay'],'config_revision'=>1]);
        $link = app(LinkService::class)->mint($this->client);
        $this->input = ['code'=>$link->code_encrypted,'session_proof'=>str_repeat('a',64),'visitor_ip'=>'192.0.2.11','product_price_id'=>$this->price->id,'config_revision'=>1,'phone'=>null,'confirm_saved_phone'=>true,'provider_key'=>'pawapay'];
        $this->wpState = ['version'=>1,'writers_ready'=>true,'eligible'=>true,'post_id'=>$this->client->wp_post_id,'wp_user_id'=>$this->client->wp_user_id,'live'=>true,'current_tier'=>'vip','escort_expire'=>$this->client->escort_expire];
        $this->wp = \Mockery::mock(WpSyncService::class);
        $this->wp->shouldReceive('payLinkState')->andReturnUsing(fn()=>$this->wpState);
        $this->wp->shouldReceive('payLinkCapabilities')->andReturn(['version'=>1,'writers_ready'=>true]);
        $this->wp->shouldReceive('payLinkOperation')->byDefault()->andReturn(['operation'=>null]);
        $this->wp->shouldReceive('activatePayLink')->byDefault()->andReturnUsing(fn($post,$payload)=>['state'=>'applied','operation_key'=>$payload['operation_key'],'post_id'=>$post,
            'crm_payment_id'=>$payload['crm_payment_id'],'crm_deal_id'=>$payload['crm_deal_id'],'escort_expire'=>now()->addDays(10)->timestamp,'profile_url'=>'https://www.pay-link-ke.test/escort/fixture/']);
        $this->mock(WpSyncFactory::class)->shouldReceive('forPlatform')->andReturn($this->wp);
        Http::preventStrayRequests();
        $this->fakeProvider();
    }

    private function fakeProvider(string $init = 'ACCEPTED'): void
    {
        $this->initState=$init;
        Http::fake(function($r){
            if(str_ends_with($r->url(),'/v2/active-conf'))return Http::response(['signatureConfiguration'=>['signedRequestsOnly'=>false],'countries'=>[['country'=>'KEN','providers'=>[['provider'=>'MPESA_KEN','currencies'=>[['currency'=>'KES','operationTypes'=>[['DEPOSIT'=>['authType'=>'PROVIDER_AUTH','pinPrompt'=>'AUTOMATIC','decimalsInAmount'=>'NONE','status'=>'OPERATIONAL','minTransactionLimit'=>'1','maxTransactionLimit'=>'150000']]]]]]]]]]);
            if(str_ends_with($r->url(),'/v2/predict-provider'))return Http::response(['phoneNumber'=>$r['phoneNumber'],'country'=>'KEN','provider'=>'MPESA_KEN']);
            if($r->method()==='POST'&&str_ends_with($r->url(),'/v2/deposits')) {
                $this->depositCalls++;
                $this->assertDatabaseHas('pay_link_charges',['deposit_id'=>$r['depositId'],'payment_state'=>'submitting']);
                $this->assertDatabaseHas('payments',['transaction_reference'=>$r['depositId'],'source'=>'self_service_pay_link']);
                $this->assertDatabaseHas('billing_provider_transactions',['provider_transaction_id'=>$r['depositId']]);
                if($this->initState==='TIMEOUT')throw new \Illuminate\Http\Client\ConnectionException('fixture timeout');
                return Http::response(['status'=>$this->initState,'depositId'=>$r['depositId']]);
            }
            $c=PayLinkCharge::where('deposit_id',basename($r->url()))->firstOrFail();
            if($this->providerState==='NOT_FOUND')return Http::response(['status'=>'NOT_FOUND']);
            return Http::response(['status'=>'FOUND','data'=>['depositId'=>$c->deposit_id,'status'=>$this->providerState,'amount'=>$this->badIdentity?'1.00':$c->amount,'currency'=>$c->currency,
                'payer'=>['type'=>'MMO','accountDetails'=>['phoneNumber'=>$c->phone,'provider'=>'MPESA_KEN']],'clientReferenceId'=>$c->public_ref,'providerTransactionId'=>'FIXTURE-RECEIPT']]);
        });
    }

    private function start(?array $input=null, ?string $key=null): array { return app(CheckoutService::class)->start($this->market,$input??$this->input,$key??(string)Str::uuid()); }
    private function charge(): PayLinkCharge { return PayLinkCharge::latest('id')->firstOrFail(); }
    private function settle(): PayLinkCharge { $c=$this->charge();app(ReconciliationService::class)->reconcile($c->id);return $c->fresh(); }
    private function expectHttp(int $status, callable $call): void
    {
        try{$call();$this->fail('Expected HTTP refusal.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($status,$e->getStatusCode());}
        catch(\App\Exceptions\ClientLifecycleMutationException $e){$this->assertSame(409,$status);$this->assertSame('lifecycle_busy',$e->reasonCode);}
    }

    public function test_wordpress_refusal_keeps_verified_money_and_reservation_for_review(): void
    {
        $response = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(409, [], '{"code":"subscription_busy"}'));
        $this->wp->shouldReceive('activatePayLink')->once()->andThrow(new \Illuminate\Http\Client\RequestException($response));
        $this->start(); $c = $this->settle();
        $this->assertSame('completed', $c->payment_state);
        $this->assertSame('needs_review', $c->activation_state);
        $this->assertSame('wordpress_activation_requires_review', $c->review_reason);
        $this->assertNotNull($c->blocking_client_id);
        $this->assertSame(0, PayLinkReceipt::count());
    }

    public function test_verified_payment_activates_once_and_creates_one_saved_number_receipt(): void
    {
        $this->start();$c=$this->settle();
        $this->assertSame('applied',$c->activation_state);$this->assertNull($c->blocking_client_id);
        $this->assertSame('completed',$c->payment->status);$this->assertSame('active',Deal::find($c->deal_id)->status);
        $this->assertSame(now()->addDays(10)->timestamp,$c->client->escort_expire);
        $this->assertSame('254712345678',PayLinkReceipt::first()->phone);
        app(PaymentCompletionService::class)->complete($c->payment,['status'=>'COMPLETED']);
        $this->assertSame(1,Payment::where('source','self_service_pay_link')->count());$this->assertSame(1,PayLinkReceipt::count());
        $this->assertNull(app(\App\Services\Rebates\RebateChannelResolver::class)->resolve($c->payment));
    }

    public function test_reservation_precedes_io_and_same_device_replay_recovers_after_rotation_and_off(): void
    {
        $key=(string)Str::uuid();$first=$this->start(null,$key);
        app(LinkService::class)->revoke($this->client,1,'fixture rotation',true);
        PayLinkSetting::where('platform_id',$this->market->id)->update(['rollout_mode'=>'off','config_revision'=>2]);
        $this->price->update(['is_active'=>false]);
        $again=$this->start(null,$key);
        $this->assertSame($first['charge_ref'],$again['charge_ref']);$this->assertSame($first['recovery_token'],$again['recovery_token']);
        Http::assertSentCount(3);$this->assertSame(1,PayLinkCharge::count());
        $c=$this->settle();$this->assertSame('applied',$c->activation_state);
    }

    public function test_changed_payload_and_another_device_cannot_replay(): void
    {
        $key=(string)Str::uuid();$this->start(null,$key);
        $this->expectHttp(409,fn()=>$this->start(array_replace($this->input,['product_price_id'=>999]),$key));
        $this->expectHttp(409,fn()=>$this->start(array_replace($this->input,['session_proof'=>str_repeat('b',64)]),$key));
        $this->assertSame(1,PayLinkCharge::count());Http::assertSentCount(3);
    }

    public function test_pending_beyond_fifteen_minutes_and_rotation_still_blocks_new_charge(): void
    {
        $this->providerState='NOT_FOUND';$this->start();$c=$this->charge();$c->update(['dispatch_started_at'=>now()->subMinutes(30)]);$this->settle();
        $link=app(LinkService::class)->revoke($this->client,1,'fixture rotation',true);
        $this->expectHttp(409,fn()=>$this->start(array_replace($this->input,['code'=>$link->code_encrypted])));
        $this->assertSame($this->client->id,$c->fresh()->blocking_client_id);$this->assertSame('uncertain',$c->fresh()->payment_state);
    }

    public function test_transport_timeout_is_uncertain_and_never_issues_a_second_deposit(): void
    {
        $this->fakeProvider('TIMEOUT');$this->providerState='NOT_FOUND';$this->start();$this->settle();$this->settle();
        $this->assertSame('uncertain',$this->charge()->payment_state);$this->assertNotNull($this->charge()->blocking_client_id);
        $this->assertSame(1,$this->depositCalls);
    }

    public function test_authoritative_failure_releases_and_a_fresh_attempt_gets_another_identity(): void
    {
        $this->start();$first=$this->charge();$this->providerState='FAILED';$c=$this->settle();
        $this->assertSame('failed',$c->payment_state);$this->assertNull($c->blocking_client_id);
        $this->start();$this->assertNotSame($first->deposit_id,$this->charge()->deposit_id);$this->assertSame(2,PayLinkCharge::count());
    }

    public function test_rejected_and_duplicate_acknowledgements_are_distinct(): void
    {
        $this->fakeProvider('REJECTED');$this->start();$this->assertSame('failed',$this->charge()->payment_state);$this->assertNull($this->charge()->blocking_client_id);
        $this->fakeProvider('DUPLICATE_IGNORED');$this->start();$this->assertSame('pending',$this->charge()->payment_state);$this->assertNotNull($this->charge()->blocking_client_id);
    }

    public function test_provider_identity_mismatch_retains_ownership_for_review_without_activation(): void
    {
        $this->start();$this->badIdentity=true;$c=$this->settle();
        $this->assertSame('needs_review',$c->activation_state);$this->assertSame('provider_identity_mismatch',$c->review_reason);$this->assertNotNull($c->blocking_client_id);
        $this->assertSame(0,PayLinkReceipt::count());$this->wp->shouldNotHaveReceived('activatePayLink');
    }

    public function test_paid_profile_hold_is_separate_from_money_and_retains_charge(): void
    {
        $this->start();$this->client->update(['profile_paused_at'=>now()]);$c=$this->settle();
        $this->assertSame('completed',$c->payment_state);$this->assertSame('completed',$c->payment->status);$this->assertSame('needs_review',$c->activation_state);
        $this->assertNotNull($c->blocking_client_id);$this->assertSame(0,PayLinkReceipt::count());$this->wp->shouldNotHaveReceived('activatePayLink');
    }

    public function test_committed_wp_result_recovers_even_after_a_later_profile_hold(): void
    {
        $this->start();$c=$this->charge();
        $saved=['state'=>'applied','operation_key'=>$c->public_ref,'post_id'=>$this->client->wp_post_id,'crm_payment_id'=>$c->payment_id,'escort_expire'=>now()->addDays(10)->timestamp,'profile_url'=>'https://www.pay-link-ke.test/escort/fixture/'];
        $this->wp->shouldReceive('payLinkOperation')->andReturn(['operation'=>$saved]);$this->client->update(['profile_paused_at'=>now()]);
        $c=$this->settle();$this->assertSame('applied',$c->activation_state);$this->wp->shouldNotHaveReceived('activatePayLink');
    }

    public function test_missing_wp_expiry_cannot_be_presented_as_applied(): void
    {
        $this->start();$this->wp->shouldReceive('activatePayLink')->andReturn(['state'=>'applied']);$c=$this->settle();
        $this->assertSame('completed',$c->payment_state);$this->assertSame('needs_review',$c->activation_state);$this->assertSame(0,PayLinkReceipt::count());
    }

    public function test_alternative_payer_does_not_replace_advertiser_contact(): void
    {
        $this->start(array_replace($this->input,['phone'=>'0711111111','confirm_saved_phone'=>false]));$c=$this->settle();
        $this->assertSame('254711111111',$c->phone);$this->assertSame('254712345678',$c->client->phone_normalized);$this->assertSame('254712345678',PayLinkReceipt::first()->phone);
    }

    public function test_owned_status_requires_market_code_device_and_recovery_capability(): void
    {
        $result=$this->start();$input=$this->input+['recovery_token'=>$result['recovery_token']];
        $this->assertSame($result['charge_ref'],app(CheckoutService::class)->status($this->market,$result['charge_ref'],$input)['charge_ref']);
        foreach([['session_proof'=>str_repeat('b',64)],['recovery_token'=>'wrong'],['code'=>'00000000']]as$bad)$this->expectHttp(404,fn()=>app(CheckoutService::class)->status($this->market,$result['charge_ref'],array_replace($input,$bad)));
    }

    public function test_live_tier_switch_and_stale_revision_and_unconfirmed_saved_phone_are_refused(): void
    {
        $this->wpState['current_tier']='premium';$this->expectHttp(422,fn()=>$this->start());$this->wpState['current_tier']='vip';
        $this->expectHttp(409,fn()=>$this->start(array_replace($this->input,['config_revision'=>99])));
        $this->expectHttp(422,fn()=>$this->start(array_replace($this->input,['confirm_saved_phone'=>false])));
        $this->assertSame(0,PayLinkCharge::count());Http::assertNothingSent();
    }

    public function test_expired_profile_can_select_configured_tier_but_hidden_or_wrong_market_prices_cannot_start(): void
    {
        $this->wpState['live']=false;$this->wpState['current_tier']='premium';$this->wpState['escort_expire']=now()->subDay()->timestamp;
        $this->start();$this->assertSame('vip',$this->charge()->tier);
        $this->providerState='FAILED';$this->settle();$this->price->product->update(['is_public'=>false]);
        $this->expectHttp(422,fn()=>$this->start());
    }

    public function test_sandbox_needs_both_client_and_device_and_suppresses_all_live_effects(): void
    {
        $s=PayLinkSetting::first();$s->update(['rollout_mode'=>'test','test_client_ids'=>[$this->client->id],'test_device_hashes'=>[]]);
        $this->expectHttp(404,fn()=>$this->start());
        $s->update(['test_device_hashes'=>[hash('sha256',$this->input['session_proof'])]]);
        $this->expectHttp(409,fn()=>$this->start()); // No sandbox binding; cannot fall back to production.
        $profile=BillingProviderProfile::first();$profile->update(['environment'=>'sandbox']);
        $this->start();$c=$this->settle();$this->assertSame('suppressed_sandbox',$c->activation_state);$this->assertNull($c->blocking_client_id);
        $this->assertSame(0,PayLinkReceipt::count());$this->assertDatabaseCount('commissions',0);$this->wp->shouldNotHaveReceived('activatePayLink');
        Http::assertNotSent(fn($r)=>str_starts_with($r->url(),'https://api.pawapay.io'));
    }

    public function test_public_api_rejects_missing_site_authentication(): void
    {
        $this->postJson('/api/wp-svc/pay-link/resolve',$this->input)->assertUnauthorized();
        $this->postJson('/api/wp-svc/pay-link/charges',$this->input)->assertUnauthorized();
        $this->postJson('/api/wp-svc/pay-link/charges/'.Str::uuid().'/status',$this->input)->assertUnauthorized();
    }

    public function test_unknown_codes_and_revoked_codes_are_equally_inactive(): void
    {
        $this->expectHttp(404,fn()=>app(CheckoutService::class)->resolve($this->market,array_replace($this->input,['code'=>'00000000'])));
        app(LinkService::class)->revoke($this->client,1,'fixture revoke');
        $this->expectHttp(404,fn()=>app(CheckoutService::class)->resolve($this->market,$this->input));
    }

    public function test_transient_sms_exception_is_held_for_review_and_cannot_undo_activation(): void
    {
        $this->start();$c=$this->settle();$r=PayLinkReceipt::first();
        $this->mock(\App\Services\NotificationService::class)->shouldReceive('sendSms')->once()->andThrow(new \RuntimeException('fixture ambiguous send'));
        (new \App\Jobs\SendPayLinkReceipt($r->id))->handle();(new \App\Jobs\SendPayLinkReceipt($r->id))->handle();
        $this->assertSame('needs_review',$r->fresh()->status);$this->assertSame('applied',$c->fresh()->activation_state);$this->assertSame(1,$r->fresh()->attempt_count);
    }

    public function test_legacy_lookup_backfill_is_bounded_and_preserves_issued_token(): void
    {
        $this->start();$p=$this->charge()->payment;$token='fixture-legacy-token';$data=$p->payment_data;$data['link_proxy']=['token_hash'=>hash('sha256',$token),'mode'=>'proxy_hosted_checkout','provider_key'=>'pawapay','environment'=>'production','token_expires_at'=>now()->addHour()->toIso8601String(),'redirect_url'=>'https://payments.example.test/fixture'];$p->update(['payment_data'=>$data]);
        $this->artisan('billing:backfill-link-tokens')->assertFailed();$this->artisan('billing:backfill-link-tokens --apply')->assertSuccessful();
        $this->get('/api/payments/link/'.$token)->assertRedirect('https://payments.example.test/fixture');
        $queries=[];DB::listen(function($event)use(&$queries){if(str_contains($event->sql,'payments'))$queries[]=$event->sql;});
        $this->get('/api/payments/link/not-a-token')->assertNotFound();
        $this->assertTrue(collect($queries)->contains(fn($sql)=>str_contains($sql,'link_proxy_token_hash')));
        $this->assertFalse(collect($queries)->contains(fn($sql)=>str_contains($sql,'payment_data is not null')));
    }

    public function test_missing_saved_advertiser_phone_does_not_strand_a_paid_alternative_payer_activation(): void
    {
        $this->client->update(['phone_normalized'=>null]);
        $this->start(array_replace($this->input,['phone'=>'0711111111','confirm_saved_phone'=>false]));$c=$this->settle();
        $this->assertSame('applied',$c->activation_state);$this->assertNull($c->blocking_client_id);
        $this->assertSame('needs_review',PayLinkReceipt::first()->status);$this->assertSame('advertiser_contact_missing',PayLinkReceipt::first()->outcome);
    }

    public function test_unresolved_charge_blocks_other_lifecycle_writers_but_owns_its_activation(): void
    {
        $this->start();
        $this->expectHttp(409,fn()=>app(\App\Services\ClientLifecycleMutationLock::class)->run($this->client,fn()=>true));
        $this->expectHttp(409,fn()=>app(\App\Services\ClientLifecycleMutationLock::class)->runBounded([$this->client->id],fn()=>true));
        $this->expectHttp(409,fn()=>app(\App\Services\PayLink\WriterGuard::class)->assertClient($this->client->id));
        $this->assertSame('applied',$this->settle()->activation_state);
        $this->assertTrue(app(\App\Services\ClientLifecycleMutationLock::class)->run($this->client,fn()=>true));
    }

    public function test_anonymous_deal_keeps_client_assignment_and_null_timeline_actor(): void
    {
        $staff=\App\Models\User::factory()->create(['role'=>'sales']);$this->client->update(['assigned_to'=>$staff->id]);
        $this->start();$deal=Deal::find($this->charge()->deal_id);
        $this->assertSame($staff->id,$deal->assigned_to);
        $this->assertNull(\App\Models\TimelineEvent::where('entity_type','deal')->where('entity_id',$deal->id)->where('event_type','deal_created')->value('actor_id'));
    }

    public function test_an_existing_unresolved_external_subscription_payment_blocks_a_new_prompt(): void
    {
        Payment::create(['platform_id'=>$this->market->id,'client_id'=>$this->client->id,'user_id'=>$this->client->wp_user_id,'product_id'=>$this->price->product_id,
            'amount'=>'1500.00','currency'=>'KES','purpose'=>'subscription','status'=>'pending','source'=>'self_checkout','provider_key'=>'pawapay','transaction_uuid'=>(string)Str::uuid(),'reference_number'=>'FIXTURE-EXTERNAL']);
        $this->expectHttp(409,fn()=>$this->start());$this->assertSame(0,PayLinkCharge::count());Http::assertNothingSent();
    }

    public function test_legacy_wp_user_only_creation_and_retry_cannot_bypass_charge_ownership(): void
    {
        $legacy=Payment::create(['platform_id'=>$this->market->id,'user_id'=>$this->client->wp_user_id,'product_id'=>$this->price->product_id,'amount'=>'1500.00','currency'=>'KES',
            'purpose'=>'subscription','status'=>'failed','transaction_uuid'=>(string)Str::uuid(),'reference_number'=>'FIXTURE-LEGACY']);
        $this->start();
        $this->expectHttp(409,fn()=>app(\App\Services\PayLink\WriterGuard::class)->prepareDispatch($legacy));
        $this->expectHttp(409,fn()=>Payment::create(['platform_id'=>$this->market->id,'user_id'=>$this->client->wp_user_id,'product_id'=>$this->price->product_id,'amount'=>'1500.00','currency'=>'KES',
            'purpose'=>'subscription','status'=>'initiated','transaction_uuid'=>(string)Str::uuid(),'reference_number'=>'FIXTURE-LEGACY-2']));
        $this->assertSame(1,$this->depositCalls);
    }

    public function test_legacy_inflight_dispatch_without_provider_or_source_is_visible_to_new_charge_gates(): void
    {
        $legacy=Payment::create(['platform_id'=>$this->market->id,'user_id'=>$this->client->wp_user_id,'product_id'=>$this->price->product_id,'amount'=>'1500.00','currency'=>'KES',
            'purpose'=>'subscription','status'=>'initiated','transaction_uuid'=>(string)Str::uuid(),'reference_number'=>'FIXTURE-INFLIGHT']);
        app(\App\Services\PayLink\WriterGuard::class)->prepareDispatch($legacy);
        $this->expectHttp(409,fn()=>$this->start());$this->assertSame(0,PayLinkCharge::count());Http::assertNothingSent();
    }

    public function test_permanent_link_only_lifecycle_template_creates_no_pending_payment_or_deal(): void
    {
        $this->client->update(['needs_payment'=>true,'notactive'=>true,'escort_expire'=>now()->subDay()->timestamp,'signup_source'=>'fast_signup']);
        app(\App\Services\LifecycleSmsSettingsService::class)->saveConfig(['enabled'=>true,'markets'=>[(string)$this->market->id=>['sms_enabled'=>true,'onboarding'=>['enabled'=>true]]]]);
        $template=\App\Models\Template::create(['platform_id'=>$this->market->id,'title'=>'Permanent fixture','category'=>'welcome','channel'=>'sms','body'=>'Hi {{first_name}}, renew here: {{pay_link}}','status'=>'active']);
        $this->mock(\App\Services\NotificationService::class)->shouldReceive('sendSmsToClient')->once()->withArgs(fn($client,$body)=>str_contains($body,'/pay/'.$this->input['code']))->andReturn(['success'=>true,'status'=>'sent']);
        $result=app(\App\Services\LifecycleSmsService::class)->send('onboarding',$this->client,['source'=>'manual']);
        $this->assertSame('sent',$result['status'],json_encode($result));$this->assertSame(0,Payment::count());$this->assertSame(0,Deal::count());
        $this->assertSame($template->id,app(\App\Services\LifecycleSmsService::class)->resolveTemplate('onboarding',$this->market->id)->id);
    }

    public function test_permanent_renewal_variable_keeps_legacy_template_behavior_separate(): void
    {
        $deal=Deal::create(['platform_id'=>$this->market->id,'client_id'=>$this->client->id,'product_id'=>$this->price->product_id,'product_price_id'=>$this->price->id,
            'plan_type'=>'vip','amount'=>'1500.00','currency'=>'KES','duration'=>'weekly','duration_days'=>7,'status'=>'active']);
        $template=\App\Models\Template::create(['title'=>'Permanent renewal fixture','category'=>'renewal','channel'=>'sms','body'=>'Renew {{pay_link}}','status'=>'active']);
        $prepared=app(\App\Services\LifecycleSmsService::class)->prepareRenewalLink($deal,$template);
        $this->assertNull($prepared['payment']);$this->assertSame('permanent',$prepared['link_mode']);$this->assertStringContainsString('/pay/',$prepared['variables']['pay_link']);$this->assertSame(0,Payment::count());
        PayLinkSetting::first()->update(['rollout_mode'=>'off']);
        $this->assertSame('unavailable',app(\App\Services\LifecycleSmsService::class)->prepareRenewalLink($deal,$template)['link_mode']);
    }
}
