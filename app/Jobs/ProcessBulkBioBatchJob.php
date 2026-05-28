<?php

namespace App\Jobs;

use App\Models\SeoBioBatch;
use App\Models\SeoBioBatchRow;
use App\Services\Seo\BioGenerationService;
use App\Services\WpSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Walks every row in a {@see SeoBioBatch}, calling BioGenerationService
 * once per resolved row. Failures on individual rows do not abort the
 * batch.
 *
 * Throughput note: bio generation is sync-blocking on the LLM provider.
 * With 250-row cap and ~5-15s per row, worst case is ~60 minutes. The
 * default queue timeout (60s) is unsuitable — we set $timeout to 0 (off).
 *
 * Run on a dedicated queue (`bulk_bio`) so it doesn't block other work.
 */
class ProcessBulkBioBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 0; // disable — generation can take hours for large batches

    public function __construct(public int $batchId)
    {
        // Dedicated queue so a long bulk run doesn't starve regular jobs.
        $this->onQueue('bulk_bio');
    }

    public function handle(BioGenerationService $generator): void
    {
        $batch = SeoBioBatch::find($this->batchId);
        if (!$batch) {
            Log::warning('seo.bulk.batch_missing', ['batch_id' => $this->batchId]);
            return;
        }

        if ($batch->status === SeoBioBatch::STATUS_CANCELLED) {
            return;
        }

        $batch->update([
            'status'     => SeoBioBatch::STATUS_PROCESSING,
            'started_at' => $batch->started_at ?: now(),
        ]);

        $platformId = (int) $batch->platform_id;
        $generationOptions = is_array($batch->generation_options) ? $batch->generation_options : [];
        $generationOptions['language'] = $batch->language ?: 'en';
        $autoSave = (bool) $batch->auto_save_to_wp;

        $wpSync = $autoSave ? $this->safeWpSync($platformId) : null;

        $batch->rows()
            ->where('status', SeoBioBatchRow::STATUS_QUEUED)
            ->orderBy('row_index')
            ->each(function (SeoBioBatchRow $row) use ($batch, $generator, $generationOptions, $platformId, $wpSync, $autoSave) {
                // Allow editor cancellation mid-flight
                $batch->refresh();
                if ($batch->status === SeoBioBatch::STATUS_CANCELLED) {
                    return false; // stop the each() loop
                }

                $this->processRow($row, $batch, $generator, $generationOptions, $platformId, $wpSync, $autoSave);
                return null;
            });

        $batch->refresh();
        if ($batch->status === SeoBioBatch::STATUS_CANCELLED) {
            $batch->update(['finished_at' => now()]);
            return;
        }

        $batch->update([
            'status'      => SeoBioBatch::STATUS_READY,
            'finished_at' => now(),
        ]);

        Log::info('seo.bulk.batch_ready', [
            'batch_id'  => $batch->id,
            'total'     => $batch->total_rows,
            'succeeded' => $batch->succeeded_rows,
            'failed'    => $batch->failed_rows,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        SeoBioBatch::where('id', $this->batchId)->update([
            'status'      => SeoBioBatch::STATUS_FAILED,
            'error'       => mb_substr($e->getMessage(), 0, 2000),
            'finished_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------

    private function processRow(
        SeoBioBatchRow $row,
        SeoBioBatch $batch,
        BioGenerationService $generator,
        array $generationOptions,
        int $platformId,
        ?WpSyncService $wpSync,
        bool $autoSave,
    ): void {
        $row->update([
            'status'       => SeoBioBatchRow::STATUS_PROCESSING,
            'processed_at' => null,
        ]);

        try {
            $params = [
                'platform_id'        => $platformId,
                'generation_options' => $generationOptions,
            ];
            if ($row->client_id) {
                $params['client_id'] = (int) $row->client_id;
            } elseif ($row->wp_post_id) {
                $params['wp_post_id'] = (int) $row->wp_post_id;
            } else {
                throw new \RuntimeException('Row has no client_id or wp_post_id.');
            }

            $result = $generator->generate($params);

            $row->fill([
                'status'        => SeoBioBatchRow::STATUS_GENERATED,
                'bio_html'      => $result['bio_html'] ?? '',
                'score'         => $result['score'] ?? null,
                'breakdown'     => $result['breakdown'] ?? null,
                'provider_used' => $result['provider_used'] ?? null,
                'error'         => null,
                'processed_at'  => now(),
            ]);

            if ($autoSave && $wpSync && $row->wp_post_id) {
                try {
                    $wpSync->updateClientProfile((int) $row->wp_post_id, ['content' => $result['bio_html']]);
                    $wpSync->writeSeoScore((int) $row->wp_post_id, (int) $result['score'], (array) $result['breakdown']);
                    $row->status = SeoBioBatchRow::STATUS_ACCEPTED;
                } catch (\Throwable $e) {
                    // Generation succeeded; only WP save failed. Mark as generated
                    // but record the WP error so the editor can retry the save.
                    $row->error = 'Bio generated but auto-save to WP failed: ' . $e->getMessage();
                    Log::warning('seo.bulk.wp_save_failed', [
                        'batch_id' => $batch->id,
                        'row_id'   => $row->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $row->save();
            $batch->increment('succeeded_rows');
            if ($row->status === SeoBioBatchRow::STATUS_ACCEPTED) {
                $batch->increment('accepted_rows');
            }
        } catch (\Throwable $e) {
            $row->update([
                'status'       => SeoBioBatchRow::STATUS_FAILED,
                'error'        => mb_substr($e->getMessage(), 0, 1000),
                'processed_at' => now(),
            ]);
            $batch->increment('failed_rows');
            Log::warning('seo.bulk.row_failed', [
                'batch_id' => $batch->id,
                'row_id'   => $row->id,
                'error'    => $e->getMessage(),
            ]);
        } finally {
            $batch->increment('processed_rows');
        }
    }

    private function safeWpSync(int $platformId): ?WpSyncService
    {
        try {
            return WpSyncService::forPlatform($platformId);
        } catch (\Throwable $e) {
            Log::warning('seo.bulk.wp_sync_unavailable', [
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
