<?php

namespace App\Services\Forecast\Levers;

class ChurnWinBackLever extends BaseLever
{
    public function key(): string
    {
        return 'churn_winback';
    }

    public function label(): string
    {
        return 'Churn win-back';
    }

    public function supportedModes(): array
    {
        return ['project', 'target'];
    }
}
