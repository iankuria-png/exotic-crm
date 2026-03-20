<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\LeadAssignmentService;
use App\Services\LeadConversionService;
use App\Services\LeadImportService;
use App\Services\MarketAuthorizationService;
use App\Services\ScraperSourceService;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    private const SCRAPE_PREVIEW_TTL_MINUTES = 30;
    private const SCRAPE_PREVIEW_CACHE_PREFIX = 'lead_scrape_preview:';

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly LeadImportService $leadImportService,
        private readonly LeadAssignmentService $leadAssignmentService,
        private readonly LeadConversionService $leadConversionService,
        private readonly AuditService $auditService,
        private readonly ScraperSourceService $scraperSourceService
    ) {
    }

    public function index(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this lead market.'
        );

        $query = Lead::with(['platform', 'assignedAgent', 'convertedClient']);
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if (!$request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $statsQuery = clone $query;
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'new' => (clone $statsQuery)->where('status', 'new')->count(),
            'contacted' => (clone $statsQuery)->where('status', 'contacted')->count(),
            'qualified' => (clone $statsQuery)->where('status', 'qualified')->count(),
            'converted' => (clone $statsQuery)->where('status', 'converted')->count(),
            'lost' => (clone $statsQuery)->where('status', 'lost')->count(),
            'assigned' => (clone $statsQuery)->whereNotNull('assigned_to')->count(),
            'unassigned' => (clone $statsQuery)->whereNull('assigned_to')->count(),
        ];

        $leads = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        $leads->setCollection(
            $leads->getCollection()->map(function (Lead $lead) {
                $matched = $this->resolveMatchedClientSummary($lead);
                $lead->setAttribute('matched_client', $matched);
                $lead->setAttribute('match_confidence', $matched['confidence'] ?? null);
                return $lead;
            })
        );

        $payload = $leads->toArray();
        $payload['stats'] = $stats;

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'name' => 'required|string|max:255',
            'phone_normalized' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'source' => 'nullable|in:registration,referral,outbound,import',
            'status' => 'nullable|in:new,contacted,qualified,converted,lost',
            'assigned_to' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $lead = $this->createManualLead(
                $request,
                $validated,
                $validated['reason'] ?? 'Manual lead create from CRM'
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($lead, 201);
    }

    public function scrapeEntry(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'source_url' => 'required|url|max:500',
            'name' => 'nullable|string|max:255',
            'phone_normalized' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'assigned_to' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $sourceUrl = trim((string) $validated['source_url']);
        $derivedName = trim((string) ($validated['name'] ?? ''));
        if ($derivedName === '') {
            $derivedName = $this->deriveLeadNameFromSourceUrl($sourceUrl);
        }

        if ($derivedName === '') {
            return response()->json([
                'message' => 'Could not derive a lead name from URL. Provide a manual lead name.',
            ], 422);
        }

        $reason = $validated['reason'] ?? 'Scrape lead intake from leads page';

        try {
            $lead = $this->createManualLead(
                $request,
                [
                    'platform_id' => (int) $validated['platform_id'],
                    'name' => $derivedName,
                    'phone_normalized' => $validated['phone_normalized'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'source_url' => $sourceUrl,
                    'source' => 'import',
                    'status' => 'new',
                    'assigned_to' => $validated['assigned_to'] ?? null,
                ],
                $reason
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $host = parse_url($sourceUrl, PHP_URL_HOST);

        TimelineEvent::create([
            'platform_id' => $lead->platform_id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_scrape_intake',
            'actor_id' => $request->user()->id,
            'content' => [
                'source_url' => $sourceUrl,
                'source_host' => $host,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $lead->platform_id,
            CrmAuditAction::LEAD_SCRAPE_INTAKE,
            'lead',
            (int) $lead->id,
            null,
            [
                'source_url' => $sourceUrl,
                'source_host' => $host,
            ],
            $reason
        );

        return response()->json([
            'lead' => $lead,
            'source_url' => $sourceUrl,
            'source_host' => $host,
        ], 201);
    }

    public function scrapePreview(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'preset_key' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
            'source_url' => 'nullable|url|max:500',
            'parser_profile' => ['nullable', Rule::in(ScraperSourceService::PARSER_PROFILES)],
            'dedupe_mode' => ['nullable', Rule::in(ScraperSourceService::DEDUPE_MODES)],
            'max_candidates' => 'nullable|integer|min:1|max:250',
            'parser_rules' => 'nullable|array',
            'parser_rules.row_selector' => 'nullable|string|max:255',
            'parser_rules.name_selector' => 'nullable|string|max:255',
            'parser_rules.phone_selector' => 'nullable|string|max:255',
            'parser_rules.email_selector' => 'nullable|string|max:255',
            'parser_rules.link_selector' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this lead market.'
        );

        $platform = Platform::query()->findOrFail($platformId);

        $preset = null;
        if (!empty($validated['preset_key'])) {
            $preset = $this->scraperSourceService->competitorPresetByKey((string) $validated['preset_key']);
            if (!$preset) {
                return response()->json([
                    'message' => 'Unknown scraper website preset selected.',
                ], 422);
            }

            if (($preset['status'] ?? 'supported') !== 'supported') {
                return response()->json([
                    'message' => $preset['blocked_reason'] ?? 'Selected website is currently blocked for automated scraping.',
                    'preset' => $preset,
                ], 422);
            }
        }

        $presetConfig = is_array($preset['configuration'] ?? null) ? $preset['configuration'] : [];
        $mergedParserRules = array_merge(
            is_array($presetConfig['parser_rules'] ?? null) ? $presetConfig['parser_rules'] : [],
            is_array($validated['parser_rules'] ?? null) ? $validated['parser_rules'] : []
        );
        $sourceUrl = trim((string) ($validated['source_url'] ?? ($preset['source_url'] ?? '')));
        if ($sourceUrl === '') {
            return response()->json([
                'message' => 'source_url is required when no preset source URL is available.',
            ], 422);
        }

        $resolvedConfig = [
            'name' => trim((string) ($validated['name'] ?? ($preset['name'] ?? 'Leads page scrape run'))),
            'source_url' => $sourceUrl,
            'parser_profile' => $validated['parser_profile'] ?? ($presetConfig['parser_profile'] ?? 'contact_cards'),
            'fetch_schedule' => 'manual_only',
            'dedupe_mode' => $validated['dedupe_mode'] ?? ($presetConfig['dedupe_mode'] ?? 'phone_or_email'),
            'is_active' => true,
            'compliance_ack_robots' => true,
            'compliance_ack_tos' => true,
            'compliance_notes' => !empty($preset['notes']) ? mb_substr((string) $preset['notes'], 0, 500) : null,
            'parser_rules' => $this->scraperSourceService->normalizeParserRules($mergedParserRules),
        ];

        $maxCandidates = (int) ($validated['max_candidates'] ?? 50);
        $previewResult = $this->scraperSourceService->previewSourceConfig(
            $platform,
            $request->user(),
            $resolvedConfig,
            $maxCandidates
        );

        $status = (string) ($previewResult['status'] ?? 'error');
        $statusCode = in_array($status, ['blocked', 'error'], true) ? 422 : 200;

        $previewId = null;
        $expiresAt = null;
        if ($statusCode === 200) {
            $previewId = (string) Str::uuid();
            $expiresAt = now()->addMinutes(self::SCRAPE_PREVIEW_TTL_MINUTES);
            Cache::put(
                $this->scrapePreviewCacheKey($previewId),
                [
                    'preview_id' => $previewId,
                    'platform_id' => $platformId,
                    'actor_id' => (int) $request->user()->id,
                    'preset_key' => $preset['key'] ?? null,
                    'source_config' => $resolvedConfig,
                    'candidates' => $previewResult['candidates'] ?? [],
                    'quality' => $previewResult['quality'] ?? null,
                    'generated_at' => now()->toDateTimeString(),
                    'expires_at' => $expiresAt->toDateTimeString(),
                ],
                $expiresAt
            );
        }

        return response()->json([
            'preview_id' => $previewId,
            'expires_at' => $expiresAt?->toDateTimeString(),
            'preset' => $preset,
            'configuration' => $resolvedConfig,
            'result' => [
                'status' => $status,
                'message' => $previewResult['message'] ?? null,
                'errors' => $previewResult['errors'] ?? [],
                'robots' => $previewResult['robots'] ?? null,
                'http' => $previewResult['http'] ?? null,
                'discovered' => (int) ($previewResult['discovered'] ?? 0),
                'duplicates' => (int) ($previewResult['duplicates'] ?? 0),
                'preview' => is_array($previewResult['preview'] ?? null) ? $previewResult['preview'] : [],
                'quality' => is_array($previewResult['quality'] ?? null)
                    ? $previewResult['quality']
                    : [],
            ],
        ], $statusCode);
    }

    public function commitScrapePreview(Request $request, string $previewId)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $preview = Cache::get($this->scrapePreviewCacheKey($previewId));
        if (!is_array($preview)) {
            return response()->json([
                'message' => 'Scrape preview session not found or expired. Run preview again.',
            ], 404);
        }

        if ((int) ($preview['actor_id'] ?? 0) !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'This scrape preview belongs to another user.',
            ], 403);
        }

        $platformId = (int) ($preview['platform_id'] ?? 0);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this lead market.'
        );

        $platform = Platform::query()->findOrFail($platformId);
        $importResult = $this->scraperSourceService->importFromPreviewCandidates(
            $platform,
            $request->user(),
            (array) ($preview['source_config'] ?? []),
            (array) ($preview['candidates'] ?? [])
        );

        Cache::forget($this->scrapePreviewCacheKey($previewId));

        $this->auditService->fromRequest(
            $request,
            $platformId,
            CrmAuditAction::SCRAPER_RUN,
            'scrape_preview',
            0,
            null,
            [
                'preview_id' => $previewId,
                'preset_key' => $preview['preset_key'] ?? null,
                'status' => $importResult['status'] ?? 'error',
                'discovered' => (int) ($importResult['discovered'] ?? 0),
                'created' => (int) ($importResult['created'] ?? 0),
                'duplicates' => (int) ($importResult['duplicates'] ?? 0),
                'skipped' => (int) ($importResult['skipped'] ?? 0),
            ],
            $validated['reason'] ?? 'Imported leads from scrape preview modal'
        );

        $statusCode = in_array(($importResult['status'] ?? ''), ['error'], true) ? 422 : 200;

        return response()->json([
            'preview_id' => $previewId,
            'result' => $importResult,
        ], $statusCode);
    }

    public function dismissScrapePreview(Request $request, string $previewId)
    {
        $preview = Cache::get($this->scrapePreviewCacheKey($previewId));
        if (!is_array($preview)) {
            return response()->json([
                'dismissed' => true,
                'preview_id' => $previewId,
            ]);
        }

        if ((int) ($preview['actor_id'] ?? 0) !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'This scrape preview belongs to another user.',
            ], 403);
        }

        $platformId = (int) ($preview['platform_id'] ?? 0);
        if ($platformId > 0) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                $platformId,
                'You do not have access to this lead market.'
            );
        }

        Cache::forget($this->scrapePreviewCacheKey($previewId));

        return response()->json([
            'dismissed' => true,
            'preview_id' => $previewId,
        ]);
    }

    public function uploadCsv(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|exists:platforms,id',
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'has_header' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this lead market.'
        );

        $rows = $this->parseCsvRows(
            $validated['file']->getRealPath(),
            (bool) ($validated['has_header'] ?? true)
        );

        if (count($rows) === 0) {
            return response()->json([
                'message' => 'CSV file has no data rows.',
            ], 422);
        }

        if (count($rows) > 500) {
            return response()->json([
                'message' => 'CSV upload limit is 500 rows per upload.',
            ], 422);
        }

        $totals = [
            'rows' => count($rows),
            'created' => 0,
            'failed' => 0,
        ];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            $payload = [
                'platform_id' => $platformId,
                'name' => trim((string) ($row['name'] ?? $row['lead_name'] ?? '')),
                'phone_normalized' => $row['phone_normalized'] ?? $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'source' => $row['source'] ?? 'outbound',
                'status' => $row['status'] ?? 'new',
                'assigned_to' => isset($row['assigned_to']) && trim((string) $row['assigned_to']) !== '' ? (int) $row['assigned_to'] : null,
            ];

            try {
                $this->createManualLead(
                    $request,
                    $payload,
                    ($validated['reason'] ?? 'CSV lead upload from CRM') . " (row {$rowNumber})"
                );
                $totals['created'] += 1;
            } catch (\Throwable $exception) {
                $totals['failed'] += 1;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'totals' => $totals,
            'errors' => $errors,
        ]);
    }

    public function assign(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);

        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $beforeState = [
            'assigned_to' => $lead->assigned_to,
        ];

        $nextOwnerId = $validated['assigned_to'] ?? null;
        $nextOwnerId = $this->leadAssignmentService->assignOwnerId(
            (int) $lead->platform_id,
            [
                'wp_post_id' => $lead->wp_post_id,
                'wp_user_id' => $lead->wp_user_id,
                'phone_normalized' => $lead->phone_normalized,
                'email' => $lead->email,
                'name' => $lead->name,
            ],
            $nextOwnerId ? (int) $nextOwnerId : null
        );

        if (!$nextOwnerId) {
            return response()->json([
                'message' => 'No eligible active owner found for this market.',
            ], 422);
        }

        $lead->update([
            'assigned_to' => $nextOwnerId,
        ]);

        TimelineEvent::create([
            'platform_id' => $lead->platform_id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_assigned',
            'actor_id' => $request->user()->id,
            'content' => [
                'before_assigned_to' => $beforeState['assigned_to'],
                'after_assigned_to' => $lead->assigned_to,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $lead->platform_id,
            CrmAuditAction::LEAD_ASSIGN,
            'lead',
            (int) $lead->id,
            $beforeState,
            [
                'assigned_to' => $lead->assigned_to,
            ],
            $validated['reason'] ?? 'Manual lead assignment from CRM'
        );

        $lead->load(['platform', 'assignedAgent', 'convertedClient']);

        return response()->json($lead);
    }

    public function show(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);
        $lead->load(['platform', 'assignedAgent', 'convertedClient']);

        $matched = $this->resolveMatchedClientSummary($lead);
        $lead->setAttribute('matched_client', $matched);
        $lead->setAttribute('match_confidence', $matched['confidence'] ?? null);

        return response()->json($lead);
    }

    public function reconcile(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);

        $validated = $request->validate([
            'action' => 'required|in:link,convert,archive',
            'client_id' => 'nullable|integer|exists:clients,id',
            'reason' => 'required|string|max:500',
        ]);

        $resolvedClientId = $this->resolveConvertedClientId($lead, $validated['client_id'] ?? null);
        if (!$resolvedClientId) {
            return response()->json([
                'message' => 'No matching client could be resolved for this lead.',
            ], 422);
        }

        $client = Client::query()->findOrFail($resolvedClientId);
        if ((int) $client->platform_id !== (int) $lead->platform_id) {
            return response()->json([
                'message' => 'Selected client does not belong to this lead market.',
            ], 422);
        }

        $beforeState = [
            'status' => $lead->status,
            'converted_client_id' => $lead->converted_client_id,
            'archived_at' => optional($lead->archived_at)->toDateTimeString(),
        ];

        $updates = [
            'converted_client_id' => (int) $client->id,
        ];

        if ($validated['action'] === 'convert') {
            $updates['status'] = 'converted';
        }

        if ($validated['action'] === 'archive') {
            $updates['archived_at'] = now();
        }

        $lead->update($updates);

        TimelineEvent::create([
            'platform_id' => $lead->platform_id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_reconciled',
            'actor_id' => $request->user()->id,
            'content' => [
                'action' => $validated['action'],
                'client_id' => (int) $client->id,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $lead->platform_id,
            CrmAuditAction::LEAD_RECONCILE,
            'lead',
            (int) $lead->id,
            $beforeState,
            [
                'status' => $lead->status,
                'converted_client_id' => $lead->converted_client_id,
                'archived_at' => optional($lead->archived_at)->toDateTimeString(),
                'action' => $validated['action'],
            ],
            (string) $validated['reason']
        );

        $lead->load(['platform', 'assignedAgent', 'convertedClient']);
        $matched = $this->resolveMatchedClientSummary($lead);
        $lead->setAttribute('matched_client', $matched);
        $lead->setAttribute('match_confidence', $matched['confidence'] ?? null);

        return response()->json([
            'lead' => $lead,
            'message' => 'Lead reconciliation applied.',
        ]);
    }

    public function convertToClient(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_normalized' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:100',
            'profile_status' => 'nullable|in:publish,private,draft,pending',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->leadConversionService->convert($request, $lead, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        if (!empty($result['duplicate'])) {
            return response()->json([
                'duplicate' => true,
                'matched_by' => $result['matched_by'],
                'existing_client' => $result['existing_client'],
                'message' => 'A likely existing client already matches this lead.',
            ], 409);
        }

        return response()->json([
            'message' => 'Lead converted to client.',
            'client' => $result['client'],
            'lead' => $result['lead'],
        ]);
    }

    public function batchReconcile(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:link,convert,archive',
            'lead_ids' => 'nullable|array',
            'lead_ids.*' => 'integer|exists:leads,id',
            'reason' => 'required|string|max:500',
        ]);

        $requestedLeadIds = collect($validated['lead_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $maxBatch = 500;

        $query = Lead::query();
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if ($requestedLeadIds->isNotEmpty()) {
            $query->whereIn('id', $requestedLeadIds->all());
        } else {
            $query->whereNull('archived_at')
                ->whereNotNull('phone_normalized')
                ->where('phone_normalized', '!=', '')
                ->whereExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('clients')
                        ->whereColumn('clients.platform_id', 'leads.platform_id')
                        ->whereColumn('clients.phone_normalized', 'leads.phone_normalized');
                });
        }

        $leads = $query->orderBy('id')->limit($maxBatch + 1)->get();
        if ($leads->count() > $maxBatch) {
            return response()->json([
                'message' => "Batch reconcile is limited to {$maxBatch} leads per request. Narrow your selection and retry.",
                'limit' => $maxBatch,
            ], 422);
        }

        if ($leads->isEmpty()) {
            return response()->json([
                'processed' => 0,
                'linked' => 0,
                'converted' => 0,
                'archived' => 0,
                'failed' => 0,
                'errors' => [],
            ]);
        }

        $processed = 0;
        $linked = 0;
        $converted = 0;
        $archived = 0;
        $failed = 0;
        $errors = [];
        $platformIds = [];

        DB::beginTransaction();
        try {
            foreach ($leads as $lead) {
                $platformIds[] = (int) $lead->platform_id;

                $resolvedClientId = $this->resolveConvertedClientId($lead, null);
                if (!$resolvedClientId) {
                    $failed++;
                    $errors[] = [
                        'lead_id' => (int) $lead->id,
                        'message' => 'No matching client could be resolved for this lead.',
                    ];
                    continue;
                }

                $client = Client::query()->find((int) $resolvedClientId);
                if (!$client || (int) $client->platform_id !== (int) $lead->platform_id) {
                    $failed++;
                    $errors[] = [
                        'lead_id' => (int) $lead->id,
                        'message' => 'Resolved client is missing or not in the same market.',
                    ];
                    continue;
                }

                $updates = [
                    'converted_client_id' => (int) $client->id,
                ];

                if ($validated['action'] === 'convert') {
                    $updates['status'] = 'converted';
                }

                if ($validated['action'] === 'archive') {
                    $updates['archived_at'] = now();
                }

                $lead->update($updates);

                TimelineEvent::create([
                    'platform_id' => $lead->platform_id,
                    'entity_type' => 'lead',
                    'entity_id' => $lead->id,
                    'event_type' => 'lead_reconciled',
                    'actor_id' => $request->user()->id,
                    'content' => [
                        'action' => $validated['action'],
                        'client_id' => (int) $client->id,
                        'batch' => true,
                    ],
                    'created_at' => now(),
                ]);

                $processed++;
                if ($validated['action'] === 'link') {
                    $linked++;
                } elseif ($validated['action'] === 'convert') {
                    $converted++;
                } elseif ($validated['action'] === 'archive') {
                    $archived++;
                }
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            return response()->json([
                'message' => 'Batch lead reconciliation failed.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        $auditPlatformId = (int) (collect($platformIds)->filter(fn($id) => $id > 0)->first() ?? 0);
        $auditEntityLeadId = (int) $leads->first()->id;
        if ($auditPlatformId > 0 && $auditEntityLeadId > 0) {
            $this->auditService->fromRequest(
                $request,
                $auditPlatformId,
                CrmAuditAction::LEAD_RECONCILE,
                'lead',
                $auditEntityLeadId,
                [
                    'action' => $validated['action'],
                    'requested_lead_ids' => $requestedLeadIds->all(),
                    'mode' => $requestedLeadIds->isNotEmpty() ? 'selected' : 'auto',
                ],
                [
                    'processed' => $processed,
                    'linked' => $linked,
                    'converted' => $converted,
                    'archived' => $archived,
                    'failed' => $failed,
                ],
                (string) $validated['reason']
            );
        }

        return response()->json([
            'processed' => $processed,
            'linked' => $linked,
            'converted' => $converted,
            'archived' => $archived,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    public function archive(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if ($lead->archived_at) {
            return response()->json([
                'message' => 'Lead is already archived.',
            ], 422);
        }

        $beforeState = [
            'status' => $lead->status,
            'archived_at' => optional($lead->archived_at)->toDateTimeString(),
        ];

        $lead->update([
            'archived_at' => now(),
        ]);

        TimelineEvent::create([
            'platform_id' => $lead->platform_id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_archived',
            'actor_id' => $request->user()->id,
            'content' => [
                'status' => $lead->status,
                'reason' => $validated['reason'],
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $lead->platform_id,
            CrmAuditAction::LEAD_ARCHIVE,
            'lead',
            (int) $lead->id,
            $beforeState,
            [
                'status' => $lead->status,
                'archived_at' => optional($lead->archived_at)->toDateTimeString(),
            ],
            $validated['reason']
        );

        $lead->load(['platform', 'assignedAgent', 'convertedClient']);

        return response()->json([
            'message' => 'Lead archived.',
            'lead' => $lead,
        ]);
    }

    public function destroy(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $beforeState = [
            'name' => $lead->name,
            'status' => $lead->status,
            'assigned_to' => $lead->assigned_to,
            'archived_at' => optional($lead->archived_at)->toDateTimeString(),
            'converted_client_id' => $lead->converted_client_id,
        ];

        TimelineEvent::create([
            'platform_id' => $lead->platform_id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_deleted',
            'actor_id' => $request->user()->id,
            'content' => [
                'name' => $lead->name,
                'status' => $lead->status,
                'reason' => $validated['reason'],
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $lead->platform_id,
            CrmAuditAction::LEAD_DELETE,
            'lead',
            (int) $lead->id,
            $beforeState,
            null,
            $validated['reason']
        );

        $lead->delete();

        return response()->json([
            'message' => 'Lead deleted.',
        ]);
    }

    public function updateStatus(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);

        $request->validate([
            'status' => 'required|in:new,contacted,qualified,converted,lost',
            'converted_client_id' => 'nullable|exists:clients,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $beforeState = [
            'status' => $lead->status,
            'converted_client_id' => $lead->converted_client_id,
            'assigned_to' => $lead->assigned_to,
            'first_contact_at' => optional($lead->first_contact_at)->toDateTimeString(),
            'last_contact_at' => optional($lead->last_contact_at)->toDateTimeString(),
        ];

        $nextStatus = $request->input('status');
        $nextConvertedClientId = $this->resolveConvertedClientId(
            $lead,
            $request->input('converted_client_id')
        );

        if ($nextStatus === 'converted' && !$nextConvertedClientId) {
            return response()->json([
                'message' => 'Lead conversion requires a linked client. Provide converted_client_id or sync client data.',
            ], 422);
        }

        $updates = [
            'status' => $nextStatus,
            'last_contact_at' => in_array($nextStatus, ['contacted', 'qualified'], true) ? now() : $lead->last_contact_at,
            'first_contact_at' => $lead->first_contact_at ?? ($nextStatus === 'contacted' ? now() : null),
        ];

        if ($nextStatus === 'converted') {
            $updates['converted_client_id'] = $nextConvertedClientId;
        }

        $lead->update($updates);

        TimelineEvent::create([
            'platform_id' => $lead->platform_id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_status_changed',
            'actor_id' => $request->user()->id,
            'content' => [
                'before_status' => $beforeState['status'],
                'after_status' => $lead->status,
                'converted_client_id' => $lead->converted_client_id,
            ],
            'created_at' => now(),
        ]);

        if ($lead->status === 'converted' && $lead->converted_client_id) {
            TimelineEvent::create([
                'platform_id' => $lead->platform_id,
                'entity_type' => 'client',
                'entity_id' => $lead->converted_client_id,
                'event_type' => 'lead_converted',
                'actor_id' => $request->user()->id,
                'content' => [
                    'lead_id' => $lead->id,
                    'source' => $lead->source,
                ],
                'created_at' => now(),
            ]);
        }

        $this->auditService->fromRequest(
            $request,
            $lead->platform_id,
            CrmAuditAction::LEAD_STATUS_UPDATE,
            'lead',
            $lead->id,
            $beforeState,
            [
                'status' => $lead->status,
                'converted_client_id' => $lead->converted_client_id,
                'first_contact_at' => optional($lead->first_contact_at)->toDateTimeString(),
                'last_contact_at' => optional($lead->last_contact_at)->toDateTimeString(),
            ],
            $request->input('reason')
        );

        return response()->json($lead);
    }

    public function pipeline(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this lead market.'
        );

        $baseQuery = Lead::query()->whereNull('archived_at');
        $this->marketAuthorizationService->applyPlatformScope($baseQuery, $request->user());

        $platformId = $request->get('platform_id');

        $stages = ['new', 'contacted', 'qualified', 'converted', 'lost'];
        $pipeline = [];

        foreach ($stages as $stage) {
            $query = (clone $baseQuery)->where('status', $stage);
            if ($platformId) {
                $query->where('platform_id', $platformId);
            }
            $pipeline[$stage] = $query->count();
        }

        return response()->json($pipeline);
    }

    public function import(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|exists:platforms,id',
            'dry_run' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:20|max:200',
        ]);

        if (!empty($validated['platform_id'])) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                (int) $validated['platform_id'],
                'You do not have access to this market.'
            );
        }

        $platforms = Platform::query()
            ->where('is_active', true)
            ->whereNotNull('wp_api_url')
            ->when(
                !empty($validated['platform_id']),
                fn ($query) => $query->where('id', (int) $validated['platform_id'])
            )
            ->get();

        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($allowedPlatformIds)) {
            $platforms = $platforms->whereIn('id', $allowedPlatformIds)->values();
        }

        if ($platforms->isEmpty()) {
            return response()->json([
                'message' => 'No accessible platforms found for lead import.',
            ], 422);
        }

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $perPage = (int) ($validated['per_page'] ?? 100);

        $results = [];
        $totals = [
            'scanned' => 0,
            'eligible' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'unassigned' => 0,
            'errors' => 0,
        ];

        foreach ($platforms as $platform) {
            $result = $this->leadImportService->importPlatform($platform, $dryRun, $perPage);
            $results[] = $result;

            $totals['scanned'] += $result['scanned'];
            $totals['eligible'] += $result['eligible'];
            $totals['created'] += $result['created'];
            $totals['updated'] += $result['updated'];
            $totals['unchanged'] += $result['unchanged'];
            $totals['unassigned'] += $result['unassigned'];
            $totals['errors'] += count($result['errors']);
        }

        return response()->json([
            'dry_run' => $dryRun,
            'totals' => $totals,
            'results' => $results,
        ]);
    }

    private function authorizeLeadAccess(Request $request, Lead $lead): void
    {
        $user = $request->user();

        if (!$this->marketAuthorizationService->userCanAccessPlatform($user, (int) $lead->platform_id)) {
            abort(403, 'You do not have access to this lead market.');
        }
    }

    private function resolveConvertedClientId(Lead $lead, $providedClientId): ?int
    {
        if ($providedClientId) {
            $client = Client::find((int) $providedClientId);
            if ($client && (int) $client->platform_id === (int) $lead->platform_id) {
                return (int) $client->id;
            }

            return null;
        }

        if ($lead->converted_client_id) {
            return (int) $lead->converted_client_id;
        }

        if ($lead->wp_post_id) {
            $client = Client::query()
                ->where('platform_id', $lead->platform_id)
                ->where('wp_post_id', $lead->wp_post_id)
                ->first();
            if ($client) {
                return (int) $client->id;
            }
        }

        if ($lead->wp_user_id) {
            $client = Client::query()
                ->where('platform_id', $lead->platform_id)
                ->where('wp_user_id', $lead->wp_user_id)
                ->first();
            if ($client) {
                return (int) $client->id;
            }
        }

        if ($lead->phone_normalized) {
            $clients = Client::query()
                ->where('platform_id', $lead->platform_id)
                ->where('phone_normalized', $lead->phone_normalized)
                ->limit(2)
                ->get();

            if ($clients->count() === 1) {
                return (int) $clients->first()->id;
            }
        }

        return null;
    }

    private function resolveMatchedClientSummary(Lead $lead): ?array
    {
        $clientId = $this->resolveConvertedClientId($lead, null);
        if (!$clientId) {
            return null;
        }

        $client = Client::query()
            ->where('id', (int) $clientId)
            ->where('platform_id', (int) $lead->platform_id)
            ->first([
                'id',
                'name',
                'wp_post_id',
                'wp_user_id',
                'phone_normalized',
                'email',
                'profile_status',
            ]);

        if (!$client) {
            return null;
        }

        return [
            'id' => (int) $client->id,
            'name' => $client->name,
            'wp_post_id' => $client->wp_post_id,
            'wp_user_id' => $client->wp_user_id,
            'phone_normalized' => $client->phone_normalized,
            'email' => $client->email,
            'profile_status' => $client->profile_status,
            'confidence' => $this->resolveMatchConfidence($lead, $client),
        ];
    }

    private function resolveMatchConfidence(Lead $lead, Client $client): string
    {
        if ($lead->converted_client_id && (int) $lead->converted_client_id === (int) $client->id) {
            return 'confirmed';
        }

        if ($lead->wp_post_id && (int) $lead->wp_post_id === (int) $client->wp_post_id) {
            return 'high';
        }

        if ($lead->wp_user_id && (int) $lead->wp_user_id === (int) $client->wp_user_id) {
            return 'high';
        }

        if ($lead->phone_normalized && $lead->phone_normalized === $client->phone_normalized) {
            return 'medium';
        }

        return 'low';
    }

    private function createManualLead(Request $request, array $payload, string $reason): Lead
    {
        $platformId = (int) ($payload['platform_id'] ?? 0);
        if ($platformId <= 0) {
            throw new \InvalidArgumentException('platform_id is required.');
        }

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this lead market.'
        );

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? 'new')));
        if (!in_array($status, ['new', 'contacted', 'qualified', 'converted', 'lost'], true)) {
            $status = 'new';
        }

        $source = strtolower(trim((string) ($payload['source'] ?? 'outbound')));
        if (!in_array($source, ['registration', 'referral', 'outbound', 'import'], true)) {
            $source = 'outbound';
        }

        $assignedTo = !empty($payload['assigned_to']) ? (int) $payload['assigned_to'] : null;
        $assignedTo = $this->leadAssignmentService->assignOwnerId(
            $platformId,
            [
                'phone_normalized' => $payload['phone_normalized'] ?? null,
                'email' => $payload['email'] ?? null,
                'name' => $name,
            ],
            $assignedTo
        );
        $phonePrefix = (string) (Platform::query()->whereKey($platformId)->value('phone_prefix') ?: '254');

        $lead = Lead::create([
            'platform_id' => $platformId,
            'name' => $name,
            'phone_normalized' => PhoneNormalizer::normalize($payload['phone_normalized'] ?? null, $phonePrefix),
            'email' => !empty($payload['email']) ? trim((string) $payload['email']) : null,
            'source_url' => !empty($payload['source_url']) ? mb_substr(trim((string) $payload['source_url']), 0, 500) : null,
            'source' => $source,
            'status' => $status,
            'assigned_to' => $assignedTo,
        ]);

        TimelineEvent::create([
            'platform_id' => $lead->platform_id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'event_type' => 'lead_created',
            'actor_id' => $request->user()->id,
            'content' => [
                'source' => $lead->source,
                'status' => $lead->status,
                'assigned_to' => $lead->assigned_to,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $lead->platform_id,
            CrmAuditAction::LEAD_CREATE,
            'lead',
            (int) $lead->id,
            null,
            [
                'status' => $lead->status,
                'source' => $lead->source,
                'assigned_to' => $lead->assigned_to,
            ],
            $reason
        );

        $lead->load(['platform', 'assignedAgent']);

        return $lead;
    }

    private function scrapePreviewCacheKey(string $previewId): string
    {
        return self::SCRAPE_PREVIEW_CACHE_PREFIX . trim($previewId);
    }

    private function parseCsvRows(string $path, bool $hasHeader): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to read uploaded CSV file.');
        }

        $rows = [];
        $header = [];
        $defaultColumns = ['name', 'phone', 'email', 'source', 'status', 'assigned_to'];

        if ($hasHeader) {
            $headerRow = fgetcsv($handle);
            if (!is_array($headerRow) || empty($headerRow)) {
                fclose($handle);
                return [];
            }

            $header = array_map(function ($column) {
                $normalized = strtolower(trim((string) $column));
                $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
                return trim($normalized, '_');
            }, $headerRow);
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $columns = $hasHeader ? $header : array_slice($defaultColumns, 0, count($row));
            if (empty($columns)) {
                continue;
            }

            $normalizedRow = [];
            foreach ($columns as $index => $columnName) {
                if ($columnName === '') {
                    continue;
                }

                $normalizedRow[$columnName] = $row[$index] ?? null;
            }

            $rows[] = $normalizedRow;
        }

        fclose($handle);

        return $rows;
    }

    private function deriveLeadNameFromSourceUrl(string $sourceUrl): string
    {
        $path = trim((string) parse_url($sourceUrl, PHP_URL_PATH), '/');
        $host = trim((string) parse_url($sourceUrl, PHP_URL_HOST));

        $candidate = $path !== '' ? basename($path) : $host;
        $candidate = str_replace(['-', '_'], ' ', $candidate);
        $candidate = preg_replace('/[^a-zA-Z0-9 ]+/', ' ', $candidate) ?? '';
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate) ?? '');

        if ($candidate === '') {
            return $host !== '' ? 'Lead from ' . $host : '';
        }

        return substr(ucwords($candidate), 0, 255);
    }
}
