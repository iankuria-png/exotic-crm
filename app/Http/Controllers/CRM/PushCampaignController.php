<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPushUploadJob;
use App\Models\Platform;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Models\ScraperProfilePreset;
use App\Services\MarketAuthorizationService;
use App\Services\PushCampaign\PushCampaignService;
use App\Services\PushCampaign\SelectorDetectionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PushCampaignController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly PushCampaignService $pushCampaignService,
        private readonly SelectorDetectionService $selectorDetectionService,
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
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        $file = $validated['file'];
        $batchId = (string) Str::uuid();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = in_array($extension, ['xlsx', 'xls'], true) ? $extension : 'xlsx';
        $storedPath = $file->storeAs('push-uploads', $batchId . '.' . $extension);

        Cache::put($this->batchCacheKey($batchId), [
            'batch_id' => $batchId,
            'status' => 'queued',
            'source_filename' => (string) ($file->getClientOriginalName() ?: $batchId . '.' . $extension),
            'queued_at' => now()->toDateTimeString(),
            'sheets_parsed' => 0,
            'total_items' => 0,
            'profiles_processed' => 0,
            'campaign_ids' => [],
            'unmapped_sheets' => [],
        ], now()->addHours(12));

        ProcessPushUploadJob::dispatch(
            $batchId,
            storage_path('app/' . $storedPath),
            (string) ($file->getClientOriginalName() ?: $batchId . '.' . $extension),
            (int) $request->user()->id,
        );

        return response()->json([
            'batch_id' => $batchId,
            'status' => 'processing',
        ], 202);
    }

    public function uploadStatus(Request $request, string $batchId)
    {
        $status = Cache::get($this->batchCacheKey($batchId));

        if (!is_array($status)) {
            return response()->json([
                'message' => 'Upload batch not found or expired.',
            ], 404);
        }

        $campaignIds = collect((array) ($status['campaign_ids'] ?? []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values();

        if ($campaignIds->isEmpty()) {
            return response()->json($status);
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
            'campaigns' => $summaries,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'batch_id' => 'required|string|max:64',
            'campaign_ids' => 'nullable|array',
            'campaign_ids.*' => 'integer|exists:push_campaigns,id',
        ]);

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

        $sentToday = 0;
        $avgClickRate = null;

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

        $totalCampaigns = (int) (clone $campaignQuery)->count();
        $pendingCampaigns = (int) (clone $campaignQuery)
            ->whereIn('status', ['processing', 'draft', 'scheduled', 'running'])
            ->count();

        return response()->json([
            'total_campaigns' => $totalCampaigns,
            'pending_campaigns' => $pendingCampaigns,
            'sent_today' => $sentToday,
            'avg_click_rate' => $avgClickRate,
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

        return response()->json([
            'items' => $platforms->map(fn(Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'platform_name' => $platform->name,
                'country' => $platform->country,
                'domain' => $platform->domain,
                'provider' => null,
                'total_subscribers' => null,
                'active_subscribers' => null,
                'last_synced_at' => null,
            ])->values(),
            'note' => 'Subscriber provider sync will be enabled in the next implementation phase.',
        ]);
    }

    public function syncSubscribers(Request $request)
    {
        return response()->json([
            'message' => 'Subscriber sync command is not yet configured.',
        ], 501);
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
}
