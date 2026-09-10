<?php

namespace App\Services\Forecast\Levers;

class RenewalLever extends BaseLever
{
    public function key(): string
    {
        return 'renewal';
    }

    public function label(): string
    {
        return 'Renewal rate';
    }
}
