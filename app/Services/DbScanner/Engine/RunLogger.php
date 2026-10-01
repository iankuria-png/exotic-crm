<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanEvent;
use App\Models\DbScanMarketRun;

/**
 * Run event log. Messages and context never contain matched values, raw
 * SQL, bindings or driver messages — only counts, ranges, durations and
 * typed codes.
 */
class RunLogger
{
    private array $verbose = [];

    public function log(DbScanMarketRun $run, string $level, string $message, array $context = [], ?string $rule = null, ?string $surface = null): void
    {
        if ($level === 'debug' && ! $this->verbose($run)) {
            return;
        }

        DbScanEvent::query()->create([
            'market_run_id' => $run->id,
            'at' => now(),
            'level' => $level,
            'rule_key' => $rule,
            'surface' => $surface,
            'message' => mb_substr($message, 0, 500),
            'context' => $context === [] ? null : $context,
        ]);
    }

    private function verbose(DbScanMarketRun $run): bool
    {
        return $this->verbose[$run->pass_id] ??= (bool) optional($run->pass)->verbose;
    }
}
