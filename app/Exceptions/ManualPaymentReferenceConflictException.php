<?php

namespace App\Exceptions;

use RuntimeException;

class ManualPaymentReferenceConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $conflict
     */
    public function __construct(private readonly array $conflict, string $message = 'This reference root is already in use.')
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function conflict(): array
    {
        return $this->conflict;
    }
}
