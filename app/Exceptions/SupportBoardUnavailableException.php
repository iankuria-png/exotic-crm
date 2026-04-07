<?php

namespace App\Exceptions;

use RuntimeException;

class SupportBoardUnavailableException extends RuntimeException
{
    public static function forCachedFailure(string $message): self
    {
        return new self($message !== '' ? $message : 'Support Board is temporarily unavailable.');
    }

    public static function forHttpFailure(string $function, int $status): self
    {
        return new self(sprintf(
            'Support Board request "%s" returned HTTP %d.',
            $function,
            $status
        ));
    }
}
