<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\AutoPushRun;
use App\Models\PushCampaignItem;
use App\Services\PushCampaign\PushCampaignService;
use App\Support\AutoPushSlotAllocator;
use App\Support\MarketTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AutoPushEngineService
{
    public function __construct(
        private readonly AutoPushSelectionService $selectionService,
        private readonly AutoPushCampaignBuilder $campaignBuilder,
        private readonly PushCampaignService $pushCampaignService,
        private readonly AutoPushAlertService $alertService,
    ) {
    }

    public function dueForRun(AutoPushPlan $plan, ?Carbon $nowMarketLocal = null): bool
    {
        $plan->loadMissing('platform');
        $timezone = MarketTimezone::resolve($plan->platform?->timezone, config('app.timezone', 'UTC'));
        $nowMarketLocal = ($nowMarketLocal?->copy() ?? now($timezone))->setTimezone($timezone);
        $schedule = is_array($plan->schedule) ? $plan->schedule : [];
        $activeDays = collect((array) ($schedule['active_days'] ?? []))->map(fn ($day) => (int) $day)->all();

        if (!in_array($nowMarketLocal->isoWeekday(), $activeDays, true)) {
            return false;
        }

        $windowEnd = Carbon::parse($nowMarketLocal->toDateString() . ' ' . (string) ($schedule['window_end'] ?? '23:00'), $timezone);
        if ($nowMarketLocal->greaterThan($windowEnd)) {
            return false;
        }

        $coverage = $this->coverageCount($plan);
        $threshold = (int) ($schedule['runway_threshold'] ?? 0);
        if ($threshold <= 0) {
            $threshold = AutoPushSlotAllocator::slotCountForLookahead($plan, $nowMarketLocal->copy()->startOfDay());
        }

        return $coverage < max(1, $threshold);
    }

    public function coverageCount(AutoPushPlan $plan): int
    {
        $coveredStatuses = ['scheduled', 'running', 'partial'];
        if ((bool) data_get($plan->schedule, 'count_unapproved_drafts_as_coverage', true)) {
            $coveredStatuses[] = 'draft';
        }

        return PushCampaignItem::query()
            ->whereHas('campaign', function ($query) use ($plan, $coveredStatuses) {
                $query->where('auto_push_plan_id', (int) $plan->id)
                    ->whereIn('status', $coveredStatuses);
            })
            ->whereIn('status', ['pending', 'scheduled'])
            ->where('scheduled_at', '>', now())
            ->count();
    }

    public function runPlan(AutoPushPlan $plan): AutoPushRun
    {
        $plan->loadMissing('platform');
        $run = AutoPushRun::query()->create([
            'auto_push_plan_id' => (int) $plan->id,
            'platform_id' => (int) $plan->platform_id,
            'status' => 'running',
        ]);

        try {
            $selection = $this->selectionService->selectForPlan($plan);
            $run->forceFill([
                'bucket_counts' => $selection['bucket_counts'],
                'candidates_selected' => $selection['primary']->count(),
                'reserve_count' => $selection['reserve']->count(),
            ])->save();

            $this->campaignBuilder->persistReserve($run, $selection['reserve']);

            if ($selection['primary']->isEmpty()) {
                $run->forceFill([
                    'status' => 'skipped',
                ])->save();

                $this->alertService->raise(
                    'no_candidates',
                    'warning',
                    'No auto-push candidates found',
                    'The engine ran but no eligible profiles matched the plan filters.',
                    ['run_id' => (int) $run->id],
                    $plan,
                );

                $plan->forceFill(['last_run_at' => now()])->save();

                return $run->fresh();
            }

            $campaign = $this->campaignBuilder->build($plan, $run, $selection);
            $firstSlot = $campaign->items()->orderBy('scheduled_at')->value('scheduled_at');
            if ($firstSlot) {
                $firstSlot = Carbon::parse((string) $firstSlot)->utc();
            }

            if ($plan->autopilot && $firstSlot instanceof Carbon) {
                $this->pushCampaignService->scheduleCampaign($campaign, $firstSlot, 0);
            } else {
                $this->alertService->raise(
                    'awaiting_approval',
                    'info',
                    'Auto-push campaign awaiting approval',
                    'Autopilot is disabled for this market, so the generated campaign is waiting in draft.',
                    ['run_id' => (int) $run->id],
                    $plan,
                    $campaign,
                    dedupeOpenCampaign: true,
                );
            }

            $run->forceFill([
                'campaign_id' => (int) $campaign->id,
                'status' => 'completed',
            ])->save();
            $plan->forceFill(['last_run_at' => now()])->save();

            return $run->fresh();
        } catch (\Throwable $exception) {
            Log::warning('auto_push.run_failed', [
                'plan_id' => (int) $plan->id,
                'run_id' => (int) $run->id,
                'error' => $exception->getMessage(),
            ]);

            $run->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            $this->alertService->raise(
                'run_failed',
                'critical',
                'Auto-push run failed',
                $exception->getMessage(),
                ['run_id' => (int) $run->id],
                $plan,
            );
            $plan->forceFill(['last_run_at' => now()])->save();

            return $run->fresh();
        }
    }
}
