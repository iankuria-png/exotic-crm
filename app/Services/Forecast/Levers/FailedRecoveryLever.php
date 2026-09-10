<?php

namespace App\Services\Forecast\Levers;

class FailedRecoveryLever extends BaseLever
{
    public function key(): string
    {
        return 'failed_recovery';
    }

    public function label(): string
    {
        return 'Failed payment recovery';
    }
}
