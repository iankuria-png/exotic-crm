<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The market's WordPress could not be reached, or is gated as unhealthy.
 *
 * Distinct from a programming error so callers can tell "try again later" from
 * "this will never work". Deal activation uses it to keep a sale alive: the
 * payment is recorded and the activation is deferred rather than the whole
 * transaction being rolled back on the advertiser who just paid.
 */
class MarketUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly int $platformId,
        string $message,
        public readonly ?string $healthStatus = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function gated(int $platformId, ?string $healthStatus): self
    {
        return new self(
            $platformId,
            sprintf(
                'WordPress market %d is temporarily gated after repeated %s health failures.',
                $platformId,
                (string) ($healthStatus ?: 'unknown')
            ),
            $healthStatus
        );
    }
}
