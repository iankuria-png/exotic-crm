<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\LeadImportService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly LeadImportService $leadImportService,
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

        $leads = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        return response()->json($leads);
    }

    public function show(Request $request, Lead $lead)
    {
        $this->authorizeLeadAccess($request, $lead);
        $lead->load(['platform', 'assignedAgent', 'convertedClient']);

        return response()->json($lead);
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

        $baseQuery = Lead::query();
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
}
