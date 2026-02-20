<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Deal;
use App\Models\Client;
use App\Models\Product;
use App\Models\TimelineEvent;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\WpSyncService;
use App\Services\ClientSyncService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DealController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService
    ) {
    }

    public function index(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this deal market.'
        );

        $query = Deal::with(['client', 'product', 'platform', 'assignedAgent']);
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%");
            });
        }

        $statsQuery = clone $query;
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'active' => (clone $statsQuery)->where('status', 'active')->count(),
            'pending' => (clone $statsQuery)->where('status', 'pending')->count(),
            'awaiting_payment' => (clone $statsQuery)->where('status', 'awaiting_payment')->count(),
            'cancelled' => (clone $statsQuery)->where('status', 'cancelled')->count(),
            'pipeline_value' => (float) (clone $statsQuery)
                ->whereIn('status', ['pending', 'awaiting_payment', 'paid', 'active'])
                ->sum('amount'),
        ];

        $deals = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        $payload = $deals->toArray();
        $payload['stats'] = $stats;

        return response()->json($payload);
    }

    public function show(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);
        $deal->load(['client', 'product', 'platform', 'assignedAgent', 'payment', 'lead']);
        return response()->json($deal);
    }

    public function update(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        $validated = $request->validate([
            'product_id' => 'sometimes|exists:products,id',
            'plan_type' => 'sometimes|in:basic,premium,vip',
            'duration' => 'sometimes|in:weekly,biweekly,monthly,manual',
            'status' => 'sometimes|in:pending,awaiting_payment,paid,active,expired,cancelled',
        ]);

        $before = $deal->only(['product_id', 'plan_type', 'duration', 'status', 'amount']);

        if (array_key_exists('product_id', $validated) || array_key_exists('duration', $validated)) {
            $productId = $validated['product_id'] ?? $deal->product_id;
            $duration = $validated['duration'] ?? $deal->duration;
            $product = Product::findOrFail($productId);

            $validated['amount'] = match ($duration) {
                'weekly' => $product->weekly_price,
                'biweekly' => $product->biweekly_price,
                'monthly' => $product->monthly_price,
                'manual' => 0,
            };
        }

        $deal->update($validated);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_updated',
            'actor_id' => $request->user()->id,
            'content' => [
                'before' => $before,
                'after' => $deal->only(['product_id', 'plan_type', 'duration', 'status', 'amount']),
            ],
            'created_at' => now(),
        ]);

        $deal->load(['client', 'product', 'platform', 'assignedAgent', 'payment', 'lead']);

        return response()->json($deal);
    }

    public function destroy(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        if ($deal->status === 'active') {
            return response()->json([
                'message' => 'Active deals cannot be deleted. Deactivate or cancel first.',
            ], 422);
        }

        $before = $deal->toArray();
        $platformId = $deal->platform_id;
        $dealId = $deal->id;

        $deal->delete();

        TimelineEvent::create([
            'platform_id' => $platformId,
            'entity_type' => 'deal',
            'entity_id' => $dealId,
            'event_type' => 'deal_deleted',
            'actor_id' => $request->user()->id,
            'content' => [
                'before' => [
                    'status' => $before['status'] ?? null,
                    'amount' => $before['amount'] ?? null,
                    'duration' => $before['duration'] ?? null,
                    'plan_type' => $before['plan_type'] ?? null,
                ],
            ],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Deal deleted']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'product_id' => 'required|exists:products,id',
            'plan_type' => 'required|in:basic,premium,vip',
            'duration' => 'required|in:weekly,biweekly,monthly,manual',
            'lead_id' => 'nullable|exists:leads,id',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $client->platform_id,
            'You do not have access to this client market.'
        );

        $product = Product::findOrFail($validated['product_id']);

        $amount = match ($validated['duration']) {
            'weekly' => $product->weekly_price,
            'biweekly' => $product->biweekly_price,
            'monthly' => $product->monthly_price,
            'manual' => 0,
        };

        $deal = Deal::create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
            'lead_id' => $validated['lead_id'] ?? null,
            'product_id' => $product->id,
            'plan_type' => $validated['plan_type'],
            'amount' => $amount,
            'currency' => $client->platform->currency_code ?? 'KES',
            'duration' => $validated['duration'],
            'status' => 'pending',
            'assigned_to' => $request->user()->id,
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_created',
            'actor_id' => $request->user()->id,
            'content' => [
                'plan_type' => $deal->plan_type,
                'duration' => $deal->duration,
                'amount' => $deal->amount,
            ],
            'created_at' => now(),
        ]);

        TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'deal_created',
            'actor_id' => $request->user()->id,
            'content' => [
                'deal_id' => $deal->id,
                'plan_type' => $deal->plan_type,
                'amount' => $deal->amount,
            ],
            'created_at' => now(),
        ]);

        $deal->load(['client', 'product', 'platform']);
        return response()->json($deal, 201);
    }

    public function activate(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if ($deal->status === 'active') {
            return response()->json(['message' => 'Deal is already active'], 422);
        }

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $durationDays = match ($deal->duration) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            'manual' => $request->input('duration_days', 30),
        };

        $beforeState = [
            'deal_status' => $deal->status,
            'client_profile_status' => $client->profile_status,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $wpSync = WpSyncService::forPlatform($client->platform_id);
            $wpSync->activateClient(
                $client->wp_post_id,
                $deal->plan_type,
                $durationDays,
                $deal->id
            );

            $deal->update([
                'status' => 'active',
                'activated_at' => now(),
                'expires_at' => now()->addDays($durationDays),
            ]);

            // Re-sync client from WP to get updated meta
            $syncService = new ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);
            $client->refresh();

            $afterState = [
                'deal_status' => 'active',
                'client_profile_status' => $client->profile_status,
                'expires_at' => $deal->expires_at->toDateTimeString(),
            ];

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_ACTIVATE,
                'deal',
                (int) $deal->id,
                $beforeState,
                $afterState,
                $request->input('reason') ?: 'Activated via CRM flow'
            );

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'profile_activated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'deal_id' => $deal->id,
                    'plan_type' => $deal->plan_type,
                    'duration_days' => $durationDays,
                    'expires_at' => $deal->expires_at->toDateTimeString(),
                ],
                'created_at' => now(),
            ]);

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'deal',
                'entity_id' => $deal->id,
                'event_type' => 'deal_activated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'duration_days' => $durationDays,
                    'expires_at' => $deal->expires_at->toDateTimeString(),
                ],
                'created_at' => now(),
            ]);

            DB::commit();

            $deal->load(['client', 'product', 'platform']);
            return response()->json($deal);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Deal activation failed', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Activation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deactivate(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        $client = $deal->client;
        if (!$client) {
            return response()->json(['message' => 'Deal has no associated client'], 422);
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $beforeState = [
            'deal_status' => $deal->status,
            'client_profile_status' => $client->profile_status,
        ];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $wpSync = WpSyncService::forPlatform($client->platform_id);
            $wpSync->deactivateClient($client->wp_post_id);

            $deal->update(['status' => 'cancelled']);

            $syncService = new ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);
            $client->refresh();

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_DEACTIVATE,
                'deal',
                (int) $deal->id,
                $beforeState,
                [
                    'deal_status' => 'cancelled',
                    'client_profile_status' => $client->profile_status,
                ],
                (string) $request->reason
            );

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'profile_deactivated',
                'actor_id' => $request->user()->id,
                'content' => [
                    'deal_id' => $deal->id,
                    'reason' => $request->reason,
                ],
                'created_at' => now(),
            ]);

            DB::commit();

            $deal->load(['client', 'product', 'platform']);
            return response()->json($deal);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Deal deactivation failed', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Deactivation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function extend(Request $request, Deal $deal)
    {
        $this->authorizeDealAccess($request, $deal);

        if ($deal->status !== 'active') {
            return response()->json(['message' => 'Only active deals can be extended'], 422);
        }

        $request->validate([
            'additional_days' => 'required|integer|min:1|max:365',
            'reason' => 'required|string|max:500',
        ]);

        $client = $deal->client;
        $beforeState = ['expires_at' => $deal->expires_at?->toDateTimeString()];

        DB::beginTransaction();
        try {
            $platform = $client->platform ?? Platform::findOrFail($client->platform_id);
            $wpSync = WpSyncService::forPlatform($client->platform_id);
            $wpSync->extendClient($client->wp_post_id, $request->additional_days);

            $newExpiry = ($deal->expires_at ?? now())->copy()->addDays($request->additional_days);
            $deal->update(['expires_at' => $newExpiry]);

            $syncService = new ClientSyncService($platform);
            $syncService->syncOne($client->wp_post_id);

            $this->auditService->fromRequest(
                $request,
                (int) $client->platform_id,
                CrmAuditAction::DEAL_EXTEND,
                'deal',
                (int) $deal->id,
                $beforeState,
                ['expires_at' => $newExpiry->toDateTimeString()],
                (string) $request->input('reason')
            );

            TimelineEvent::create([
                'platform_id' => $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'deal_extended',
                'actor_id' => $request->user()->id,
                'content' => [
                    'deal_id' => $deal->id,
                    'additional_days' => $request->additional_days,
                    'new_expires_at' => $newExpiry->toDateTimeString(),
                ],
                'created_at' => now(),
            ]);

            DB::commit();

            $deal->load(['client', 'product', 'platform']);
            return response()->json($deal);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Extension failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function authorizeDealAccess(Request $request, Deal $deal): void
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $deal->platform_id,
            'You do not have access to this deal market.'
        );
    }
}
