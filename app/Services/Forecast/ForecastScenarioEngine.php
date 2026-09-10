<?php

namespace App\Services\Forecast;

use App\Services\Forecast\Contracts\ForecastLever;
use App\Services\Forecast\Levers\AgentTargetsLever;
use App\Services\Forecast\Levers\ChurnWinBackLever;
use App\Services\Forecast\Levers\FailedRecoveryLever;
use App\Services\Forecast\Levers\NewActivationsLever;
use App\Services\Forecast\Levers\NewMarketLever;
use App\Services\Forecast\Levers\RenewalLever;
use App\Services\Forecast\Levers\SignupSourceConversionLever;

class ForecastScenarioEngine
{
    /** @var array<string, ForecastLever> */
    private array $levers;

    public function __construct()
    {
        $this->levers = collect([
            new FailedRecoveryLever,
            new NewActivationsLever,
            new SignupSourceConversionLever,
            new RenewalLever,
            new ChurnWinBackLever,
            new NewMarketLever,
            new AgentTargetsLever,
        ])->keyBy(fn (ForecastLever $lever) => $lever->key())->all();
    }

    public function compute(array $baseline, array $inputs = [], string $mode = 'project'): array
    {
        $baseTotal = $mode === 'replay'
            ? (float) data_get($baseline, 'baseline_revenue.normalized_total', 0)
            : (float) data_get($baseline, 'projection.horizon_run_rate_total', data_get($baseline, 'baseline_revenue.normalized_total', 0));

        // Levers are measured against the window's own population, so a rate change is
        // worth one window. Projecting stretches the baseline over the horizon, and the
        // lever contributions have to stretch with it - otherwise a 90-day projection
        // carries a 30-day upside and understates every lever threefold.
        $windowDays = max(1, (int) data_get($baseline, 'context.days', 30));
        $horizonScale = $mode === 'replay'
            ? 1.0
            : max(1, (int) data_get($baseline, 'projection.horizon_days', $windowDays)) / $windowDays;

        $bridgeRows = [[
            'key' => 'baseline',
            'label' => $mode === 'replay' ? 'Baseline collected revenue' : 'Baseline run-rate',
            'from' => $baseTotal,
            'to' => $baseTotal,
            'contribution' => $baseTotal,
            'is_baseline' => true,
        ]];
        $leverOutcomes = [];

        foreach ($this->levers as $key => $lever) {
            $leverBaseline = data_get($baseline, "levers.{$key}");
            if (! is_array($leverBaseline)) {
                continue;
            }

            if (! in_array($mode, $lever->supportedModes(), true) && ! array_key_exists($key, $inputs)) {
                continue;
            }

            $input = $inputs[$key] ?? ['target' => $leverBaseline['actual'] ?? 0];
            $outcome = $lever->apply($leverBaseline, $input, $mode);
            $outcome['contribution'] = round($outcome['contribution'] * $horizonScale, 2);
            $outcome['horizon_scale'] = round($horizonScale, 4);
            if ($outcome['contribution'] > 0 || array_key_exists($key, $inputs)) {
                $leverOutcomes[$key] = $outcome;
                $bridgeRows[] = $outcome + ['is_baseline' => false];
            }
        }

        $incremental = round((float) collect($leverOutcomes)->sum('contribution'), 2);
        $scenarioTotal = round($baseTotal + $incremental, 2);

        return [
            'mode' => $mode,
            'base_total' => round($baseTotal, 2),
            'horizon_scale' => round($horizonScale, 4),
            'window_days' => $windowDays,
            'incremental_total' => $incremental,
            'scenario_total' => $scenarioTotal,
            'bridge_rows' => $bridgeRows,
            'levers' => $leverOutcomes,
            'per_market' => $this->marketSplit($baseline, $scenarioTotal),
            'agent_decomposition' => $this->agentDecomposition($scenarioTotal),
            'normalization_meta' => $baseline['normalization_meta'] ?? null,
            'config_digest' => $baseline['config_digest'] ?? null,
        ];
    }

    public function lever(string $key): ?ForecastLever
    {
        return $this->levers[$key] ?? null;
    }

    public function leverKeys(): array
    {
        return array_keys($this->levers);
    }

    private function marketSplit(array $baseline, float $scenarioTotal): array
    {
        $markets = $baseline['per_market'] ?? [];
        if (! is_array($markets) || empty($markets)) {
            return [];
        }

        $count = count($markets);

        return collect($markets)
            ->map(fn (array $market) => [
                'platform_id' => $market['platform_id'] ?? null,
                'market_label' => $market['market_label'] ?? 'Market',
                'scenario_total' => round($scenarioTotal / max(1, $count), 2),
            ])
            ->all();
    }

    private function agentDecomposition(float $scenarioTotal): array
    {
        return [
            'basis' => 'trailing_revenue_share',
            'scenario_total' => round($scenarioTotal, 2),
            'rows' => [],
            'note' => 'Agent capacity is a cross-check and does not add revenue.',
        ];
    }
}
