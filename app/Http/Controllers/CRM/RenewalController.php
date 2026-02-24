<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\RenewalRun;
use App\Services\MarketAuthorizationService;
use App\Services\RenewalService;
use Illuminate\Http\Request;

class RenewalController extends Controller
{
    public function __construct(
        private readonly RenewalService $renewalService,
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function overview(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'search' => 'nullable|string|max:255',
            'bucket' => 'nullable|in:risk,pending,stable,expired,paused',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:500',
        ]);

        $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($accessiblePlatformIds) && !empty($validated['platform_id']) && !in_array((int) $validated['platform_id'], $accessiblePlatformIds, true)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $filters = [
            'search' => $validated['search'] ?? null,
            'bucket' => $validated['bucket'] ?? null,
        ];

        if (!empty($validated['platform_id'])) {
            $filters['platform_id'] = (int) $validated['platform_id'];
        } elseif (is_array($accessiblePlatformIds)) {
            $filters['platform_ids'] = $accessiblePlatformIds;
        }

        $overview = $this->renewalService->buildOverview(
            $filters,
            (int) ($validated['per_page'] ?? 50),
            $request->user()
        );

        return response()->json($overview);
    }

    public function run(Request $request)
    {
        $validated = $request->validate([
            'campaign_id' => 'nullable|integer|exists:renewal_campaigns,id',
        ]);

        $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($accessiblePlatformIds) && empty($accessiblePlatformIds)) {
            return response()->json([
                'message' => 'No accessible markets found for renewal execution.',
            ], 422);
        }

        $result = $this->renewalService->runCampaigns(
            $validated['campaign_id'] ?? null,
            $request->user()->id,
            $accessiblePlatformIds
        );

        return response()->json($result);
    }

    public function remind(Request $request)
    {
        $validated = $request->validate([
            'deal_id' => 'nullable|integer|exists:deals,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'template_id' => 'nullable|integer|exists:templates,id',
        ]);

        if (empty($validated['deal_id']) && empty($validated['client_id'])) {
            return response()->json(['message' => 'Either deal_id or client_id is required.'], 422);
        }

        if (!empty($validated['deal_id'])) {
            $deal = Deal::query()->with(['client.platform', 'product'])->findOrFail((int) $validated['deal_id']);
            if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $deal->platform_id)) {
                return response()->json(['message' => 'You do not have access to this market.'], 403);
            }
        } else {
            // Virtual renewal (client only)
            $client = \App\Models\Client::with('platform')->findOrFail((int) $validated['client_id']);
            if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $client->platform_id)) {
                return response()->json(['message' => 'You do not have access to this market.'], 403);
            }
            // Create a "virtual deal" object for the service
            $deal = new Deal();
            $deal->client_id = $client->id;
            $deal->platform_id = $client->platform_id;
            $deal->client = $client;
            $deal->expires_at = $client->escort_expire;
        }

        $result = $this->renewalService->sendManualReminder(
            $deal,
            $validated['template_id'] ?? null,
            $request->user()->id
        );

        $status = !empty($result['success']) ? 200 : 422;

        return response()->json($result, $status);
    }

    public function bulkRemind(Request $request)
    {
        $validated = $request->validate([
            'selection' => 'nullable|array',
            'select_all' => 'nullable|boolean',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'search' => 'nullable|string|max:255',
            'bucket' => 'nullable|in:risk,pending,stable,expired,paused',
            'template_id' => 'nullable|integer|exists:templates,id',
        ]);

        $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($accessiblePlatformIds) && !empty($validated['platform_id']) && !in_array((int) $validated['platform_id'], $accessiblePlatformIds, true)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $filters = [
            'search' => $validated['search'] ?? null,
            'bucket' => $validated['bucket'] ?? null,
        ];

        if (!empty($validated['platform_id'])) {
            $filters['platform_id'] = (int) $validated['platform_id'];
        } elseif (is_array($accessiblePlatformIds)) {
            $filters['platform_ids'] = $accessiblePlatformIds;
        }

        $result = $this->renewalService->bulkRemind(
            $validated['selection'] ?? [],
            (bool) ($validated['select_all'] ?? false),
            $filters,
            $validated['template_id'] ?? null,
            $request->user()->id
        );

        return response()->json($result);
    }

    public function pause(Request $request)
    {
        $validated = $request->validate([
            'deal_id' => 'nullable|integer|exists:deals,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'reason' => 'required|string|max:500',
            'pause_until' => 'nullable|date|after_or_equal:today',
        ]);

        if (!empty($validated['deal_id'])) {
            $deal = Deal::query()->with(['client.platform', 'product'])->findOrFail((int) $validated['deal_id']);
            if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $deal->platform_id)) {
                return response()->json(['message' => 'You do not have access to this market.'], 403);
            }
            $result = $this->renewalService->pauseReminders($deal, $validated['reason'], $request->user()->id, $validated['pause_until'] ?? null);
        } else if (!empty($validated['client_id'])) {
            return response()->json(['message' => 'Pausing is currently only supported for active subscriptions (deals). Please create a subscription first.'], 422);
        } else {
            return response()->json(['message' => 'deal_id is required.'], 422);
        }

        return response()->json($result);
    }

    public function resume(Request $request)
    {
        $validated = $request->validate([
            'deal_id' => 'nullable|integer|exists:deals,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'reason' => 'required|string|max:500',
        ]);

        if (!empty($validated['deal_id'])) {
            $deal = Deal::query()->with(['client.platform', 'product'])->findOrFail((int) $validated['deal_id']);
            if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $deal->platform_id)) {
                return response()->json(['message' => 'You do not have access to this market.'], 403);
            }
            $result = $this->renewalService->resumeReminders($deal, $validated['reason'], $request->user()->id);
        } else if (!empty($validated['client_id'])) {
            return response()->json(['message' => 'Resuming is currently only supported for active subscriptions (deals).'], 422);
        } else {
            return response()->json(['message' => 'deal_id is required.'], 422);
        }

        return response()->json($result);
    }

    public function runs(Request $request)
    {
        $validated = $request->validate([
            'campaign_id' => 'nullable|integer|exists:renewal_campaigns,id',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $runsQuery = RenewalRun::query()
            ->with(['campaign.template:id,title', 'runner:id,name'])
            ->when(
                !empty($validated['campaign_id']),
                fn($query) => $query->where('campaign_id', (int) $validated['campaign_id'])
            );

        // `renewal_runs` is campaign-level and not market-keyed; non-admin users can view only their own runs.
        if ($request->user()->role !== MarketAuthorizationService::ROLE_ADMIN) {
            $runsQuery->where('run_by', $request->user()->id);
        }

        $runs = $runsQuery
            ->orderByDesc('run_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json($runs);
    }
}
