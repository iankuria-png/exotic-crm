<?php

namespace App\Services\DbScanner\Engine;

use App\Jobs\DbScanner\ScanMarketJob;
use App\Models\DbScanOutbox;
use Illuminate\Support\Facades\DB;

/**
 * Transactional outbox for slice jobs. Intents are written in the same CRM
 * transaction as the state change that needs them, then published after
 * commit (immediately, and again by the minute dispatcher for anything a
 * crash left behind). Duplicate publication is harmless: every job carries
 * the generation it expects and stale jobs exit without touching anything.
 */
class Outbox
{
    public function enqueue(int $runId, int $generation, ?\DateTimeInterface $availableAt = null): void
    {
        DB::table('db_scan_outbox')->insertOrIgnore([
            'dedupe_key' => 'run:'.$runId.':gen:'.$generation,
            'run_id' => $runId,
            'generation' => $generation,
            'available_at' => $availableAt ?? now(),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, int>  $runIds
     */
    public function revokeForRuns(array $runIds): void
    {
        if ($runIds === []) {
            return;
        }

        DbScanOutbox::query()
            ->whereIn('run_id', $runIds)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Publish unpublished, unrevoked intents. Bounded per call.
     */
    public function publishPending(int $limit = 50): int
    {
        $rows = DbScanOutbox::query()
            ->whereNull('published_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $published = 0;
        foreach ($rows as $row) {
            $claimed = DbScanOutbox::query()
                ->whereKey($row->id)
                ->whereNull('published_at')
                ->update(['published_at' => now(), 'attempts' => $row->attempts + 1, 'updated_at' => now()]);
            if ($claimed === 0) {
                continue;
            }

            $job = new ScanMarketJob((int) $row->run_id, (int) $row->generation);
            $delay = $row->available_at && $row->available_at->isFuture() ? $row->available_at : null;

            try {
                $job->onConnection((string) config('db_scanner.queue_connection'))->onQueue((string) config('db_scanner.queue'));
                if ($delay) {
                    $job->delay($delay);
                }
                dispatch($job);
                $published++;
            } catch (\Throwable) {
                // Leave it for the next dispatcher tick.
                DbScanOutbox::query()->whereKey($row->id)->update(['published_at' => null, 'updated_at' => now()]);
            }
        }

        return $published;
    }
}
