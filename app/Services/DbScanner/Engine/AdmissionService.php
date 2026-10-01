<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanAdmission;
use App\Models\DbScanMarketRun;
use App\Models\DbScanSlot;
use App\Services\DbScanner\ScannerSettings;
use Illuminate\Support\Facades\DB;

/**
 * Durable CRM-side admission:
 *
 * - one logical active run per market (db_scan_admissions),
 * - at most N global execution slots and one per canonical host group
 *   (db_scan_slots), each leased with an owner token and an increasing
 *   generation.
 *
 * Callers wrap these in their own transaction. Slots are always locked in
 * the same order (global, then host) so concurrent workers cannot deadlock.
 * A waiting or paused run keeps only its logical market claim.
 */
class AdmissionService
{
    public function __construct(private readonly ScannerSettings $settings) {}

    /**
     * Whether a market already has a live logical claim. Locks the row.
     */
    public function marketBusy(int $platformId): ?int
    {
        $admission = $this->admissionRow($platformId, true);
        if (! $admission->active_run_id) {
            return null;
        }

        $run = DbScanMarketRun::query()->find($admission->active_run_id);
        if (! $run || $run->isTerminal()) {
            $admission->forceFill(['active_run_id' => null])->save();

            return null;
        }

        return (int) $run->id;
    }

    public function claimMarket(int $platformId, int $runId): void
    {
        $admission = $this->admissionRow($platformId, true);
        $admission->forceFill(['active_run_id' => $runId, 'generation' => $admission->generation + 1])->save();
    }

    public function releaseMarket(int $platformId, int $runId): void
    {
        DbScanAdmission::query()
            ->where('platform_id', $platformId)
            ->where('active_run_id', $runId)
            ->update(['active_run_id' => null, 'updated_at' => now()]);
    }

    /**
     * Try to take one global slot and the host-group slot for this run.
     * Returns false on contention (nothing is held in that case).
     */
    public function acquireSlots(DbScanMarketRun $run, string $token, string $hostGroup): bool
    {
        $globalKeys = [];
        for ($i = 1; $i <= $this->settings->globalSlots(); $i++) {
            $globalKeys[] = 'global:'.$i;
        }
        $hostKey = 'host:'.$hostGroup;
        $this->ensureSlots(array_merge($globalKeys, [$hostKey]));

        $lease = now()->addSeconds((int) config('db_scanner.envelope.lease_seconds', 90));
        $grace = (int) config('db_scanner.envelope.recovery_grace_seconds', 30);

        $globals = DbScanSlot::query()->whereIn('slot_key', $globalKeys)->orderBy('slot_key')->lockForUpdate()->get();
        $host = DbScanSlot::query()->where('slot_key', $hostKey)->lockForUpdate()->first();

        // A run never holds two global slots; re-entry after a crash reuses its own.
        $global = $globals->first(fn (DbScanSlot $s) => (int) $s->owner_run_id === (int) $run->id)
            ?? $globals->first(fn (DbScanSlot $s) => $this->free($s, $grace));

        $hostFree = $host && ((int) $host->owner_run_id === (int) $run->id || $this->free($host, $grace));
        if (! $global || ! $hostFree) {
            return false;
        }

        foreach ([$global, $host] as $slot) {
            $slot->forceFill([
                'owner_run_id' => $run->id,
                'owner_token' => $token,
                'generation' => $slot->generation + 1,
                'lease_expires_at' => $lease,
                'heartbeat_at' => now(),
            ])->save();
        }

        return true;
    }

    /**
     * Extend the lease; false means ownership was lost (fenced out).
     */
    public function renew(DbScanMarketRun $run, string $token): bool
    {
        $lease = now()->addSeconds((int) config('db_scanner.envelope.lease_seconds', 90));
        DbScanSlot::query()
            ->where('owner_run_id', $run->id)
            ->where('owner_token', $token)
            ->where('lease_expires_at', '>', now())
            ->update(['lease_expires_at' => $lease, 'heartbeat_at' => now(), 'updated_at' => now()]);

        // MySQL/MariaDB report changed rows, not matched rows: two renewals in
        // the same second change nothing and report 0. Verify by reading.
        return $this->holds($run, $token);
    }

    public function holds(DbScanMarketRun $run, string $token): bool
    {
        return DbScanSlot::query()
            ->where('owner_run_id', $run->id)
            ->where('owner_token', $token)
            ->where('lease_expires_at', '>', now())
            ->count() >= 2;
    }

    /**
     * Release only what this owner still holds; a newer owner is untouched.
     */
    public function releaseSlots(DbScanMarketRun $run, ?string $token = null): void
    {
        DbScanSlot::query()
            ->where('owner_run_id', $run->id)
            ->when($token !== null, fn ($q) => $q->where('owner_token', $token))
            ->update(['owner_run_id' => null, 'owner_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }

    private function free(DbScanSlot $slot, int $grace): bool
    {
        if ($slot->quarantined_reason) {
            return false;
        }
        if (! $slot->owner_run_id) {
            return true;
        }

        // An expired lease is reusable only after the remote statement bound
        // and termination grace have passed.
        return $slot->lease_expires_at === null || $slot->lease_expires_at->lt(now()->subSeconds($grace));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function ensureSlots(array $keys): void
    {
        foreach ($keys as $key) {
            if (! DbScanSlot::query()->where('slot_key', $key)->exists()) {
                try {
                    DB::table('db_scan_slots')->insertOrIgnore([
                        'slot_key' => $key, 'generation' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (\Throwable) {
                    // A concurrent insert won; the row exists either way.
                }
            }
        }
    }

    private function admissionRow(int $platformId, bool $lock): DbScanAdmission
    {
        DB::table('db_scan_admissions')->insertOrIgnore([
            'platform_id' => $platformId, 'generation' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $query = DbScanAdmission::query()->where('platform_id', $platformId);

        return $lock ? $query->lockForUpdate()->firstOrFail() : $query->firstOrFail();
    }
}
