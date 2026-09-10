<?php

namespace App\Services\Forecast\Dto;

class LeverBaseline
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly float $actual,
        public readonly float $suggested,
        public readonly int $eligibleUnits,
        public readonly float $unitValue,
        public readonly string $unit,
        public readonly array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'actual' => $this->actual,
            'suggested' => $this->suggested,
            'eligible_units' => $this->eligibleUnits,
            'unit_value' => $this->unitValue,
            'unit' => $this->unit,
            'evidence' => $this->evidence,
        ];
    }
}
