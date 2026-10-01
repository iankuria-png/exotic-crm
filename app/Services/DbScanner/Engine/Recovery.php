<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanMarketRun;
use App\Models\DbScanOutbox;
use App\Models\DbScanPass;
use App\Models\DbScanSlot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bounded CRM-only recovery, every minute.
 *
 * Recovery waits beyond the job termination bound (60 s worker timeout,
 * 10 s statement timeout) plus grace before replacing an expired owner, and
 * fences the old owner by bumping the generation. It never relies on the
 * shared 4200-second queue retry delay.
 */
class Recovery
{
    public const HEARTBEAT_KEY = 'db_scanner.recovery_heartbeat';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly RunTerminator $terminator,
        private readonly PassStatus $passStatus,
        private readonly RunLogger $log,
    ) {}

    /**
     * @return array<string, int>
     */
    public function sweep(): array
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
        $summary = ['stale_owners' => 0, 'lost_jobs' => 0, 'deadlines' => 0, 'slots_cleared' => 0, 'passes' => 0];

        $staleAfter = (int) config('db_scanner.envelope.lease_seconds', 90) + (int) config('db_scanner.envelope.recovery_grace_seconds', 30);

        foreach (DbScanMarketRun::query()->where('status', 'running')->where('mode', '!=', 'preflight')->where('heartbeat_at', '<', now()->subSeconds($staleAfter))->limit(50)->get() as $run) {
            DB::transaction(function () use ($run, $staleAfter, &$summary) {
                $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'running' || ! $locked->heartbeat_at || $locked->heartbeat_at->gte(now()->subSeconds($staleAfter))) {
                    return;
                }
                $generation = (int) $locked->generation + 1;
                DbScanSlot::query()->where('owner_run_id', $locked->id)->update(['owner_run_id' => null, 'owner_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                if ($locked->control === 'stop') {
                    $this->terminator->terminateLocked($locked, 'stopped', 'stopped_by_operator');
                } elseif ($locked->control === 'pause') {
                    $this->terminator->pauseLocked($locked, 'manual');
                } else {
                    $locked->forceFill(['status' => 'queued', 'owner_token' => null, 'generation' => $generation])->save();
                    $this->outbox->enqueue((int) $locked->id, $generation);
                }
                $this->log->log($locked, 'warn', 'Recovered a run whose worker stopped heartbeating; continuing from the last committed chunk.');
                $summary['stale_owners']++;
            });
            $this->passStatus->refresh((int) $run->pass_id);
        }

        // Preflight runs are synchronous; an abandoned one is simply closed.
        foreach (DbScanMarketRun::query()->where('mode', 'preflight')->where('status', 'running')->where('heartbeat_at', '<', now()->subMinutes(5))->get() as $run) {
            $this->terminator->terminate($run, 'failed', 'preflight_abandoned');
        }

        // Queued runs whose published job never arrived (lost from the queue).
        // Only while workers are demonstrably alive: if they are down, the
        // queued jobs are still waiting and re-publishing would pile up.
        $workersAlive = ($beat = Cache::get(ScanSliceHeartbeat::KEY)) && strtotime((string) $beat) > now()->subMinutes(5)->timestamp;
        foreach (! $workersAlive ? [] : DbScanMarketRun::query()->whereIn('status', ['queued', 'waiting_lock'])->where('updated_at', '<', now()->subMinutes(10))->limit(50)->get() as $run) {
            $intent = DbScanOutbox::query()->where('run_id', $run->id)->where('generation', $run->generation)->first();
            if ($intent && ! $intent->published_at && ! $intent->revoked_at) {
                continue; // the dispatcher will publish it
            }
            if ($intent && $intent->available_at && $intent->available_at->isFuture()) {
                continue;
            }
            DB::transaction(function () use ($run, &$summary) {
                $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if (! $locked || ! in_array($locked->status, ['queued', 'waiting_lock'], true) || $locked->updated_at->gte(now()->subMinutes(10))) {
                    return;
                }
                $generation = (int) $locked->generation + 1;
                $locked->forceFill(['status' => 'queued', 'generation' => $generation])->save();
                $this->outbox->enqueue((int) $locked->id, $generation);
                $summary['lost_jobs']++;
            });
        }

        foreach (DbScanMarketRun::query()->whereNotIn('status', DbScanMarketRun::TERMINAL)->whereNotNull('deadline_at')->where('deadline_at', '<', now())->limit(50)->get() as $run) {
            if ($run->status === 'running') {
                continue; // the owner sees the deadline at its next slice
            }
            $this->terminator->terminate($run, 'partial', 'deadline');
            $summary['deadlines']++;
        }

        // Slots left behind by runs that are no longer running.
        foreach (DbScanSlot::query()->whereNotNull('owner_run_id')->where('lease_expires_at', '<', now()->subSeconds((int) config('db_scanner.envelope.recovery_grace_seconds', 30)))->get() as $slot) {
            $owner = DbScanMarketRun::query()->find($slot->owner_run_id);
            if (! $owner || $owner->status !== 'running') {
                $slot->forceFill(['owner_run_id' => null, 'owner_token' => null, 'lease_expires_at' => null])->save();
                $summary['slots_cleared']++;
            }
        }

        foreach (DbScanPass::query()->whereNotIn('status', DbScanPass::TERMINAL)->limit(100)->pluck('id') as $passId) {
            $this->passStatus->refresh((int) $passId);
            $summary['passes']++;
        }

        $this->outbox->publishPending(100);

        return $summary;
    }
}
