<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanMarketRun;
use App\Models\DbScanPass;

/**
 * Derives a pass's status from its durable market runs. Runs own
 * terminalization; a pass is never "completed" by a batch counter.
 */
class PassStatus
{
    public function refresh(int $passId): void
    {
        $pass = DbScanPass::query()->find($passId);
        if (! $pass) {
            return;
        }

        $statuses = DbScanMarketRun::query()->where('pass_id', $passId)->pluck('status')->all();
        if ($statuses === []) {
            return;
        }

        $terminal = array_filter($statuses, fn ($s) => in_array($s, DbScanMarketRun::TERMINAL, true));
        $updates = [];

        if (count($terminal) === count($statuses)) {
            $counts = array_count_values($statuses);
            $status = match (true) {
                ($counts['stopped'] ?? 0) === count($statuses) => 'stopped',
                ($counts['completed'] ?? 0) === count($statuses) => 'completed',
                count(array_diff($statuses, ['completed', 'completed_with_gaps', 'partial'])) === 0 => 'completed_with_gaps',
                default => 'completed_with_errors',
            };
            $updates = ['status' => $status, 'finished_at' => $pass->finished_at ?? now()];
        } else {
            $live = array_diff($statuses, DbScanMarketRun::TERMINAL);
            $status = match (true) {
                in_array($pass->status, ['stopping'], true) => 'stopping',
                in_array('running', $live, true) => $pass->status === 'pausing' ? 'pausing' : 'running',
                count(array_diff($live, ['paused'])) === 0 => 'paused',
                default => in_array($pass->status, ['pausing', 'paused'], true) && in_array('paused', $live, true) ? 'paused' : ($pass->started_at ? 'running' : 'queued'),
            };
            $updates = ['status' => $status];
            if ($status === 'running' && ! $pass->started_at) {
                $updates['started_at'] = now();
            }
        }

        $pass->forceFill($updates)->save();
    }
}
