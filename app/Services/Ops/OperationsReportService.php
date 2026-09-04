<?php

namespace App\Services\Ops;

use App\Models\SystemIncident;
use Illuminate\Support\Carbon;

/**
 * Turns the incident rows into the two numbers that actually settle whether
 * the thresholds are calibrated: how long the platform spent at each level, and
 * what that would have cost had enforcement been on.
 *
 * Observe-only mode exists to produce exactly this evidence. Without it the
 * board can only say "these ten capabilities would be paused", which is a list,
 * not a measurement — and a list gives nobody the confidence to turn
 * enforcement on.
 */
class OperationsReportService
{
    public function __construct(
        private readonly OperationsSettingsService $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $hours = 24): array
    {
        $hours = max(1, min(720, $hours));
        $until = now();
        $since = $until->copy()->subHours($hours);

        $incidents = $this->incidentsOverlapping($since, $until);
        $windowSeconds = max(1, $since->diffInSeconds($until));

        // "At least level L" is the honest primitive: escalation can leave a
        // Cautious row and a Limp row open at the same time, so summing rows
        // per level would double-count. Time spent exactly at L is then the
        // difference between the two coverages.
        $atLeast = [];
        foreach ([LoadShedder::LEVEL_CAUTIOUS, LoadShedder::LEVEL_LIMP, LoadShedder::LEVEL_CRITICAL] as $level) {
            $atLeast[$level] = $this->unionSeconds(
                $incidents->filter(fn (SystemIncident $incident): bool => $incident->to_level >= $level),
                $since,
                $until
            );
        }

        $exact = [
            LoadShedder::LEVEL_CRITICAL => $atLeast[LoadShedder::LEVEL_CRITICAL],
            LoadShedder::LEVEL_LIMP => max(0, $atLeast[LoadShedder::LEVEL_LIMP] - $atLeast[LoadShedder::LEVEL_CRITICAL]),
            LoadShedder::LEVEL_CAUTIOUS => max(0, $atLeast[LoadShedder::LEVEL_CAUTIOUS] - $atLeast[LoadShedder::LEVEL_LIMP]),
        ];
        $exact[LoadShedder::LEVEL_NORMAL] = max(0, $windowSeconds - $atLeast[LoadShedder::LEVEL_CAUTIOUS]);

        $levels = [];
        foreach ([LoadShedder::LEVEL_NORMAL, LoadShedder::LEVEL_CAUTIOUS, LoadShedder::LEVEL_LIMP, LoadShedder::LEVEL_CRITICAL] as $level) {
            $levels[] = [
                'level' => $level,
                'label' => LoadShedder::label($level),
                'seconds' => $exact[$level],
                'share' => round($exact[$level] / $windowSeconds * 100, 1),
            ];
        }

        $enforcementEnabled = $this->settings->boolean('ops.enforcement.enabled');
        $capabilities = [];

        foreach (LoadShedder::capabilities() as $capability => $shedAt) {
            $matching = $incidents->filter(fn (SystemIncident $incident): bool => $incident->to_level >= $shedAt);

            $capabilities[] = [
                'capability' => $capability,
                'sheds_at' => $shedAt,
                'sheds_at_label' => LoadShedder::label($shedAt),
                'seconds' => $atLeast[$shedAt] ?? 0,
                'share' => round(($atLeast[$shedAt] ?? 0) / $windowSeconds * 100, 1),
                'episodes' => $this->episodeCount($matching, $since, $until),
                'enforced' => $enforcementEnabled,
            ];
        }

        usort($capabilities, fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return [
            'window_hours' => $hours,
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'window_seconds' => $windowSeconds,
            'enforcement_enabled' => $enforcementEnabled,
            'levels' => $levels,
            'capabilities' => $capabilities,
            'transitions' => $incidents->count(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, SystemIncident>
     */
    private function incidentsOverlapping(Carbon $since, Carbon $until)
    {
        return SystemIncident::query()
            ->where('started_at', '<=', $until)
            ->where(function ($query) use ($since) {
                $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $since);
            })
            ->orderBy('started_at')
            ->limit(1000)
            ->get(['id', 'to_level', 'started_at', 'resolved_at']);
    }

    /**
     * Total wall-clock covered by a set of possibly-overlapping incidents,
     * clipped to the window.
     *
     * @param  \Illuminate\Support\Collection<int, SystemIncident>  $incidents
     */
    private function unionSeconds($incidents, Carbon $since, Carbon $until): int
    {
        $total = 0;
        $cursor = null;

        foreach ($this->clippedIntervals($incidents, $since, $until) as [$start, $end]) {
            if ($cursor === null || $start > $cursor) {
                $total += $end - $start;
                $cursor = $end;
                continue;
            }

            if ($end > $cursor) {
                $total += $end - $cursor;
                $cursor = $end;
            }
        }

        return $total;
    }

    /**
     * Contiguous stretches, which is what a person means by "how many times".
     *
     * @param  \Illuminate\Support\Collection<int, SystemIncident>  $incidents
     */
    private function episodeCount($incidents, Carbon $since, Carbon $until): int
    {
        $episodes = 0;
        $cursor = null;

        foreach ($this->clippedIntervals($incidents, $since, $until) as [$start, $end]) {
            if ($cursor === null || $start > $cursor) {
                $episodes++;
                $cursor = $end;
                continue;
            }

            $cursor = max($cursor, $end);
        }

        return $episodes;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SystemIncident>  $incidents
     * @return array<int, array{0:int, 1:int}>
     */
    private function clippedIntervals($incidents, Carbon $since, Carbon $until): array
    {
        $intervals = [];

        foreach ($incidents as $incident) {
            $start = max($since->getTimestamp(), $incident->started_at?->getTimestamp() ?? $since->getTimestamp());
            // An unresolved incident runs to "now", not forever.
            $end = min($until->getTimestamp(), $incident->resolved_at?->getTimestamp() ?? $until->getTimestamp());

            if ($end > $start) {
                $intervals[] = [$start, $end];
            }
        }

        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $intervals;
    }
}
