<?php

namespace App\Jobs\Kyc;

use App\Models\KycSubjectSite;
use App\Services\Kyc\KycStatusFanoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushKycStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [1, 2, 4, 8, 16];

    public function __construct(public readonly int $siteId)
    {
        $this->onQueue('kyc-fanout');
    }

    public function handle(KycStatusFanoutService $fanoutService): void
    {
        $site = KycSubjectSite::query()->findOrFail($this->siteId);
        $fanoutService->pushTo($site);
    }

    public function failed(\Throwable $exception): void
    {
        KycSubjectSite::query()->whereKey($this->siteId)->update([
            'last_sync_status' => 'failed',
            'last_sync_error' => $exception->getMessage(),
        ]);
    }
}
