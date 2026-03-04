<?php

namespace App\Services\PushCampaign;

use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use Carbon\Carbon;

class PushCampaignDispatchReadinessService
{
    public const DISPATCH_WINDOW_HOURS = 24;

    public const LATE_GRACE_MINUTES = 5;

    private const OVERDUE_SAMPLE_LIMIT = 5;

    /**
     * @return array<string, mixed>
     */
    public function analyzeActivation(
        PushCampaign $pushCampaign,
        ?Carbon $activationAtUtc = null,
        ?string $activationTimezone = null
    ): array {
        $pushCampaign->loadMissing('platform:id,timezone');

        $activationAt = ($activationAtUtc?->copy() ?? now())->utc();
        $timezone = trim((string) ($activationTimezone ?: ($pushCampaign->platform?->timezone ?: config('app.timezone', 'UTC'))));
        $dispatchUntil = $activationAt->copy()->addHours(self::DISPATCH_WINDOW_HOURS);
        $graceThreshold = $activationAt->copy()->subMinutes(self::LATE_GRACE_MINUTES);

        $pendingItems = PushCampaignItem::query()
            ->where('campaign_id', (int) $pushCampaign->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get(['id', 'profile_name', 'profile_url', 'scheduled_at', 'status']);

        $counts = [
            'overdue' => 0,
            'send_immediately' => 0,
            'queue_with_delay' => 0,
            'outside_dispatch_window' => 0,
            'total_pending' => (int) $pendingItems->count(),
        ];

        $firstOverdueAt = null;
        $sampleOverdueItems = [];

        foreach ($pendingItems as $item) {
            $scheduledAt = $item->scheduled_at?->copy()?->utc();

            if ($scheduledAt === null) {
                $counts['send_immediately']++;
                continue;
            }

            if ($scheduledAt->lessThan($graceThreshold)) {
                $counts['overdue']++;
                if ($firstOverdueAt === null || $scheduledAt->lessThan($firstOverdueAt)) {
                    $firstOverdueAt = $scheduledAt->copy();
                }

                if (count($sampleOverdueItems) < self::OVERDUE_SAMPLE_LIMIT) {
                    $sampleOverdueItems[] = [
                        'id' => (int) $item->id,
                        'profile_name' => $item->profile_name ?: 'Unknown',
                        'profile_url' => $item->profile_url,
                        'scheduled_at' => $scheduledAt->toIso8601String(),
                        'scheduled_at_local' => $scheduledAt->copy()->setTimezone($timezone)->toIso8601String(),
                    ];
                }
                continue;
            }

            if ($scheduledAt->lessThanOrEqualTo($activationAt)) {
                $counts['send_immediately']++;
                continue;
            }

            if ($scheduledAt->lessThanOrEqualTo($dispatchUntil)) {
                $counts['queue_with_delay']++;
                continue;
            }

            $counts['outside_dispatch_window']++;
        }

        $canActivate = (int) $counts['overdue'] === 0;
        $message = $canActivate
            ? sprintf(
                'Campaign can activate. %d item(s) send immediately, %d item(s) queue with delay.',
                (int) $counts['send_immediately'],
                (int) $counts['queue_with_delay'],
            )
            : sprintf(
                '%d overdue item(s) must be rescheduled before activation.',
                (int) $counts['overdue']
            );

        return [
            'can_activate' => $canActivate,
            'activation_at_utc' => $activationAt->toIso8601String(),
            'activation_timezone' => $timezone,
            'policy' => [
                'dispatch_window_hours' => self::DISPATCH_WINDOW_HOURS,
                'late_grace_minutes' => self::LATE_GRACE_MINUTES,
                'activation_semantics' => 'campaign_activation_gate_only',
                'overdue_policy' => 'block_and_require_fix',
            ],
            'counts' => $counts,
            'first_overdue_at' => $firstOverdueAt?->toIso8601String(),
            'sample_overdue_items' => $sampleOverdueItems,
            'message' => $message,
        ];
    }

    /**
     * @return array{timing_state:string,is_overdue:bool,timing_reference_timezone:string}
     */
    public function describeItemTimingState(
        PushCampaignItem $item,
        ?Carbon $referenceAtUtc = null,
        ?string $referenceTimezone = null
    ): array {
        $timezone = trim((string) ($referenceTimezone ?: config('app.timezone', 'UTC')));
        $referenceAt = ($referenceAtUtc?->copy() ?? now())->utc();

        if ((string) $item->status !== 'pending') {
            return [
                'timing_state' => 'unscheduled',
                'is_overdue' => false,
                'timing_reference_timezone' => $timezone,
            ];
        }

        $scheduledAt = $item->scheduled_at?->copy()?->utc();
        if ($scheduledAt === null) {
            return [
                'timing_state' => 'unscheduled',
                'is_overdue' => false,
                'timing_reference_timezone' => $timezone,
            ];
        }

        $graceThreshold = $referenceAt->copy()->subMinutes(self::LATE_GRACE_MINUTES);
        $dispatchUntil = $referenceAt->copy()->addHours(self::DISPATCH_WINDOW_HOURS);

        if ($scheduledAt->lessThan($graceThreshold)) {
            return [
                'timing_state' => 'overdue',
                'is_overdue' => true,
                'timing_reference_timezone' => $timezone,
            ];
        }

        if ($scheduledAt->lessThanOrEqualTo($referenceAt)) {
            return [
                'timing_state' => 'send_now',
                'is_overdue' => false,
                'timing_reference_timezone' => $timezone,
            ];
        }

        if ($scheduledAt->lessThanOrEqualTo($dispatchUntil)) {
            return [
                'timing_state' => 'future_delayed',
                'is_overdue' => false,
                'timing_reference_timezone' => $timezone,
            ];
        }

        return [
            'timing_state' => 'outside_window',
            'is_overdue' => false,
            'timing_reference_timezone' => $timezone,
        ];
    }
}
