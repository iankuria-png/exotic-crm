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
use App\Services\PushCampaign\SelectorDetectionService;
use App\Services\PushCampaign\UploadBatchStatusService;
use App\Services\PushNotification\PushProviderService;
use App\Services\PushNotification\SubscriberSyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PushCampaignController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly PushCampaignService $pushCampaignService,
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
            ->with('platform:id,name,country')
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

        $timezone = trim((string) ($validated['timezone'] ?? ($platform->timezone ?: config('app.timezone', 'UTC'))));
        $scheduledAt = !empty($validated['scheduled_at'])
            ? Carbon::parse((string) $validated['scheduled_at'], $timezone)->utc()
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
        $dateLabel = $scheduledAt ? $scheduledAt->copy()->setTimezone($timezone)->toDateString() : null;

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

        $campaign->load('platform:id,name,country');

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

        $batchState = Cache::get($this->batchCacheKey((string) $validated['batch_id']));
        if (is_array($batchState) && (bool) ($batchState['dry_run'] ?? false)) {
            return response()->json([
                'message' => 'This batch was uploaded in dry-run mode. Upload again without dry-run to create campaigns.',
            ], 422);
        }

        $query = PushCampaign::query()
            ->where('upload_batch_id', (string) $validated['batch_id']);

        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if (!empty($validated['campaign_ids'])) {
            $query->whereIn('id', array_map('intval', $validated['campaign_ids']));
        }

        $campaigns = $query->get();

        if ($campaigns->isEmpty()) {
            return response()->json([
                'message' => 'No campaigns found for the requested batch.',
            ], 404);
        }

        $processingCount = $campaigns->where('status', 'processing')->count();
        if ($processingCount > 0) {
            return response()->json([
                'message' => 'Upload processing is still in progress. Wait for extraction to finish before confirming.',
            ], 422);
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

        return response()->json([
            'batch_id' => (string) $validated['batch_id'],
            'confirmed_count' => $confirmed->count(),
            'campaigns' => $confirmed,
        ]);
    }

    public function show(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        $validated = $request->validate([
            'status' => 'nullable|in:pending_extraction,needs_preset,pending,scheduled,sent,failed,skipped',
            'per_page' => 'nullable|integer|min:10|max:250',
        ]);

        $pushCampaign->load([
            'platform:id,name,country',
            'creator:id,name,email',
        ]);

        $itemsQuery = PushCampaignItem::query()
            ->where('campaign_id', (int) $pushCampaign->id)
            ->orderBy('scheduled_at')
            ->orderBy('id');

        if (!empty($validated['status'])) {
            $itemsQuery->where('status', (string) $validated['status']);
        }

        $items = $itemsQuery->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'campaign' => $pushCampaign,
            'items' => $items,
        ]);
    }

    public function updateItem(Request $request, PushCampaign $pushCampaign, PushCampaignItem $pushCampaignItem)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        if ((int) $pushCampaignItem->campaign_id !== (int) $pushCampaign->id) {
            return response()->json([
                'message' => 'Campaign item does not belong to this campaign.',
            ], 422);
        }

        if ((string) $pushCampaignItem->status === 'sent') {
            return response()->json([
                'message' => 'Sent items cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'custom_message' => 'sometimes|required|string|max:255',
            'scheduled_at' => 'sometimes|nullable|date',
            'timezone' => 'nullable|string|max:64',
        ]);

        if (!array_key_exists('custom_message', $validated) && !array_key_exists('scheduled_at', $validated)) {
            return response()->json([
                'message' => 'No editable fields were provided.',
            ], 422);
        }

        $payload = [];

        if (array_key_exists('custom_message', $validated)) {
            $payload['custom_message'] = trim((string) $validated['custom_message']);
        }

        if (array_key_exists('scheduled_at', $validated)) {
            $timezone = trim((string) ($validated['timezone'] ?? config('app.timezone', 'UTC')));
            $scheduledAt = $validated['scheduled_at']
                ? Carbon::parse((string) $validated['scheduled_at'], $timezone)->utc()
                : null;

            $payload['scheduled_at'] = $scheduledAt?->toDateTimeString();
            $payload['date_label'] = $scheduledAt
                ? $scheduledAt->copy()->setTimezone($timezone)->toDateString()
                : null;
        }

        $pushCampaignItem->forceFill($payload)->save();

        return response()->json([
            'item' => $pushCampaignItem->fresh(),
            'message' => 'Campaign item updated.',
        ]);
    }

    public function execute(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        if ($pushCampaign->status === 'processing') {
            return response()->json([
                'message' => 'Campaign is still processing and cannot be executed yet.',
            ], 422);
        }

        $campaign = $this->pushCampaignService->executeCampaign($pushCampaign, (int) $request->user()->id);

        return response()->json([
            'campaign' => $campaign,
        ]);
    }

    public function schedule(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        $validated = $request->validate([
            'scheduled_at' => 'required|date',
            'timezone' => 'nullable|string|max:64',
        ]);

        $timezone = trim((string) ($validated['timezone'] ?? config('app.timezone', 'UTC')));
        $scheduledAt = Carbon::parse((string) $validated['scheduled_at'], $timezone)->utc();

        if ($scheduledAt->lessThanOrEqualTo(now())) {
            return response()->json([
                'message' => 'scheduled_at must be in the future.',
            ], 422);
        }

        $campaign = $this->pushCampaignService->scheduleCampaign(
            $pushCampaign,
            $scheduledAt,
            (int) $request->user()->id
        );

        return response()->json([
            'campaign' => $campaign,
        ]);
    }

    public function analytics(Request $request, PushCampaign $pushCampaign)
    {
        $this->ensureCampaignAccess($request, $pushCampaign);

        $campaign = $this->pushCampaignService->refreshAnalytics($pushCampaign);
        $totals = $this->deliveryTotals((int) $campaign->id);

        return response()->json([
            'campaign' => $campaign,
            'analytics' => $totals,
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

    private function batchCacheKey(string $batchId): string
    {
        return 'push_upload:' . $batchId;
    }

    private function shouldProcessInline(\Illuminate\Http\UploadedFile $file, bool $dryRun): bool
    {
        if (!$dryRun) {
            return false;
        }

        $defaultLocalRows = app()->environment('local') ? 30000 : 800;
        $configuredMaxRows = config('services.push_campaigns.inline_dry_run_max_rows');
        $maxRows = is_numeric($configuredMaxRows) ? (int) $configuredMaxRows : $defaultLocalRows;
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
