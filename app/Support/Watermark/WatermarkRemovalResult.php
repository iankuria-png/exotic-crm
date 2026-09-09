<?php

namespace App\Support\Watermark;

/**
 * What a removal attempt did, and why.
 *
 * "Declined" has several quite different causes — an unreadable file, a stamp
 * that barely lands, pixels that do not look blended with this logo — and they
 * need different fixes. Returning them separately means an operator can tell
 * a misconfiguration from a photo that never carried the mark.
 */
class WatermarkRemovalResult
{
    public function __construct(
        public readonly bool $applied,
        /** Stable slug for grouping; see WatermarkRemovalAttempt::OUTCOME_*. */
        public readonly string $outcome,
        public readonly string $reason,
        public readonly array $stats = [],
    ) {
    }

    public static function applied(array $stats): self
    {
        return new self(true, 'applied', 'applied', $stats);
    }

    public static function declined(string $outcome, string $reason, array $stats = []): self
    {
        return new self(false, $outcome, $reason, $stats);
    }
}
