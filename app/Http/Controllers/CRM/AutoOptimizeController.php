<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyAutoOptimizeItemJob;
use App\Models\AutoOptimizeAlert;
use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\AutoOptimizeRun;
use App\Services\AutoOptimize\AutoOptimizeApplyService;
use App\Services\AutoOptimize\AutoOptimizeConfig;
use App\Services\AutoOptimize\AutoOptimizeEngineService;
use App\Services\MarketAuthorizationService;
use App\Support\MarketTimezone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AutoOptimizeController extends Controller
{
    private const VIEW_ROLES = ['marketing', 'sales', 'admin', 'sub_admin'];
    private const CONFIGURE_ROLES = ['marketing', 'admin', 'sub_admin'];
    private const APPLY_ROLES = ['admin', 'sub_admin', 'sales'];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuth,
        private readonly AutoOptimizeEngineService $engineService,
        private readonly AutoOptimizeApplyService $applyService,
    ) {}

    public function index(Request $request)
    {
        $this->requireRole($request, self::VIEW_ROLES);
        $query = AutoOptimizePlan::query()->with(['platform:id,name,country,timezone', 'runs' => fn ($q) => $q->latest()->limit(1)]);
        $this->marketAuth->applyPlatformScope($query, $request->user());

        return response()->json([
            'data' => $query->orderBy('name')->get()
                ->map(fn ($plan) => $this->planPayload($plan))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->requireRole($request, self::CONFIGURE_ROLES);
        $payload = $this->validatedPayload($request);
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $payload['platform_id']);

        // Validate scorer_weights if provided
        if ($error = AutoOptimizeConfig::validateScorerWeights(
            data_get($payload, 'actions.generation.scorer_weights')
        )) {
            return response()->json(['message' => $error], 422);
        }

        $plan = AutoOptimizePlan::query()->create($payload + ['created_by' => $request->user()->id]);
        $plan->load('platform');

        return response()->json(['plan' => $this->planPayload($plan)], 201);
    }

    public function update(Request $request, AutoOptimizePlan $plan)
    {
        $this->requireRole($request, self::CONFIGURE_ROLES);
        $this->ensurePlanAccess($request, $plan);
        $payload = $this->validatedPayload($request, $plan);

        if ($error = AutoOptimizeConfig::validateScorerWeights(
            data_get($payload, 'actions.generation.scorer_weights')
        )) {
            return response()->json(['message' => $error], 422);
        }

        $plan->fill($payload)->save();
        $plan->load('platform');

        return response()->json(['plan' => $this->planPayload($plan)]);
    }

    public function destroy(Request $request, AutoOptimizePlan $plan)
    {
        $this->requireRole($request, self::CONFIGURE_ROLES);
        $this->ensurePlanAccess($request, $plan);
        $plan->delete();
        return response()->json(['message' => 'Auto-optimize plan deleted.']);
    }

    public function clone(Request $request, AutoOptimizePlan $plan)
    {
        $this->requireRole($request, self::CONFIGURE_ROLES);
        $this->ensurePlanAccess($request, $plan);
        $validated = $request->validate([
            'platform_ids' => 'required|array|min:1',
            'platform_ids.*' => 'integer|exists:platforms,id',
        ]);

        $created = [];
        foreach ((array) $validated['platform_ids'] as $platformId) {
            $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $platformId);
            $copy = AutoOptimizePlan::query()->create([
                'name' => $plan->name,
                'platform_id' => (int) $platformId,
                'enabled' => false,
                'autopilot' => false,
                'criteria' => $plan->criteria,
                'actions' => $plan->actions,
                'schedule' => $plan->schedule,
                'reliability' => $plan->reliability,
                'created_by' => $request->user()->id,
            ]);
            $copy->load('platform');
            $created[] = $this->planPayload($copy);
        }

        return response()->json(['plans' => $created], 201);
    }

    public function toggle(Request $request, AutoOptimizePlan $plan)
    {
        $this->requireRole($request, self::CONFIGURE_ROLES);
        $this->ensurePlanAccess($request, $plan);

        $newEnabled = !$plan->enabled;

        DB::transaction(function () use ($plan, $newEnabled, $request) {
            if ($newEnabled) {
                // Disable all other enabled plans for this platform (one-enabled-per-market)
                AutoOptimizePlan::query()
                    ->where('platform_id', $plan->platform_id)
                    ->where('id', '!=', $plan->id)
                    ->where('enabled', true)
                    ->each(fn ($p) => $p->forceFill(['enabled' => false])->save());
            }
            $plan->forceFill(['enabled' => $newEnabled])->save();
        });

        return response()->json(['plan' => $this->planPayload($plan->fresh('platform'))]);
    }

    public function autopilot(Request $request, AutoOptimizePlan $plan)
    {
        $this->requireRole($request, self::CONFIGURE_ROLES);
        $this->ensurePlanAccess($request, $plan);
        $plan->forceFill(['autopilot' => !$plan->autopilot])->save();
        return response()->json(['plan' => $this->planPayload($plan->fresh('platform'))]);
    }

    public function runNow(Request $request, AutoOptimizePlan $plan)
    {
        $this->requireRole($request, self::APPLY_ROLES);
        $this->ensurePlanAccess($request, $plan);
        $run = $this->engineService->runPlan($plan->fresh('platform'));
        return response()->json(['run' => $run]);
    }

    public function items(Request $request)
    {
        $this->requireRole($request, self::VIEW_ROLES);

        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'status' => 'nullable|string',
            'plan_id' => 'nullable|integer|exists:auto_optimize_plans,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AutoOptimizeItem::query()
            ->with(['client', 'run', 'plan.platform', 'approver', 'reverter']);

        $platformIds = $this->marketAuth->resolveAccessiblePlatformIds($request->user());
        if (is_array($platformIds)) {
            $query->whereIn('platform_id', $platformIds);
        }
        if (!empty($validated['platform_id'])) {
            $query->where('platform_id', (int) $validated['platform_id']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['plan_id'])) {
            $query->where('auto_optimize_plan_id', (int) $validated['plan_id']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return response()->json($query->latest()->paginate($perPage));
    }

    public function showItem(Request $request, AutoOptimizeItem $item)
    {
        $this->requireRole($request, self::VIEW_ROLES);
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $item->platform_id);
        $item->load(['client', 'plan.platform', 'run', 'approver', 'reverter']);
        return response()->json(['item' => $item]);
    }

    public function approve(Request $request, AutoOptimizeItem $item)
    {
        $this->requireRole($request, self::APPLY_ROLES);
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $item->platform_id);

        if ($item->status !== 'pending') {
            return response()->json(['message' => 'Item is not pending.'], 409);
        }

        // Enqueue — no synchronous WP writes in the request
        ApplyAutoOptimizeItemJob::dispatch($item->id, $request->user()->id);

        return response()->json(['message' => 'Apply job dispatched.', 'item_id' => $item->id]);
    }

    public function approveAll(Request $request)
    {
        $this->requireRole($request, self::APPLY_ROLES);
        $validated = $request->validate(['plan_id' => 'required|integer|exists:auto_optimize_plans,id']);

        $plan = AutoOptimizePlan::findOrFail((int) $validated['plan_id']);
        $this->ensurePlanAccess($request, $plan);

        $count = 0;
        AutoOptimizeItem::query()
            ->where('auto_optimize_plan_id', $plan->id)
            ->where('status', 'pending')
            ->each(function ($item) use ($request, &$count) {
                ApplyAutoOptimizeItemJob::dispatch($item->id, $request->user()->id);
                $count++;
            });

        return response()->json(['message' => "{$count} apply job(s) dispatched."]);
    }

    public function revert(Request $request, AutoOptimizeItem $item)
    {
        $this->requireRole($request, self::APPLY_ROLES);
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $item->platform_id);

        $force = (bool) $request->boolean('force', false);

        if ($item->status !== 'applied') {
            return response()->json(['message' => 'Item is not applied.'], 409);
        }

        try {
            $result = $this->applyService->revert($item, $request->user(), $force);
            return response()->json(['item' => $result]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'revert_conflict')) {
                return response()->json(['message' => $e->getMessage(), 'conflict' => true], 409);
            }
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function skip(Request $request, AutoOptimizeItem $item)
    {
        $this->requireRole($request, self::APPLY_ROLES);
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $item->platform_id);

        if (!in_array($item->status, ['pending', 'queued'], true)) {
            return response()->json(['message' => 'Item cannot be skipped in its current state.'], 409);
        }

        $item->forceFill(['status' => 'skipped', 'reason' => 'Manually skipped by ' . $request->user()->name])->save();

        return response()->json(['item' => $item->fresh()]);
    }

    public function runs(Request $request)
    {
        $this->requireRole($request, self::VIEW_ROLES);
        $validated = $request->validate(['plan_id' => 'nullable|integer|exists:auto_optimize_plans,id']);

        $query = AutoOptimizeRun::query()->with('plan.platform');
        if (!empty($validated['plan_id'])) {
            $plan = AutoOptimizePlan::findOrFail((int) $validated['plan_id']);
            $this->ensurePlanAccess($request, $plan);
            $query->where('auto_optimize_plan_id', $plan->id);
        } else {
            $platformIds = $this->marketAuth->resolveAccessiblePlatformIds($request->user());
            if (is_array($platformIds)) {
                $query->whereIn('platform_id', $platformIds);
            }
        }

        return response()->json(['data' => $query->latest()->limit(50)->get()]);
    }

    public function alerts(Request $request)
    {
        $this->requireRole($request, self::VIEW_ROLES);
        $validated = $request->validate(['plan_id' => 'nullable|integer', 'resolved' => 'nullable|boolean']);

        $query = AutoOptimizeAlert::query()->with('plan.platform', 'client');
        $platformIds = $this->marketAuth->resolveAccessiblePlatformIds($request->user());
        if (is_array($platformIds)) {
            $query->whereIn('platform_id', $platformIds);
        }
        if (!empty($validated['plan_id'])) {
            $query->where('auto_optimize_plan_id', (int) $validated['plan_id']);
        }
        if (array_key_exists('resolved', $validated)) {
            $validated['resolved']
                ? $query->whereNotNull('resolved_at')
                : $query->whereNull('resolved_at');
        }

        return response()->json(['data' => $query->latest()->limit(100)->get()]);
    }

    public function resolveAlert(Request $request, AutoOptimizeAlert $alert)
    {
        $this->requireRole($request, self::CONFIGURE_ROLES);
        if ($alert->platform_id) {
            $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $alert->platform_id);
        }
        $alert->forceFill(['resolved_at' => now(), 'resolved_by' => $request->user()->id])->save();
        return response()->json(['alert' => $alert->fresh()]);
    }

    public function metrics(Request $request)
    {
        $this->requireRole($request, self::VIEW_ROLES);
        $validated = $request->validate(['platform_id' => 'nullable|integer|exists:platforms,id']);

        $platformIds = $this->marketAuth->resolveAccessiblePlatformIds($request->user());
        $query = AutoOptimizeItem::query();

        if (!empty($validated['platform_id'])) {
            $query->where('platform_id', (int) $validated['platform_id']);
        } elseif (is_array($platformIds)) {
            $query->whereIn('platform_id', $platformIds);
        }

        $counts = $query->selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status');

        $totalCost = AutoOptimizeItem::query()
            ->when(!empty($validated['platform_id']), fn ($q) => $q->where('platform_id', (int) $validated['platform_id']))
            ->when(is_array($platformIds), fn ($q) => $q->whereIn('platform_id', $platformIds))
            ->sum('ai_cost_usd');

        $improved = AutoOptimizeItem::query()
            ->where('status', 'applied')
            ->whereNotNull('impact')
            ->when(!empty($validated['platform_id']), fn ($q) => $q->where('platform_id', (int) $validated['platform_id']))
            ->get()
            ->filter(fn ($i) => ($i->impact['improved'] ?? false))
            ->count();

        $withImpact = AutoOptimizeItem::query()
            ->where('status', 'applied')
            ->whereNotNull('impact')
            ->when(!empty($validated['platform_id']), fn ($q) => $q->where('platform_id', (int) $validated['platform_id']))
            ->count();

        return response()->json([
            'optimized' => $counts['applied'] ?? 0,
            'pending' => $counts['pending'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
            'reverted' => $counts['reverted'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'cost_usd' => round((float) $totalCost, 4),
            'pct_improved' => $withImpact > 0 ? round(($improved / $withImpact) * 100, 1) : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function planPayload(AutoOptimizePlan $plan): array
    {
        $plan->loadMissing('platform');
        $lastRun = $plan->runs->sortByDesc('created_at')->first();
        $cfg = AutoOptimizeConfig::effective($plan);

        return [
            'id' => (int) $plan->id,
            'name' => $plan->name,
            'platform_id' => (int) $plan->platform_id,
            'platform' => $plan->platform,
            'enabled' => (bool) $plan->enabled,
            'autopilot' => (bool) $plan->autopilot,
            'criteria' => $cfg['criteria'],
            'actions' => $plan->actions, // raw (not effective) for UI editing
            'schedule' => $cfg['schedule'],
            'reliability' => $cfg['reliability'],
            'last_run_at' => optional($plan->last_run_at)->toIso8601String(),
            'coverage_count' => $this->engineService->coverageCount($plan),
            'due_now' => $this->engineService->dueForRun($plan),
            'last_run' => $lastRun,
            'queue_counts' => $this->queueCounts($plan),
        ];
    }

    private function queueCounts(AutoOptimizePlan $plan): array
    {
        $counts = AutoOptimizeItem::query()
            ->where('auto_optimize_plan_id', $plan->id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return [
            'pending' => $counts['pending'] ?? 0,
            'applied' => $counts['applied'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'reverted' => $counts['reverted'] ?? 0,
        ];
    }

    private function validatedPayload(Request $request, ?AutoOptimizePlan $plan = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'platform_id' => 'required|integer|exists:platforms,id',
            'enabled' => 'nullable|boolean',
            'autopilot' => 'nullable|boolean',
            'criteria' => 'nullable|array',
            'criteria.max_score' => 'nullable|integer|min:0|max:100',
            'criteria.views_below_market_pct' => 'nullable|numeric|min:0|max:100',
            'criteria.contact_rate_below_market_pct' => 'nullable|numeric|min:0|max:100',
            'criteria.engagement_below_market_pct' => 'nullable|numeric|min:0|max:100',
            'criteria.require_below' => ['nullable', 'string', Rule::in(['any', 'all'])],
            'criteria.min_market_sample' => 'nullable|integer|min:1',
            'criteria.only_published' => 'nullable|boolean',
            'criteria.eligibility_window_days' => 'nullable|integer|min:1|max:90',
            'actions' => 'nullable|array',
            'actions.optimize_bio' => 'nullable|boolean',
            'actions.switch_main_image' => 'nullable|boolean',
            'actions.generation' => 'nullable|array',
            'actions.generation.language' => ['nullable', 'string', Rule::in(array_keys(\App\Services\Seo\BioGenerationService::SUPPORTED_LANGUAGES))],
            'actions.generation.respect_existing_language' => 'nullable|boolean',
            'actions.generation.tone' => 'nullable|string|max:255',
            'actions.generation.temperament' => 'nullable|string|max:255',
            'actions.generation.min_words' => 'nullable|integer|min:20|max:500',
            'actions.generation.max_words' => 'nullable|integer|min:40|max:700',
            'actions.generation.max_characters' => 'nullable|integer|min:200|max:5000',
            'actions.generation.max_services' => 'nullable|integer|min:0|max:20',
            'actions.generation.include_location' => 'nullable|boolean',
            'actions.generation.include_services' => 'nullable|boolean',
            'actions.generation.include_contact' => 'nullable|boolean',
            'actions.generation.contact_channel' => ['nullable', 'string', Rule::in(['none', 'phone', 'whatsapp', 'both'])],
            'actions.generation.custom_prompt' => 'nullable|string|max:2000',
            'actions.generation.providers_order' => 'nullable|array',
            'actions.generation.scorer_weights' => 'nullable|array',
            'actions.image_quality' => 'nullable|array',
            'schedule' => 'nullable|array',
            'schedule.active_days' => 'nullable|array',
            'schedule.active_days.*' => 'integer|min:1|max:7',
            'schedule.window_start' => 'nullable|string|max:5',
            'schedule.window_end' => 'nullable|string|max:5',
            'schedule.daily_limit' => 'nullable|integer|min:1|max:500',
            'schedule.runway_threshold' => 'nullable|integer|min:0|max:500',
            'reliability' => 'nullable|array',
            'reliability.exclude_optimized_within_days' => 'nullable|integer|min:0|max:90',
            'reliability.impact_recheck_days' => 'nullable|integer|min:1|max:30',
            'reliability.min_score_gain' => 'nullable|integer|min:0|max:50',
            'reliability.max_writes_per_hour' => 'nullable|integer|min:1|max:500',
            'reliability.retry_attempts' => 'nullable|integer|min:1|max:10',
            'reliability.language_confidence' => 'nullable|numeric|min:0|max:1',
            'reliability.similarity_lookback_days' => 'nullable|integer|min:1|max:90',
            'reliability.max_similarity_distance' => 'nullable|integer|min:0|max:64',
        ]);

        return [
            'name' => trim((string) $validated['name']),
            'platform_id' => (int) $validated['platform_id'],
            'enabled' => (bool) ($validated['enabled'] ?? $plan?->enabled ?? false),
            'autopilot' => (bool) ($validated['autopilot'] ?? $plan?->autopilot ?? false),
            'criteria' => $validated['criteria'] ?? $plan?->criteria,
            'actions' => $validated['actions'] ?? $plan?->actions,
            'schedule' => $validated['schedule'] ?? $plan?->schedule,
            'reliability' => $validated['reliability'] ?? $plan?->reliability,
        ];
    }

    private function ensurePlanAccess(Request $request, AutoOptimizePlan $plan): void
    {
        $this->marketAuth->ensureUserCanAccessPlatform($request->user(), (int) $plan->platform_id);
    }

    private function requireRole(Request $request, array $roles): void
    {
        $user = $request->user();
        if (!in_array($user?->role, $roles, true)) {
            abort(403, 'Insufficient permissions.');
        }
    }
}
