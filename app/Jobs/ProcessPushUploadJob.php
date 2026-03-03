<?php

namespace App\Jobs;

use App\Models\PushCampaignItem;
use App\Services\PushCampaign\ProfileExtractionService;
use App\Services\PushCampaign\PushCampaignService;
use App\Services\PushCampaign\UploadBatchStatusService;
use App\Support\Spreadsheet\ChunkReadFilter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProcessPushUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ROW_CHUNK_SIZE = 500;
    private const DRY_RUN_ROW_CHUNK_SIZE = 2000;

    public int $timeout = 600;

    public function __construct(
        public readonly string $batchId,
        public readonly string $filePath,
        public readonly string $sourceFilename,
        public readonly int $userId,
        public readonly bool $dryRun = false,
        public readonly bool $inlineExtraction = false,
    ) {
    }

    public function handle(
        ProfileExtractionService $profileExtractionService,
        PushCampaignService $pushCampaignService,
        UploadBatchStatusService $uploadBatchStatusService
    ): void
    {
        $uploadBatchStatusService->put($this->batchId, [
            'batch_id' => $this->batchId,
            'status' => 'processing',
            'source_filename' => $this->sourceFilename,
            'started_at' => now()->toDateTimeString(),
            'initiated_by' => $this->userId,
            'sheets_parsed' => 0,
            'total_items' => 0,
            'profiles_processed' => 0,
            'campaign_ids' => [],
            'unmapped_sheets' => [],
            'dry_run' => $this->dryRun,
        ]);

        if (!is_file($this->filePath)) {
            throw new \RuntimeException('Upload file not found: ' . $this->filePath);
        }

        $year = $this->extractYearFromFilename($this->sourceFilename);
        $reader = IOFactory::createReaderForFile($this->filePath);
        $reader->setReadDataOnly(true);
        $worksheetInfo = $reader->listWorksheetInfo($this->filePath);
        $processableSheetNames = collect($worksheetInfo)
            ->map(fn(array $meta): string => trim((string) ($meta['worksheetName'] ?? '')))
            ->filter(fn(string $name): bool => $name !== '' && !$profileExtractionService->shouldSkipSheet($name))
            ->values()
            ->all();
        $sheetCount = count($processableSheetNames);
        $estimatedRows = collect($worksheetInfo)->reduce(
            fn(int $carry, array $meta): int => $carry + max(0, ((int) ($meta['totalRows'] ?? 0)) - 1),
            0
        );
        $singleSheetUpload = count($processableSheetNames) === 1;

        $uploadBatchStatusService->put($this->batchId, [
            'sheet_count' => $sheetCount,
            'estimated_rows' => $estimatedRows,
            'updated_at' => now()->toDateTimeString(),
        ]);

        $campaignsByPlatform = [];
        $campaignIds = [];
        $unmappedSheets = [];
        $sheetRowCounts = [];
        $sheetsParsed = 0;
        $mappedSheets = 0;
        $totalItems = 0;

        foreach ($worksheetInfo as $sheetMeta) {
            $sheetName = trim((string) ($sheetMeta['worksheetName'] ?? ''));
            if ($sheetName === '') {
                continue;
            }

            if ($profileExtractionService->shouldSkipSheet($sheetName)) {
                continue;
            }

            $platform = $profileExtractionService->resolvePlatformForSheet(
                $sheetName,
                $this->sourceFilename,
                $singleSheetUpload
            );

            if (!$platform) {
                $unmappedSheets[] = $sheetName;
                continue;
            }
            $mappedSheets++;
            $highestRow = (int) ($sheetMeta['totalRows'] ?? 0);
            $highestRow = max(2, $highestRow);
            $sheetItemCount = 0;
            $carriedDateLabel = null;
            $rowChunkSize = $this->dryRun ? self::DRY_RUN_ROW_CHUNK_SIZE : self::ROW_CHUNK_SIZE;

            for ($startRow = 2; $startRow <= $highestRow; $startRow += $rowChunkSize) {
                $chunkSize = min($rowChunkSize, $highestRow - $startRow + 1);
                $endRow = $startRow + $chunkSize - 1;

                $reader->setLoadSheetsOnly($sheetName);
                $reader->setReadFilter(new ChunkReadFilter($sheetName, $startRow, $chunkSize));

                $chunkSpreadsheet = $reader->load($this->filePath);
                $chunkSheet = $chunkSpreadsheet->getSheetByName($sheetName);

                if (!$chunkSheet) {
                    $chunkSpreadsheet->disconnectWorksheets();
                    unset($chunkSpreadsheet);
                    continue;
                }

                $parsed = $profileExtractionService->parseSheetChunk(
                    $chunkSheet,
                    $sheetName,
                    $year,
                    $startRow,
                    $endRow,
                    $carriedDateLabel,
                    $platform
                );

                $chunkSpreadsheet->disconnectWorksheets();
                unset($chunkSpreadsheet, $chunkSheet);

                $carriedDateLabel = $parsed['last_date_label'] ?? $carriedDateLabel;
                $rows = $parsed['rows'] ?? [];

                if (empty($rows)) {
                    continue;
                }

                $rowCount = count($rows);
                $sheetItemCount += $rowCount;
                $totalItems += $rowCount;

                if ($this->dryRun) {
                    continue;
                }

                if (!isset($campaignsByPlatform[$platform->id])) {
                    $campaignsByPlatform[$platform->id] = $pushCampaignService->createCampaignForPlatform(
                        (int) $platform->id,
                        $this->batchId,
                        $this->sourceFilename,
                        $this->userId,
                    );
                }

                $campaign = $campaignsByPlatform[$platform->id];
                $now = now();
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'campaign_id' => (int) $campaign->id,
                        'profile_url' => (string) ($row['profile_url'] ?? ''),
                        'custom_message' => (string) ($row['custom_message'] ?? ''),
                        'scheduled_at' => $row['scheduled_at'] ?? null,
                        'date_label' => $row['date_label'] ?? null,
                        'status' => 'pending_extraction',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                PushCampaignItem::query()->insert($payload);
            }

            $sheetRowCounts[$sheetName] = $sheetItemCount;

            if (!$this->dryRun && $sheetItemCount > 0 && isset($campaignsByPlatform[$platform->id])) {
                $campaign = $campaignsByPlatform[$platform->id];
                $campaign->increment('total_items', $sheetItemCount);
            }

            if (!$this->dryRun && isset($campaignsByPlatform[$platform->id])) {
                $campaign = $campaignsByPlatform[$platform->id];
                $campaignIds[(int) $campaign->id] = (int) $campaign->id;
            }

            $sheetsParsed++;

            $uploadBatchStatusService->put($this->batchId, [
                'batch_id' => $this->batchId,
                'status' => 'processing',
                'source_filename' => $this->sourceFilename,
                'year' => $year,
                'sheets_parsed' => $sheetsParsed,
                'sheet_count' => $sheetCount,
                'mapped_sheets' => $mappedSheets,
                'total_items' => $totalItems,
                'campaign_ids' => array_values($campaignIds),
                'unmapped_sheets' => $unmappedSheets,
                'sheet_row_counts' => $sheetRowCounts,
                'dry_run' => $this->dryRun,
                'updated_at' => now()->toDateTimeString(),
            ]);
        }

        if ($this->dryRun) {
            $uploadBatchStatusService->put($this->batchId, [
                'batch_id' => $this->batchId,
                'status' => 'ready',
                'source_filename' => $this->sourceFilename,
                'year' => $year,
                'sheets_parsed' => $sheetsParsed,
                'sheet_count' => $sheetCount,
                'mapped_sheets' => $mappedSheets,
                'total_items' => $totalItems,
                'campaign_ids' => [],
                'unmapped_sheets' => $unmappedSheets,
                'sheet_row_counts' => $sheetRowCounts,
                'dry_run' => true,
                'message' => 'Dry run complete. No campaigns were created.',
                'updated_at' => now()->toDateTimeString(),
            ]);

            return;
        }

        if (empty($campaignIds)) {
            $uploadBatchStatusService->put($this->batchId, [
                'batch_id' => $this->batchId,
                'status' => 'failed',
                'source_filename' => $this->sourceFilename,
                'year' => $year,
                'sheets_parsed' => $sheetsParsed,
                'sheet_count' => $sheetCount,
                'mapped_sheets' => $mappedSheets,
                'total_items' => $totalItems,
                'campaign_ids' => [],
                'unmapped_sheets' => $unmappedSheets,
                'sheet_row_counts' => $sheetRowCounts,
                'dry_run' => false,
                'error' => $mappedSheets === 0
                    ? 'No mapped sheets found in upload file.'
                    : 'No valid data rows found in mapped sheets.',
                'updated_at' => now()->toDateTimeString(),
            ]);
            return;
        }

        if ($this->inlineExtraction) {
            foreach ($campaignsByPlatform as $platformId => $campaign) {
                ExtractPushProfilesJob::dispatchSync((int) $campaign->id, (int) $platformId, $this->batchId);
            }

            return;
        }

        foreach ($campaignsByPlatform as $platformId => $campaign) {
            ExtractPushProfilesJob::dispatch((int) $campaign->id, (int) $platformId, $this->batchId);
        }

        $uploadBatchStatusService->put($this->batchId, [
            'batch_id' => $this->batchId,
            'status' => 'extracting',
            'source_filename' => $this->sourceFilename,
            'year' => $year,
            'sheets_parsed' => $sheetsParsed,
            'sheet_count' => $sheetCount,
            'total_items' => $totalItems,
            'campaign_ids' => array_values($campaignIds),
            'unmapped_sheets' => $unmappedSheets,
            'sheet_row_counts' => $sheetRowCounts,
            'dry_run' => false,
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessPushUploadJob failed', [
            'batch_id' => $this->batchId,
            'file_path' => $this->filePath,
            'error' => $exception->getMessage(),
        ]);

        app(UploadBatchStatusService::class)->put($this->batchId, [
            'batch_id' => $this->batchId,
            'status' => 'failed',
            'source_filename' => $this->sourceFilename,
            'initiated_by' => $this->userId,
            'error' => $exception->getMessage(),
            'dry_run' => $this->dryRun,
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    private function extractYearFromFilename(string $sourceFilename): int
    {
        if (preg_match('/PUSH.*?(\d{4})/i', $sourceFilename, $match)) {
            return (int) $match[1];
        }

        return (int) now()->year;
    }

}
