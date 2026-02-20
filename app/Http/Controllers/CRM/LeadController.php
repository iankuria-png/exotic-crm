<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\LeadAssignmentService;
use App\Services\LeadImportService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly LeadImportService $leadImportService,
        private readonly LeadAssignmentService $leadAssignmentService,
        private readonly AuditService $auditService
    ) {
    }

    public function index(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this lead market.'
        );

        $query = Lead::with(['platform', 'assignedAgent']);
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

        return response()->json($lead);
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

        $lead = Lead::create([
            'platform_id' => $platformId,
            'name' => $name,
            'phone_normalized' => $this->normalizePhone($payload['phone_normalized'] ?? null),
            'email' => !empty($payload['email']) ? trim((string) $payload['email']) : null,
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

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone);
        if (!$normalized) {
            return null;
        }

        $normalized = ltrim($normalized, '+');
        if (str_starts_with($normalized, '0')) {
            $normalized = '254' . substr($normalized, 1);
        }

        return $normalized ?: null;
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
}
