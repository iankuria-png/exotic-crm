<?php

namespace App\Services\Ai;

final class SqlValidationPolicy
{
    /**
     * @param  string[]  $allowedViews
     * @param  string[]  $forbiddenColumns
     */
    public function __construct(
        public readonly array $allowedViews,
        public readonly int $defaultRowLimit,
        public readonly int $maxRowLimit,
        public readonly int $timeoutSeconds = 10,
        public readonly array $forbiddenColumns = [],
    ) {}
}
