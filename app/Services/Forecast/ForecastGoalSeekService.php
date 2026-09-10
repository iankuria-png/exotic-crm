<?php

namespace App\Services\Forecast;

class ForecastGoalSeekService
{
    public function __construct(
        private readonly ForecastScenarioEngine $engine
    ) {}

    public function solve(array $baseline, float $monthlyTarget, int $reachByMonths, array $excludeLevers = []): array
    {
        $current = (float) data_get($baseline, 'projection.monthly_run_rate', 0);
        if ($monthlyTarget <= $current) {
            abort(422, 'Monthly target must be above the current run-rate.');
        }

        $gap = round($monthlyTarget - $current, 2);
        $routes = [];

        foreach (['conservative', 'balanced', 'stretch', 'downside'] as $band) {
            $routes[] = $this->route($baseline, $band, $current, $gap, $reachByMonths, $excludeLevers);
        }

        return [
            'current_monthly_run_rate' => round($current, 2),
            'target_monthly' => round($monthlyTarget, 2),
            'gap_monthly' => $gap,
            'reach_by_months' => $reachByMonths,
            'config_digest' => $baseline['config_digest'] ?? null,
            'routes' => $routes,
            'normalization_meta' => $baseline['normalization_meta'] ?? null,
        ];
    }

    private function route(array $baseline, string $band, float $current, float $gap, int $months, array $excludeLevers): array
    {
        if ($band === 'downside') {
            return [
                'band' => $band,
                'reached_monthly' => round($current * 0.96, 2),
                'shortfall' => round($gap + ($current * 0.04), 2),
                'monthly_path' => $this->monthlyPath($current, $current * 0.96, $months),
                'moves' => [],
                'unprecedented' => [],
                'verdict' => 'Downside projects from a weaker comparable run-rate.',
                'lever_inputs' => [],
            ];
        }

        $candidates = $this->candidates($baseline, $band, $excludeLevers);
        $remaining = $gap;
        $moves = [];
        $leverInputs = [];
        $byLever = [];
        $concentrationCap = $gap * (float) config('forecast.concentration_cap');

        foreach ($candidates as $candidate) {
            if ($remaining <= 0.004) {
                break;
            }

            $already = $byLever[$candidate['lever']] ?? 0.0;
            $leverRoom = max(0, $concentrationCap - $already);
            $take = min($candidate['available_monthly'], $remaining, $leverRoom);
            if ($take <= 0) {
                continue;
            }

            $fraction = $candidate['available_monthly'] > 0 ? $take / $candidate['available_monthly'] : 0;
            $to = $candidate['from'] + (($candidate['ceiling'] - $candidate['from']) * $fraction);
            $moves[] = [
                'lever' => $candidate['lever'],
                'label' => $candidate['label'],
                'platform_id' => $candidate['platform_id'],
                'market_label' => $candidate['market_label'],
                'from' => round($candidate['from'], 2),
                'to' => round($to, 2),
                'contribution' => round($take, 2),
                'eligible_units' => $candidate['eligible_units'],
                'ceiling_basis' => $candidate['basis'],
            ];
            $leverInputs[$candidate['lever']] = ['target' => round(max($leverInputs[$candidate['lever']]['target'] ?? 0, $to), 2)];
            $byLever[$candidate['lever']] = $already + $take;
            $remaining -= $take;
        }

        $reached = round($current + ($gap - max(0, $remaining)), 2);

        return [
            'band' => $band,
            'reached_monthly' => $reached,
            'shortfall' => round(max(0, $remaining), 2),
            'monthly_path' => $this->monthlyPath($current, $reached, $months),
            'moves' => $moves,
            'unprecedented' => $band === 'stretch'
                ? collect($moves)->filter(fn (array $move) => str_starts_with((string) ($move['ceiling_basis'] ?? ''), 'stretch'))->values()->all()
                : [],
            'verdict' => $remaining > 0 ? 'Falls short within this band.' : 'Reaches target within this band.',
            'lever_inputs' => $leverInputs,
        ];
    }

    private function candidates(array $baseline, string $band, array $excludeLevers): array
    {
        $markets = $baseline['per_market'] ?? [];
        if (! is_array($markets) || empty($markets)) {
            $markets = [[
                'platform_id' => null,
                'market_label' => 'All markets',
                'levers' => $baseline['levers'] ?? [],
            ]];
        }

        $candidates = [];
        foreach ($markets as $market) {
            foreach (($market['levers'] ?? []) as $key => $lever) {
                if (in_array($key, $excludeLevers, true) || $key === 'agent_targets') {
                    continue;
                }

                $from = (float) ($lever['actual'] ?? 0);
                $ceiling = $this->ceiling($key, $lever, $band);
                if ($ceiling <= $from) {
                    continue;
                }

                $yield = $this->engine->lever($key)?->yieldPerUnit($lever) ?? 0;
                $effort = (float) data_get(config('forecast.effort_weights'), $key, 1.0);
                $available = $this->monthlyContribution($lever, $from, $ceiling, (int) data_get($baseline, 'context.days', 30));
                if ($available <= 0) {
                    continue;
                }

                $candidates[] = [
                    'lever' => $key,
                    'label' => $lever['label'] ?? $key,
                    'platform_id' => $market['platform_id'] ?? null,
                    'market_label' => $market['market_label'] ?? 'All markets',
                    'from' => $from,
                    'ceiling' => $ceiling,
                    'eligible_units' => (int) ($lever['eligible_units'] ?? 0),
                    'available_monthly' => round($available, 2),
                    'score' => $effort > 0 ? $yield / $effort : $yield,
                    'yield' => $yield,
                    'basis' => $this->basis($band, $market['market_label'] ?? 'All markets'),
                ];
            }
        }

        usort($candidates, fn (array $left, array $right) => [
            -$left['score'],
            -$left['yield'],
            -$left['eligible_units'],
            $left['platform_id'] ?? 0,
            $left['lever'],
        ] <=> [
            -$right['score'],
            -$right['yield'],
            -$right['eligible_units'],
            $right['platform_id'] ?? 0,
            $right['lever'],
        ]);

        return $candidates;
    }

    private function ceiling(string $key, array $lever, string $band): float
    {
        $actual = (float) ($lever['actual'] ?? 0);
        $suggested = (float) ($lever['suggested'] ?? $actual);

        if ($band === 'conservative') {
            return max($actual, $suggested);
        }

        if ($band === 'balanced') {
            return max($actual, $suggested + (($lever['unit'] ?? '') === 'count' ? 5 : 2));
        }

        if (($lever['unit'] ?? '') === 'count') {
            return max($actual, $suggested * (float) data_get(config('forecast.count_growth_caps'), $key, 1.1));
        }

        $bandConfig = data_get(config('forecast.stretch_bands'), $key, ['points' => 5, 'max' => 85]);

        return min((float) $bandConfig['max'], max($suggested, $actual) + (float) $bandConfig['points']);
    }

    private function monthlyContribution(array $lever, float $from, float $to, int $windowDays): float
    {
        $eligible = (int) ($lever['eligible_units'] ?? 0);
        $unitValue = (float) ($lever['unit_value'] ?? 0);
        $units = ($lever['unit'] ?? '') === 'count'
            ? max(0, $to - $from)
            : max(0, $to - $from) / 100 * $eligible;

        return $windowDays > 0 ? ($units * $unitValue / $windowDays) * 30.4375 : 0.0;
    }

    private function monthlyPath(float $from, float $to, int $months): array
    {
        return collect(range(1, max(1, $months)))
            ->map(fn (int $month) => [
                'month' => $month,
                'monthly_run_rate' => round($from + (($to - $from) * ($month / max(1, $months))), 2),
            ])
            ->all();
    }

    private function basis(string $band, string $market): string
    {
        return match ($band) {
            'conservative' => "{$market}, best trailing window",
            'balanced' => 'sustained median from markets in scope',
            'stretch' => 'stretch band, no precedent',
            default => 'weakest comparable trailing window',
        };
    }
}
