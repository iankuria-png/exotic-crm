<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\SystemIncident;
use App\Services\Ops\DegradationEvaluator;
use App\Services\Ops\LoadShedder;
use App\Services\Ops\OperationsSettingsRegistry;
use App\Services\Ops\OperationsReportService;
use App\Services\Ops\OperationsSettingsService;
use App\Services\Ops\OperationsSettingValidationException;
use App\Services\Ops\VitalsSampler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Operations tab's API. Kept out of SystemHealthUpdateController, which is
 * already long and answers a different question — that one asks whether each
 * dependency is configured and reachable, this one asks whether the platform is
 * under pressure right now and what it is doing about it.
 */
class OperationsController extends Controller
{
    public function __construct(
        private readonly VitalsSampler $sampler,
        private readonly DegradationEvaluator $evaluator,
        private readonly OperationsSettingsRegistry $registry,
        private readonly OperationsSettingsService $settings,
        private readonly OperationsReportService $reports,
        private readonly LoadShedder $shedder,
    ) {
    }

    /**
     * The vitals board.
     *
     * Serves the last cached sample and NEVER recomputes. A dashboard that
     * re-runs a process scan on every poll would add exactly the pressure it is
     * reporting on — and it would do so hardest when several people open the
     * page because the site feels slow.
     */
    public function vitals(): JsonResponse
    {
        $sample = $this->sampler->latest();
        $state = $this->evaluator->currentState();

        $level = (int) ($state['level'] ?? LoadShedder::LEVEL_NORMAL);
        $sampledAt = $sample['sampled_at'] ?? null;

        // The sampler runs every minute. Anything older than a few minutes
        // means the board is describing the past, and it says so rather than
        // presenting stale numbers as current.
        $ageSeconds = $sampledAt ? max(0, time() - strtotime((string) $sampledAt)) : null;

        return response()->json([
            'level' => $level,
            'level_label' => LoadShedder::label($level),
            'sampled_level' => (int) ($state['sampled_level'] ?? $level),
            'since' => $state['since'] ?? null,
            'forced' => (bool) ($state['forced'] ?? false),
            'forced_expires_at' => $state['forced_expires_at'] ?? null,
            'forced_reason' => $state['forced_reason'] ?? null,
            'enforcement_enabled' => (bool) ($state['enforcement'] ?? $this->settings->boolean('ops.enforcement.enabled')),
            'trigger_signal' => $state['trigger_signal'] ?? null,
            'trigger_value' => $state['trigger_value'] ?? null,
            'threshold' => $state['threshold'] ?? null,
            'paused_capabilities' => LoadShedder::pausedAt($level),
            'capabilities' => LoadShedder::capabilities(),
            'sampled_at' => $sampledAt,
            'sample_age_seconds' => $ageSeconds,
            'sampler_stale' => $ageSeconds === null || $ageSeconds > 300,
            'signals' => $sample['signals'] ?? [],
            'lanes' => $sample['lanes'] ?? [],
            'markets_down_names' => $sample['markets_down_names'] ?? [],
            'stalled_runs' => $sample['stalled_runs'] ?? [],
            'process_ceiling' => $sample['process_ceiling'] ?? $this->settings->integer('ops.threshold.php_processes.ceiling'),
            'process_ceiling_verified' => $sample['process_ceiling_verified']
                ?? $this->settings->boolean('ops.threshold.php_processes.ceiling_verified'),
            'scheduler' => $sample['scheduler'] ?? null,
            // Conflicts in the stored thresholds. Rejecting a bad combination
            // on save does nothing about one already in the database, so the
            // board states it rather than rendering numbers that do not
            // reconcile and leaving the reader to notice.
            'configuration_warnings' => $this->settings->configurationWarnings(),
            'process_breakdown' => $sample['process_breakdown'] ?? [],
            'process_reason' => $sample['process_reason'] ?? null,
            'history' => $sample['history'] ?? ['points' => [], 'series' => []],
        ]);
    }

    /**
     * Force or release a degradation level.
     *
     * A forced level ALWAYS expires. A forced level with no expiry is how a
     * system ends up shed for a week after an incident nobody closed out, so
     * the expiry is required rather than defaulted.
     */
    public function forceDegradation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => ['required', 'integer', 'min:0', 'max:3'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'expires_in_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $state = $this->evaluator->force(
            (int) $validated['level'],
            trim((string) $validated['reason']),
            (int) $validated['expires_in_minutes'],
            $request->user()?->id
        );

        return response()->json([
            'level' => $state['level'],
            'level_label' => LoadShedder::label((int) $state['level']),
            'forced' => true,
            'expires_at' => $state['forced_expires_at'],
        ]);
    }

    /**
     * Resume normal operation: drop the override and hand control back to the
     * sampler, which will re-escalate on the next sample if the pressure is
     * still there.
     */
    public function releaseDegradation(Request $request): JsonResponse
    {
        $state = $this->evaluator->release($request->user()?->id);

        return response()->json([
            'level' => $state['level'],
            'level_label' => LoadShedder::label((int) $state['level']),
            'forced' => false,
        ]);
    }

    /**
     * What the levels actually cost.
     *
     * Answers the question observe-only mode was shipped to answer: not "which
     * capabilities would be paused" but "for how long, and how often" — which
     * is what somebody needs before they are willing to turn enforcement on.
     */
    public function summary(Request $request): JsonResponse
    {
        $hours = (int) $request->query('hours', 24);

        return response()->json($this->reports->summary($hours));
    }

    /**
     * The incident timeline. One row per transition, so this is a short list
     * even after a bad week.
     */
    public function incidents(Request $request): JsonResponse
    {
        $query = SystemIncident::query()->with('actor:id,name')->orderByDesc('started_at');

        if ($request->filled('origin')) {
            $query->where('origin', (string) $request->query('origin'));
        }

        if ($request->filled('level')) {
            $query->where('to_level', (int) $request->query('level'));
        }

        if ($request->filled('state')) {
            $request->query('state') === 'open'
                ? $query->whereNull('resolved_at')
                : $query->whereNotNull('resolved_at');
        }

        $incidents = $query->limit(200)->get();

        return response()->json([
            'incidents' => $incidents->map(fn (SystemIncident $incident): array => [
                // No surrogate id is surfaced as a label; the reference is a
                // short, human-quotable code derived from it.
                'reference' => 'INC-'.str_pad((string) $incident->id, 5, '0', STR_PAD_LEFT),
                'id' => $incident->id,
                'from_level' => $incident->from_level,
                'from_level_label' => LoadShedder::label($incident->from_level),
                'to_level' => $incident->to_level,
                'to_level_label' => LoadShedder::label($incident->to_level),
                'trigger_signal' => $incident->trigger_signal,
                'trigger_value' => $incident->trigger_value,
                'threshold' => $incident->threshold,
                'origin' => $incident->origin,
                'actor_name' => $incident->actor?->name,
                'started_at' => $incident->started_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'duration_seconds' => $incident->resolved_at && $incident->started_at
                    ? $incident->resolved_at->diffInSeconds($incident->started_at)
                    : null,
                'snapshot' => $incident->snapshot,
            ])->all(),
        ]);
    }

    /**
     * The tuning panel.
     *
     * The registry is the source of truth for bounds and defaults, so the UI
     * never renders a range the server does not also enforce.
     */
    public function settings(Request $request): JsonResponse
    {
        $role = $request->user()?->role;
        $resolved = $this->settings->resolved();
        $groups = [];

        foreach ($this->registry->groups() as $groupKey => $group) {
            $settings = [];

            foreach ($this->registry->inGroup($groupKey) as $key => $definition) {
                $settings[] = [
                    'key' => $key,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'type' => $definition['type'],
                    'value' => $resolved[$key] ?? $definition['default'],
                    'default' => $definition['default'],
                    'min' => $definition['min'],
                    'max' => $definition['max'],
                    'unit' => $definition['unit'],
                    'risk' => $definition['risk'],
                    'is_default' => ($resolved[$key] ?? $definition['default']) === $definition['default'],
                ];
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'description' => $group['description'],
                'writable' => $this->registry->canWriteGroup($groupKey, $role),
                'settings' => $settings,
            ];
        }

        return response()->json(['groups' => $groups]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'updates' => ['required', 'array', 'min:1', 'max:60'],
            'updates.*.key' => ['required', 'string', 'max:120'],
            'updates.*.value' => ['present'],
        ]);

        try {
            $result = $this->settings->update(
                $validated['updates'],
                $request->user()?->id,
                $request->user()?->role,
                $request->ip()
            );
        } catch (OperationsSettingValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'key' => $exception->settingKey,
                'errors' => [$exception->settingKey => [$exception->getMessage()]],
            ], $exception->status);
        }

        return response()->json([
            'updated' => $result['updated'],
            'changes' => $result['changes'],
            'effective_at' => 'next scheduler tick',
        ]);
    }

    public function resetSetting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $value = $this->settings->reset(
                $validated['key'],
                $request->user()?->id,
                $request->user()?->role,
                $request->ip()
            );
        } catch (OperationsSettingValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'key' => $exception->settingKey,
            ], $exception->status);
        }

        return response()->json(['key' => $validated['key'], 'value' => $value]);
    }
}
