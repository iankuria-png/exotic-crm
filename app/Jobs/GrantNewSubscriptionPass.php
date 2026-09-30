<?php

namespace App\Jobs;

use App\Services\Monetization\ComplimentaryPassService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs after activation commits so a pass grant can never roll back a paid activation. */
class GrantNewSubscriptionPass implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $dealId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'monetize-new-subscription-pass:'.$this->dealId;
    }

    public function handle(ComplimentaryPassService $passes): void
    {
        $passes->grantForNewSubscription($this->dealId);
    }
}
