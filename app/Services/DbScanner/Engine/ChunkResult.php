<?php

namespace App\Services\DbScanner\Engine;

use App\Services\DbScanner\Rules\Accumulator;
use App\Services\DbScanner\Rules\Hit;

final class ChunkResult
{
    /** @var array<int, Hit> */
    public array $hits = [];

    public Accumulator $accumulator;

    public int $rows = 0;

    public int $candidates = 0;

    public int $bytes = 0;

    public int $truncated = 0;

    public int $excluded = 0;

    public int $decodeCapped = 0;

    public int $matcherErrors = 0;

    /** @var array<string, int> rule => matches in this chunk */
    public array $ruleMatches = [];

    /** @var array<string, int> rule => decode caps in this chunk */
    public array $ruleDecodeCaps = [];

    public bool $exhausted = false;

    public function __construct(
        public readonly int $rangeStart,
        public readonly int $rangeEnd,
    ) {
        $this->accumulator = new Accumulator;
    }
}
