<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Services\FeatureSettingsService;
use App\Services\ProfileLifecycleRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RollbackLifecycleProfilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RunsOnHeavyQueue;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $platformId,
        public readonly ?int $actorId = null,
        public readonly string $reason = 'lifecycle_disabled',
    ) {
        $this->routeToHeavyQueue();
    }

    public static function settingsKey(int $platformId): string
    {
        return "lifecycle.rollback.last_run.{$platformId}";
    }

    public function handle(ProfileLifecycleRestoreService $restorer, FeatureSettingsService $settings): void
    {
        $lock = cache()->lock("lifecycle-rollback-platform-{$this->platformId}", 3600);

        if (! $lock->get()) {
            Log::info('Lifecycle rollback already running for platform', [
                'platform_id' => $this->platformId,
            ]);

            return;
        }

        try {
            $summary = $restorer->rollbackMarket($this->platformId, $this->actorId, $this->reason);

            $settings->set(self::settingsKey($this->platformId), array_merge($summary, [
                'reason' => $this->reason,
                'finished_at' => now()->toDateTimeString(),
            ]), $this->actorId);
        } catch (\Throwable $exception) {
            Log::error('Lifecycle rollback failed', [
                'platform_id' => $this->platformId,
                'reason' => $this->reason,
                'error' => $exception->getMessage(),
            ]);

            $settings->set(self::settingsKey($this->platformId), [
                'reason' => $this->reason,
                'error' => $exception->getMessage(),
                'finished_at' => now()->toDateTimeString(),
            ], $this->actorId);

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
