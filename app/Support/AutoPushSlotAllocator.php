<?php

namespace App\Support;

use App\Models\AutoPushPlan;
use App\Models\PushCampaign;
use App\Services\PushCampaign\PushCampaignDispatchReadinessService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class AutoPushSlotAllocator
{
    /**
     * @return \Illuminate\Support\Collection<int, \Carbon\Carbon>
     */
    public static function slotGrid(AutoPushPlan $plan, Carbon $fromMarketDate, int $days): Collection
    {
        $schedule = self::schedule($plan);
        $timezone = self::timezone($plan);
        $days = max(1, $days);
        $activeDays = collect((array) ($schedule['active_days'] ?? []))
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->values()
            ->all();
        $intervalHours = max(1, (int) ($schedule['interval_hours'] ?? 1));
        $maxItemsPerDay = max(1, (int) ($schedule['max_items_per_day'] ?? 1));
        $windowStart = (string) ($schedule['window_start'] ?? '10:00');
        $windowEnd = (string) ($schedule['window_end'] ?? '23:00');

        $slots = collect();
        for ($offset = 0; $offset < $days; $offset++) {
            $marketDate = $fromMarketDate->copy()->addDays($offset)->startOfDay();
            if (!in_array($marketDate->isoWeekday(), $activeDays, true)) {
                continue;
            }

            $start = Carbon::parse($marketDate->toDateString() . ' ' . $windowStart, $timezone);
            $end = Carbon::parse($marketDate->toDateString() . ' ' . $windowEnd, $timezone);
            if ($end->lessThan($start)) {
                continue;
            }

            $count = 0;
            for ($cursor = $start->copy(); $cursor->lte($end) && $count < $maxItemsPerDay; $cursor->addHours($intervalHours)) {
                $slots->push($cursor->copy()->utc());
                $count++;
            }
        }

        return $slots->sortBy(fn (Carbon $slot) => $slot->getTimestamp())->values();
    }

    public static function slotCountForLookahead(AutoPushPlan $plan, Carbon $fromMarketDate): int
    {
        $days = max(1, (int) data_get(self::schedule($plan), 'lookahead_days', 1));

        return self::slotGrid($plan, $fromMarketDate->copy()->startOfDay(), $days)->count();
    }

    /**
     * @param  array<int, string|\Carbon\Carbon>  $occupiedSlots
     */
    public static function nextFreeSlot(AutoPushPlan $plan, PushCampaign $campaign, ?Carbon $afterUtc, array $occupiedSlots = []): ?Carbon
    {
        $timezone = self::timezone($plan);
        $schedule = self::schedule($plan);
        $spillover = (string) data_get(self::reliability($plan), 'replacement_spillover', 'next_active_day');
        $leadCutoff = now()->utc()->addMinutes(PushCampaignDispatchReadinessService::LATE_GRACE_MINUTES);
        $after = $afterUtc?->copy()->utc() ?? now()->utc();
        $cutoff = $after->greaterThan($leadCutoff) ? $after : $leadCutoff;
        $occupied = collect($occupiedSlots)
            ->map(function ($value): string {
                return ($value instanceof Carbon ? $value->copy() : Carbon::parse((string) $value))
                    ->utc()
                    ->toDateTimeString();
            })
            ->flip();

        $fromMarketDate = $cutoff->copy()->setTimezone($timezone)->startOfDay();
        $targetSlots = max(1, self::slotCountForLookahead($plan, $fromMarketDate));

        if ($spillover === 'same_day') {
            return self::slotGrid($plan, $fromMarketDate, 1)
                ->first(function (Carbon $slot) use ($cutoff, $occupied): bool {
                    return $slot->greaterThan($cutoff) && !$occupied->has($slot->toDateTimeString());
                });
        }

        $collected = 0;
        $offset = 0;
        while ($collected < $targetSlots) {
            $daySlots = self::slotGrid($plan, $fromMarketDate->copy()->addDays($offset), 1);
            $collected += $daySlots->count();

            $match = $daySlots->first(function (Carbon $slot) use ($cutoff, $occupied): bool {
                return $slot->greaterThan($cutoff) && !$occupied->has($slot->toDateTimeString());
            });
            if ($match) {
                return $match;
            }

            $offset++;
            if ($offset > 31) {
                break;
            }
        }

        return null;
    }

    private static function schedule(AutoPushPlan $plan): array
    {
        return is_array($plan->schedule) ? $plan->schedule : [];
    }

    private static function reliability(AutoPushPlan $plan): array
    {
        return is_array($plan->reliability) ? $plan->reliability : [];
    }

    private static function timezone(AutoPushPlan $plan): string
    {
        return MarketTimezone::resolve($plan->platform?->timezone, config('app.timezone', 'UTC'));
    }
}
