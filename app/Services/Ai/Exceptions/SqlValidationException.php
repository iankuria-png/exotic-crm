<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * Thrown by SqlSafetyValidator when a candidate NL->SQL statement violates the
 * read-only safety contract. The message is safe to surface to the operator;
 * the $reason slug is for logging/telemetry.
 */
class SqlValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
    ) {
        parent::__construct($message);
    }
}
