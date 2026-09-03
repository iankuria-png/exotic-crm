<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Jobs\Concerns\Sheddable;
use App\Models\PbnSeedBatch;
use App\Services\Pbn\PbnSeedProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunPbnSeedBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RunsOnHeavyQueue, Sheddable;

    public int $tries = 2;
    public int $timeout = 3600;
    public array $backoff = [30];

    public function __construct(
        public readonly int $batchId,
    ) {
        $this->routeToHeavyQueue();
    }

    public function shedCapability(): string
    {
        return 'pbn_seed';
    }

    public function handle(PbnSeedProvisioningService $provisioningService): void
    {
        // Stand down while the platform is shedding load. The job is
        // released with a delay rather than failed, so the work is
        // deferred and the worker process is freed immediately.
        if ($this->shedIfDegraded()) {
            return;
        }

        $batch = PbnSeedBatch::query()->with('pbnSite')->find($this->batchId);
        if (!$batch || in_array($batch->status, [PbnSeedBatch::STATUS_COMPLETED, PbnSeedBatch::STATUS_CANCELLED], true)) {
            return;
        }

        $lock = cache()->lock(sprintf('pbn-seed-site-%d', (int) $batch->pbn_site_id), 3600);
        if (!$lock->get()) {
            Log::warning('PBN seed job skipped because another seed is already running for this site.', [
                'batch_id' => (int) $batch->id,
                'pbn_site_id' => (int) $batch->pbn_site_id,
            ]);

            return;
        }

        try {
            $provisioningService->execute($batch);
        } finally {
            $lock->release();
        }
    }
}
