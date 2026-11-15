<?php

namespace App\Console\Commands;

use App\Models\{PayLinkCharge, PayLinkReceipt};
use App\Services\PayLink\ReconciliationService;
use Illuminate\Console\Command;

class ReconcilePayLinks extends Command
{
    protected $signature='pay-links:reconcile {--limit=10}';
    protected $description='Bounded reconciliation of original pay-link deposits, activations and receipts';

    public function handle(): int
    {
        $limit=max(1,min(20,(int)$this->option('limit')));
        $deadline=microtime(true)+40;$processed=0;
        foreach(PayLinkCharge::whereNull('final_at')->whereNotNull('next_reconcile_at')->where('next_reconcile_at','<=',now())->orderBy('next_reconcile_at')->limit($limit)->pluck('id')as$id){
            if(microtime(true)>$deadline)break;
            app(ReconciliationService::class)->reconcile($id);$processed++;
        }
        PayLinkReceipt::where('status','sending')->where('updated_at','<',now()->subMinutes(5))->update(['status'=>'needs_review','outcome'=>'worker_interrupted_after_dispatch']);
        foreach(PayLinkReceipt::where('status','pending')->where('next_attempt_at','<=',now())->limit($limit)->pluck('id')as$id){if(microtime(true)>$deadline)break;(new \App\Jobs\SendPayLinkReceipt($id))->handle();}
        $this->line(json_encode(['reconciled'=>$processed,'paid_needs_review'=>PayLinkCharge::where('payment_state','completed')->where('activation_state','needs_review')->count(),
            'uncertain_over_15m'=>PayLinkCharge::whereNotNull('blocking_client_id')->where('payment_state','!=','completed')->where('created_at','<',now()->subMinutes(15))->count()]));
        return self::SUCCESS;
    }
}
