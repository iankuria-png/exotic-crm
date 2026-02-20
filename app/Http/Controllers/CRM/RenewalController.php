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
            'per_page' => 'nullable|integer|min:10|max:100',
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
            'deal_id' => 'required|integer|exists:deals,id',
            'template_id' => 'nullable|integer|exists:templates,id',
        ]);

        $deal = Deal::query()->with(['client.platform', 'product'])->findOrFail((int) $validated['deal_id']);

        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $deal->platform_id)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $result = $this->renewalService->sendManualReminder(
            $deal,
            $validated['template_id'] ?? null,
            $request->user()->id
        );

        $status = !empty($result['success']) ? 200 : 422;

        return response()->json($result, $status);
    }

    public function pause(Request $request)
    {
        $validated = $request->validate([
            'deal_id' => 'required|integer|exists:deals,id',
            'reason' => 'required|string|max:500',
            'pause_until' => 'nullable|date|after_or_equal:today',
        ]);

        $deal = Deal::query()->with(['client.platform', 'product'])->findOrFail((int) $validated['deal_id']);

        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $deal->platform_id)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $result = $this->renewalService->pauseReminders(
            $deal,
            $validated['reason'],
            $request->user()->id,
            $validated['pause_until'] ?? null
        );

        return response()->json($result);
    }

    public function resume(Request $request)
    {
        $validated = $request->validate([
            'deal_id' => 'required|integer|exists:deals,id',
            'reason' => 'required|string|max:500',
        ]);

        $deal = Deal::query()->with(['client.platform', 'product'])->findOrFail((int) $validated['deal_id']);

        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $deal->platform_id)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $result = $this->renewalService->resumeReminders(
            $deal,
            $validated['reason'],
            $request->user()->id
        );

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
                fn ($query) => $query->where('campaign_id', (int) $validated['campaign_id'])
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
