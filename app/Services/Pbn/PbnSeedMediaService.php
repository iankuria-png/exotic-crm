<?php

namespace App\Services\Pbn;

use App\Models\PbnSeedBatch;
use App\Models\PbnSeedEvent;
use App\Models\PbnSeedItem;
use App\Services\ClientProfileImageService;
use App\Services\WpSyncService;
use App\Support\WordPressSiteConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PbnSeedMediaService
{
    private const DEFAULT_ITEMS_PER_RUN = 5;
    private const MAX_ITEMS_PER_RUN = 20;
    private const MAX_MEDIA_PER_PROFILE = 10;

    public function __construct(
        private readonly ClientProfileImageService $imageService,
        private readonly PbnSeedPreviewService $previewService
    ) {
    }

    public function processBatch(PbnSeedBatch $batch, int $limit = self::DEFAULT_ITEMS_PER_RUN, ?int $actorId = null): array
    {
        $batch->loadMissing('pbnSite');
        $site = $batch->pbnSite;
        if (!$site || !filled($site->wp_api_url) || !filled($site->wp_api_user) || !filled($site->wp_api_password)) {
            throw ValidationException::withMessages([
                'pbn_site_id' => 'PBN REST credentials are required before copying pending media.',
            ]);
        }

        $limit = max(1, min(self::MAX_ITEMS_PER_RUN, $limit));
        $items = $batch->items()
            ->where('status', PbnSeedItem::STATUS_MEDIA_PENDING)
            ->whereNotNull('target_wp_post_id')
            ->with(['batch.pbnSite', 'sourceClient.platform'])
            ->orderByRaw('case when failure_reason is not null then 0 else 1 end')
            ->orderBy('provision_finished_at')
            ->limit($limit)
            ->get();

        $result = [
            'requested' => $limit,
            'processed' => 0,
            'copied' => 0,
            'attention' => 0,
            'skipped' => 0,
        ];

        foreach ($items as $item) {
            $outcome = $this->processItem($item, $actorId);
            $result['processed']++;
            $result[$outcome] = ($result[$outcome] ?? 0) + 1;
        }

        $this->previewService->refreshBatchCounts($batch->fresh(['targets']));
        $fresh = $batch->fresh();

        return [
            ...$result,
            'media' => $this->batchMediaSummary($fresh),
            'batch' => $fresh,
            'message' => $result['copied'] > 0
                ? "{$result['copied']} pending media profile(s) copied."
                : 'No pending media profiles were copied.',
        ];
    }

    public function processItem(PbnSeedItem $item, ?int $actorId = null): string
    {
        $item->loadMissing(['batch.pbnSite', 'sourceClient.platform']);

        if ($item->status !== PbnSeedItem::STATUS_MEDIA_PENDING || !$item->target_wp_post_id) {
            return 'skipped';
        }

        $batch = $item->batch;
        $site = $batch?->pbnSite;
        if (!$batch || !$site || !$item->sourceClient?->platform) {
            $this->markMediaAttention($item, 'PBN site or source profile link is missing.', $actorId);

            return 'attention';
        }

        try {
            $sourceMediaPayload = WpSyncService::forPlatform((int) $item->source_platform_id)
                ->getClientMedia((int) $item->source_wp_post_id);
            $sourceMedia = array_slice($this->imageService->normalizeMediaItems($sourceMediaPayload), 0, self::MAX_MEDIA_PER_PROFILE);
            if ($sourceMedia === []) {
                throw new \RuntimeException('No image media was returned by the source WordPress profile.');
            }

            $destinationSync = new WpSyncService(WordPressSiteConnection::fromPbnSite($site));
            $uploaded = 0;
            foreach ($sourceMedia as $index => $media) {
                [$uploadedFile, $temporaryPath] = $this->downloadMedia($item, $media);
                try {
                    $destinationSync->uploadClientMedia(
                        (int) $item->target_wp_post_id,
                        $uploadedFile,
                        (bool) ($media['is_main'] ?? false) || $index === 0
                    );
                    $uploaded++;
                } finally {
                    if (is_file($temporaryPath)) {
                        @unlink($temporaryPath);
                    }
                }
            }

            if ($uploaded < 1) {
                throw new \RuntimeException('No media uploads were accepted by the destination WordPress site.');
            }

            $item->forceFill([
                'status' => PbnSeedItem::STATUS_CREATED,
                'failure_reason' => null,
                'provision_finished_at' => now(),
            ])->save();

            $this->recordEvent($batch, $item, 'item_media_copied', 'info', 'PBN seed item media copied to destination WordPress.', [
                'uploaded_count' => $uploaded,
                'source_media_count' => count($sourceMedia),
                'target_wp_post_id' => (int) $item->target_wp_post_id,
            ], $actorId);

            return 'copied';
        } catch (\Throwable $exception) {
            $this->markMediaAttention($item, $exception->getMessage(), $actorId);

            return 'attention';
        }
    }

    public function batchMediaSummary(PbnSeedBatch $batch): array
    {
        $pendingQuery = $batch->items()->where('status', PbnSeedItem::STATUS_MEDIA_PENDING);
        $pendingCount = (clone $pendingQuery)->count();
        $attentionCount = (clone $pendingQuery)->whereNotNull('failure_reason')->count();
        $oldestPendingAt = (clone $pendingQuery)->oldest('provision_finished_at')->value('provision_finished_at');
        $estimatedSeconds = $pendingCount * 30;

        return [
            'pending_count' => $pendingCount,
            'attention_count' => $attentionCount,
            'ready_count' => $batch->items()->where('status', PbnSeedItem::STATUS_CREATED)->count(),
            'oldest_pending_at' => $oldestPendingAt ? (string) $oldestPendingAt : null,
            'eta_label' => $pendingCount > 0
                ? $this->etaLabel($estimatedSeconds)
                : 'No pending media',
            'reason' => $pendingCount > 0
                ? 'Profiles were created with two-stage media copy enabled; media is waiting for the copy pass.'
                : 'All created profiles have cleared the media step.',
            'next_action' => $attentionCount > 0
                ? 'Retry media copy, then inspect profiles that remain flagged.'
                : ($pendingCount > 0 ? 'Process the next pending media profiles.' : 'No media action needed.'),
            'can_process' => $pendingCount > 0,
            'process_limit' => self::DEFAULT_ITEMS_PER_RUN,
            'max_process_limit' => self::MAX_ITEMS_PER_RUN,
        ];
    }

    public function itemMediaState(PbnSeedItem $item): ?array
    {
        if ($item->status !== PbnSeedItem::STATUS_MEDIA_PENDING) {
            return null;
        }

        return [
            'reason' => $item->failure_reason
                ?: 'Profile was created; source media has not been copied to the destination profile yet.',
            'pending_since' => optional($item->provision_finished_at ?: $item->updated_at)->toDateTimeString(),
            'elapsed_label' => $this->elapsedLabel($item->provision_finished_at ?: $item->updated_at),
            'suggested_action' => $item->failure_reason
                ? 'Retry media copy for this batch or inspect this source profile media.'
                : 'Run the batch media copy action.',
            'retry_available' => (bool) $item->target_wp_post_id,
        ];
    }

    private function downloadMedia(PbnSeedItem $item, array $media): array
    {
        $url = trim((string) ($media['url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException('Source media URL is missing.');
        }

        $response = Http::timeout(60)->get($url);
        if (!$response->successful()) {
            throw new \RuntimeException("Source media download failed with HTTP {$response->status()}.");
        }

        $body = $response->body();
        if ($body === '') {
            throw new \RuntimeException('Source media download returned an empty file.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'pbn_media_');
        if ($temporaryPath === false || file_put_contents($temporaryPath, $body) === false) {
            throw new \RuntimeException('Unable to stage source media for upload.');
        }

        $mime = trim((string) ($response->header('Content-Type') ?: ($media['mime_type'] ?? 'image/jpeg')));
        $filename = $this->mediaFilename($item, $media, $url, $mime);

        return [
            new UploadedFile($temporaryPath, $filename, $mime ?: 'image/jpeg', null, true),
            $temporaryPath,
        ];
    }

    private function mediaFilename(PbnSeedItem $item, array $media, string $url, string $mime): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $filename = $path ? basename($path) : '';
        if ($filename && str_contains($filename, '.')) {
            return Str::limit($filename, 180, '');
        }

        $extension = match (strtolower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            default => 'jpg',
        };

        return sprintf('pbn-%d-media-%d.%s', (int) $item->id, (int) ($media['id'] ?? 0), $extension);
    }

    private function markMediaAttention(PbnSeedItem $item, string $message, ?int $actorId = null): void
    {
        $item->forceFill([
            'status' => PbnSeedItem::STATUS_MEDIA_PENDING,
            'failure_reason' => Str::limit('Media copy pending: ' . $message, 1000, ''),
            'provision_finished_at' => now(),
        ])->save();

        $this->recordEvent($item->batch, $item, 'item_media_attention', 'warning', 'PBN seed item media needs attention.', [
            'failure_reason' => Str::limit($message, 500, ''),
            'target_wp_post_id' => (int) $item->target_wp_post_id,
        ], $actorId);
    }

    private function recordEvent(?PbnSeedBatch $batch, ?PbnSeedItem $item, string $type, string $level, string $message, array $context = [], ?int $actorId = null): void
    {
        $siteId = (int) ($batch?->pbn_site_id ?: $item?->pbn_site_id ?: 0);
        if ($siteId < 1) {
            return;
        }

        PbnSeedEvent::create([
            'pbn_site_id' => $siteId,
            'batch_id' => $batch?->id,
            'item_id' => $item?->id,
            'actor_id' => $actorId ?: $batch?->created_by,
            'type' => $type,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }

    private function etaLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return 'About 1 minute';
        }

        return 'About ' . max(1, (int) ceil($seconds / 60)) . ' minutes';
    }

    private function elapsedLabel($timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }

        $minutes = max(0, now()->diffInMinutes($timestamp));
        if ($minutes < 1) {
            return 'Just now';
        }
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        return max(1, (int) floor($minutes / 60)) . ' hr';
    }
}
