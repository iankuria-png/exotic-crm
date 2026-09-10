<?php

namespace App\Services\Forecast\Levers;

class NewMarketLever extends BaseLever
{
    public function key(): string
    {
        return 'new_market';
    }

    public function label(): string
    {
        return 'New market';
    }

    public function supportedModes(): array
    {
        return ['project', 'target'];
    }
}
