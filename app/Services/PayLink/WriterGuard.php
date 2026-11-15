<?php

namespace App\Services\PayLink;

use App\Models\PayLinkCharge;
use App\Models\{Client, Payment};
use App\Exceptions\ClientLifecycleMutationException;
use Closure;
use Illuminate\Support\Facades\Schema;

/** Request-scoped ownership; elapsed cache leases never release unsettled money. */
class WriterGuard
{
    private ?int $ownedCharge = null;
    private ?int $ownedClient = null;

    public function ownedCharge(PayLinkCharge $charge, Closure $callback): mixed
    {
        $previous = [$this->ownedCharge, $this->ownedClient];
        $saved = PayLinkCharge::findOrFail($charge->id);
        if ((int)$saved->client_id !== (int)$charge->client_id || (int)$saved->payment_id !== (int)$charge->payment_id) throw ClientLifecycleMutationException::busy();
        $this->ownedCharge = (int)$saved->id; $this->ownedClient = (int)$saved->client_id;
        try { return $callback(); } finally { [$this->ownedCharge, $this->ownedClient] = $previous; }
    }

    public function assertClient(int $clientId): void
    {
        app(\App\Services\FlexibleAmounts\FlexibleAttemptGuard::class)->assertClient($clientId);
        if (!Schema::hasTable('pay_link_charges')) return; // Additive deployment, before migration.
        $blocking = PayLinkCharge::where('blocking_client_id',$clientId)->value('id');
        if ($blocking && ((int)$blocking !== $this->ownedCharge || $clientId !== $this->ownedClient)) throw ClientLifecycleMutationException::busy();
    }

    public function paymentClient(Payment $payment): ?Client
    {
        if ($payment->client_id) return Client::where('platform_id',$payment->platform_id)->find($payment->client_id);
        return $payment->user_id ? Client::where('platform_id',$payment->platform_id)->where('wp_user_id',$payment->user_id)->first() : null;
    }

    public function assertNoExternalFinancialWork(Client $client): void
    {
        $pending=Payment::where('platform_id',$client->platform_id)->where(fn($q)=>$q->where('client_id',$client->id)->orWhere(fn($q)=>$q->whereNull('client_id')->where('user_id',$client->wp_user_id)))
            ->where(fn($q)=>$q->where('purpose','subscription')->orWhereNull('purpose'))->whereIn('status',['initiated','pending','awaiting_payment'])
            ->where(fn($q)=>$q->whereNotNull('provider_key')->orWhereIn('source',['self_checkout','deal_payment_initiation','crm_lifecycle','crm_activation'])->orWhereNotNull('payment_data->pay_link_dispatch_reserved_at'))->exists();
        $wallet=false;
        if(Schema::hasTable('crm_wallet_operations')){
            abort_unless(Schema::hasColumns('crm_wallet_operations',['platform_id','client_id','kind','confirmed_at','status']),409,'A financial reservation needs a team check.');
            $wallet=\Illuminate\Support\Facades\DB::table('crm_wallet_operations')->where('platform_id',$client->platform_id)->where('client_id',$client->id)
                ->whereIn('kind',['subscription','reservation'])->whereNotNull('confirmed_at')->whereIn('status',['initiating','pending','unknown','provisioning','needs_review'])->exists();
        }
        abort_if($pending||$wallet,409,'An earlier subscription payment needs checking before starting another.');
    }

    /** Legacy retries must publish pending ownership before any new provider request. */
    public function prepareDispatch(\App\Models\Payment $payment): void
    {
        if (!$payment->exists || !in_array($payment->purpose, [null, 'subscription'], true) || $payment->source === 'self_service_pay_link'
            || !Schema::hasTable('pay_link_charges')) return;
        $client = $this->paymentClient($payment);
        if (!$client) return;
        \Illuminate\Support\Facades\DB::transaction(function () use ($client, $payment) {
            \App\Models\Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->assertClient((int)$client->id);
            $current=\App\Models\Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($current->status,['initiated','pending','awaiting_payment','failed'],true),409,'This payment cannot start another provider request.');
            $current->update(['status'=>'pending','payment_data'=>array_merge($current->payment_data??[],['pay_link_dispatch_reserved_at'=>now()->toIso8601String()])]);
        });
    }
}
