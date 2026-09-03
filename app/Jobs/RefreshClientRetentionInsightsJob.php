<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Jobs\Concerns\Sheddable;
use App\Models\Client;
use App\Services\ClientRetentionInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshClientRetentionInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RunsOnHeavyQueue, Sheddable;

    public int $tries = 2;
    public int $timeout = 900;

    public function __construct(
        public readonly int $platformId,
        public readonly string $syncedAfter,
    ) {
        $this->routeToHeavyQueue();
    }

    public function shedCapability(): string
    {
        return 'retention_insights';
    }

    public function handle(ClientRetentionInsightService $clientRetentionInsightService): void
    {
        // Stand down while the platform is shedding load. The job is
        // released with a delay rather than failed, so the work is
        // deferred and the worker process is freed immediately.
        if ($this->shedIfDegraded()) {
            return;
        }

        $clientIds = Client::query()
            ->where('platform_id', $this->platformId)
            ->whereNotNull('last_synced_at')
            ->where('last_synced_at', '>=', $this->syncedAfter)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $clientRetentionInsightService->refreshForClientIds($clientIds);
    }
}
