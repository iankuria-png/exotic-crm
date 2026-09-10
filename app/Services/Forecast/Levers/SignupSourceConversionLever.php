<?php

namespace App\Services\Forecast\Levers;

class SignupSourceConversionLever extends BaseLever
{
    public function key(): string
    {
        return 'signup_source_conversion';
    }

    public function label(): string
    {
        return 'Signup-source conversion';
    }
}
