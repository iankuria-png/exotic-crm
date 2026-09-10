<?php

namespace App\Services\Forecast\Levers;

class AgentTargetsLever extends BaseLever
{
    public function key(): string
    {
        return 'agent_targets';
    }

    public function label(): string
    {
        return 'Agent capacity';
    }

    public function apply(array $baseline, mixed $input, string $mode): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'from' => 0,
            'to' => 0,
            'delta_units' => 0,
            'contribution' => 0,
            'eligible_units' => 0,
            'unit_value' => 0,
            'evidence' => $baseline['evidence'] ?? [],
        ];
    }
}
