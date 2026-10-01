<?php

namespace App\Services\DbScanner\Engine;

use RuntimeException;

class MarketBusyException extends RuntimeException
{
    /**
     * @param  array<int, int>  $platformIds
     */
    public function __construct(public readonly array $platformIds)
    {
        parent::__construct('A selected market already has an active scan.');
    }
}
