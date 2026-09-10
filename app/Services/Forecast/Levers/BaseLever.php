<?php

namespace App\Services\Forecast\Levers;

use App\Services\Forecast\Contracts\ForecastLever;

abstract class BaseLever implements ForecastLever
{
    public function supportedModes(): array
    {
        return ['replay', 'project', 'target'];
    }

    public function apply(array $baseline, mixed $input, string $mode): array
    {
        if (! in_array($mode, $this->supportedModes(), true)) {
            abort(422, "{$this->key()} is not supported in {$mode} mode.");
        }

        $target = $this->targetValue($baseline, $input);
        $actual = (float) ($baseline['actual'] ?? 0);
        $eligible = (int) ($baseline['eligible_units'] ?? 0);
        $unitValue = (float) ($baseline['unit_value'] ?? 0);
        $deltaUnits = $this->deltaUnits($actual, $target, $eligible, (string) ($baseline['unit'] ?? 'percentage_points'));
        $contribution = round(max(0, $deltaUnits) * $unitValue, 2);

        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'from' => round($actual, 2),
            'to' => round($target, 2),
            'delta_units' => round($deltaUnits, 4),
            'contribution' => $contribution,
            'eligible_units' => $eligible,
            'unit_value' => round($unitValue, 2),
            'evidence' => $baseline['evidence'] ?? [],
        ];
    }

    public function yieldPerUnit(array $baseline): float
    {
        $unitValue = (float) ($baseline['unit_value'] ?? 0);

        return $this->isRateUnit((string) ($baseline['unit'] ?? 'percentage_points'))
            ? $unitValue * max(1, (int) ($baseline['eligible_units'] ?? 0)) / 100
            : $unitValue;
    }

    protected function targetValue(array $baseline, mixed $input): float
    {
        if (is_array($input) && array_key_exists('target', $input)) {
            return (float) $input['target'];
        }

        if (is_numeric($input)) {
            return (float) $input;
        }

        return (float) ($baseline['actual'] ?? 0);
    }

    protected function deltaUnits(float $actual, float $target, int $eligible, string $unit): float
    {
        if ($this->isRateUnit($unit)) {
            return max(0, $target - $actual) / 100 * $eligible;
        }

        return max(0, $target - $actual);
    }

    /**
     * Read from the baseline payload, never declared on the class.
     *
     * A class flag saying "this is a count" outlived the lever being re-based as a
     * rate, so an 11.9 point conversion change was priced as 11.9 extra customers
     * instead of 11.9% of 1,997 - a lever worth 2,600 reported 127.
     */
    protected function isRateUnit(string $unit): bool
    {
        return $unit === 'percentage_points';
    }
}
