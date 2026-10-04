<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? '') !== '--apply-local-qa') throw new RuntimeException('Use --apply-local-qa: creates sandbox fixture payments and revisions.');
if (!app()->environment('local')) throw new RuntimeException('Local only.');
$c=App\Models\Client::with('platform')->findOrFail(2514);
$s=app(App\Services\Rebates\RebateProgramService::class)->forPlatform($c->platform);
if($s->rollout_mode!=='sandbox'||$s->test_client_ids!==[2514])throw new RuntimeException('Sandbox fixture only.');
$before=App\Models\WalletTransaction::where('client_id',2514)->where('reference_type','wallet_rebate')->count();
$balance=app(App\Services\WalletService::class)->balanceFor($c,'KES');
function payment($c,$purpose,$channel,$amount){return App\Models\Payment::create(['platform_id'=>$c->platform_id,'client_id'=>$c->id,'amount'=>$amount,'currency'=>'KES','purpose'=>$purpose,'source'=>$channel==='topup'?'gateway':'crm_lifecycle','provider_environment'=>'sandbox','provider_key'=>'kopokopo','transaction_uuid'=>(string)Illuminate\Support\Str::uuid(),'status'=>'pending','payment_data'=>['initiator'=>$channel==='topup'?'companion':'staff_link','test_mode'=>true,'qa_wallet_rebates'=>true]]);}
$topup=payment($c,'wallet_topup','topup',5000);app(App\Services\PaymentCompletionService::class)->complete($topup);
$link=payment($c,'subscription','staff_link',6000);app(App\Services\PaymentCompletionService::class)->complete($link);
$rows=App\Models\WalletRebate::whereIn('source_payment_id',[$topup->id,$link->id])->get();
if($rows->count()!==2||$rows->where('status','simulated')->count()!==2||$rows->where('channel','staff_link')->first()->amount!='120.00')throw new RuntimeException('Sandbox grant mismatch.');
$svc=app(App\Services\Rebates\RebateProgramService::class);$d=$s->fresh()->draft_json;$d['self']['channels']['staff_link']['on']=false;$current=$svc->save($c->platform,$d,$s->fresh()->draft_revision,$s->updated_by);$svc->publish($c->platform,$current->draft_revision,'Sandbox channel-off contract QA',$s->updated_by);
$off=payment($c,'subscription','staff_link',6000);app(App\Services\PaymentCompletionService::class)->complete($off);
$row=App\Models\WalletRebate::where('source_payment_id',$off->id)->firstOrFail();if($row->status!=='skipped'||$row->reason!=='channel_off')throw new RuntimeException('Channel-off mismatch.');
$d['self']['channels']['staff_link']['on']=true;$current=$svc->save($c->platform,$d,$s->fresh()->draft_revision,$s->updated_by);$svc->publish($c->platform,$current->draft_revision,'Restore sandbox defaults after contract QA',$s->updated_by);
$sync=app(App\Services\WalletSyncService::class)->syncPlatformConfig($c->platform,$c);$bs=app(App\Services\WalletSyncService::class)->syncClientBalance($c);
if($balance!==app(App\Services\WalletService::class)->balanceFor($c,'KES')||$before!==App\Models\WalletTransaction::where('client_id',2514)->where('reference_type','wallet_rebate')->count())throw new RuntimeException('Sandbox changed money.');
echo json_encode(['rows'=>$rows->map->only(['source_payment_id','status','channel','amount'])->values(),'channel_off'=>['payment'=>$off->id,'status'=>$row->status,'reason'=>$row->reason],'config_sync'=>$sync['status'],'balance_sync'=>$bs['status'],'wallet_unchanged'=>true,'revision'=>$s->fresh()->published_revision]);
