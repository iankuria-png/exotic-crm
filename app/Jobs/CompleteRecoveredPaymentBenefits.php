<?php

namespace App\Jobs;

use App\Models\Deal;
use App\Models\Platform;
use App\Services\ClientSyncService;
use App\Services\CommissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompleteRecoveredPaymentBenefits implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public function __construct(public int $dealId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $deal = Deal::with('client')->findOrFail($this->dealId);
        app(CommissionService::class)->recordActivationCommission($deal);
        GrantNewSubscriptionPass::dispatch($deal->id);
        (new ClientSyncService(Platform::findOrFail($deal->platform_id)))->syncOne($deal->client->wp_post_id);
    }
}
