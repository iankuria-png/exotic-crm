<?php

namespace App\Services\Collections;

use RuntimeException;

class CollectionException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 409)
    {
        parent::__construct(str_replace('_', ' ', $reason));
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->httpStatus);
    }
}
