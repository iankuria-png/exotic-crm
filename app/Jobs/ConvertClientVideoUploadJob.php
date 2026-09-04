<?php

namespace App\Jobs;

use App\Support\CrmAuditAction;
use App\Models\Client;
use App\Services\AuditService;
use App\Services\ClientSyncService;
use App\Services\MediaConversionStatusService;
use App\Services\VideoTranscodeService;
use App\Services\WpSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Convert one staff-uploaded video to MP4, then hand it to WordPress.
 *
 * Queued rather than done in the upload request: a full re-encode of a 50MB
 * phone video runs for minutes, well past any shared-hosting request timeout.
 * The browser gets a conversion id back immediately and follows the status
 * feed, so the staff member can keep working.
 */
class ConvertClientVideoUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public readonly string $conversionId,
        public readonly int $clientId,
        public readonly string $sourcePath,
        public readonly string $originalName,
        public readonly ?int $actorId = null,
        public readonly ?string $reason = null,
    ) {
        // The existing heavy lane: its connection allows a 4200s retry window,
        // comfortably longer than this job's timeout, so a slow re-encode is
        // never picked up twice. Deliberately not load-shed — a staff member is
        // waiting on the result, and the heavy worker is already gated.
        $this->onConnection('database_long')->onQueue('heavy');
    }

    public function handle(
        VideoTranscodeService $transcoder,
        MediaConversionStatusService $status,
        AuditService $auditService
    ): void {
        $client = Client::query()->find($this->clientId);
        if (! $client || (int) $client->wp_post_id <= 0) {
            $this->finishAsFailed($status, 'The client is no longer linked to a WordPress profile.');

            return;
        }

        $targetPath = $this->targetPathFor($this->sourcePath);

        $status->put($this->conversionId, [
            'status' => 'converting',
            'message' => 'Converting to MP4.',
        ]);

        $result = $transcoder->toMp4($this->sourcePath, $targetPath);

        if (! $result['ok']) {
            $this->finishAsFailed($status, $result['message']);

            return;
        }

        $status->put($this->conversionId, [
            'status' => 'uploading',
            'message' => 'Uploading the converted video to WordPress.',
            'conversion_mode' => $result['mode'],
        ]);

        try {
            $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
            $uploaded = $wpSync->uploadClientMediaFile(
                (int) $client->wp_post_id,
                $targetPath,
                $this->mp4FileName(),
                'video/mp4',
                false
            );
        } catch (\Throwable $exception) {
            Log::warning('Converted video failed to upload to WordPress.', [
                'client_id' => $this->clientId,
                'conversion_id' => $this->conversionId,
                'error' => $exception->getMessage(),
            ]);

            $this->finishAsFailed($status, 'The video converted, but WordPress rejected the upload: ' . $exception->getMessage());

            return;
        } finally {
            $this->cleanUp($targetPath);
        }

        $attachment = (array) ($uploaded['attachment'] ?? []);

        $auditService->record([
            'platform_id' => (int) $client->platform_id,
            'actor_id' => $this->actorId,
            'action' => CrmAuditAction::CLIENT_PROFILE_EDIT,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'after_state' => [
                'media_upload' => [
                    'upload_count' => 1,
                    'attachments' => [$attachment],
                    'converted_from' => $this->originalName,
                    'conversion_mode' => $result['mode'],
                ],
            ],
            'reason' => $this->reason ?: 'Uploaded converted profile video from CRM',
        ]);

        try {
            (new ClientSyncService($client->platform))->syncOne((int) $client->wp_post_id);
        } catch (\Throwable $exception) {
            Log::warning('Client re-sync failed after video conversion.', [
                'client_id' => $this->clientId,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->cleanUp($this->sourcePath);

        $status->put($this->conversionId, [
            'status' => 'completed',
            'message' => $result['mode'] === 'remux'
                ? 'Converted to MP4 and uploaded.'
                : 'Re-encoded to MP4 and uploaded.',
            'attachment' => $attachment,
            'conversion_mode' => $result['mode'],
            'duration_seconds' => $result['duration_seconds'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        app(MediaConversionStatusService::class)->put($this->conversionId, [
            'status' => 'failed',
            'message' => 'Video conversion failed: ' . $exception->getMessage(),
        ]);

        $this->cleanUp($this->targetPathFor($this->sourcePath));
        $this->cleanUp($this->sourcePath);
    }

    private function finishAsFailed(MediaConversionStatusService $status, string $message): void
    {
        $status->put($this->conversionId, [
            'status' => 'failed',
            'message' => $message,
        ]);

        $this->cleanUp($this->targetPathFor($this->sourcePath));
        $this->cleanUp($this->sourcePath);
    }

    private function targetPathFor(string $sourcePath): string
    {
        return preg_replace('/\.[^.]+$/', '', $sourcePath) . '.mp4';
    }

    private function mp4FileName(): string
    {
        $base = pathinfo($this->originalName, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $base), '-');

        return ($base !== '' ? $base : 'video') . '.mp4';
    }

    private function cleanUp(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
