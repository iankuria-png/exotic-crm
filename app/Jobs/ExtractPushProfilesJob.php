<?php

namespace App\Jobs;

use App\Models\Platform;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Services\PushCampaign\ProfileExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExtractPushProfilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly int $campaignId,
        public readonly int $platformId,
        public readonly ?string $batchId = null,
    ) {
    }

    public function handle(ProfileExtractionService $profileExtractionService): void
    {
        $campaign = PushCampaign::query()->find($this->campaignId);
        $platform = Platform::query()->find($this->platformId);

        if (!$campaign || !$platform) {
            return;
        }

        $campaign->forceFill(['status' => 'processing'])->save();

        $processed = 0;

        while (true) {
            $items = PushCampaignItem::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', 'pending_extraction')
                ->orderBy('id')
                ->limit(100)
                ->get();

            if ($items->isEmpty()) {
                break;
            }

            $profileExtractionService->extractProfileBatch($items, $platform);
            $chunkProcessed = $items->count();
            $processed += $chunkProcessed;

            $this->updateBatchStatus('extracting', $chunkProcessed);
        }

        $total = PushCampaignItem::query()->where('campaign_id', $campaign->id)->count();
        $needsPreset = PushCampaignItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'needs_preset')
            ->count();

        $pendingExtraction = PushCampaignItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending_extraction')
            ->count();

        $campaign->forceFill([
            'total_items' => $total,
            'status' => $pendingExtraction > 0 ? 'processing' : 'draft',
        ])->save();

        if ($pendingExtraction === 0) {
            $this->updateBatchStatus('ready', 0, [
                'needs_preset_count' => $needsPreset,
                'profiles_processed' => $processed,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractPushProfilesJob failed', [
            'campaign_id' => $this->campaignId,
            'platform_id' => $this->platformId,
            'error' => $exception->getMessage(),
        ]);

        $this->updateBatchStatus('failed', 0, [
            'error' => $exception->getMessage(),
        ]);
    }

    private function updateBatchStatus(string $status, int $processed, array $extra = []): void
    {
        $batchId = $this->batchId;

        if (!$batchId) {
            $campaign = PushCampaign::query()->find($this->campaignId);
            $batchId = $campaign?->upload_batch_id;
        }

        if (!$batchId) {
            return;
        }

        $key = 'push_upload:' . $batchId;
        $current = Cache::get($key, []);

        if (!is_array($current)) {
            $current = [];
        }

        $campaignIds = array_values(array_filter(array_map('intval', (array) ($current['campaign_ids'] ?? []))));
        $allDone = false;

        if (!empty($campaignIds)) {
            $remaining = PushCampaign::query()
                ->whereIn('id', $campaignIds)
                ->where('status', 'processing')
                ->count();
            $allDone = $remaining === 0;
        }

        $effectiveStatus = $status;
        if ($status === 'ready' && !$allDone) {
            $effectiveStatus = 'extracting';
        }

        $payload = array_merge($current, [
            'batch_id' => $batchId,
            'status' => $effectiveStatus,
            'profiles_processed' => ((int) ($current['profiles_processed'] ?? 0)) + $processed,
            'updated_at' => now()->toDateTimeString(),
        ], $extra);

        Cache::put($key, $payload, now()->addHours(12));
    }
}
