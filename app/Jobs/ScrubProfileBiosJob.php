<?php

namespace App\Jobs;

use App\Services\FeatureSettingsService;
use App\Services\ProfileBioScrubService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backs the "Scrub expired bios" button. Each profile costs at least one
 * WordPress read, so a market-wide sweep is far too slow for a web request.
 * The outcome is stashed as a settings blob and surfaced on the market card.
 */
class ScrubProfileBiosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public readonly int $platformId,
        public readonly bool $dryRun = true,
        public readonly int $limit = 500,
        public readonly ?int $actorId = null,
    ) {
    }

    public static function settingsKey(int $platformId): string
    {
        return "lifecycle.bio_scrub.last_run.{$platformId}";
    }

    public function handle(ProfileBioScrubService $scrubber, FeatureSettingsService $settings): void
    {
        // One sweep per market at a time: overlapping runs would double up the
        // WordPress writes for no benefit.
        $lock = cache()->lock("bio-scrub-platform-{$this->platformId}", 1800);
        if (! $lock->get()) {
            Log::info('Bio scrub already running for platform', ['platform_id' => $this->platformId]);

            return;
        }

        try {
            $summary = $scrubber->runBatch($this->platformId, $this->limit, $this->dryRun, $this->actorId);

            $settings->set(self::settingsKey($this->platformId), array_merge($summary, [
                'dry_run' => $this->dryRun,
                'limit' => $this->limit,
                'finished_at' => now()->toDateTimeString(),
            ]), $this->actorId);
        } catch (\Throwable $exception) {
            Log::error('Bio scrub sweep failed', [
                'platform_id' => $this->platformId,
                'error' => $exception->getMessage(),
            ]);

            $settings->set(self::settingsKey($this->platformId), [
                'error' => $exception->getMessage(),
                'dry_run' => $this->dryRun,
                'finished_at' => now()->toDateTimeString(),
            ], $this->actorId);
        } finally {
            $lock->release();
        }
    }
}
