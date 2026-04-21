<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPushUploadJob;
use App\Models\Client;
use App\Models\Platform;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Models\PushSubscriberSnapshot;
use App\Models\ScraperProfilePreset;
use App\Services\MarketAuthorizationService;
use App\Services\PushCampaign\PushCampaignService;
use App\Services\PushCampaign\PushCampaignDispatchReadinessService;
use App\Services\PushCampaign\PushCampaignItemMatchService;
use App\Services\PushCampaign\SelectorDetectionService;
use App\Services\PushCampaign\UploadBatchStatusService;
use App\Services\PushNotification\PushProviderService;
use App\Services\PushNotification\SubscriberSyncService;
use App\Services\AuditService;
use App\Services\WpSyncService;
use App\Support\MarketTimezone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PushCampaignController extends Controller
{
    private const EXPRESS_PASTE_MAX_ROWS = 20;
    private const PROFILE_IMAGE_ALLOWED_EXTENSIONS = 'jpg,jpeg,png,webp';

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly PushCampaignService $pushCampaignService,
        private readonly PushCampaignDispatchReadinessService $pushCampaignDispatchReadinessService,
        private readonly PushCampaignItemMatchService $pushCampaignItemMatchService,
        private readonly SelectorDetectionService $selectorDetectionService,
        private readonly UploadBatchStatusService $uploadBatchStatusService,
        private readonly SubscriberSyncService $subscriberSyncService,
        private readonly PushProviderService $pushProviderService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'status' => 'nullable|in:processing,draft,scheduled,running,completed,partial,failed',
            'batch_id' => 'nullable|string|max:64',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if (!empty($validated['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $validated['platform_id'],
                'You do not have access to this market.'
            );
        }

        $query = PushCampaign::query()
            ->with('platform:id,name,country,timezone')
            ->withCount([
                'items as pending_items_count' => fn($builder) => $builder->whereIn('status', ['pending_extraction', 'needs_preset', 'pending', 'scheduled']),
                'items as failed_items_count' => fn($builder) => $builder->where('status', 'failed'),
            ])
            ->orderByDesc('id');

        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if (!empty($validated['platform_id'])) {
            $query->where('platform_id', (int) $validated['platform_id']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if (!empty($validated['batch_id'])) {
            $query->where('upload_batch_id', (string) $validated['batch_id']);
        }

        if (!empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('source_filename', 'like', '%' . $search . '%')
                    ->orWhere('upload_batch_id', 'like', '%' . $search . '%');
            });
        }

        $campaigns = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json($campaigns);
    }

    public function upload(Request $request)
    {
        $uploadErrorCode = (int) data_get($_FILES, 'file.error', UPLOAD_ERR_OK);
        if ($uploadErrorCode !== UPLOAD_ERR_OK) {
            return response()->json([
                'message' => $this->uploadErrorMessage($uploadErrorCode),
            ], 422);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
            'dry_run' => 'nullable|boolean',
        ]);

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $setupIssues = $this->pushUploadSetupIssues($dryRun);
        if (!empty($setupIssues)) {
            return response()->json([
                'message' => 'Push upload setup is incomplete. Run CRM push migrations/setup first.',
                'issues' => $setupIssues,
            ], 503);
        }

        $file = $validated['file'];
        $batchId = (string) Str::uuid();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = in_array($extension, ['xlsx', 'xls'], true) ? $extension : 'xlsx';
        $storedPath = $file->storeAs('push-uploads', $batchId . '.' . $extension);

        $initialStatus = $this->uploadBatchStatusService->put($batchId, [
            'batch_id' => $batchId,
            'status' => 'queued',
            'source_filename' => (string) ($file->getClientOriginalName() ?: $batchId . '.' . $extension),
            'stored_path' => (string) $storedPath,
            'queued_at' => now()->toDateTimeString(),
            'initiated_by' => (int) $request->user()->id,
            'sheets_parsed' => 0,
            'total_items' => 0,
            'profiles_processed' => 0,
            'campaign_ids' => [],
            'unmapped_sheets' => [],
            'dry_run' => $dryRun,
        ]);

        $processedInline = false;

        try {
            $jobPayload = [
                $batchId,
                storage_path('app/' . $storedPath),
                (string) ($file->getClientOriginalName() ?: $batchId . '.' . $extension),
                (int) $request->user()->id,
                $dryRun,
            ];

            if ($this->shouldProcessInline($file, $dryRun)) {
                ProcessPushUploadJob::dispatchSync(...$jobPayload);
                $processedInline = true;
            } else {
                ProcessPushUploadJob::dispatch(...$jobPayload);
            }
        } catch (\Throwable $exception) {
            $this->uploadBatchStatusService->put($batchId, [
                'batch_id' => $batchId,
                'status' => 'failed',
                'source_filename' => (string) ($file->getClientOriginalName() ?: $batchId . '.' . $extension),
                'initiated_by' => (int) $request->user()->id,
                'error' => $exception->getMessage(),
                'dry_run' => $dryRun,
                'updated_at' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'message' => 'Failed to queue workbook processing.',
                'error' => $exception->getMessage(),
                'batch_id' => $batchId,
            ], 500);
        }

        $statusPayload = $this->uploadBatchStatusService->get($batchId) ?? $initialStatus;

        if ($processedInline) {
            return response()->json([
                'batch_id' => $batchId,
                'status' => (string) ($statusPayload['status'] ?? 'ready'),
                'dry_run' => $dryRun,
                'processed_inline' => true,
                'status_payload' => $statusPayload,
            ]);
        }

        return response()->json([
            'batch_id' => $batchId,
            'status' => 'processing',
            'dry_run' => $dryRun,
            'processed_inline' => false,
            'status_payload' => $initialStatus,
        ], 202);
    }

    public function uploadPaste(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'content' => 'required|string',
            'dry_run' => 'nullable|boolean',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this market.'
        );

        $platform = Platform::query()->findOrFail($platformId);
        $dryRun = array_key_exists('dry_run', $validated) ? (bool) $validated['dry_run'] : true;
        $year = (int) ($validated['year'] ?? now()->year);
        $content = trim((string) ($validated['content'] ?? ''));

        if ($content === '') {
            return response()->json([
                'message' => 'Paste content is empty. Paste tab-separated rows: Date, Profile URL, Message, Time.',
            ], 422);
        }

        try {
            $rows = $this->parsePasteRows($content);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Unable to parse pasted content. Paste tab-separated rows: Date, Profile URL, Message, Time.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        $setupIssues = $this->pushUploadSetupIssues($dryRun);
        if (!empty($setupIssues)) {
            return response()->json([
                'message' => 'Push upload setup is incomplete. Run CRM push migrations/setup first.',
                'issues' => $setupIssues,
            ], 503);
        }

        $batchId = (string) Str::uuid();
        $storedPath = 'push-uploads/' . $batchId . '.xlsx';
        $absolutePath = storage_path('app/' . $storedPath);
        $sourceFilename = $this->sourceFilenameForPaste($platform, $year);
        $expressMode = !$dryRun && count($rows) <= self::EXPRESS_PASTE_MAX_ROWS;

        $this->writePasteWorkbook($absolutePath, $platform, $year, $rows);

        $initialStatus = $this->uploadBatchStatusService->put($batchId, [
            'batch_id' => $batchId,
            'status' => 'queued',
            'source_filename' => $sourceFilename,
            'stored_path' => $storedPath,
            'queued_at' => now()->toDateTimeString(),
            'initiated_by' => (int) $request->user()->id,
            'sheets_parsed' => 0,
            'total_items' => 0,
            'profiles_processed' => 0,
            'campaign_ids' => [],
            'unmapped_sheets' => [],
            'dry_run' => $dryRun,
            'year' => $year,
            'paste_mode' => true,
            'paste_rows' => count($rows),
            'express_mode' => $expressMode,
        ]);

        $processedInline = false;

        try {
            $jobPayload = [
                $batchId,
                $absolutePath,
                $sourceFilename,
                (int) $request->user()->id,
                $dryRun,
                $expressMode,
            ];

            if ($this->shouldProcessPasteInline(count($rows), $dryRun, $expressMode)) {
                ProcessPushUploadJob::dispatchSync(...$jobPayload);
                $processedInline = true;
            } else {
                ProcessPushUploadJob::dispatch(...$jobPayload);
            }
        } catch (\Throwable $exception) {
            $this->uploadBatchStatusService->put($batchId, [
                'batch_id' => $batchId,
                'status' => 'failed',
                'source_filename' => $sourceFilename,
                'initiated_by' => (int) $request->user()->id,
                'error' => $exception->getMessage(),
                'dry_run' => $dryRun,
                'updated_at' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'message' => 'Failed to queue pasted workbook processing.',
                'error' => $exception->getMessage(),
                'batch_id' => $batchId,
            ], 500);
        }

        $statusPayload = $this->uploadBatchStatusService->get($batchId) ?? $initialStatus;

        if ($processedInline) {
            return response()->json([
                'batch_id' => $batchId,
                'status' => (string) ($statusPayload['status'] ?? 'ready'),
                'dry_run' => $dryRun,
                'processed_inline' => true,
                'status_payload' => $statusPayload,
            ]);
        }

        return response()->json([
            'batch_id' => $batchId,
            'status' => 'processing',
            'dry_run' => $dryRun,
            'processed_inline' => false,
            'status_payload' => $initialStatus,
        ], 202);
    }

    public function uploadLimits(): \Illuminate\Http\JsonResponse
    {
        $uploadMax = (string) ini_get('upload_max_filesize');
        $postMax = (string) ini_get('post_max_size');

        return response()->json([
            'upload_max_filesize' => $uploadMax,
            'post_max_size' => $postMax,
            'upload_max_bytes' => $this->iniSizeToBytes($uploadMax),
            'post_max_bytes' => $this->iniSizeToBytes($postMax),
        ]);
    }

    public function uploadStatus(Request $request, string $batchId)
    {
        $status = $this->uploadBatchStatusService->get($batchId);

        if (!is_array($status)) {
            return response()->json([
                'message' => 'Upload batch not found or expired.',
            ], 404);
        }

        $queueOverview = $this->uploadBatchStatusService->queueOverviewForUser(
            (int) $request->user()->id,
            $batchId
        );

        $campaignIds = collect((array) ($status['campaign_ids'] ?? []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values();

        if ($campaignIds->isEmpty()) {
            return response()->json([
                ...$status,
                'queue' => $queueOverview,
            ]);
        }

        $campaignsQuery = PushCampaign::query()
            ->whereIn('id', $campaignIds->all())
            ->with('platform:id,name,country')
            ->withCount([
                'items as pending_extraction_count' => fn($builder) => $builder->where('status', 'pending_extraction'),
                'items as pending_count' => fn($builder) => $builder->where('status', 'pending'),
                'items as scheduled_count' => fn($builder) => $builder->where('status', 'scheduled'),
                'items as needs_preset_count' => fn($builder) => $builder->where('status', 'needs_preset'),
                'items as failed_items_count' => fn($builder) => $builder->where('status', 'failed'),
            ])
            ->orderBy('id');

        $this->marketAuthorizationService->applyPlatformScope($campaignsQuery, $request->user());
        $campaigns = $campaignsQuery->get();

        $summaries = $campaigns->map(function (PushCampaign $campaign): array {
            $sampleItems = $campaign->items()
                ->select([
                    'id',
                    'profile_url',
                    'profile_name',
                    'profile_image_url',
                    'custom_message',
                    'scheduled_at',
                    'date_label',
                    'status',
                    'error_message',
                ])
                ->orderBy('id')
                ->limit(10)
                ->get();

            return [
                'id' => (int) $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'platform' => $campaign->platform,
                'total_items' => (int) $campaign->total_items,
                'pending_extraction_count' => (int) ($campaign->pending_extraction_count ?? 0),
                'pending_count' => (int) ($campaign->pending_count ?? 0),
                'scheduled_count' => (int) ($campaign->scheduled_count ?? 0),
                'needs_preset_count' => (int) ($campaign->needs_preset_count ?? 0),
                'failed_count' => (int) ($campaign->failed_items_count ?? 0),
                'confirmed_at' => optional($campaign->confirmed_at)->toDateTimeString(),
                'sample_items' => $sampleItems,
            ];
        })->values();

        return response()->json([
            ...$status,
            'queue' => $queueOverview,
            'campaigns' => $summaries,
        ]);
    }

    public function uploadQueue(Request $request)
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $paginator = $this->uploadBatchStatusService->paginateForUser((int) $request->user()->id, $page, $perPage);
        $items = $paginator['data'];
        $health = $this->uploadBatchStatusService->queueHealthSnapshot();
        $batchIds = collect($items)->pluck('batch_id')->filter()->values()->all();

        $campaignStatsQuery = PushCampaign::query()
            ->selectRaw('upload_batch_id, count(*) as campaigns_count')
            ->selectRaw('sum(case when confirmed_at is null then 1 else 0 end) as unconfirmed_count')
            ->selectRaw("sum(case when status = 'processing' then 1 else 0 end) as processing_count")
            ->whereIn('upload_batch_id', $batchIds)
            ->groupBy('upload_batch_id');
        $this->marketAuthorizationService->applyPlatformScope($campaignStatsQuery, $request->user());
        $campaignStats = $campaignStatsQuery
            ->get()
            ->keyBy('upload_batch_id');

        $rows = array_map(function (array $item) use ($campaignStats): array {
            $status = (string) ($item['status'] ?? 'queued');
            $dryRun = (bool) ($item['dry_run'] ?? false);
            $batchId = (string) ($item['batch_id'] ?? '');
            $stats = $batchId !== '' ? $campaignStats->get($batchId) : null;
            $campaignCount = (int) ($stats?->campaigns_count ?? 0);
            $unconfirmedCount = (int) ($stats?->unconfirmed_count ?? 0);
            $processingCount = (int) ($stats?->processing_count ?? 0);

            return [
                ...$item,
                'campaign_count' => $campaignCount,
                'unconfirmed_count' => $unconfirmedCount,
                'can_cancel' => $status === 'queued',
                'can_process_now' => $status === 'queued',
                'can_create_from_dry_run' => $status === 'ready' && $dryRun && (int) ($item['total_items'] ?? 0) > 0,
                'can_confirm' => $status === 'ready' && !$dryRun && $campaignCount > 0 && $unconfirmedCount > 0 && $processingCount === 0,
            ];
        }, $items);

        return response()->json([
            'data' => $rows,
            'items' => $rows,
            'current_page' => (int) ($paginator['current_page'] ?? 1),
            'last_page' => (int) ($paginator['last_page'] ?? 1),
            'per_page' => (int) ($paginator['per_page'] ?? $perPage),
            'total' => (int) ($paginator['total'] ?? count($rows)),
            'from' => $paginator['from'] ?? null,
            'to' => $paginator['to'] ?? null,
            'health' => $health,
        ]);
    }

    public function processQueuedUploadNow(Request $request, string $batchId)
    {
        $status = $this->uploadBatchStatusService->get($batchId);
        if (!is_array($status)) {
            return response()->json([
                'message' => 'Upload batch not found or expired.',
            ], 404);
        }

        $this->ensureUploadBatchAccess($request, $status);

        if (($status['status'] ?? null) !== 'queued') {
            return response()->json([
                'message' => 'Only queued uploads can be processed immediately.',
            ], 422);
        }

        if (config('queue.default') !== 'database') {
            return response()->json([
                'message' => 'Process now requires database queue driver.',
            ], 422);
        }

        $job = $this->findQueuedUploadJob($batchId);
        if (!$job) {
            return response()->json([
                'message' => 'No queued job was found for this upload batch.',
            ], 409);
        }

        $command = $this->decodeQueuedUploadCommand((string) $job->payload);
        if (!$command instanceof ProcessPushUploadJob || $command->batchId !== $batchId) {
            return response()->json([
                'message' => 'Unable to decode queued upload job payload.',
            ], 500);
        }

        DB::table('jobs')->where('id', (int) $job->id)->delete();

        try {
            ProcessPushUploadJob::dispatchSync(
                $command->batchId,
                $command->filePath,
                $command->sourceFilename,
                $command->userId,
                (bool) $command->dryRun,
            );
        } catch (\Throwable $exception) {
            $this->uploadBatchStatusService->put($batchId, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'message' => 'Failed to process queued upload now.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Upload processing completed.',
            'status_payload' => $this->uploadBatchStatusService->get($batchId),
        ]);
    }

    public function cancelQueuedUpload(Request $request, string $batchId)
    {
        $status = $this->uploadBatchStatusService->get($batchId);
        if (!is_array($status)) {
            return response()->json([
                'message' => 'Upload batch not found or expired.',
            ], 404);
        }

        $this->ensureUploadBatchAccess($request, $status);

        if (($status['status'] ?? null) !== 'queued') {
            return response()->json([
                'message' => 'Only queued uploads can be cancelled.',
            ], 422);
        }

        if (config('queue.default') === 'database') {
            DB::table('jobs')
                ->where('queue', 'default')
                ->whereNull('reserved_at')
                ->where('payload', 'like', '%ProcessPushUploadJob%')
                ->where('payload', 'like', '%' . $batchId . '%')
                ->delete();
        }

        $updated = $this->uploadBatchStatusService->put($batchId, [
            'status' => 'cancelled',
            'message' => 'Upload was cancelled by user.',
            'updated_at' => now()->toDateTimeString(),
        ]);

        return response()->json([
            'message' => 'Upload queue item cancelled.',
            'status_payload' => $updated,
        ]);
    }

    public function createCampaignsFromDryRun(Request $request, string $batchId)
    {
        $status = $this->uploadBatchStatusService->get($batchId);
        if (!is_array($status)) {
            return response()->json([
                'message' => 'Upload batch not found or expired.',
            ], 404);
        }

        $this->ensureUploadBatchAccess($request, $status);

        if (($status['status'] ?? null) !== 'ready' || !(bool) ($status['dry_run'] ?? false)) {
            return response()->json([
                'message' => 'Create campaigns is available only for ready dry-run batches.',
            ], 422);
        }

        if ((int) ($status['total_items'] ?? 0) <= 0) {
            return response()->json([
                'message' => 'This dry-run batch has no valid rows to create campaigns.',
            ], 422);
        }

        $setupIssues = $this->pushUploadSetupIssues(false);
        if (!empty($setupIssues)) {
            return response()->json([
                'message' => 'Push upload setup is incomplete. Run CRM push migrations/setup first.',
                'issues' => $setupIssues,
            ], 503);
        }

        $existingCampaignCount = PushCampaign::query()
            ->where('upload_batch_id', $batchId)
            ->count();
        if ($existingCampaignCount > 0) {
            return response()->json([
                'message' => 'Campaigns already exist for this batch.',
            ], 422);
        }

        $storedPath = (string) ($status['stored_path'] ?? '');
        if ($storedPath === '') {
            $storedPath = $this->resolveStoredPathForBatch(
                $batchId,
                (string) ($status['source_filename'] ?? '')
            );
        }
        if ($storedPath === '') {
            return response()->json([
                'message' => 'Stored upload file path is missing for this batch.',
            ], 422);
        }

        $absolutePath = storage_path('app/' . ltrim($storedPath, '/'));
        if (!is_file($absolutePath)) {
            return response()->json([
                'message' => 'Stored upload file no longer exists on disk.',
            ], 422);
        }

        $updated = $this->uploadBatchStatusService->put($batchId, [
            'status' => 'queued',
            'dry_run' => false,
            'queued_at' => now()->toDateTimeString(),
            'message' => 'Creating campaigns from dry-run batch.',
            'started_at' => null,
            'processing_started_at' => null,
            'profiles_processed' => 0,
            'updated_at' => now()->toDateTimeString(),
        ]);

        $pasteMode = (bool) ($status['paste_mode'] ?? false);
        $totalItems = (int) ($status['total_items'] ?? 0);
        $expressMode = $pasteMode && $totalItems > 0 && $totalItems <= self::EXPRESS_PASTE_MAX_ROWS;

        if ($expressMode) {
            $updated = $this->uploadBatchStatusService->put($batchId, [
                'express_mode' => true,
            ]);

            ProcessPushUploadJob::dispatchSync(
                $batchId,
                $absolutePath,
                (string) ($status['source_filename'] ?? basename($absolutePath)),
                (int) $request->user()->id,
                false,
                true,
            );

            return response()->json([
                'message' => 'Express campaign creation completed from dry-run batch.',
                'status_payload' => $this->uploadBatchStatusService->get($batchId),
            ]);
        }

        ProcessPushUploadJob::dispatch(
            $batchId,
            $absolutePath,
            (string) ($status['source_filename'] ?? basename($absolutePath)),
            (int) $request->user()->id,
            false,
            false,
        );

        return response()->json([
            'message' => 'Campaign creation queued from dry-run batch.',
            'status_payload' => $updated,
        ], 202);
    }

    public function confirmQueuedBatch(Request $request, string $batchId)
    {
        $batchState = $this->uploadBatchStatusService->get($batchId);
        if (is_array($batchState)) {
            $this->ensureUploadBatchAccess($request, $batchState);
        }

        $payload = $this->confirmBatch(
            $request,
            $batchId,
            [],
            $batchState
        );

        return response()->json($payload);
    }

    public function crmProfiles(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'search' => 'nullable|string|max:255',
            'profile_status' => 'nullable|in:publish,private,draft,pending',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if (!empty($validated['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $validated['platform_id'],
                'You do not have access to this market.'
            );
        }

        $query = Client::query()
            ->with('platform:id,name,country,domain')
            ->where('client_type', 'escort')
            ->orderByDesc('id');

        if (!empty($validated['platform_id'])) {
            $query->where('platform_id', (int) $validated['platform_id']);
        } else {
            $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
            if (is_array($allowedPlatformIds)) {
                $query->whereIn('platform_id', $allowedPlatformIds);
            }
        }

        if (!empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $normalizedDigits = preg_replace('/\D+/', '', $search) ?? '';
            $query->where(function ($builder) use ($search, $normalizedDigits): void {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('phone_normalized', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('city', 'like', '%' . $search . '%');

                if ($normalizedDigits !== '') {
                    $builder->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_normalized, ''), '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') like ?",
                        ['%' . $normalizedDigits . '%']
                    );
                }
            });
        }

        if (!empty($validated['profile_status'])) {
            $query->where('profile_status', (string) $validated['profile_status']);
        }

        $profiles = $query->paginate((int) ($validated['per_page'] ?? 25));
        $profiles->through(function (Client $client): array {
            return [
                'id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'platform_name' => $client->platform?->name,
                'platform_country' => $client->platform?->country,
                'name' => $client->name,
                'phone_normalized' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'profile_status' => $client->profile_status,
                'premium' => (bool) $client->premium,
                'featured' => (bool) $client->featured,
                'verified' => (bool) $client->verified,
                'main_image_url' => $client->main_image_url,
                'wp_post_id' => $client->wp_post_id ? (int) $client->wp_post_id : null,
                'wp_profile_url' => $client->wp_profile_url,
            ];
        });

        return response()->json($profiles);
    }

    public function storeFromCrm(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'client_ids' => 'required|array|min:1|max:500',
            'client_ids.*' => 'integer|exists:clients,id',
            'message' => 'required|string|max:255',
            'campaign_name' => 'nullable|string|max:255',
            'scheduled_at' => 'nullable|date',
            'timezone' => 'nullable|string|max:64',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this market.'
        );

        $platform = Platform::query()->findOrFail($platformId);
        $platformTimezone = MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC'));
        $clientIds = collect((array) $validated['client_ids'])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $clients = Client::query()
            ->where('platform_id', $platformId)
            ->where('client_type', 'escort')
            ->whereIn('id', $clientIds)
            ->with('platform:id,name,country,domain')
            ->get();

        if ($clients->count() !== count($clientIds)) {
            return response()->json([
                'message' => 'One or more selected escorts are invalid for the selected market.',
            ], 422);
        }

        $scheduledAt = !empty($validated['scheduled_at'])
            ? Carbon::parse((string) $validated['scheduled_at'], $platformTimezone)->utc()
            : null;

        $campaign = PushCampaign::query()->create([
            'name' => trim((string) ($validated['campaign_name'] ?? ($platform->name . ' CRM Escort Push - ' . now()->toDateString()))),
            'platform_id' => $platformId,
            'status' => 'draft',
            'total_items' => 0,
            'created_by' => (int) $request->user()->id,
            'upload_batch_id' => (string) Str::uuid(),
            'source_filename' => 'crm_selection',
            'scheduled_at' => $scheduledAt,
        ]);

        $message = trim((string) $validated['message']);
        $items = [];
        $skippedClientIds = [];
        $now = now();
        $scheduledAtString = $scheduledAt?->toDateTimeString();
        $dateLabel = $scheduledAt ? $scheduledAt->copy()->setTimezone($platformTimezone)->toDateString() : null;

        foreach ($clients as $client) {
            $profileUrl = $this->resolveClientProfileUrl($client, $platform);
            if (!$profileUrl) {
                $skippedClientIds[] = (int) $client->id;
                continue;
            }

            $items[] = [
                'campaign_id' => (int) $campaign->id,
                'client_id' => (int) $client->id,
                'profile_url' => $profileUrl,
                'wp_post_id' => $client->wp_post_id ? (int) $client->wp_post_id : null,
                'profile_name' => $client->name,
                'profile_phone' => $client->phone_normalized,
                'profile_image_url' => $client->main_image_url,
                'custom_message' => $message,
                'scheduled_at' => $scheduledAtString,
                'date_label' => $dateLabel,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($items)) {
            $campaign->delete();

            return response()->json([
                'message' => 'Selected escorts did not have resolvable profile URLs.',
                'skipped_client_ids' => $skippedClientIds,
            ], 422);
        }

        PushCampaignItem::query()->insert($items);

        $campaign->forceFill([
            'total_items' => count($items),
            'status' => 'draft',
        ])->save();

        $campaign->load('platform:id,name,country,timezone');
        if ($campaign->platform) {
            $campaign->platform->setAttribute('timezone', $platformTimezone);
        }

        return response()->json([
            'campaign' => $campaign,
            'created_items' => count($items),
            'skipped_client_ids' => $skippedClientIds,
        ], 201);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'batch_id' => 'required|string|max:64',
            'campaign_ids' => 'nullable|array',
            'campaign_ids.*' => 'integer|exists:push_campaigns,id',
        ]);

        return response()->json(
            $this->confirmBatch(
                $request,
                (string) $validated['batch_id'],
                array_map('intval', (array) ($validated['campaign_ids'] ?? [])),
                $this->uploadBatchStatusService->get((string) $validated['batch_id']),
            )
        );
    }

    public function show(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        $validated = $request->validate([
            'status' => 'nullable|in:pending_extraction,needs_preset,pending,scheduled,sent,failed,skipped',
            'per_page' => 'nullable|integer|min:10|max:250',
        ]);

        $pushCampaign->load([
            'platform:id,name,country,timezone',
            'creator:id,name,email',
        ]);
        if ($pushCampaign->platform) {
            $pushCampaign->platform->setAttribute(
                'timezone',
                MarketTimezone::resolve($pushCampaign->platform->timezone, config('app.timezone', 'UTC'))
            );
        }

        $itemsQuery = PushCampaignItem::query()
            ->where('campaign_id', (int) $pushCampaign->id)
            ->orderBy('scheduled_at')
            ->orderBy('id');

        if (!empty($validated['status'])) {
            $itemsQuery->where('status', (string) $validated['status']);
        }

        $items = $itemsQuery->paginate((int) ($validated['per_page'] ?? 50));
        $timingReferenceUtc = now()->utc();
        $timingTimezone = MarketTimezone::resolve($pushCampaign->platform?->timezone, config('app.timezone', 'UTC'));
        $items->getCollection()->transform(function (PushCampaignItem $item) use ($timingReferenceUtc, $timingTimezone): PushCampaignItem {
            $timing = $this->pushCampaignDispatchReadinessService->describeItemTimingState(
                $item,
                $timingReferenceUtc,
                $timingTimezone
            );

            $item->setAttribute('timing_state', (string) ($timing['timing_state'] ?? 'unscheduled'));
            $item->setAttribute('timing_reference_timezone', (string) ($timing['timing_reference_timezone'] ?? $timingTimezone));
            $item->setAttribute('is_overdue', (bool) ($timing['is_overdue'] ?? false));

            return $item;
        });

        return response()->json([
            'campaign' => $pushCampaign,
            'items' => $items,
        ]);
    }

    public function updateItem(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);
        $this->ensureCampaignItemMutable($pushCampaignItem, 'Sent items cannot be edited.');
        $pushCampaign->loadMissing('platform:id,timezone');

        $validated = $request->validate([
            'custom_message' => 'sometimes|required|string|max:255',
            'scheduled_at' => 'sometimes|nullable|date',
            'profile_url' => 'sometimes|required|url|max:500',
            'profile_name' => 'sometimes|nullable|string|max:255',
            'profile_phone' => 'sometimes|nullable|string|max:30',
            'profile_image_url' => 'sometimes|nullable|url|max:500',
            'profile_age' => 'sometimes|nullable|string|max:10',
            'timezone' => 'nullable|string|max:64',
        ]);

        $editableFields = [
            'custom_message',
            'scheduled_at',
            'profile_url',
            'profile_name',
            'profile_phone',
            'profile_image_url',
            'profile_age',
        ];
        $hasEditableField = collect($editableFields)->contains(fn(string $field): bool => array_key_exists($field, $validated));

        if (!$hasEditableField) {
            return response()->json([
                'message' => 'No editable fields were provided.',
            ], 422);
        }

        $payload = [];

        if (array_key_exists('custom_message', $validated)) {
            $payload['custom_message'] = trim((string) $validated['custom_message']);
        }

        if (array_key_exists('profile_url', $validated)) {
            $profileUrl = trim((string) $validated['profile_url']);
            $payload['profile_url'] = $profileUrl;
            $payload['wp_post_id'] = $this->parseWpPostIdFromUrl($profileUrl);
            $payload['client_id'] = null;
        }

        if (array_key_exists('profile_name', $validated)) {
            $payload['profile_name'] = $validated['profile_name'] === null
                ? null
                : trim((string) $validated['profile_name']);
        }

        if (array_key_exists('profile_phone', $validated)) {
            $payload['profile_phone'] = $validated['profile_phone'] === null
                ? null
                : trim((string) $validated['profile_phone']);
        }

        if (array_key_exists('profile_image_url', $validated)) {
            $payload['profile_image_url'] = $validated['profile_image_url'] === null
                ? null
                : trim((string) $validated['profile_image_url']);
        }

        if (array_key_exists('profile_age', $validated)) {
            $payload['profile_age'] = $validated['profile_age'] === null
                ? null
                : trim((string) $validated['profile_age']);
        }

        if (array_key_exists('scheduled_at', $validated)) {
            $timezone = MarketTimezone::resolve($pushCampaign->platform?->timezone, config('app.timezone', 'UTC'));
            $scheduledAt = $validated['scheduled_at']
                ? Carbon::parse((string) $validated['scheduled_at'], $timezone)->utc()
                : null;

            $payload['scheduled_at'] = $scheduledAt?->toDateTimeString();
            $payload['date_label'] = $scheduledAt
                ? $scheduledAt->copy()->setTimezone($timezone)->toDateString()
                : null;
        }

        $profileFieldChanged = collect(['profile_url', 'profile_name', 'profile_phone', 'profile_image_url', 'profile_age'])
            ->contains(fn(string $field): bool => array_key_exists($field, $payload));

        if ($profileFieldChanged) {
            $payload['error_message'] = null;
            if (in_array((string) $pushCampaignItem->status, ['failed', 'pending_extraction', 'needs_preset'], true)) {
                $payload['status'] = 'pending';
            }
        }

        $pushCampaignItem->forceFill($payload)->save();
        $this->recalculateCampaignActiveTotals($pushCampaign);

        return response()->json([
            'item' => $pushCampaignItem->fresh(),
            'message' => 'Campaign item updated.',
        ]);
    }

    public function matchCandidates(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);

        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:25',
        ]);

        $paginator = $this->pushCampaignItemMatchService->paginateCandidatesForProfile(
            (int) $pushCampaign->platform_id,
            (string) $pushCampaignItem->profile_url,
            $validated['search'] ?? null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 10),
        );

        return response()->json($paginator);
    }

    public function matchCrm(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);
        $this->ensureCampaignItemMutable($pushCampaignItem, 'Sent items cannot be matched.');

        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'replace_profile_url' => 'nullable|boolean',
            'hydrate_wp_details' => 'nullable|boolean',
        ]);

        $client = $this->pushCampaignItemMatchService->findScopedEscortClient(
            (int) $pushCampaign->platform_id,
            (int) $validated['client_id']
        );

        if (!$client) {
            return response()->json([
                'message' => 'Selected CRM profile is not an escort in this campaign market.',
            ], 422);
        }

        $replaceProfileUrl = array_key_exists('replace_profile_url', $validated)
            ? (bool) $validated['replace_profile_url']
            : true;
        $hydrateWpDetails = array_key_exists('hydrate_wp_details', $validated)
            ? (bool) $validated['hydrate_wp_details']
            : true;

        $pushCampaign->loadMissing('platform:id,domain,wp_api_url,wp_api_user,wp_api_password');
        $profileUrl = (string) $pushCampaignItem->profile_url;

        if ($replaceProfileUrl) {
            $resolvedUrl = $this->resolveClientProfileUrl($client, $pushCampaign->platform);
            if ($resolvedUrl) {
                $profileUrl = $resolvedUrl;
            }
        }

        $payload = [
            'client_id' => (int) $client->id,
            'wp_post_id' => (int) ($client->wp_post_id ?? 0) ?: $this->parseWpPostIdFromUrl($profileUrl),
            'profile_url' => $profileUrl,
            'profile_name' => $client->name,
            'profile_phone' => $client->phone_normalized,
            'profile_image_url' => $client->main_image_url,
            'status' => 'pending',
            'error_message' => null,
        ];

        $wpPostId = (int) ($payload['wp_post_id'] ?? 0);
        if ($hydrateWpDetails && $wpPostId > 0 && $this->platformHasWpIntegration($pushCampaign->platform)) {
            try {
                $wpPayload = (new WpSyncService($pushCampaign->platform))->getClientProfile($wpPostId);
                $fields = $this->extractWpProfileFields($wpPayload);

                if (!empty($fields['phone'])) {
                    $payload['profile_phone'] = $fields['phone'];
                }
                if (!empty($fields['image'])) {
                    $payload['profile_image_url'] = $fields['image'];
                }
                if (!empty($fields['age_value'])) {
                    $payload['profile_age'] = $fields['age_value'];
                }
            } catch (\Throwable) {
                // Keep CRM payload if WP hydration fails.
            }
        }

        $pushCampaignItem->forceFill($payload)->save();
        if ($hydrateWpDetails) {
            $this->hydratePushCampaignItemProfile($pushCampaign, $pushCampaignItem, true, true);
        }
        $this->recalculateCampaignActiveTotals($pushCampaign);

        return response()->json([
            'item' => $pushCampaignItem->fresh(),
            'message' => 'Campaign item matched with CRM profile.',
        ]);
    }

    public function removeItem(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);
        $this->ensureCampaignItemMutable($pushCampaignItem, 'Sent items cannot be removed.');

        if ((string) $pushCampaignItem->status !== 'skipped') {
            $pushCampaignItem->forceFill([
                'status' => 'skipped',
                'error_message' => null,
            ])->save();
        }

        $this->recalculateCampaignActiveTotals($pushCampaign);

        return response()->json([
            'item' => $pushCampaignItem->fresh(),
            'message' => 'Campaign item removed from active send list.',
        ]);
    }

    public function hydrateItemProfile(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);
        $this->ensureCampaignItemMutable($pushCampaignItem, 'Sent items cannot be hydrated.');

        $validated = $request->validate([
            'force' => 'nullable|boolean',
        ]);

        $force = (bool) ($validated['force'] ?? false);
        $result = $this->hydratePushCampaignItemProfile($pushCampaign, $pushCampaignItem, $force, true);

        return response()->json([
            'item' => $result['item'],
            'sources' => $result['sources'],
            'media' => $result['media'],
        ]);
    }

    public function itemMedia(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);

        $context = $this->resolveItemWpContext($pushCampaign, $pushCampaignItem, true);
        if (($context['error'] ?? null) !== null) {
            return response()->json([
                'message' => (string) $context['error'],
            ], 422);
        }

        $media = $this->fetchWpMediaOptions((array) $context);
        $recommended = $this->pickRecommendedMedia($media);

        return response()->json([
            'data' => $media,
            'selected_url' => $pushCampaignItem->profile_image_url ?: null,
            'recommended_url' => $recommended['url'] ?? null,
        ]);
    }

    public function selectItemMedia(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);
        $this->ensureCampaignItemMutable($pushCampaignItem, 'Sent items cannot be edited.');

        $validated = $request->validate([
            'attachment_id' => 'required|integer|min:1',
        ]);

        $context = $this->resolveItemWpContext($pushCampaign, $pushCampaignItem, true);
        if (($context['error'] ?? null) !== null) {
            return response()->json([
                'message' => (string) $context['error'],
            ], 422);
        }

        $media = $this->fetchWpMediaOptions((array) $context);
        $selected = collect($media)->first(fn(array $item): bool => (int) ($item['id'] ?? 0) === (int) $validated['attachment_id']);

        if (!$selected) {
            return response()->json([
                'message' => 'Selected media item was not found for this profile.',
            ], 422);
        }

        $pushCampaignItem->forceFill([
            'profile_image_url' => (string) ($selected['url'] ?? ''),
            'error_message' => null,
            'status' => in_array((string) $pushCampaignItem->status, ['failed', 'pending_extraction', 'needs_preset'], true)
                ? 'pending'
                : $pushCampaignItem->status,
        ])->save();
        $this->recalculateCampaignActiveTotals($pushCampaign);

        return response()->json([
            'item' => $pushCampaignItem->fresh(),
            'selected_media' => $selected,
        ]);
    }

    public function uploadItemMedia(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $this->ensureCampaignItemBelongsToCampaign($pushCampaign, $pushCampaignItem);
        $this->ensureCampaignItemMutable($pushCampaignItem, 'Sent items cannot be edited.');

        $validated = $request->validate([
            'file' => 'required|file|mimes:' . self::PROFILE_IMAGE_ALLOWED_EXTENSIONS . '|max:5120',
            'apply_to_item' => 'nullable|boolean',
        ], [
            'file.mimes' => 'Campaign profile media must be a JPEG, PNG, or WEBP image.',
            'file.max' => 'Campaign profile images must not exceed 5MB.',
        ]);

        $context = $this->resolveItemWpContext($pushCampaign, $pushCampaignItem, true);
        if (($context['error'] ?? null) !== null) {
            return response()->json([
                'message' => (string) $context['error'],
            ], 422);
        }

        try {
            /** @var Platform $platform */
            $platform = $context['platform'];
            $wpPostId = (int) ($context['wp_post_id'] ?? 0);
            $wpSync = new WpSyncService($platform);

            $upload = $wpSync->uploadClientMedia(
                $wpPostId,
                $request->file('file'),
                false
            );

            $media = $this->normalizeWpMediaItems($wpSync->getClientMedia($wpPostId));
            $attachmentId = (int) data_get($upload, 'attachment.id', 0);
            $uploadedMedia = collect($media)->first(fn(array $item): bool => (int) ($item['id'] ?? 0) === $attachmentId);

            $applyToItem = array_key_exists('apply_to_item', $validated)
                ? (bool) $validated['apply_to_item']
                : true;

            if ($applyToItem && $uploadedMedia && !empty($uploadedMedia['url'])) {
                $pushCampaignItem->forceFill([
                    'profile_image_url' => (string) $uploadedMedia['url'],
                    'error_message' => null,
                    'status' => in_array((string) $pushCampaignItem->status, ['failed', 'pending_extraction', 'needs_preset'], true)
                        ? 'pending'
                        : $pushCampaignItem->status,
                ])->save();
                $this->recalculateCampaignActiveTotals($pushCampaign);
            }

            return response()->json([
                'item' => $pushCampaignItem->fresh(),
                'uploaded_media' => $uploadedMedia ?: [
                    'id' => $attachmentId > 0 ? $attachmentId : null,
                    'url' => data_get($upload, 'attachment.url'),
                    'filename' => data_get($upload, 'attachment.filename'),
                    'is_main' => false,
                    'mime_type' => data_get($upload, 'attachment.mime_type'),
                    'uploaded_at' => data_get($upload, 'attachment.uploaded_at'),
                ],
                'media' => $media,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to upload profile media for campaign item.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function dispatchReadiness(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        $validated = $request->validate([
            'mode' => 'nullable|in:execute_now,schedule',
            'scheduled_at' => 'nullable|date',
            'timezone' => 'nullable|string|max:64',
        ]);

        $pushCampaign->loadMissing('platform:id,timezone');
        $mode = (string) ($validated['mode'] ?? 'execute_now');
        $timezone = MarketTimezone::resolve($pushCampaign->platform?->timezone, config('app.timezone', 'UTC'));
        $activationAt = now()->utc();

        if ($mode === 'schedule') {
            if (empty($validated['scheduled_at'])) {
                return response()->json([
                    'message' => 'scheduled_at is required when mode is schedule.',
                ], 422);
            }

            $activationAt = Carbon::parse((string) $validated['scheduled_at'], $timezone)->utc();
        }

        $readiness = $this->pushCampaignDispatchReadinessService->analyzeActivation(
            $pushCampaign,
            $activationAt,
            $timezone
        );

        return response()->json($readiness);
    }

    public function execute(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        if ($pushCampaign->status === 'processing') {
            return response()->json([
                'message' => 'Campaign is still processing and cannot be executed yet.',
            ], 422);
        }

        $pushCampaign->loadMissing('platform:id,timezone');

        if ($request->boolean('reschedule_overdue')) {
            $graceThreshold = now()->utc()->subMinutes(
                PushCampaignDispatchReadinessService::LATE_GRACE_MINUTES
            );
            PushCampaignItem::query()
                ->where('campaign_id', (int) $pushCampaign->id)
                ->where('status', 'pending')
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<', $graceThreshold->toDateTimeString())
                ->update(['scheduled_at' => now()->utc()->toDateTimeString()]);
        }

        $readiness = $this->pushCampaignDispatchReadinessService->analyzeActivation(
            $pushCampaign,
            now()->utc(),
            MarketTimezone::resolve($pushCampaign->platform?->timezone, config('app.timezone', 'UTC'))
        );

        if (!(bool) ($readiness['can_activate'] ?? false)) {
            return $this->readinessBlockedResponse($readiness);
        }

        $campaign = $this->pushCampaignService->executeCampaign($pushCampaign, (int) $request->user()->id);

        return response()->json([
            'campaign' => $campaign,
            'dispatch_plan' => $readiness,
        ]);
    }

    public function schedule(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);
        $pushCampaign->loadMissing('platform:id,timezone');

        $validated = $request->validate([
            'scheduled_at' => 'required|date',
            'timezone' => 'nullable|string|max:64',
        ]);

        $timezone = MarketTimezone::resolve($pushCampaign->platform?->timezone, config('app.timezone', 'UTC'));
        $scheduledAt = Carbon::parse((string) $validated['scheduled_at'], $timezone)->utc();

        if ($scheduledAt->lessThanOrEqualTo(now())) {
            return response()->json([
                'message' => 'scheduled_at must be in the future.',
            ], 422);
        }

        $readiness = $this->pushCampaignDispatchReadinessService->analyzeActivation(
            $pushCampaign,
            $scheduledAt,
            $timezone
        );

        if (!(bool) ($readiness['can_activate'] ?? false)) {
            return $this->readinessBlockedResponse($readiness);
        }

        $campaign = $this->pushCampaignService->scheduleCampaign(
            $pushCampaign,
            $scheduledAt,
            (int) $request->user()->id
        );

        return response()->json([
            'campaign' => $campaign,
            'dispatch_plan' => $readiness,
        ]);
    }

    public function analytics(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        $campaign = $this->pushCampaignService->refreshAnalytics($pushCampaign);
        $totals = $this->deliveryTotals((int) $campaign->id);

        // Item-level status breakdown (always available, doesn't depend on provider stats).
        $itemCounts = PushCampaignItem::query()
            ->where('campaign_id', (int) $campaign->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return response()->json([
            'campaign' => $campaign,
            'analytics' => $totals,
            'item_summary' => [
                'total' => (int) $itemCounts->sum(),
                'sent' => (int) $itemCounts->get('sent', 0),
                'failed' => (int) $itemCounts->get('failed', 0),
                'skipped' => (int) $itemCounts->get('skipped', 0),
                'pending' => (int) $itemCounts->get('pending', 0),
                'scheduled' => (int) $itemCounts->get('scheduled', 0),
            ],
        ]);
    }

    /**
     * Create a new campaign from the failed/missed/skipped items of an existing campaign,
     * shifting each item's scheduled_at forward by a given number of days.
     */
    public function reschedule(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        $request->validate([
            'shift_days' => 'required|integer|min:0|max:30',
            'include_statuses' => 'nullable|array',
            'include_statuses.*' => 'string|in:failed,skipped,pending,scheduled',
        ]);

        $shiftDays = (int) $request->input('shift_days', 1);
        $includeStatuses = $request->input('include_statuses', ['failed', 'skipped']);

        $sourceItems = PushCampaignItem::query()
            ->where('campaign_id', (int) $pushCampaign->id)
            ->whereIn('status', $includeStatuses)
            ->get();

        if ($sourceItems->isEmpty()) {
            return response()->json([
                'message' => 'No items match the selected statuses to reschedule.',
            ], 422);
        }

        $newCampaign = PushCampaign::query()->create([
            'platform_id' => (int) $pushCampaign->platform_id,
            'name' => sprintf('%s (rescheduled)', $pushCampaign->name),
            'status' => 'draft',
            'provider' => $pushCampaign->provider,
            'source_filename' => $pushCampaign->source_filename,
            'upload_batch_id' => null,
            'created_by' => $request->user()?->id,
        ]);

        $created = 0;
        foreach ($sourceItems as $srcItem) {
            $newScheduledAt = $srcItem->scheduled_at
                ? $srcItem->scheduled_at->copy()->addDays($shiftDays)
                : ($shiftDays > 0 ? now()->utc()->addDays($shiftDays) : null);

            PushCampaignItem::query()->create([
                'campaign_id' => (int) $newCampaign->id,
                'client_id' => $srcItem->client_id,
                'wp_post_id' => $srcItem->wp_post_id,
                'profile_url' => $srcItem->profile_url,
                'profile_name' => $srcItem->profile_name,
                'profile_city' => $srcItem->profile_city,
                'profile_phone' => $srcItem->profile_phone,
                'profile_image_url' => $srcItem->profile_image_url,
                'profile_age' => $srcItem->profile_age,
                'custom_message' => $srcItem->custom_message,
                'scheduled_at' => $newScheduledAt,
                'status' => 'pending',
            ]);
            $created++;
        }

        $newCampaign->forceFill(['total_items' => $created])->save();

        app(AuditService::class)->record([
            'platform_id' => (int) $newCampaign->platform_id,
            'actor_id' => $request->user()?->id,
            'action' => 'push_campaign_create',
            'entity_type' => 'push_campaign',
            'entity_id' => (int) $newCampaign->id,
            'after_state' => [
                'source_campaign_id' => (int) $pushCampaign->id,
                'shift_days' => $shiftDays,
                'items_rescheduled' => $created,
            ],
            'reason' => sprintf(
                'Rescheduled %d items from campaign #%d with %d-day shift.',
                $created,
                $pushCampaign->id,
                $shiftDays
            ),
        ]);

        return response()->json([
            'message' => sprintf('%d items rescheduled into new campaign.', $created),
            'campaign' => $newCampaign->fresh(),
        ]);
    }

    public function destroy(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        if (!in_array((string) $pushCampaign->status, ['draft', 'failed'], true)) {
            return response()->json([
                'message' => 'Only draft or failed campaigns can be deleted.',
            ], 422);
        }

        $pushCampaign->delete();

        return response()->json([
            'message' => 'Campaign deleted.',
        ]);
    }

    public function dashboard(Request $request)
    {
        $campaignQuery = PushCampaign::query();
        $this->marketAuthorizationService->applyPlatformScope($campaignQuery, $request->user());

        $campaignIds = $campaignQuery->pluck('id');
        $platformScopeQuery = Platform::query();
        $this->marketAuthorizationService->applyPlatformScope($platformScopeQuery, $request->user());
        $platformIds = $platformScopeQuery
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        $sentToday = 0;
        $avgClickRate = null;
        $totalSubscribers = 0;
        $activeSubscribers = 0;

        if ($campaignIds->isNotEmpty()) {
            $sentToday = PushCampaignItem::query()
                ->whereIn('campaign_id', $campaignIds->all())
                ->where('status', 'sent')
                ->whereDate('sent_at', now()->toDateString())
                ->count();

            $aggregate = $this->deliveryTotalsForCampaignIds($campaignIds->all());
            if (($aggregate['delivered'] ?? 0) > 0) {
                $avgClickRate = round(((float) $aggregate['clicked'] / (float) $aggregate['delivered']) * 100, 2);
            }
        }

        if (!empty($platformIds)) {
            $snapshots = PushSubscriberSnapshot::query()
                ->whereIn('platform_id', $platformIds)
                ->orderByDesc('snapshot_date')
                ->orderByDesc('id')
                ->get();

            $latestByPlatform = [];
            foreach ($snapshots as $snapshot) {
                if (isset($latestByPlatform[$snapshot->platform_id])) {
                    continue;
                }
                $latestByPlatform[$snapshot->platform_id] = $snapshot;
            }

            foreach ($latestByPlatform as $snapshot) {
                $totalSubscribers += (int) $snapshot->total_subscribers;
                $activeSubscribers += (int) $snapshot->active_subscribers;
            }
        }

        $totalCampaigns = (int) (clone $campaignQuery)->count();
        $pendingCampaigns = (int) (clone $campaignQuery)
            ->whereIn('status', ['processing', 'draft', 'scheduled', 'running'])
            ->count();

        return response()->json([
            'total_campaigns' => $totalCampaigns,
            'pending_campaigns' => $pendingCampaigns,
            'sent_today' => $sentToday,
            'avg_click_rate' => $avgClickRate,
            'total_subscribers' => $totalSubscribers,
            'active_subscribers' => $activeSubscribers,
        ]);
    }

    public function subscribers(Request $request)
    {
        $platformQuery = Platform::query()->orderBy('id');
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $platformQuery->whereIn('id', $allowedPlatformIds);
        }

        $platforms = $platformQuery->get(['id', 'name', 'country', 'domain']);
        $platformIds = $platforms->pluck('id')->map(fn($id) => (int) $id)->all();
        $historyByPlatform = [];
        $latestByPlatform = [];

        if (!empty($platformIds)) {
            $snapshots = PushSubscriberSnapshot::query()
                ->whereIn('platform_id', $platformIds)
                ->where('snapshot_date', '>=', now()->subDays(29)->toDateString())
                ->orderBy('snapshot_date')
                ->orderBy('id')
                ->get();

            foreach ($snapshots as $snapshot) {
                $historyByPlatform[$snapshot->platform_id][] = [
                    'snapshot_date' => optional($snapshot->snapshot_date)->toDateString() ?: (string) $snapshot->getRawOriginal('snapshot_date'),
                    'provider' => (string) $snapshot->provider,
                    'total_subscribers' => (int) $snapshot->total_subscribers,
                    'active_subscribers' => (int) $snapshot->active_subscribers,
                ];
            }

            foreach ($snapshots->sortByDesc(fn($snapshot) => sprintf('%s-%010d', $snapshot->snapshot_date, $snapshot->id)) as $snapshot) {
                if (!isset($latestByPlatform[$snapshot->platform_id])) {
                    $latestByPlatform[$snapshot->platform_id] = $snapshot;
                }
            }
        }

        return response()->json([
            'items' => $platforms->map(fn(Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'platform_name' => $platform->name,
                'country' => $platform->country,
                'domain' => $platform->domain,
                'provider' => $latestByPlatform[$platform->id]->provider ?? null,
                'total_subscribers' => isset($latestByPlatform[$platform->id]) ? (int) $latestByPlatform[$platform->id]->total_subscribers : null,
                'active_subscribers' => isset($latestByPlatform[$platform->id]) ? (int) $latestByPlatform[$platform->id]->active_subscribers : null,
                'last_synced_at' => isset($latestByPlatform[$platform->id]) ? optional($latestByPlatform[$platform->id]->updated_at)->toDateTimeString() : null,
                'history' => $historyByPlatform[$platform->id] ?? [],
            ])->values(),
            'note' => 'Subscribers are captured from provider APIs. Users still must opt-in on each market domain.',
        ]);
    }

    public function syncSubscribers(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        if (!empty($validated['platform_id'])) {
            $platform = Platform::query()->find((int) $validated['platform_id']);
            if (!$platform) {
                return response()->json([
                    'message' => 'Selected platform was not found.',
                ], 404);
            }

            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $platform->id,
                'You do not have access to this market.'
            );

            $result = $this->subscriberSyncService->syncPlatform($platform);
            $diagnostic = null;
            if (!$result) {
                $diagnostic = $this->pushProviderService->debugSubscriberCountForPlatform((int) $platform->id);
            }

            return response()->json([
                'synced' => $result ? 1 : 0,
                'results' => $result ? [$result] : [],
                'diagnostics' => $diagnostic ? [[
                    'platform_id' => (int) $platform->id,
                    'provider' => $diagnostic['provider'] ?? null,
                    'error' => $diagnostic['error'] ?? null,
                ]] : [],
                'message' => $result
                    ? 'Subscriber snapshot synced for selected market.'
                    : ((string) ($diagnostic['error'] ?? 'No subscriber data returned. Verify provider credentials and active provider for this market.')),
            ]);
        }

        $results = $this->subscriberSyncService->syncAllPlatforms();
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($allowedPlatformIds)) {
            $results = array_values(array_filter($results, static fn(array $row): bool => in_array((int) ($row['platform_id'] ?? 0), $allowedPlatformIds, true)));
        }

        $resultPlatformIds = collect($results)
            ->pluck('platform_id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        $diagnostics = [];
        $platformQuery = Platform::query()->where('is_active', true)->orderBy('id');
        if (is_array($allowedPlatformIds)) {
            $platformQuery->whereIn('id', $allowedPlatformIds);
        }

        foreach ($platformQuery->get(['id']) as $platform) {
            $platformId = (int) $platform->id;
            if (in_array($platformId, $resultPlatformIds, true)) {
                continue;
            }

            $diagnostic = $this->pushProviderService->debugSubscriberCountForPlatform($platformId);
            if (!(bool) ($diagnostic['ok'] ?? false)) {
                $diagnostics[] = [
                    'platform_id' => $platformId,
                    'provider' => $diagnostic['provider'] ?? null,
                    'error' => $diagnostic['error'] ?? null,
                ];
            }
        }

        $message = count($results) > 0
            ? 'Subscriber snapshots synced successfully.'
            : 'No subscriber snapshots were synced. Verify provider credentials and active provider mapping per market.';

        if (count($results) === 0 && !empty($diagnostics)) {
            $first = $diagnostics[0];
            if (!empty($first['error'])) {
                $message = (string) $first['error'];
            }
        }

        return response()->json([
            'synced' => count($results),
            'results' => $results,
            'diagnostics' => $diagnostics,
            'message' => $message,
        ]);
    }

    public function listPresets(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'nullable|string|max:255',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'is_active' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if (!empty($validated['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $validated['platform_id'],
                'You do not have access to this market.'
            );
        }

        $query = ScraperProfilePreset::query()
            ->with('platform:id,name,country')
            ->with('creator:id,name,email')
            ->orderByDesc('updated_at');

        if (!empty($validated['domain'])) {
            $query->forDomain((string) $validated['domain']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        if (!empty($validated['platform_id'])) {
            $query->where('platform_id', (int) $validated['platform_id']);
        }

        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($allowedPlatformIds)) {
            $query->where(function ($builder) use ($allowedPlatformIds): void {
                $builder->whereNull('platform_id');
                if (!empty($allowedPlatformIds)) {
                    $builder->orWhereIn('platform_id', $allowedPlatformIds);
                }
            });
        }

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 25)));
    }

    public function detectPreset(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url|max:500',
        ]);

        $url = (string) $validated['url'];
        $domain = $this->extractDomainFromUrl($url);

        if (!$domain) {
            return response()->json([
                'message' => 'Unable to derive domain from URL.',
            ], 422);
        }

        $detection = $this->selectorDetectionService->detectSelectors($url);

        $existingPreset = ScraperProfilePreset::query()->forDomain($domain)->first();
        if ($existingPreset && !empty($existingPreset->platform_id)) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $existingPreset->platform_id,
                'You do not have access to this preset.'
            );
        }

        return response()->json([
            'domain' => $domain,
            'detected' => $detection,
            'existing_preset' => $existingPreset,
        ]);
    }

    public function storePreset(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'nullable|string|max:255',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'name_selector' => 'nullable|string|max:255',
            'age_selector' => 'nullable|string|max:255',
            'city_selector' => 'nullable|string|max:255',
            'phone_selector' => 'nullable|string|max:255',
            'image_selector' => 'nullable|string|max:255',
            'name_regex' => 'nullable|string|max:255',
            'age_regex' => 'nullable|string|max:255',
            'test_url' => 'nullable|url|max:500',
            'test_result' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $domain = $this->normalizeDomain((string) ($validated['domain'] ?? ''));
        if ($domain === null && !empty($validated['test_url'])) {
            $domain = $this->extractDomainFromUrl((string) $validated['test_url']);
        }

        if (!$domain) {
            return response()->json([
                'message' => 'A valid domain (or test_url with a valid domain) is required.',
            ], 422);
        }

        if (!empty($validated['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $validated['platform_id'],
                'You do not have access to this market.'
            );
        }

        $preset = ScraperProfilePreset::query()->firstOrNew([
            'domain' => $domain,
        ]);

        if ($preset->exists && !empty($preset->platform_id)) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $preset->platform_id,
                'You do not have access to this preset.'
            );
        }

        $preset->fill([
            'platform_id' => $validated['platform_id'] ?? $preset->platform_id,
            'name_selector' => $validated['name_selector'] ?? $preset->name_selector,
            'age_selector' => $validated['age_selector'] ?? $preset->age_selector,
            'city_selector' => $validated['city_selector'] ?? $preset->city_selector,
            'phone_selector' => $validated['phone_selector'] ?? $preset->phone_selector,
            'image_selector' => $validated['image_selector'] ?? $preset->image_selector,
            'name_regex' => $validated['name_regex'] ?? $preset->name_regex,
            'age_regex' => $validated['age_regex'] ?? $preset->age_regex,
            'test_url' => $validated['test_url'] ?? $preset->test_url,
            'test_result' => $validated['test_result'] ?? $preset->test_result,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : (bool) ($preset->is_active ?? true),
        ]);

        if (!$preset->exists || !$preset->created_by) {
            $preset->created_by = (int) $request->user()->id;
        }

        $created = !$preset->exists;
        $preset->save();
        $preset->load('platform:id,name,country', 'creator:id,name,email');

        return response()->json([
            'preset' => $preset,
        ], $created ? 201 : 200);
    }

    public function testPreset(Request $request, ScraperProfilePreset $preset)
    {
        $this->ensurePresetAccess($request, $preset);

        $validated = $request->validate([
            'url' => 'required|url|max:500',
        ]);

        $result = $this->selectorDetectionService->testPreset((string) $validated['url'], $preset);

        $preset->forceFill([
            'test_url' => (string) $validated['url'],
            'test_result' => $result,
        ])->save();

        return response()->json([
            'preset' => $preset,
            'result' => $result,
        ]);
    }

    public function updatePreset(Request $request, ScraperProfilePreset $preset)
    {
        $this->ensurePresetAccess($request, $preset);

        $validated = $request->validate([
            'domain' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('scraper_profile_presets', 'domain')->ignore((int) $preset->id),
            ],
            'platform_id' => 'sometimes|nullable|integer|exists:platforms,id',
            'name_selector' => 'sometimes|nullable|string|max:255',
            'age_selector' => 'sometimes|nullable|string|max:255',
            'city_selector' => 'sometimes|nullable|string|max:255',
            'phone_selector' => 'sometimes|nullable|string|max:255',
            'image_selector' => 'sometimes|nullable|string|max:255',
            'name_regex' => 'sometimes|nullable|string|max:255',
            'age_regex' => 'sometimes|nullable|string|max:255',
            'test_url' => 'sometimes|nullable|url|max:500',
            'test_result' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('platform_id', $validated) && !empty($validated['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $validated['platform_id'],
                'You do not have access to this market.'
            );
        }

        if (array_key_exists('domain', $validated)) {
            $normalizedDomain = $this->normalizeDomain((string) $validated['domain']);
            if (!$normalizedDomain) {
                return response()->json([
                    'message' => 'domain must be a valid host value.',
                ], 422);
            }
            $validated['domain'] = $normalizedDomain;
        }

        $preset->fill($validated)->save();
        $preset->load('platform:id,name,country', 'creator:id,name,email');

        return response()->json([
            'preset' => $preset,
        ]);
    }

    /**
     * @param array<string, mixed> $readiness
     */
    private function readinessBlockedResponse(array $readiness)
    {
        return response()->json(array_merge([
            'message' => 'Campaign activation is blocked by overdue item times. Reschedule overdue items first.',
        ], $readiness), 422);
    }

    private function ensureCampaignAccess(Request $request, PushCampaign $pushCampaign): void
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $pushCampaign->platform_id,
            'You do not have access to this campaign.'
        );
    }

    private function ensurePresetAccess(Request $request, ScraperProfilePreset $preset): void
    {
        if (empty($preset->platform_id)) {
            return;
        }

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $preset->platform_id,
            'You do not have access to this preset.'
        );
    }

    private function ensureCampaignItemBelongsToCampaign(PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem): void
    {
        if ((int) $pushCampaignItem->campaign_id !== (int) $pushCampaign->id) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Campaign item does not belong to this campaign.',
            ], 422));
        }
    }

    private function ensureCampaignItemMutable(PushCampaignItem $pushCampaignItem, string $message): void
    {
        if ((string) $pushCampaignItem->status === 'sent') {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => $message,
            ], 422));
        }
    }

    private function recalculateCampaignActiveTotals(PushCampaign $pushCampaign): void
    {
        $totals = PushCampaignItem::query()
            ->where('campaign_id', (int) $pushCampaign->id)
            ->selectRaw("SUM(CASE WHEN status != 'skipped' THEN 1 ELSE 0 END) AS active_total_items")
            ->selectRaw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_items")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_items")
            ->first();

        $pushCampaign->forceFill([
            'total_items' => (int) ($totals?->active_total_items ?? 0),
            'sent_count' => (int) ($totals?->sent_items ?? 0),
            'failed_count' => (int) ($totals?->failed_items ?? 0),
        ])->save();
    }

    /**
     * @param array<int, int> $campaignIds
     * @param array<string, mixed>|null $batchState
     * @return array<string, mixed>
     */
    private function confirmBatch(Request $request, string $batchId, array $campaignIds = [], ?array $batchState = null): array
    {
        if (is_array($batchState) && (bool) ($batchState['dry_run'] ?? false)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'This batch is in dry-run mode. Use "Create campaigns" first.',
            ], 422));
        }

        $query = PushCampaign::query()
            ->where('upload_batch_id', $batchId);

        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if (!empty($campaignIds)) {
            $query->whereIn('id', $campaignIds);
        }

        $campaigns = $query->get();

        if ($campaigns->isEmpty()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'No campaigns found for the requested batch.',
            ], 404));
        }

        $processingCount = $campaigns->where('status', 'processing')->count();
        if ($processingCount > 0) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Upload processing is still in progress. Wait for extraction to finish before confirming.',
            ], 422));
        }

        PushCampaign::query()
            ->whereIn('id', $campaigns->pluck('id')->all())
            ->whereNull('confirmed_at')
            ->update([
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

        $confirmed = PushCampaign::query()
            ->whereIn('id', $campaigns->pluck('id')->all())
            ->with('platform:id,name,country')
            ->orderBy('id')
            ->get();

        $this->uploadBatchStatusService->put($batchId, [
            'confirmed_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return [
            'batch_id' => $batchId,
            'confirmed_count' => $confirmed->count(),
            'campaigns' => $confirmed,
        ];
    }

    /**
     * @param array<string, mixed> $batch
     */
    private function ensureUploadBatchAccess(Request $request, array $batch): void
    {
        $ownerId = (int) ($batch['initiated_by'] ?? 0);
        $userId = (int) $request->user()->id;
        $role = (string) ($request->user()->role ?? '');

        if ($ownerId > 0 && $ownerId !== $userId && !in_array($role, ['admin', 'sub_admin'], true)) {
            abort(403, 'You do not have access to this upload batch.');
        }
    }

    private function findQueuedUploadJob(string $batchId): ?object
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        return DB::table('jobs')
            ->select(['id', 'payload'])
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->where('payload', 'like', '%ProcessPushUploadJob%')
            ->where('payload', 'like', '%' . $batchId . '%')
            ->orderBy('id')
            ->first();
    }

    private function decodeQueuedUploadCommand(string $payload): ?object
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $serializedCommand = (string) data_get($decoded, 'data.command', '');
        if ($serializedCommand === '') {
            return null;
        }

        try {
            $command = @unserialize($serializedCommand, ['allowed_classes' => true]);
        } catch (\Throwable) {
            return null;
        }

        return is_object($command) ? $command : null;
    }

    private function resolveStoredPathForBatch(string $batchId, string $sourceFilename = ''): string
    {
        $candidates = [];
        $extension = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $candidates[] = 'push-uploads/' . $batchId . '.' . $extension;
        }

        $candidates[] = 'push-uploads/' . $batchId . '.xlsx';
        $candidates[] = 'push-uploads/' . $batchId . '.xls';
        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            if (is_file(storage_path('app/' . $candidate))) {
                return $candidate;
            }
        }

        return '';
    }

    private function shouldProcessInline(\Illuminate\Http\UploadedFile $file, bool $dryRun): bool
    {
        if (!$dryRun) {
            return false;
        }

        $configuredMaxRows = config('services.push_campaigns.inline_dry_run_max_rows');
        $maxRows = is_numeric($configuredMaxRows) ? (int) $configuredMaxRows : 2000;
        if ($maxRows <= 0) {
            return false;
        }

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || !is_file($realPath)) {
            return false;
        }

        $estimatedRows = $this->estimateWorkbookRows($realPath);
        if ($estimatedRows === null) {
            return false;
        }

        return $estimatedRows > 0 && $estimatedRows <= $maxRows;
    }

    private function shouldProcessPasteInline(int $rowCount, bool $dryRun, bool $expressMode = false): bool
    {
        if ($rowCount <= 0) {
            return false;
        }

        if ($expressMode) {
            return true;
        }

        if (!$dryRun) {
            return false;
        }

        $configuredMaxRows = config('services.push_campaigns.inline_dry_run_max_rows');
        $maxRows = is_numeric($configuredMaxRows) ? (int) $configuredMaxRows : 2000;

        if ($maxRows <= 0) {
            return false;
        }

        return $rowCount <= $maxRows;
    }

    /**
     * @return array<int, array{date:string,profile_url:string,message:string,time:string}>
     */
    private function parsePasteRows(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($content));
        if ($normalized === '') {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Paste content is empty. Paste tab-separated rows: Date, Profile URL, Message, Time.',
            ], 422));
        }

        $rows = [];
        $lines = explode("\n", $normalized);

        foreach ($lines as $index => $line) {
            $rawLine = rtrim((string) $line, "\r\n");
            if (trim($rawLine) === '') {
                continue;
            }

            $lineNumber = $index + 1;
            $parsedColumns = str_getcsv($rawLine, "\t");
            if (!is_array($parsedColumns)) {
                $parsedColumns = explode("\t", $rawLine);
            }

            $columns = array_map(fn($value): string => trim((string) $value), $parsedColumns);
            while (!empty($columns) && end($columns) === '') {
                array_pop($columns);
            }

            if (count($columns) < 3) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => sprintf(
                        'Invalid paste format at line %d. Expected tab-separated columns: Date, Profile URL, Message, Time.',
                        $lineNumber
                    ),
                ], 422));
            }

            if (count($columns) > 4) {
                $time = (string) array_pop($columns);
                $date = (string) ($columns[0] ?? '');
                $profileUrl = (string) ($columns[1] ?? '');
                $message = trim(implode(' ', array_slice($columns, 2)));
            } elseif (count($columns) === 3) {
                $date = (string) ($columns[0] ?? '');
                $profileUrl = (string) ($columns[1] ?? '');
                $message = (string) ($columns[2] ?? '');
                $time = '';
            } else {
                $date = (string) ($columns[0] ?? '');
                $profileUrl = (string) ($columns[1] ?? '');
                $message = (string) ($columns[2] ?? '');
                $time = (string) ($columns[3] ?? '');
            }

            $rows[] = [
                'line' => $lineNumber,
                'date' => trim($date),
                'profile_url' => trim($profileUrl),
                'message' => trim($message),
                'time' => trim($time),
            ];
        }

        if (empty($rows)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Paste content has no non-empty rows. Paste tab-separated rows: Date, Profile URL, Message, Time.',
            ], 422));
        }

        if ($this->isPasteHeaderRow($rows[0])) {
            array_shift($rows);
        }

        if (empty($rows)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Only header row detected. Paste at least one data row.',
            ], 422));
        }

        $normalizedRows = [];
        $lastDate = '';

        foreach ($rows as $row) {
            $lineNumber = (int) ($row['line'] ?? 0);
            $date = trim((string) ($row['date'] ?? ''));
            $profileUrl = trim((string) ($row['profile_url'] ?? ''));
            $message = trim((string) ($row['message'] ?? ''));
            $time = trim((string) ($row['time'] ?? ''));

            if ($date !== '') {
                $lastDate = $date;
            }

            if ($profileUrl === '' && $message === '' && $time === '') {
                continue;
            }

            if ($profileUrl === '') {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => sprintf('Line %d is missing Profile URL. Expected columns: Date, Profile URL, Message, Time.', $lineNumber),
                ], 422));
            }

            if ($message === '') {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => sprintf('Line %d is missing Message. Expected columns: Date, Profile URL, Message, Time.', $lineNumber),
                ], 422));
            }

            if ($date === '' && $lastDate === '') {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'message' => sprintf('Line %d requires a Date (or a previous row date to fill down).', $lineNumber),
                ], 422));
            }

            $normalizedRows[] = [
                'date' => $date,
                'profile_url' => $profileUrl,
                'message' => $message,
                'time' => $time,
            ];
        }

        if (empty($normalizedRows)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'No valid data rows detected after validation. Check pasted columns and row values.',
            ], 422));
        }

        return $normalizedRows;
    }

    /**
     * @param array{date:string,profile_url:string,message:string,time:string}|array<string, mixed> $row
     */
    private function isPasteHeaderRow(array $row): bool
    {
        $normalizedDate = strtolower(trim((string) ($row['date'] ?? '')));
        $normalizedUrl = strtolower(trim((string) ($row['profile_url'] ?? '')));
        $normalizedMessage = strtolower(trim((string) ($row['message'] ?? '')));
        $normalizedTime = strtolower(trim((string) ($row['time'] ?? '')));

        $dateLike = str_contains($normalizedDate, 'date');
        $urlLike = str_contains($normalizedUrl, 'url')
            || str_contains($normalizedUrl, 'link')
            || str_contains($normalizedUrl, 'profile');
        $messageLike = str_contains($normalizedMessage, 'message');
        $timeLike = str_contains($normalizedTime, 'time');

        return $dateLike && $urlLike && $messageLike && $timeLike;
    }

    private function sourceFilenameForPaste(Platform $platform, int $year): string
    {
        $marketLabel = trim((string) ($platform->country ?: $platform->name ?: 'Market'));
        $marketLabel = preg_replace('/\s+/', ' ', $marketLabel) ?? $marketLabel;
        $marketLabel = trim((string) $marketLabel);
        if ($marketLabel === '') {
            $marketLabel = 'Market';
        }

        return sprintf('%s Push %d.xlsx', $marketLabel, $year);
    }

    private function worksheetTitleForPlatform(Platform $platform): string
    {
        $title = trim((string) ($platform->country ?: $platform->name ?: 'MARKET'));
        $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim((string) $title);

        if ($title === '') {
            $title = 'MARKET';
        }

        return substr($title, 0, 31);
    }

    /**
     * @param array<int, array{date:string,profile_url:string,message:string,time:string}> $rows
     */
    private function writePasteWorkbook(string $absolutePath, Platform $platform, int $year, array $rows): void
    {
        $directory = dirname($absolutePath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $spreadsheet = null;

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle($this->worksheetTitleForPlatform($platform));
            $sheet->setCellValue('A1', 'DATE');
            $sheet->setCellValue('B1', 'PROFILE URL');
            $sheet->setCellValue('C1', sprintf('%d MESSAGES', $year));
            $sheet->setCellValue('D1', 'TIME');

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheet->setCellValue('A' . $rowNumber, $row['date']);
                $sheet->setCellValue('B' . $rowNumber, $row['profile_url']);
                $sheet->setCellValue('C' . $rowNumber, $row['message']);
                $sheet->setCellValue('D' . $rowNumber, $row['time']);
                $rowNumber++;
            }

            (new Xlsx($spreadsheet))->save($absolutePath);
        } catch (\Throwable $exception) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => 'Failed to create workbook from pasted content.',
                'error' => $exception->getMessage(),
            ], 422));
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
        }
    }

    private function estimateWorkbookRows(string $filePath): ?int
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $worksheetInfo = $reader->listWorksheetInfo($filePath);
            $rows = 0;

            foreach ($worksheetInfo as $sheetMeta) {
                $rows += max(0, ((int) ($sheetMeta['totalRows'] ?? 0)) - 1);
            }

            return $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function pushUploadSetupIssues(bool $dryRun): array
    {
        $issues = [];

        if (config('queue.default') === 'database' && !Schema::hasTable('jobs')) {
            $issues[] = 'Missing table: jobs (required for QUEUE_CONNECTION=database).';
        }

        if (!$dryRun) {
            foreach (['push_campaigns', 'push_campaign_items'] as $table) {
                if (!Schema::hasTable($table)) {
                    $issues[] = "Missing table: {$table}.";
                }
            }
        }

        return $issues;
    }

    /**
     * @return array{total_sent:int, delivered:int, clicked:int, failed:int, closed:int}
     */
    private function deliveryTotals(int $campaignId): array
    {
        $items = PushCampaignItem::query()
            ->where('campaign_id', $campaignId)
            ->whereNotNull('delivery_stats')
            ->get(['delivery_stats']);

        $totals = [
            'total_sent' => 0,
            'delivered' => 0,
            'clicked' => 0,
            'failed' => 0,
            'closed' => 0,
        ];

        foreach ($items as $item) {
            $stats = is_array($item->delivery_stats) ? $item->delivery_stats : [];
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($stats[$key] ?? 0);
            }
        }

        $totals['ctr'] = $totals['delivered'] > 0
            ? round(($totals['clicked'] / $totals['delivered']) * 100, 1)
            : null;

        return $totals;
    }

    /**
     * @param array<int, int> $campaignIds
     * @return array{total_sent:int, delivered:int, clicked:int, failed:int, closed:int}
     */
    private function deliveryTotalsForCampaignIds(array $campaignIds): array
    {
        if (empty($campaignIds)) {
            return [
                'total_sent' => 0,
                'delivered' => 0,
                'clicked' => 0,
                'failed' => 0,
                'closed' => 0,
            ];
        }

        $items = PushCampaignItem::query()
            ->whereIn('campaign_id', $campaignIds)
            ->whereNotNull('delivery_stats')
            ->get(['delivery_stats']);

        $totals = [
            'total_sent' => 0,
            'delivered' => 0,
            'clicked' => 0,
            'failed' => 0,
            'closed' => 0,
        ];

        foreach ($items as $item) {
            $stats = is_array($item->delivery_stats) ? $item->delivery_stats : [];
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($stats[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function parseWpPostIdFromUrl(string $url): ?int
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/[?&]post_type=escort(?:&|;)?p=(\d+)/i', $trimmed, $match)) {
            return (int) ($match[1] ?? 0);
        }

        if (preg_match('/[?&]p=(\d+)/i', $trimmed, $match)) {
            return (int) ($match[1] ?? 0);
        }

        if (preg_match('#/(\d+)/?$#', $trimmed, $match)) {
            return (int) ($match[1] ?? 0);
        }

        return null;
    }

    private function platformHasWpIntegration(?Platform $platform): bool
    {
        if (!$platform) {
            return false;
        }

        return !empty($platform->wp_api_url)
            && !empty($platform->wp_api_user)
            && !empty($platform->wp_api_password);
    }

    /**
     * @return array{name:?string,phone:?string,image:?string,age_value:?string,birthday:?string}
     */
    private function extractWpProfileFields(array $payload): array
    {
        $profile = $payload['client'] ?? $payload['data'] ?? $payload;
        $profile = is_array($profile) ? $profile : [];
        $meta = is_array($profile['meta'] ?? null) ? $profile['meta'] : [];

        $name = $profile['name']
            ?? ($profile['post']['title'] ?? null)
            ?? null;

        $phone = $meta['phone']
            ?? ($profile['phone'] ?? null)
            ?? null;

        $image = $profile['main_image_url']
            ?? ($profile['featured_image'] ?? null)
            ?? ($meta['main_image_url'] ?? null)
            ?? null;

        $age = $meta['age']
            ?? ($meta['profile_age'] ?? null)
            ?? null;

        $birthday = $meta['birthday'] ?? ($profile['birthday'] ?? null);

        return [
            'name' => $name ? trim((string) $name) : null,
            'phone' => $phone ? trim((string) $phone) : null,
            'image' => $image ? trim((string) $image) : null,
            'age_value' => $age ? trim((string) $age) : null,
            'birthday' => $birthday ? trim((string) $birthday) : null,
        ];
    }

    /**
     * @return array{item:PushCampaignItem,sources:array{age_source:?string,image_source:?string},media:array<int,array<string,mixed>>}
     */
    private function hydratePushCampaignItemProfile(
        PushCampaign $pushCampaign,
        PushCampaignItem $pushCampaignItem,
        bool $force = false,
        bool $persist = true
    ): array {
        $context = $this->resolveItemWpContext($pushCampaign, $pushCampaignItem, false);
        /** @var Platform $platform */
        $platform = $context['platform'];
        /** @var Client|null $client */
        $client = $context['client'];
        $wpPostId = (int) ($context['wp_post_id'] ?? 0);
        $canHydrateWp = (bool) ($context['can_hydrate_wp'] ?? false);

        $payload = [];
        $media = [];
        $sources = [
            'age_source' => $pushCampaignItem->profile_age ? 'existing' : null,
            'image_source' => $pushCampaignItem->profile_image_url ? 'item_existing' : null,
        ];

        if ($client) {
            if ($force || $this->isBlankValue($pushCampaignItem->profile_name)) {
                if (!$this->isBlankValue($client->name)) {
                    $payload['profile_name'] = trim((string) $client->name);
                }
            }

            if ($force || $this->isBlankValue($pushCampaignItem->profile_phone)) {
                if (!$this->isBlankValue($client->phone_normalized)) {
                    $payload['profile_phone'] = trim((string) $client->phone_normalized);
                }
            }

            if ($force || $this->isBlankValue($pushCampaignItem->profile_image_url)) {
                if (!$this->isBlankValue($client->main_image_url)) {
                    $payload['profile_image_url'] = trim((string) $client->main_image_url);
                    $sources['image_source'] = 'client_main';
                }
            }
        }

        if ($canHydrateWp && $wpPostId > 0) {
            try {
                $wpSync = new WpSyncService($platform);
                $wpPayload = $wpSync->getClientProfile($wpPostId);
                $fields = $this->extractWpProfileFields($wpPayload);

                if (($force || $this->isBlankValue($pushCampaignItem->profile_name)) && !$this->isBlankValue($fields['name'] ?? null)) {
                    $payload['profile_name'] = (string) $fields['name'];
                }

                if (($force || $this->isBlankValue($pushCampaignItem->profile_phone)) && !$this->isBlankValue($fields['phone'] ?? null)) {
                    $payload['profile_phone'] = (string) $fields['phone'];
                }

                if (($force || $this->isBlankValue($pushCampaignItem->profile_image_url) || $this->isBlankValue($payload['profile_image_url'] ?? null))
                    && !$this->isBlankValue($fields['image'] ?? null)) {
                    $payload['profile_image_url'] = (string) $fields['image'];
                    $sources['image_source'] = 'wp_profile';
                }

                if ($force || $this->isBlankValue($pushCampaignItem->profile_age)) {
                    if (!$this->isBlankValue($fields['age_value'] ?? null)) {
                        $payload['profile_age'] = (string) $fields['age_value'];
                        $sources['age_source'] = 'wp_meta_age';
                    } elseif (!$this->isBlankValue($fields['birthday'] ?? null)) {
                        $derivedAge = $this->deriveAgeFromBirthday(
                            (string) $fields['birthday'],
                            $this->resolveItemAgeReferenceDate($pushCampaignItem, $platform),
                            MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC'))
                        );
                        if ($derivedAge !== null) {
                            $payload['profile_age'] = $derivedAge;
                            $sources['age_source'] = 'wp_birthday_derived';
                        }
                    }
                }

                if ($force || $this->isBlankValue($pushCampaignItem->profile_image_url) || $this->isBlankValue($payload['profile_image_url'] ?? null)) {
                    $media = $this->normalizeWpMediaItems($wpSync->getClientMedia($wpPostId));
                    $recommended = $this->pickRecommendedMedia($media);
                    if ($recommended && !$this->isBlankValue($recommended['url'] ?? null)) {
                        $payload['profile_image_url'] = (string) ($recommended['url'] ?? '');
                        $sources['image_source'] = (bool) ($recommended['is_main'] ?? false) ? 'wp_media_main' : 'wp_media_first';
                    }
                } else {
                    $media = $this->normalizeWpMediaItems($wpSync->getClientMedia($wpPostId));
                }
            } catch (\Throwable) {
                // Keep available local/client values when WP hydration fails.
            }
        }

        if (($force || $this->isBlankValue($pushCampaignItem->wp_post_id)) && $wpPostId > 0) {
            $payload['wp_post_id'] = $wpPostId;
        }

        $changedProfileFields = collect(['profile_name', 'profile_phone', 'profile_image_url', 'profile_age'])
            ->contains(fn(string $field): bool => array_key_exists($field, $payload));

        if ($changedProfileFields) {
            $payload['error_message'] = null;
            if (in_array((string) $pushCampaignItem->status, ['failed', 'pending_extraction', 'needs_preset'], true)) {
                $payload['status'] = 'pending';
            }
        }

        if ($persist && !empty($payload)) {
            $pushCampaignItem->forceFill($payload)->save();
            $pushCampaignItem = $pushCampaignItem->fresh();
        } elseif (!empty($payload)) {
            $pushCampaignItem->forceFill($payload);
        }

        return [
            'item' => $pushCampaignItem,
            'sources' => [
                'age_source' => $sources['age_source'],
                'image_source' => $sources['image_source'],
            ],
            'media' => $media,
        ];
    }

    /**
     * @param array{platform:Platform,wp_post_id:int} $context
     * @return array<int, array{id:int,url:string,filename:?string,is_main:bool,mime_type:?string,uploaded_at:?string}>
     */
    private function fetchWpMediaOptions(array $context): array
    {
        $wpSync = new WpSyncService($context['platform']);
        return $this->normalizeWpMediaItems($wpSync->getClientMedia((int) $context['wp_post_id']));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{id:int,url:string,filename:?string,is_main:bool,mime_type:?string,uploaded_at:?string}>
     */
    private function normalizeWpMediaItems(array $payload): array
    {
        $rows = data_get($payload, 'data');
        if (!is_array($rows)) {
            $rows = array_is_list($payload) ? $payload : [];
        }

        return collect($rows)
            ->map(function ($media): array {
                $row = is_array($media) ? $media : [];
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'url' => trim((string) ($row['url'] ?? '')),
                    'filename' => isset($row['filename']) ? trim((string) $row['filename']) : null,
                    'is_main' => (bool) ($row['is_main'] ?? false),
                    'mime_type' => isset($row['mime_type']) ? trim((string) $row['mime_type']) : null,
                    'uploaded_at' => isset($row['uploaded_at']) ? trim((string) $row['uploaded_at']) : null,
                ];
            })
            ->filter(fn(array $media): bool => (int) ($media['id'] ?? 0) > 0
                && !$this->isBlankValue($media['url'] ?? null)
                && $this->isPushCampaignImageMedia($media))
            ->values()
            ->all();
    }

    private function isPushCampaignImageMedia(array $media): bool
    {
        $mimeType = strtolower(trim((string) ($media['mime_type'] ?? '')));
        if ($mimeType !== '') {
            return str_starts_with($mimeType, 'image/');
        }

        $url = strtolower(trim((string) ($media['url'] ?? '')));
        return (bool) preg_match('/\.(jpe?g|png|webp)(?:$|[?#])/', $url);
    }

    /**
     * @param array<int, array{id:int,url:string,filename:?string,is_main:bool,mime_type:?string,uploaded_at:?string}> $media
     * @return array{id:int,url:string,filename:?string,is_main:bool,mime_type:?string,uploaded_at:?string}|null
     */
    private function pickRecommendedMedia(array $media): ?array
    {
        if (empty($media)) {
            return null;
        }

        $main = collect($media)->first(fn(array $item): bool => (bool) ($item['is_main'] ?? false));
        if ($main) {
            return $main;
        }

        return $media[0] ?? null;
    }

    /**
     * @return array{
     *     platform:Platform,
     *     client:Client|null,
     *     wp_post_id:int,
     *     can_hydrate_wp:bool,
     *     error:?string
     * }
     */
    private function resolveItemWpContext(
        PushCampaign $pushCampaign,
        PushCampaignItem $pushCampaignItem,
        bool $requireLinkedClient = true
    ): array {
        $pushCampaign->loadMissing('platform:id,name,domain,timezone,wp_api_url,wp_api_user,wp_api_password');
        $pushCampaignItem->loadMissing('client:id,platform_id,wp_post_id,name,phone_normalized,main_image_url');

        /** @var Platform $platform */
        $platform = $pushCampaign->platform;
        $client = $pushCampaignItem->client;
        $wpPostId = (int) ($pushCampaignItem->wp_post_id ?? 0);

        if ($wpPostId <= 0 && $client) {
            $wpPostId = (int) ($client->wp_post_id ?? 0);
        }
        if ($wpPostId <= 0) {
            $wpPostId = (int) ($this->parseWpPostIdFromUrl((string) ($pushCampaignItem->profile_url ?? '')) ?? 0);
        }

        if ($requireLinkedClient && !$client) {
            return [
                'platform' => $platform,
                'client' => null,
                'wp_post_id' => $wpPostId,
                'can_hydrate_wp' => false,
                'error' => 'Match this campaign item to a CRM escort profile before managing media.',
            ];
        }

        if ($wpPostId <= 0) {
            return [
                'platform' => $platform,
                'client' => $client,
                'wp_post_id' => 0,
                'can_hydrate_wp' => false,
                'error' => 'WordPress profile link is missing for this campaign item.',
            ];
        }

        if (!$this->platformHasWpIntegration($platform)) {
            return [
                'platform' => $platform,
                'client' => $client,
                'wp_post_id' => $wpPostId,
                'can_hydrate_wp' => false,
                'error' => 'WordPress integration credentials are missing for this market.',
            ];
        }

        return [
            'platform' => $platform,
            'client' => $client,
            'wp_post_id' => $wpPostId,
            'can_hydrate_wp' => true,
            'error' => null,
        ];
    }

    private function resolveItemAgeReferenceDate(PushCampaignItem $pushCampaignItem, Platform $platform): Carbon
    {
        $timezone = MarketTimezone::resolve($platform->timezone, config('app.timezone', 'UTC'));
        $scheduledAt = $pushCampaignItem->scheduled_at;

        if ($scheduledAt instanceof Carbon) {
            return $scheduledAt->copy()->setTimezone($timezone);
        }

        if (!$this->isBlankValue($scheduledAt)) {
            try {
                return Carbon::parse((string) $scheduledAt, 'UTC')->setTimezone($timezone);
            } catch (\Throwable) {
                // Fallback to now for invalid date payload.
            }
        }

        return now($timezone);
    }

    private function deriveAgeFromBirthday(string $birthday, Carbon $referenceDate, string $timezone): ?string
    {
        $normalizedBirthday = trim($birthday);
        if ($normalizedBirthday === '') {
            return null;
        }

        try {
            $birthDate = Carbon::parse($normalizedBirthday, $timezone)->startOfDay();
            $reference = $referenceDate->copy()->setTimezone($timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($birthDate->greaterThan($reference)) {
            return null;
        }

        $years = $birthDate->diffInYears($reference);
        if ($years < 0 || $years > 120) {
            return null;
        }

        return (string) $years;
    }

    private function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }

    private function extractDomainFromUrl(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (!is_string($host) || trim($host) === '') {
            return null;
        }

        return $this->normalizeDomain($host);
    }

    private function normalizeDomain(string $domain): ?string
    {
        $normalized = strtolower(trim($domain));
        $normalized = preg_replace('/^https?:\/\//i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\/.*/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^www\./i', '', $normalized) ?? $normalized;

        if ($normalized === '' || !preg_match('/^[a-z0-9.-]+$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function resolveClientProfileUrl(Client $client, Platform $platform): ?string
    {
        if (!empty($client->wp_profile_url)) {
            return (string) $client->wp_profile_url;
        }

        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return null;
        }

        $domain = trim((string) ($platform->domain ?? ''));
        if ($domain === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/') . '/?p=' . $wpPostId;
    }

    private function uploadErrorMessage(int $uploadErrorCode): string
    {
        if ($uploadErrorCode === UPLOAD_ERR_INI_SIZE || $uploadErrorCode === UPLOAD_ERR_FORM_SIZE) {
            return sprintf(
                'The uploaded workbook exceeds server upload limits (upload_max_filesize=%s, post_max_size=%s).',
                (string) ini_get('upload_max_filesize'),
                (string) ini_get('post_max_size')
            );
        }

        if ($uploadErrorCode === UPLOAD_ERR_PARTIAL) {
            return 'The workbook upload was interrupted before completion. Please retry.';
        }

        if ($uploadErrorCode === UPLOAD_ERR_NO_TMP_DIR) {
            return 'Server upload temp directory is missing. Configure PHP upload_tmp_dir and retry.';
        }

        if ($uploadErrorCode === UPLOAD_ERR_CANT_WRITE) {
            return 'Server could not write the uploaded workbook to disk. Check filesystem permissions.';
        }

        if ($uploadErrorCode === UPLOAD_ERR_EXTENSION) {
            return 'A PHP extension blocked workbook upload. Check server upload extensions and retry.';
        }

        return 'The workbook failed to upload. Please retry.';
    }

    private function iniSizeToBytes(string $value): int
    {
        $normalized = trim(strtolower($value));
        if ($normalized === '') {
            return 0;
        }

        $number = (float) $normalized;
        $unit = substr($normalized, -1);

        if ($unit === 'g') {
            return (int) ($number * 1024 * 1024 * 1024);
        }

        if ($unit === 'm') {
            return (int) ($number * 1024 * 1024);
        }

        if ($unit === 'k') {
            return (int) ($number * 1024);
        }

        return (int) $number;
    }
}
