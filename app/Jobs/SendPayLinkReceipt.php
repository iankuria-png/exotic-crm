<?php

namespace App\Jobs;

use App\Models\PayLinkReceipt;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class SendPayLinkReceipt
{
    public function __construct(public readonly int $id) {}

    public function handle(): void
    {
        $receipt=DB::transaction(function(){
            $r=PayLinkReceipt::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if($r->status!=='pending'||($r->next_attempt_at&&$r->next_attempt_at->isFuture()))return null;
            $c=$r->charge;
            if($c->environment!=='production'||$c->activation_state!=='applied'){ $r->update(['status'=>'needs_review','outcome'=>'activation_prerequisite']);return null; }
            // A crash after this commit is ambiguous: never automatically resend a possibly delivered receipt.
            $r->update(['status'=>'sending','attempt_count'=>$r->attempt_count+1,'next_attempt_at'=>null]);return $r;
        });
        if(!$receipt)return;
        try {
            $c=$receipt->charge;
            $result=app(NotificationService::class)->sendSms($receipt->phone,$receipt->body,['platform_id'=>$c->platform_id,'client_id'=>$c->client_id,'purpose'=>'pay_link_receipt','template_key'=>'pay_link_receipt','skip_fallback'=>true]);
            $disabled=($result['status']??'')==='disabled';
            $sent=!$disabled&&!empty($result['success']);
            $receipt->update(['status'=>$disabled?'pending':($sent?'sent':'needs_review'),'outcome'=>$disabled?'transport_disabled':($sent?'accepted_by_transport':'delivery_unconfirmed'),
                'next_attempt_at'=>$disabled?now()->addHour():null]);
        }catch(\Throwable){$receipt->update(['status'=>'needs_review','outcome'=>'ambiguous_transport_result']);}
    }
}
