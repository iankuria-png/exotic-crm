<?php

namespace App\Services\DbContainment;

class ContainmentException extends \RuntimeException
{
    public function __construct(public readonly string $reason, int $httpStatus = 409)
    {
        parent::__construct($reason, $httpStatus);
    }
}
