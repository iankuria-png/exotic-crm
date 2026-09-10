<?php

namespace App\Services\Forecast\Dto;

class LeverOutcome
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly float $from,
        public readonly float $to,
        public readonly float $deltaUnits,
        public readonly float $contribution,
        public readonly array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'from' => $this->from,
            'to' => $this->to,
            'delta_units' => $this->deltaUnits,
            'contribution' => $this->contribution,
            'evidence' => $this->evidence,
        ];
    }
}
