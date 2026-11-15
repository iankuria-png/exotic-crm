<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\{Platform, PayLinkCharge, PayLinkReceipt, ProductPrice};
use App\Services\{AuditService, MarketAuthorizationService};
use App\Services\PayLink\{SettingsService, ReconciliationService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayLinkSettingsController extends Controller
{
    private function authorizeMarket(Request $r, int $id): void
    {
        $a=app(MarketAuthorizationService::class);$a->ensureRole($r->user(),['admin','sub_admin']);$a->ensureUserCanAccessPlatform($r->user(),$id);
    }

    public function show(Request $r, Platform $platform)
    {
        $this->authorizeMarket($r,$platform->id);
        $settings=app(SettingsService::class)->forPlatform($platform);
        return response()->json(['settings'=>$settings,'catalogue'=>ProductPrice::with('product:id,name,display_name,tier')->where('is_active',true)->where('price','>',0)
            ->whereHas('product',fn($q)=>$q->where('platform_id',$platform->id)->where('is_active',true)->where('is_public',true)->where('is_archived',false))->orderBy('product_id')->orderBy('sort_order')->get(),
            'charges'=>PayLinkCharge::where('platform_id',$platform->id)->orderByDesc('id')->limit(100)->get(),
            'receipts'=>PayLinkReceipt::whereIn('charge_id',PayLinkCharge::where('platform_id',$platform->id)->select('id'))->orderByDesc('id')->limit(100)->get(),
            'counts'=>['starts'=>PayLinkCharge::where('platform_id',$platform->id)->count(),'paid'=>PayLinkCharge::where('platform_id',$platform->id)->where('payment_state','completed')->count(),
                'unresolved'=>PayLinkCharge::where('platform_id',$platform->id)->whereNotNull('blocking_client_id')->count()]]);
    }

    public function save(Request $r, Platform $platform)
    {
        $this->authorizeMarket($r,$platform->id);
        return response()->json(['settings'=>app(SettingsService::class)->save($platform,$r->all(),$r->user()->id)]);
    }

    public function pairing(Request $r, Platform $platform)
    {
        $this->authorizeMarket($r,$platform->id);abort_unless(app(SettingsService::class)->forPlatform($platform)->rollout_mode==='test',409,'Enable test mode first.');
        $token=bin2hex(random_bytes(16));DB::table('pay_link_device_pairings')->insert(['platform_id'=>$platform->id,'token_hash'=>hash('sha256',$token),'created_by_user_id'=>$r->user()->id,
            'expires_at'=>now()->addMinutes(10),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['pairing_token'=>$token,'expires_in_seconds'=>600])->header('Cache-Control','no-store');
    }

    public function recheck(Request $r, PayLinkCharge $charge)
    {
        $this->authorizeMarket($r,$charge->platform_id);$d=$r->validate(['reason'=>'required|string|min:5|max:1000']);
        DB::transaction(function()use($r,$charge,$d){
            $row=PayLinkCharge::whereKey($charge->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AuditService::class)->record(['platform_id'=>$row->platform_id,'entity_type'=>'pay_link_charge','entity_id'=>$row->id,'actor_id'=>$r->user()->id,
                'action'=>'recheck_requested','reason'=>$d['reason'],'before_state'=>['payment_state'=>$row->payment_state,'activation_state'=>$row->activation_state,'review_reason'=>$row->review_reason]]),503);
            if(!$row->final_at)$row->update(['activation_state'=>$row->payment_state==='completed'?'pending':'not_started','review_reason'=>null,'next_reconcile_at'=>now()]);
        });
        app(ReconciliationService::class)->reconcile($charge->id);
        return response()->json(['charge'=>$charge->fresh()]);
    }
}
