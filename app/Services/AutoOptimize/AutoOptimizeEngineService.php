<?php

namespace App\Services\AutoOptimize;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\AutoOptimizeRun;
use App\Support\MarketTimezone;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\OptimizeProfileJob;
use App\Jobs\ApplyAutoOptimizeItemJob;
use Illuminate\Support\Collection;

class AutoOptimizeEngineService
{
    public function __construct(
        private readonly AutoOptimizeSelectionService $selectionService,
        private readonly AutoOptimizeAlertService $alertService,
    ) {}

    public function dueForRun(AutoOptimizePlan $plan, ?Carbon $nowMarketLocal = null): bool
    {
        $plan->loadMissing('platform');
        $timezone = MarketTimezone::resolve($plan->platform?->timezone, config('app.timezone', 'UTC'));
        $nowMarketLocal = ($nowMarketLocal?->copy() ?? now($timezone))->setTimezone($timezone);
        $cfg = AutoOptimizeConfig::effective($plan);
        $schedule = $cfg['schedule'];

        $activeDays = collect((array) ($schedule['active_days'] ?? []))->map(fn ($d) => (int) $d)->all();
        if (!in_array($nowMarketLocal->isoWeekday(), $activeDays, true)) {
            return false;
        }

        $windowEnd = Carbon::parse(
            $nowMarketLocal->toDateString() . ' ' . (string) ($schedule['window_end'] ?? '06:00'),
            $timezone
        );
        if ($nowMarketLocal->greaterThan($windowEnd)) {
            return false;
        }

        // Check coverage (items queued/building/pending/applying today)
        $coverage = $this->coverageCount($plan);
        $threshold = (int) ($schedule['runway_threshold'] ?? 0);
        if ($threshold <= 0) {
            $threshold = (int) ($schedule['daily_limit'] ?? 20);
        }

        return $coverage < max(1, $threshold);
    }

    public function coverageCount(AutoOptimizePlan $plan): int
    {
        return AutoOptimizeItem::query()
            ->where('auto_optimize_plan_id', $plan->id)
            ->whereIn('status', ['queued', 'building', 'pending', 'applying'])
            ->count();
    }

    public function runPlan(AutoOptimizePlan $plan): AutoOptimizeRun
    {
        $plan->loadMissing('platform');

        $run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'platform_id' => $plan->platform_id,
            'status' => 'running',
        ]);

        try {
            $selectedClients = $this->selectionService->selectForPlan($plan);

            if ($selectedClients->isEmpty()) {
                $run->forceFill([
                    'status' => 'skipped',
                    'candidates_scanned' => 0,
                    'candidates_selected' => 0,
                    'jobs_total' => 0,
                ])->save();

                $this->alertService->raise(
                    'no_candidates',
                    'warning',
                    'No auto-optimize candidates found',
                    'The engine ran but no eligible profiles matched the plan criteria.',
                    ['run_id' => $run->id],
                    $plan,
                );

                $plan->forceFill(['last_run_at' => now()])->save();
                return $run->fresh();
            }

            $this->dispatchSelectedClients($plan, $run, $selectedClients, 'Selected by engine run #' . $run->id);

        } catch (\Throwable $e) {
            Log::error('auto_optimize.run_failed', [
                'plan_id' => $plan->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();

            $this->alertService->raise(
                'run_failed',
                'critical',
                'Auto Optimize run failed',
                $e->getMessage(),
                ['run_id' => $run->id],
                $plan,
            );

            $plan->forceFill(['last_run_at' => now()])->save();
        }

        return $run->fresh();
    }

    /**
     * Stage a hand-picked set of clients into the normal optimizer build queue.
     * Used by quality audit recovery so bad writing can be fixed even when the
     * profile is not selected by traffic/conversion criteria.
     */
    public function runSelectedClients(AutoOptimizePlan $plan, Collection $selectedClients, string $reason): AutoOptimizeRun
    {
        $plan->loadMissing('platform');

        $run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'platform_id' => $plan->platform_id,
            'status' => 'running',
        ]);

        try {
            if ($selectedClients->isEmpty()) {
                $run->forceFill([
                    'status' => 'skipped',
                    'candidates_scanned' => 0,
                    'candidates_selected' => 0,
                    'jobs_total' => 0,
                ])->save();

                $this->alertService->raise(
                    'no_quality_candidates',
                    'warning',
                    'No bio quality candidates found',
                    'The audit action ran but no live profiles had enough quality issues to stage.',
                    ['run_id' => $run->id],
                    $plan,
                );

                return $run->fresh();
            }

            $this->dispatchSelectedClients($plan, $run, $selectedClients, $reason . ' #' . $run->id);
        } catch (\Throwable $e) {
            Log::error('auto_optimize.quality_recovery_failed', [
                'plan_id' => $plan->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();
        }

        return $run->fresh();
    }

    private function dispatchSelectedClients(AutoOptimizePlan $plan, AutoOptimizeRun $run, Collection $selectedClients, string $reason): void
    {
        // Create queued items BEFORE dispatch (idempotency guarantee).
        $itemIds = [];
        DB::transaction(function () use ($selectedClients, $plan, $run, $reason, &$itemIds) {
            foreach ($selectedClients as $client) {
                $hasActiveItem = AutoOptimizeItem::query()
                    ->where('client_id', $client->id)
                    ->whereIn('status', AutoOptimizeItem::ACTIVE_STATUSES)
                    ->exists();

                if ($hasActiveItem) {
                    continue;
                }

                $item = AutoOptimizeItem::query()->create([
                    'auto_optimize_plan_id' => $plan->id,
                    'auto_optimize_run_id' => $run->id,
                    'platform_id' => $plan->platform_id,
                    'client_id' => $client->id,
                    'status' => 'queued',
                    'reason' => $reason,
                    // Capture the current score at selection so the "Before" ring
                    // shows immediately (the builder confirms it from canonical WP later).
                    'previous_score' => $client->seo_score !== null ? (int) $client->seo_score : null,
                    'previous_score_breakdown' => is_array($client->seo_score_breakdown) ? $client->seo_score_breakdown : null,
                ]);
                $itemIds[] = $item->id;
            }
        });

        if ($itemIds === []) {
            $run->forceFill([
                'status' => 'skipped',
                'candidates_scanned' => $selectedClients->count(),
                'candidates_selected' => 0,
                'jobs_total' => 0,
                'error_message' => 'Every selected profile already has an active optimizer item.',
            ])->save();
            $plan->forceFill(['last_run_at' => now()])->save();
            return;
        }

        // Build batch — autopilot chains the apply job after the build job.
        $batchJobs = [];
        foreach ($itemIds as $itemId) {
            if ($plan->autopilot) {
                // Chain: build → apply (both tracked in the batch).
                $batchJobs[] = [
                    new OptimizeProfileJob($itemId),
                    new ApplyAutoOptimizeItemJob($itemId, null), // actor = system
                ];
            } else {
                $batchJobs[] = new OptimizeProfileJob($itemId);
            }
        }

        $runId = $run->id;
        $batch = Bus::batch($batchJobs)
            ->onQueue('auto_optimize')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($runId) {
                $run = AutoOptimizeRun::find($runId);
                if (!$run) {
                    return;
                }
                $status = $batch->failedJobs > 0 ? 'completed_with_failures' : 'completed';
                $run->forceFill([
                    'status' => $status,
                    'jobs_completed' => $batch->totalJobs - $batch->failedJobs,
                    'jobs_failed' => $batch->failedJobs,
                ])->save();
            })
            ->dispatch();

        $run->forceFill([
            'candidates_scanned' => $selectedClients->count(),
            'candidates_selected' => count($itemIds),
            'jobs_total' => count($batchJobs),
            'batch_id' => $batch->id,
        ])->save();

        $plan->forceFill(['last_run_at' => now()])->save();
    }
}
