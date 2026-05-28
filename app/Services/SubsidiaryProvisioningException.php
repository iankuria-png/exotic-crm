<?php

namespace App\Services;

use RuntimeException;

class SubsidiaryProvisioningException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'provisioning_failed'
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
