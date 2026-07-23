<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\RenewalCampaign;
use App\Models\RenewalRun;
use App\Models\Template;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\RenewalService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;

class RenewalController extends Controller
{
    public function __construct(
        private readonly RenewalService $renewalService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService
    ) {
    }

    public function overview(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'search' => 'nullable|string|max:255',
            'bucket' => 'nullable|in:all,active,risk,pending,workload,stable,expired,lapsed,paused',
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
            'campaign_ids' => 'nullable|array',
            'campaign_ids.*' => 'integer|exists:renewal_campaigns,id',
            'campaign_id' => 'nullable|integer|exists:renewal_campaigns,id',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'search' => 'nullable|string|max:255',
            'bucket' => 'nullable|in:all,active,risk,pending,workload,stable,expired,lapsed,paused',
            'channel' => 'nullable|in:sms,email,whatsapp',
            'dry_run' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (is_array($accessiblePlatformIds) && empty($accessiblePlatformIds)) {
            return response()->json([
                'message' => 'No accessible markets found for renewal execution.',
            ], 422);
        }

        if (
            !empty($validated['platform_id']) &&
            is_array($accessiblePlatformIds) &&
            !in_array((int) $validated['platform_id'], $accessiblePlatformIds, true)
        ) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $campaignIds = collect($validated['campaign_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();
        if (!empty($validated['campaign_id'])) {
            $campaignIds->push((int) $validated['campaign_id']);
            $campaignIds = $campaignIds->unique()->values();
        }

        $platformScope = $accessiblePlatformIds;
        if (!empty($validated['platform_id'])) {
            $platformScope = [(int) $validated['platform_id']];
        }

        $options = [
            'bucket' => $validated['bucket'] ?? null,
            'search' => $validated['search'] ?? null,
            'channel' => $validated['channel'] ?? null,
            'dry_run' => (bool) ($validated['dry_run'] ?? false),
        ];

        if (!empty($validated['search']) || !empty($validated['bucket']) || !empty($validated['platform_id'])) {
            $overviewFilters = [
                'search' => $validated['search'] ?? null,
                'bucket' => $validated['bucket'] ?? null,
            ];
            if (!empty($validated['platform_id'])) {
                $overviewFilters['platform_id'] = (int) $validated['platform_id'];
            } elseif (is_array($accessiblePlatformIds)) {
                $overviewFilters['platform_ids'] = $accessiblePlatformIds;
            }

            $overview = $this->renewalService->buildOverview($overviewFilters, 5000, $request->user());
            $options['targets'] = collect($overview['targets']->items())
                ->map(fn($row) => is_array($row) ? $row : (array) $row)
                ->values()
                ->all();
        }

        $result = $this->renewalService->runCampaigns(
            $campaignIds->isEmpty() ? null : $campaignIds->all(),
            $request->user()->id,
            $platformScope,
            $options
        );

        $auditPlatformId = !empty($validated['platform_id'])
            ? (int) $validated['platform_id']
            : (is_array($platformScope) && !empty($platformScope) ? (int) $platformScope[0] : 0);
        $auditCampaignId = (int) ($campaignIds->first() ?? ($result['campaigns'][0]['campaign_id'] ?? 0));
        if ($auditPlatformId > 0 && $auditCampaignId > 0) {
            $this->auditService->fromRequest(
                $request,
                $auditPlatformId,
                CrmAuditAction::CAMPAIGN_RUN_CONFIGURED,
                'renewal_campaign',
                $auditCampaignId,
                null,
                [
                    'campaign_ids' => $campaignIds->all(),
                    'bucket' => $validated['bucket'] ?? null,
                    'search' => $validated['search'] ?? null,
                    'channel' => $validated['channel'] ?? null,
                    'dry_run' => (bool) ($validated['dry_run'] ?? false),
                    'targeted' => $result['totals']['targeted'] ?? 0,
                ],
                $validated['reason'] ?? 'Campaign run configured from CRM'
            );
        }

        return response()->json($result);
    }

    public function remind(Request $request)
    {
        $validated = $request->validate([
            'deal_id' => 'nullable|integer|exists:deals,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'template_id' => 'nullable|integer|exists:templates,id',
            'channel' => 'nullable|in:sms,whatsapp',
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
            $request->user()->id,
            $validated['channel'] ?? 'sms'
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
            'bucket' => 'nullable|in:all,active,risk,pending,workload,stable,expired,lapsed,paused',
            'template_id' => 'nullable|integer|exists:templates,id',
            'channel' => 'nullable|in:sms,whatsapp',
            'dry_run' => 'nullable|boolean',
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
            $request->user()->id,
            (bool) ($validated['dry_run'] ?? false),
            $validated['channel'] ?? 'sms'
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

    /**
     * Read the reminder cadence config for a market (or the global default set when
     * no platform_id is supplied).
     */
    public function cadence(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        $platformId = !empty($validated['platform_id']) ? (int) $validated['platform_id'] : null;
        if ($platformId !== null && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), $platformId)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        return response()->json($this->renewalService->cadenceConfig($platformId));
    }

    public function storeCampaign(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'trigger_days' => 'required|integer|min:-60|max:60',
            'template_id' => 'required|integer|exists:templates,id',
            'enabled' => 'nullable|boolean',
        ]);

        $platformId = !empty($validated['platform_id']) ? (int) $validated['platform_id'] : null;
        if ($platformId !== null && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), $platformId)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $template = Template::query()->findOrFail((int) $validated['template_id']);
        $channel = $this->campaignChannel($template->channel);

        // Guard against duplicate reminder points in the same scope+channel, which
        // would double-send at that offset (each campaign is deduped independently).
        $duplicate = RenewalCampaign::query()
            ->when($platformId === null, fn($q) => $q->whereNull('platform_id'), fn($q) => $q->where('platform_id', $platformId))
            ->where('trigger_days', (int) $validated['trigger_days'])
            ->where('channel', $channel)
            ->exists();
        if ($duplicate) {
            return response()->json([
                'message' => 'A reminder already exists at this offset for this market and channel.',
            ], 422);
        }

        $campaign = RenewalCampaign::create([
            'platform_id' => $platformId,
            'trigger_days' => (int) $validated['trigger_days'],
            'channel' => $channel,
            'template_id' => (int) $validated['template_id'],
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ]);

        $this->auditService->record([
            'platform_id' => $platformId ?? 0,
            'actor_id' => $request->user()->id,
            'action' => CrmAuditAction::RENEWAL_CAMPAIGN_CREATE,
            'entity_type' => 'renewal_campaign',
            'entity_id' => $campaign->id,
            'after_state' => $campaign->only(['platform_id', 'trigger_days', 'channel', 'template_id', 'enabled']),
            'reason' => 'Renewal cadence updated',
        ]);

        return response()->json($campaign->load('template:id,title,channel,status,platform_id'), 201);
    }

    public function updateCampaign(Request $request, RenewalCampaign $campaign)
    {
        $validated = $request->validate([
            'trigger_days' => 'nullable|integer|min:-60|max:60',
            'template_id' => 'nullable|integer|exists:templates,id',
            'enabled' => 'nullable|boolean',
        ]);

        if ($campaign->platform_id !== null && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $campaign->platform_id)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $before = $campaign->only(['platform_id', 'trigger_days', 'channel', 'template_id', 'enabled']);

        if (array_key_exists('template_id', $validated) && $validated['template_id'] !== null) {
            $template = Template::query()->findOrFail((int) $validated['template_id']);
            $campaign->template_id = (int) $validated['template_id'];
            $campaign->channel = $this->campaignChannel($template->channel);
        }
        if (array_key_exists('trigger_days', $validated) && $validated['trigger_days'] !== null) {
            $campaign->trigger_days = (int) $validated['trigger_days'];
        }
        if (array_key_exists('enabled', $validated) && $validated['enabled'] !== null) {
            $campaign->enabled = (bool) $validated['enabled'];
        }

        $duplicate = RenewalCampaign::query()
            ->whereKeyNot($campaign->id)
            ->when($campaign->platform_id === null, fn($q) => $q->whereNull('platform_id'), fn($q) => $q->where('platform_id', $campaign->platform_id))
            ->where('trigger_days', $campaign->trigger_days)
            ->where('channel', $campaign->channel)
            ->exists();
        if ($duplicate) {
            return response()->json([
                'message' => 'A reminder already exists at this offset for this market and channel.',
            ], 422);
        }

        $campaign->save();

        $this->auditService->record([
            'platform_id' => $campaign->platform_id ?? 0,
            'actor_id' => $request->user()->id,
            'action' => CrmAuditAction::RENEWAL_CAMPAIGN_UPDATE,
            'entity_type' => 'renewal_campaign',
            'entity_id' => $campaign->id,
            'before_state' => $before,
            'after_state' => $campaign->only(['platform_id', 'trigger_days', 'channel', 'template_id', 'enabled']),
            'reason' => 'Renewal cadence updated',
        ]);

        return response()->json($campaign->load('template:id,title,channel,status,platform_id'));
    }

    public function destroyCampaign(Request $request, RenewalCampaign $campaign)
    {
        if ($campaign->platform_id !== null && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $campaign->platform_id)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $before = $campaign->only(['platform_id', 'trigger_days', 'channel', 'template_id', 'enabled']);
        $campaignId = $campaign->id;
        $platformId = $campaign->platform_id;
        $campaign->delete();

        $this->auditService->record([
            'platform_id' => $platformId ?? 0,
            'actor_id' => $request->user()->id,
            'action' => CrmAuditAction::RENEWAL_CAMPAIGN_DELETE,
            'entity_type' => 'renewal_campaign',
            'entity_id' => $campaignId,
            'before_state' => $before,
            'reason' => 'Renewal cadence updated',
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Toggle the per-market short-cycle guard (auto-suppress reminders whose lead time
     * is >= the client's subscription length).
     */
    public function updateGuard(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'enabled' => 'required|boolean',
        ]);

        $platformId = (int) $validated['platform_id'];
        if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), $platformId)) {
            return response()->json(['message' => 'You do not have access to this market.'], 403);
        }

        $platform = Platform::query()->findOrFail($platformId);
        $before = ['renewal_reminder_guard_enabled' => (bool) $platform->renewal_reminder_guard_enabled];
        $platform->renewal_reminder_guard_enabled = (bool) $validated['enabled'];
        $platform->save();

        $this->auditService->record([
            'platform_id' => $platformId,
            'actor_id' => $request->user()->id,
            'action' => CrmAuditAction::RENEWAL_GUARD_UPDATE,
            'entity_type' => 'platform',
            'entity_id' => $platformId,
            'before_state' => $before,
            'after_state' => ['renewal_reminder_guard_enabled' => (bool) $validated['enabled']],
            'reason' => 'Short-cycle reminder guard updated',
        ]);

        return response()->json([
            'success' => true,
            'platform_id' => $platformId,
            'guard_enabled' => (bool) $validated['enabled'],
        ]);
    }

    /**
     * Map a template channel (email/sms/whatsapp) onto the renewal_campaigns channel
     * enum, which accepts email/sms/both/whatsapp.
     */
    private function campaignChannel(?string $templateChannel): string
    {
        $channel = strtolower(trim((string) $templateChannel));

        return in_array($channel, ['email', 'sms', 'whatsapp'], true) ? $channel : 'sms';
    }
}
