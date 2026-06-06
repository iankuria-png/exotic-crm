<?php

namespace App\Services\AutoOptimize;

use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizeWriteReservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Atomic, DB-backed per-platform-hour write throttle.
 *
 * Reservation model:
 * 1. reserve() — increment aggregate writes_count by `reserved`; insert a reservation row.
 * 2. consume() — increment reservation.consumed (does NOT change the aggregate).
 * 3. release() — decrement aggregate by (reserved − consumed); set released_at. Exactly once.
 * 4. Maintenance reclaims expired reservations with the same release logic.
 */
class AutoOptimizeWriteLedger
{
    /**
     * Opens a write reservation for an item operation.
     * Returns the reservation id, or throws if capacity is exceeded.
     *
     * @param  int  $maxPerHour  From reliability.max_writes_per_hour
     * @throws RuntimeException when capacity is exceeded
     */
    public function reserve(
        int $itemId,
        int $platformId,
        string $operation,
        int $maxReserved,
        int $maxPerHour,
        int $ttlSeconds = 300
    ): int {
        $windowStart = $this->currentWindow();

        return DB::transaction(function () use (
            $itemId, $platformId, $operation,
            $maxReserved, $maxPerHour, $windowStart, $ttlSeconds
        ) {
            // Lock the aggregate row for this platform+hour
            $log = DB::table('auto_optimize_write_log')
                ->where('platform_id', $platformId)
                ->where('window_start', $windowStart)
                ->lockForUpdate()
                ->first();

            $current = $log ? (int) $log->writes_count : 0;

            if ($current + $maxReserved > $maxPerHour) {
                throw new RuntimeException(
                    "Auto Optimize write limit exceeded for platform {$platformId}: {$current}/{$maxPerHour} this hour."
                );
            }

            // Increment aggregate
            if ($log) {
                DB::table('auto_optimize_write_log')
                    ->where('platform_id', $platformId)
                    ->where('window_start', $windowStart)
                    ->update(['writes_count' => $current + $maxReserved, 'updated_at' => now()]);
            } else {
                DB::table('auto_optimize_write_log')->insert([
                    'platform_id' => $platformId,
                    'window_start' => $windowStart,
                    'writes_count' => $maxReserved,
                    'updated_at' => now(),
                ]);
            }

            // Insert reservation row
            $reservation = AutoOptimizeWriteReservation::query()->create([
                'item_id' => $itemId,
                'platform_id' => $platformId,
                'window_start' => $windowStart,
                'operation' => $operation,
                'reserved' => $maxReserved,
                'consumed' => 0,
                'expires_at' => now()->addSeconds($ttlSeconds),
                'released_at' => null,
            ]);

            return (int) $reservation->id;
        });
    }

    /**
     * Records that one actual write was performed against the reservation.
     * Does NOT change the aggregate counter.
     */
    public function consume(int $reservationId): void
    {
        DB::table('auto_optimize_write_reservations')
            ->where('id', $reservationId)
            ->increment('consumed');
    }

    /**
     * Releases unused reservation slots back to the aggregate.
     * Idempotent — guarded by released_at IS NULL.
     */
    public function release(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {
            $reservation = DB::table('auto_optimize_write_reservations')
                ->where('id', $reservationId)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            if (!$reservation) {
                return; // Already released — idempotent
            }

            $unused = max(0, (int) $reservation->reserved - (int) $reservation->consumed);

            if ($unused > 0) {
                DB::table('auto_optimize_write_log')
                    ->where('platform_id', $reservation->platform_id)
                    ->where('window_start', $reservation->window_start)
                    ->decrement('writes_count', $unused);
            }

            DB::table('auto_optimize_write_reservations')
                ->where('id', $reservationId)
                ->whereNull('released_at') // double-check under lock
                ->update(['released_at' => now()]);
        });
    }

    /**
     * Reclaims expired reservations from crashed jobs.
     * Called by the maintenance command.
     */
    public function reclaimExpired(): int
    {
        $expired = DB::table('auto_optimize_write_reservations')
            ->whereNull('released_at')
            ->where('expires_at', '<', now())
            // Only reclaim if the owning item is no longer actively applying
            ->get();

        $reclaimed = 0;
        foreach ($expired as $reservation) {
            $item = AutoOptimizeItem::query()->find($reservation->item_id);
            if ($item && $item->isActive()) {
                continue; // Job may still be running — skip
            }

            $this->release((int) $reservation->id);
            $reclaimed++;
        }

        return $reclaimed;
    }

    private function currentWindow(): string
    {
        return now()->startOfHour()->toDateTimeString();
    }
}
