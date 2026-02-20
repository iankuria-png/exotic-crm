<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\TimelineEvent;
use App\Models\Platform;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LeadAssignmentService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;

class ClientController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly LeadAssignmentService $leadAssignmentService,
        private readonly AuditService $auditService
    ) {
    }

    public function index(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this client market.'
        );

        $query = Client::with(['platform', 'assignedAgent']);
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $numeric = (int) $search;
                    $q->orWhere('id', $numeric)
                        ->orWhere('wp_post_id', $numeric)
                        ->orWhere('wp_user_id', $numeric);
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('profile_status', $request->status);
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        if ($request->filled('plan')) {
            if ($request->plan === 'premium') {
                $query->where('premium', true);
            } elseif ($request->plan === 'featured') {
                $query->where('featured', true);
            } elseif ($request->plan === 'basic') {
                $query->where('premium', false)->where('featured', false);
            }
        }

        if ($request->filled('verified')) {
            $query->where('verified', $request->boolean('verified'));
        }

        $statsQuery = clone $query;
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'active' => (clone $statsQuery)->where('profile_status', 'publish')->count(),
            'premium' => (clone $statsQuery)->where('premium', true)->count(),
            'verified' => (clone $statsQuery)->where('verified', true)->count(),
            'inactive' => (clone $statsQuery)->where('profile_status', 'private')->count(),
        ];

        $clients = $query->orderBy('updated_at', 'desc')
            ->paginate($request->get('per_page', 25));

        $payload = $clients->toArray();
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
            'city' => 'nullable|string|max:100',
            'profile_status' => 'nullable|in:publish,private,draft,pending',
            'assigned_to' => 'nullable|exists:users,id',
            'wp_user_id' => 'nullable|integer|min:1',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $client = $this->createManualClient(
                $request,
                $validated,
                $validated['reason'] ?? 'Manual client create from CRM'
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($client, 201);
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
            'You do not have access to this client market.'
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
                'name' => trim((string) ($row['name'] ?? $row['client_name'] ?? '')),
                'phone_normalized' => $row['phone_normalized'] ?? $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'city' => $row['city'] ?? null,
                'profile_status' => $row['profile_status'] ?? $row['status'] ?? 'private',
                'assigned_to' => isset($row['assigned_to']) && trim((string) $row['assigned_to']) !== '' ? (int) $row['assigned_to'] : null,
                'wp_user_id' => isset($row['wp_user_id']) && trim((string) $row['wp_user_id']) !== '' ? (int) $row['wp_user_id'] : null,
            ];

            try {
                $this->createManualClient(
                    $request,
                    $payload,
                    ($validated['reason'] ?? 'CSV client upload from CRM') . " (row {$rowNumber})"
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

    public function show(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $client->load([
            'platform',
            'assignedAgent',
            'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
            'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
            'activeDeal.product',
        ]);

        return response()->json($client);
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'city' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone_normalized' => 'nullable|string|max:20',
        ]);

        $beforeState = [
            'assigned_to' => $client->assigned_to,
            'city' => $client->city,
            'email' => $client->email,
            'phone_normalized' => $client->phone_normalized,
        ];

        if (array_key_exists('assigned_to', $validated) && $validated['assigned_to']) {
            $assignee = User::query()->find((int) $validated['assigned_to']);
            if (!$assignee || !$assignee->isActive() || !$this->marketAuthorizationService->userCanAccessPlatform($assignee, (int) $client->platform_id)) {
                return response()->json([
                    'message' => 'Assigned owner is not eligible for this market.',
                ], 422);
            }
        }

        $client->update($validated);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'client_updated',
            'actor_id' => $request->user()->id,
            'content' => [
                'before' => $beforeState,
                'after' => [
                    'assigned_to' => $client->assigned_to,
                    'city' => $client->city,
                    'email' => $client->email,
                    'phone_normalized' => $client->phone_normalized,
                ],
            ],
            'created_at' => now(),
        ]);

        $client->load(['platform', 'assignedAgent']);

        return response()->json($client);
    }

    public function timeline(Client $client, Request $request)
    {
        $this->authorizeClientAccess($request, $client);

        $events = TimelineEvent::forEntity('client', $client->id)
            ->with('actor')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        return response()->json($events);
    }

    public function storeNote(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'note_type' => 'required|in:call,email,sms,internal,system',
            'content' => 'required|string|max:5000',
            'follow_up_at' => 'nullable|date|after:now',
        ]);

        $note = ClientNote::create([
            'client_id' => $client->id,
            'author_id' => $request->user()->id,
            'note_type' => $validated['note_type'],
            'content' => $validated['content'],
            'follow_up_at' => $validated['follow_up_at'] ?? null,
            'created_at' => now(),
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'note_added',
            'actor_id' => $request->user()->id,
            'content' => [
                'note_id' => $note->id,
                'note_type' => $note->note_type,
                'has_follow_up' => $note->follow_up_at !== null,
            ],
            'created_at' => now(),
        ]);

        $note->load('author');
        return response()->json($note, 201);
    }

    public function syncOne(Request $request, Client $client)
    {
        $this->authorizeClientAccess($request, $client);

        if ((int) $client->wp_post_id <= 0) {
            return response()->json([
                'message' => 'This client record is CRM-only and cannot be synced from WordPress yet.',
            ], 422);
        }

        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $syncService = new \App\Services\ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);
            $client->refresh();
            $client->load([
                'platform',
                'assignedAgent',
                'deals' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'notes' => fn($q) => $q->with('author')->orderBy('created_at', 'desc'),
                'payments' => fn($q) => $q->with('product')->orderBy('created_at', 'desc'),
                'activeDeal.product',
            ]);

            return response()->json($client);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function authorizeClientAccess(Request $request, Client $client): void
    {
        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $client->platform_id)) {
            abort(403, 'You do not have access to this client market.');
        }
    }

    private function createManualClient(Request $request, array $payload, string $reason): Client
    {
        $platformId = (int) ($payload['platform_id'] ?? 0);
        if ($platformId <= 0) {
            throw new \InvalidArgumentException('platform_id is required.');
        }

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this client market.'
        );

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name is required.');
        }

        $profileStatus = strtolower(trim((string) ($payload['profile_status'] ?? 'private')));
        if (!in_array($profileStatus, ['publish', 'private', 'draft', 'pending'], true)) {
            $profileStatus = 'private';
        }

        $assignedTo = !empty($payload['assigned_to']) ? (int) $payload['assigned_to'] : null;
        if ($assignedTo) {
            $assignee = User::query()->find($assignedTo);
            if (
                !$assignee ||
                !$assignee->isActive() ||
                !$this->marketAuthorizationService->userCanAccessPlatform($assignee, $platformId)
            ) {
                throw new \InvalidArgumentException('Assigned owner is not eligible for this market.');
            }
        } else {
            $assignedTo = $this->leadAssignmentService->assignOwnerId($platformId, [
                'phone_normalized' => $payload['phone_normalized'] ?? null,
                'email' => $payload['email'] ?? null,
                'name' => $name,
            ]);
        }

        $manualWpPostId = $this->nextManualWpPostId($platformId);

        $client = Client::create([
            'platform_id' => $platformId,
            'wp_post_id' => $manualWpPostId,
            'wp_user_id' => !empty($payload['wp_user_id']) ? (int) $payload['wp_user_id'] : null,
            'client_type' => 'escort',
            'name' => $name,
            'phone_normalized' => $this->normalizePhone($payload['phone_normalized'] ?? null),
            'email' => !empty($payload['email']) ? trim((string) $payload['email']) : null,
            'city' => !empty($payload['city']) ? trim((string) $payload['city']) : null,
            'profile_status' => $profileStatus,
            'assigned_to' => $assignedTo,
            'premium' => false,
            'featured' => false,
            'verified' => false,
            'last_synced_at' => null,
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'client_created',
            'actor_id' => $request->user()->id,
            'content' => [
                'source' => 'manual',
                'assigned_to' => $client->assigned_to,
                'profile_status' => $client->profile_status,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $client->platform_id,
            CrmAuditAction::CLIENT_CREATE,
            'client',
            (int) $client->id,
            null,
            [
                'name' => $client->name,
                'phone_normalized' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'profile_status' => $client->profile_status,
                'assigned_to' => $client->assigned_to,
                'wp_post_id' => $client->wp_post_id,
            ],
            $reason
        );

        $client->load(['platform', 'assignedAgent']);

        return $client;
    }

    private function nextManualWpPostId(int $platformId): int
    {
        $minId = Client::query()
            ->where('platform_id', $platformId)
            ->min('wp_post_id');

        if ($minId === null || (int) $minId >= 0) {
            return -1;
        }

        return ((int) $minId) - 1;
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
        $defaultColumns = ['name', 'phone', 'email', 'city', 'status', 'assigned_to', 'wp_user_id'];

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
