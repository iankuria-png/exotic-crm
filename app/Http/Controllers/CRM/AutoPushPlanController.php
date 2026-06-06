<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AutoPushAlert;
use App\Models\AutoPushPlan;
use App\Models\AutoPushRun;
use App\Services\AutoPush\AutoPushDraftPackageService;
use App\Services\AutoPush\AutoPushEngineService;
use App\Services\MarketAuthorizationService;
use App\Support\AutoPushSlotAllocator;
use App\Support\MarketTimezone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutoPushPlanController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AutoPushEngineService $engineService,
        private readonly AutoPushDraftPackageService $draftPackageService,
    ) {
    }

    public function index(Request $request)
    {
        $query = AutoPushPlan::query()->with(['platform:id,name,country,timezone', 'runs' => fn ($runQuery) => $runQuery->latest()->limit(1)]);
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        $plans = $query->orderBy('name')->get();

        return response()->json([
            'data' => $plans->map(fn (AutoPushPlan $plan) => $this->planPayload($plan))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $payload = $this->validatedPayload($request);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $payload['platform_id']);

        $plan = AutoPushPlan::query()->create($payload + [
            'created_by' => (int) $request->user()->id,
        ]);
        $plan->load('platform');

        return response()->json([
            'plan' => $this->planPayload($plan),
        ], 201);
    }

    public function update(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        $payload = $this->validatedPayload($request, $plan);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $payload['platform_id']);

        $plan->fill($payload)->save();
        $plan->load('platform');

        return response()->json([
            'plan' => $this->planPayload($plan),
        ]);
    }

    public function destroy(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        $plan->delete();

        return response()->json(['message' => 'Auto-push plan deleted.']);
    }

    public function clone(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        $validated = $request->validate([
            'platform_ids' => 'required|array|min:1',
            'platform_ids.*' => 'integer|exists:platforms,id',
        ]);

        $created = [];
        foreach ((array) $validated['platform_ids'] as $platformId) {
            $platformId = (int) $platformId;
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), $platformId);

            $copy = AutoPushPlan::query()->create([
                'name' => $plan->name,
                'platform_id' => $platformId,
                'enabled' => false,
                'autopilot' => false,
                'buckets' => $plan->buckets,
                'schedule' => $plan->schedule,
                'message_strategy' => $plan->message_strategy,
                'reliability' => $plan->reliability,
                'created_by' => (int) $request->user()->id,
            ]);
            $copy->load('platform');
            $created[] = $this->planPayload($copy);
        }

        return response()->json(['plans' => $created], 201);
    }

    public function toggle(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        $plan->forceFill(['enabled' => !$plan->enabled])->save();

        return response()->json(['plan' => $this->planPayload($plan->fresh('platform'))]);
    }

    public function autopilot(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        $plan->forceFill(['autopilot' => !$plan->autopilot])->save();

        return response()->json(['plan' => $this->planPayload($plan->fresh('platform'))]);
    }

    public function preview(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        return response()->json($this->draftPackageService->refresh($plan));
    }

    public function draftPackage(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);

        return response()->json($this->draftPackageService->load($plan));
    }

    public function saveDraftPackage(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.preview_id' => 'required|string|max:80',
            'items.*.slot_index' => 'nullable|integer|min:0|max:500',
            'items.*.client_id' => 'nullable|integer|exists:clients,id',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.city' => 'nullable|string|max:255',
            'items.*.profile_url' => 'nullable|string|max:1000',
            'items.*.profile_image_url' => 'nullable|string|max:1000',
            'items.*.fallback_profile_image_url' => 'nullable|string|max:1000',
            'items.*.message' => 'nullable|string|max:500',
            'items.*.message_source' => 'nullable|string|max:50',
            'items.*.scheduled_at' => 'nullable|date',
            'items.*.scheduled_at_market' => 'nullable|date',
            'ui' => 'nullable|array',
            'ui.active_preview_id' => 'nullable|string|max:80',
            'ui.preview_device' => ['nullable', 'string', Rule::in(['mobile', 'desktop'])],
        ]);

        return response()->json($this->draftPackageService->save($plan, $validated));
    }

    public function shuffleDraftPackage(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);

        return response()->json($this->draftPackageService->shuffle($plan));
    }

    public function replaceDraftPackageItem(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        $validated = $request->validate([
            'preview_id' => 'required|string|max:80',
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        return response()->json(
            $this->draftPackageService->replaceItem(
                $plan,
                (string) $validated['preview_id'],
                isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            )
        );
    }

    public function runNow(Request $request, AutoPushPlan $plan)
    {
        $this->ensurePlanAccess($request, $plan);
        $run = $this->engineService->runPlan($plan->fresh('platform'));
        $run->load('campaign');

        if ($run->status === 'skipped' && $run->campaign_id) {
            return response()->json([
                'message' => $run->error_message ?: 'This plan already has an open auto-push campaign.',
                'run' => $run,
                'campaign' => $run->campaign,
            ], 409);
        }

        return response()->json([
            'run' => $run,
            'campaign' => $run->campaign,
        ]);
    }

    public function runs(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'nullable|integer|exists:auto_push_plans,id',
        ]);

        $query = AutoPushRun::query()->with(['plan.platform', 'campaign']);
        if (!empty($validated['plan_id'])) {
            $plan = AutoPushPlan::query()->findOrFail((int) $validated['plan_id']);
            $this->ensurePlanAccess($request, $plan);
            $query->where('auto_push_plan_id', (int) $plan->id);
        } else {
            $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
            if (is_array($platformIds)) {
                $query->whereIn('platform_id', $platformIds);
            }
        }

        return response()->json([
            'data' => $query->latest()->limit(50)->get(),
        ]);
    }

    public function alerts(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'nullable|integer|exists:auto_push_plans,id',
            'resolved' => 'nullable|boolean',
        ]);

        $query = AutoPushAlert::query()->with(['plan.platform', 'campaign']);
        if (!empty($validated['plan_id'])) {
            $plan = AutoPushPlan::query()->findOrFail((int) $validated['plan_id']);
            $this->ensurePlanAccess($request, $plan);
            $query->where('auto_push_plan_id', (int) $plan->id);
        } else {
            $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
            if (is_array($platformIds)) {
                $query->whereIn('platform_id', $platformIds);
            }
        }

        if (array_key_exists('resolved', $validated)) {
            $validated['resolved']
                ? $query->whereNotNull('resolved_at')
                : $query->whereNull('resolved_at');
        }

        return response()->json([
            'data' => $query->latest()->limit(100)->get(),
        ]);
    }

    public function resolveAlert(Request $request, AutoPushAlert $alert)
    {
        if ($alert->platform_id) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $alert->platform_id);
        }

        $alert->forceFill([
            'resolved_at' => now(),
            'resolved_by' => (int) $request->user()->id,
        ])->save();

        return response()->json(['alert' => $alert->fresh()]);
    }

    private function ensurePlanAccess(Request $request, AutoPushPlan $plan): void
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $plan->platform_id);
    }

    private function validatedPayload(Request $request, ?AutoPushPlan $plan = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'platform_id' => 'required|integer|exists:platforms,id',
            'enabled' => 'nullable|boolean',
            'autopilot' => 'nullable|boolean',
            'buckets' => 'required|array|min:1',
            'buckets.*.type' => ['required', 'string', Rule::in(['new_subscriptions', 'subscription_tier', 'bottom_engagement'])],
            'buckets.*.enabled' => 'nullable|boolean',
            'buckets.*.limit' => 'required|integer|min:1|max:500',
            'buckets.*.params' => 'nullable|array',
            'schedule' => 'required|array',
            'schedule.active_days' => 'required|array|min:1',
            'schedule.active_days.*' => 'integer|min:1|max:7',
            'schedule.window_start' => 'required|string|max:5',
            'schedule.window_end' => 'required|string|max:5',
            'schedule.interval_hours' => 'required|integer|min:1|max:24',
            'schedule.max_items_per_day' => 'required|integer|min:1|max:500',
            'schedule.lookahead_days' => 'required|integer|min:1|max:14',
            'schedule.runway_threshold' => 'nullable|integer|min:1|max:500',
            'schedule.count_unapproved_drafts_as_coverage' => 'nullable|boolean',
            'message_strategy' => 'required|array',
            'message_strategy.mode' => ['required', 'string', Rule::in(['hybrid', 'ai', 'seed'])],
            'message_strategy.seed_phrases' => 'required|array|min:1',
            'message_strategy.seed_phrases.*' => 'string|max:255',
            'message_strategy.tone' => 'nullable|string|max:255',
            'message_strategy.temperament' => 'nullable|string|max:255',
            'message_strategy.language' => 'nullable|string|max:10',
            'message_strategy.max_chars' => 'nullable|integer|min:40|max:255',
            'reliability' => 'nullable|array',
            'reliability.reserve_multiplier' => 'nullable|numeric|min:1|max:10',
            'reliability.max_replacements_per_item' => 'nullable|integer|min:0|max:10',
            'reliability.exclude_pushed_within_days' => 'nullable|integer|min:0|max:30',
            'reliability.replacement_spillover' => ['nullable', 'string', Rule::in(['same_day', 'next_active_day'])],
            'reliability.sms_alerts_enabled' => 'nullable|boolean',
        ]);

        return [
            'name' => trim((string) $validated['name']),
            'platform_id' => (int) $validated['platform_id'],
            'enabled' => (bool) ($validated['enabled'] ?? $plan?->enabled ?? false),
            'autopilot' => (bool) ($validated['autopilot'] ?? $plan?->autopilot ?? false),
            'buckets' => array_map(function (array $bucket): array {
                return [
                    'type' => (string) $bucket['type'],
                    'enabled' => (bool) ($bucket['enabled'] ?? true),
                    'limit' => max(1, (int) ($bucket['limit'] ?? 1)),
                    'params' => is_array($bucket['params'] ?? null) ? $bucket['params'] : [],
                ];
            }, (array) $validated['buckets']),
            'schedule' => [
                'active_days' => array_values(array_map('intval', (array) $validated['schedule']['active_days'])),
                'window_start' => (string) $validated['schedule']['window_start'],
                'window_end' => (string) $validated['schedule']['window_end'],
                'interval_hours' => (int) $validated['schedule']['interval_hours'],
                'max_items_per_day' => (int) $validated['schedule']['max_items_per_day'],
                'lookahead_days' => (int) $validated['schedule']['lookahead_days'],
                'runway_threshold' => $validated['schedule']['runway_threshold'] ?? null,
                'count_unapproved_drafts_as_coverage' => (bool) ($validated['schedule']['count_unapproved_drafts_as_coverage'] ?? true),
            ],
            'message_strategy' => [
                'mode' => (string) $validated['message_strategy']['mode'],
                'seed_phrases' => array_values(array_map(fn ($value) => trim((string) $value), (array) $validated['message_strategy']['seed_phrases'])),
                'tone' => trim((string) ($validated['message_strategy']['tone'] ?? '')),
                'temperament' => trim((string) ($validated['message_strategy']['temperament'] ?? '')),
                'language' => trim((string) ($validated['message_strategy']['language'] ?? 'en')),
                'max_chars' => (int) ($validated['message_strategy']['max_chars'] ?? 120),
            ],
            'reliability' => [
                'reserve_multiplier' => (float) ($validated['reliability']['reserve_multiplier'] ?? 1.5),
                'max_replacements_per_item' => (int) ($validated['reliability']['max_replacements_per_item'] ?? 2),
                'exclude_pushed_within_days' => (int) ($validated['reliability']['exclude_pushed_within_days'] ?? 3),
                'replacement_spillover' => (string) ($validated['reliability']['replacement_spillover'] ?? 'next_active_day'),
                'sms_alerts_enabled' => (bool) ($validated['reliability']['sms_alerts_enabled'] ?? false),
            ],
        ];
    }

    private function planPayload(AutoPushPlan $plan): array
    {
        $plan->loadMissing('platform');
        $lastRun = $plan->runs->sortByDesc('created_at')->first();
        $timezone = MarketTimezone::resolve($plan->platform?->timezone, config('app.timezone', 'UTC'));
        $nowMarket = now($timezone);
        $runwayThreshold = (int) (data_get($plan->schedule, 'runway_threshold') ?: AutoPushSlotAllocator::slotCountForLookahead($plan, $nowMarket->copy()->startOfDay()));

        return [
            'id' => (int) $plan->id,
            'name' => $plan->name,
            'platform_id' => (int) $plan->platform_id,
            'platform' => $plan->platform,
            'enabled' => (bool) $plan->enabled,
            'autopilot' => (bool) $plan->autopilot,
            'buckets' => $plan->buckets,
            'schedule' => $plan->schedule,
            'message_strategy' => $plan->message_strategy,
            'reliability' => $plan->reliability,
            'last_run_at' => optional($plan->last_run_at)->toIso8601String(),
            'coverage_count' => $this->engineService->coverageCount($plan),
            'runway_threshold' => $runwayThreshold,
            'due_now' => $this->engineService->dueForRun($plan, $nowMarket),
            'last_run' => $lastRun,
            'has_draft_run_package' => !empty($plan->draft_run_package),
        ];
    }
}
