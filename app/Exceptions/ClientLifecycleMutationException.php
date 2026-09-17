<?php

namespace App\Exceptions;

use RuntimeException;

class ClientLifecycleMutationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
    ) {
        parent::__construct($message);
    }

    public static function busy(): self
    {
        return new self(
            'This profile is already being changed. Wait a moment and try again.',
            'lifecycle_busy',
        );
    }

    public static function paidEntitlement(): self
    {
        return new self(
            'This profile has a current or future paid entitlement and cannot be taken offline, archived, or deleted.',
            'paid_entitlement',
        );
    }
}
