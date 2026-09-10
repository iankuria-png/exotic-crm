<?php

namespace App\Services\Forecast\Levers;

class NewActivationsLever extends BaseLever
{
    public function key(): string
    {
        return 'new_activations';
    }

    public function label(): string
    {
        return 'New paid activations';
    }

    protected function isRateLever(): bool
    {
        return false;
    }
}
