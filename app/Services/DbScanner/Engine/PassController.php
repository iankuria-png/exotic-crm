<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanConnection;
use App\Models\DbScanMarketRun;
use App\Models\DbScanPass;
use App\Models\DbScanSweep;
use App\Models\Platform;
use App\Services\DbScanner\Rules\RuleResolver;
use App\Services\DbScanner\ScannerSettings;
use Illuminate\Support\Facades\DB;

/**
 * Creates passes and applies operator controls.
 *
 * Pass creation locks admission rows in stable order and creates the pass,
 * market runs, immutable configuration snapshots and outbox intents in ONE
 * CRM transaction. A busy selected market fails the whole request (409)
 * for manual scans; schedules defer instead of discarding.
 */
class PassController
{
    public function __construct(
        private readonly AdmissionService $admission,
        private readonly Outbox $outbox,
        private readonly RuleResolver $resolver,
        private readonly ScannerSettings $settings,
        private readonly RunTerminator $terminator,
        private readonly PassStatus $passStatus,
        private readonly RunLogger $log,
    ) {}

    /**
     * @param  array<int, int>  $platformIds
     *
     * @throws MarketBusyException
     */
    public function start(
        array $platformIds,
        string $profile,
        string $trigger,
        ?int $actorId = null,
        ?array $ruleSubset = null,
        bool $verbose = false,
        ?string $idempotencyKey = null,
        ?int $scheduleId = null,
        bool $bypassWindow = false,
        string $mode = 'scan',
        ?array $loadOverride = null,
    ): DbScanPass {
        $platformIds = array_values(array_unique(array_map('intval', $platformIds)));
        sort($platformIds);

        if ($loadOverride !== null && ($trigger !== 'manual' || $mode !== 'scan' || $scheduleId !== null || count($platformIds) !== 1
            || (int) ($loadOverride['platform_id'] ?? 0) !== $platformIds[0] || (int) ($loadOverride['actor_id'] ?? 0) !== $actorId)) {
            throw new \InvalidArgumentException('Load overrides require one manual market scan and its actor.');
        }

        $pass = DB::transaction(function () use ($platformIds, $profile, $trigger, $actorId, $ruleSubset, $verbose, $idempotencyKey, $scheduleId, $bypassWindow, $mode, $loadOverride) {
            \App\Services\MarketOperationCoordinator::lock($platformIds);
            if ($idempotencyKey && $actorId) {
                $existing = DbScanPass::query()->where('triggered_by', $actorId)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $busy = [];
            foreach ($platformIds as $platformId) {
                if ($this->admission->marketBusy($platformId)) {
                    $busy[] = $platformId;
                }
            }
            if ($busy !== []) {
                throw new MarketBusyException($busy);
            }

            $pass = DbScanPass::query()->create([
                'trigger' => $trigger,
                'mode' => $mode,
                'profile' => $profile,
                'schedule_id' => $scheduleId,
                'triggered_by' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'scope' => ['platform_ids' => $platformIds, 'load_override' => $loadOverride],
                'rules' => $ruleSubset,
                'verbose' => $verbose,
                'bypass_window' => $bypassWindow,
                'status' => 'queued',
            ]);

            if ($loadOverride !== null) {
                app(\App\Services\DbScanner\DbScanAuditWriter::class)->record($actorId, 'pass', $pass->id, 'load_override_granted', null, $loadOverride, $platformIds[0]);
            }

            foreach ($platformIds as $platformId) {
                $platform = Platform::query()->findOrFail($platformId);
                $connection = DbScanConnection::query()->where('platform_id', $platformId)->first();
                $rules = $this->resolver->resolve($platform, $profile, $ruleSubset, $connection);

                $sweep = null;
                if ($mode === 'scan') {
                    $sweep = $this->openSweep($platformId, $profile, (int) $rules->configVersionId, $trigger);
                }

                $run = DbScanMarketRun::query()->create([
                    'pass_id' => $pass->id,
                    'platform_id' => $platformId,
                    'sweep_id' => $sweep?->id,
                    'mode' => $mode,
                    'profile' => $profile,
                    'status' => 'queued',
                    'config_version_id' => $rules->configVersionId,
                    'connection_config_version' => $connection?->config_version,
                    'generation' => 0,
                    'deadline_at' => now()->addHours((int) config('db_scanner.envelope.run_deadline_hours', 24)),
                    'budget_seconds' => $this->settings->budgetSeconds($mode === 'test' ? 'quick' : $profile),
                    'cursor' => $sweep?->cursors,
                    'metrics' => ['pack_hash' => \App\Services\DbScanner\ScannerProvenance::packs($rules->rules()), 'config_hash' => $rules->hash()],
                ]);

                if ($sweep) {
                    DbScanPass::query()->whereKey($pass->id)->update(['sweep_id' => $sweep->id]);
                }

                $this->admission->claimMarket($platformId, (int) $run->id);
                $this->outbox->enqueue((int) $run->id, 0);
            }

            return $pass;
        });

        $this->outbox->publishPending();

        return $pass->fresh();
    }

    /**
     * Continue an unfinished sweep in a new bounded pass/run. Returns null if
     * the market is busy (another run holds the logical claim).
     */
    public function continueSweep(DbScanSweep $sweep): ?DbScanPass
    {
        $pass = DB::transaction(function () use ($sweep) {
            \App\Services\MarketOperationCoordinator::lock([(int) $sweep->platform_id]);
            $locked = DbScanSweep::query()->whereKey($sweep->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isOpen() || $locked->continuation_paused) {
                return null;
            }
            if ($this->admission->marketBusy((int) $locked->platform_id)) {
                return null;
            }

            $connection = DbScanConnection::query()->where('platform_id', $locked->platform_id)->first();
            $rules = $this->resolver->fromVersion(\App\Models\DbScanConfigVersion::query()->findOrFail($locked->config_version_id));
            $pass = DbScanPass::query()->create([
                'trigger' => 'continuation',
                'mode' => 'scan',
                'profile' => $locked->profile,
                'sweep_id' => $locked->id,
                'scope' => ['platform_ids' => [(int) $locked->platform_id]],
                'status' => 'queued',
            ]);

            $run = DbScanMarketRun::query()->create([
                'pass_id' => $pass->id,
                'platform_id' => $locked->platform_id,
                'sweep_id' => $locked->id,
                'mode' => 'scan',
                'profile' => $locked->profile,
                'status' => 'queued',
                'config_version_id' => $locked->config_version_id,
                'connection_config_version' => $connection?->config_version,
                'generation' => 0,
                'deadline_at' => now()->addHours((int) config('db_scanner.envelope.run_deadline_hours', 24)),
                'budget_seconds' => $this->settings->budgetSeconds($locked->profile),
                'cursor' => $locked->cursors,
                'metrics' => ['pack_hash' => \App\Services\DbScanner\ScannerProvenance::packs($rules->rules()), 'config_hash' => $rules->hash()],
            ]);

            $locked->forceFill(['last_served_at' => now()])->save();
            $this->admission->claimMarket((int) $locked->platform_id, (int) $run->id);
            $this->outbox->enqueue((int) $run->id, 0);

            return $pass;
        });

        if ($pass) {
            $this->outbox->publishPending();
        }

        return $pass;
    }

    public function pause(DbScanPass $pass): DbScanPass
    {
        DB::transaction(function () use ($pass) {
            \App\Services\MarketOperationCoordinator::lock((array) ($pass->scope['platform_ids'] ?? []));
            $locked = DbScanPass::query()->whereKey($pass->id)->lockForUpdate()->first();
            if (in_array($locked->status, DbScanPass::TERMINAL, true) || in_array($locked->status, ['stopping'], true)) {
                throw new InvalidTransitionException('This pass cannot be paused from status '.$locked->status.'.');
            }

            foreach (DbScanMarketRun::query()->where('pass_id', $pass->id)->lockForUpdate()->get() as $run) {
                if ($run->isTerminal()) {
                    continue;
                }
                if ($run->status === 'running') {
                    $run->forceFill(['control' => 'pause'])->save();
                } else {
                    $this->terminator->pauseLocked($run, 'manual');
                }
                if ($run->sweep_id) {
                    DbScanSweep::query()->whereKey($run->sweep_id)->update(['continuation_paused' => true, 'updated_at' => now()]);
                }
            }
            $locked->forceFill(['status' => 'pausing', 'pause_reason' => 'manual'])->save();
        });
        $this->passStatus->refresh((int) $pass->id);

        return $pass->fresh();
    }

    public function resume(DbScanPass $pass): DbScanPass
    {
        DB::transaction(function () use ($pass) {
            \App\Services\MarketOperationCoordinator::lock((array) ($pass->scope['platform_ids'] ?? []));
            $locked = DbScanPass::query()->whereKey($pass->id)->lockForUpdate()->first();
            if (! in_array($locked->status, ['paused', 'pausing'], true)) {
                throw new InvalidTransitionException('Only a paused pass can be resumed.');
            }
            $override = $locked->scope['load_override'] ?? null;
            $connection = $override ? DbScanConnection::query()->where('platform_id', $override['platform_id'])->first() : null;
            if ($override && LoadOverride::ended($override) && $connection?->load_gate_enabled !== false) {
                throw new InvalidTransitionException(ScannerGate::describe('override_expired'));
            }

            foreach (DbScanMarketRun::query()->where('pass_id', $pass->id)->lockForUpdate()->get() as $run) {
                if ($run->status === 'running' && $run->control === 'pause') {
                    $run->forceFill(['control' => null])->save();
                } elseif ($run->status === 'paused') {
                    $generation = (int) $run->generation + 1;
                    $run->forceFill(['status' => 'queued', 'pause_reason' => null, 'generation' => $generation, 'next_attempt_at' => null])->save();
                    $this->outbox->enqueue((int) $run->id, $generation);
                    $this->log->log($run, 'info', 'Resumed by an operator; continuing from the last committed chunk.');
                }
                if ($run->sweep_id) {
                    DbScanSweep::query()->whereKey($run->sweep_id)->update(['continuation_paused' => false, 'updated_at' => now()]);
                }
            }
            $locked->forceFill(['status' => 'queued', 'pause_reason' => null])->save();
        });
        $this->outbox->publishPending();
        $this->passStatus->refresh((int) $pass->id);

        return $pass->fresh();
    }

    /**
     * Stop: unstarted/paused runs end now; running owners commit only their
     * safe checkpoint, then stop. The sweep is stopped so no continuation is
     * ever created for it; a new Scan now starts a new sweep.
     */
    public function stop(DbScanPass $pass, ?int $actorId = null): DbScanPass
    {
        DB::transaction(function () use ($pass, $actorId) {
            \App\Services\MarketOperationCoordinator::lock((array) ($pass->scope['platform_ids'] ?? []));
            $locked = DbScanPass::query()->whereKey($pass->id)->lockForUpdate()->first();
            if (in_array($locked->status, DbScanPass::TERMINAL, true)) {
                throw new InvalidTransitionException('This pass has already finished.');
            }

            foreach (DbScanMarketRun::query()->where('pass_id', $pass->id)->lockForUpdate()->get() as $run) {
                if ($run->sweep_id) {
                    DbScanSweep::query()->whereKey($run->sweep_id)->where('status', 'running')->update([
                        'status' => 'stopped', 'stop_reason' => 'stopped_by_operator', 'finished_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if ($run->isTerminal()) {
                    continue;
                }
                if ($run->status === 'running') {
                    $run->forceFill(['control' => 'stop'])->save();
                } else {
                    $this->terminator->terminateLocked($run, 'stopped', 'stopped_by_operator');
                }
            }
            $scope = $locked->scope;
            if (isset($scope['load_override']) && empty($scope['load_override']['revoked_at'])) {
                $before = $scope['load_override'];
                $scope['load_override']['revoked_at'] = now()->toIso8601String();
                app(\App\Services\DbScanner\DbScanAuditWriter::class)->record($actorId, 'pass', $locked->id, 'load_override_revoked', $before, $scope['load_override'], (int) $scope['load_override']['platform_id']);
            }
            $locked->forceFill(['status' => 'stopping', 'scope' => $scope])->save();
        });
        $this->passStatus->refresh((int) $pass->id);

        return $pass->fresh();
    }

    /**
     * Global resume re-queues runs that were paused by the global switch.
     */
    public function resumeGloballyPaused(): int
    {
        $count = 0;
        foreach (DbScanMarketRun::query()->where('status', 'paused')->where('pause_reason', 'operator_global')->get() as $run) {
            DB::transaction(function () use ($run, &$count) {
                \App\Services\MarketOperationCoordinator::lock([(int) $run->platform_id]);
                $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if ($locked && $locked->status === 'paused' && $locked->pause_reason === 'operator_global') {
                    $generation = (int) $locked->generation + 1;
                    $locked->forceFill(['status' => 'queued', 'pause_reason' => null, 'generation' => $generation])->save();
                    $this->outbox->enqueue((int) $locked->id, $generation);
                    $count++;
                }
            });
            $this->passStatus->refresh((int) $run->pass_id);
        }
        $this->outbox->publishPending();

        return $count;
    }

    /**
     * A compatible unfinished sweep is continued, never silently duplicated.
     * Manual scans supersede it explicitly; configuration changes close it.
     */
    private function openSweep(int $platformId, string $profile, int $configVersionId, string $trigger): DbScanSweep
    {
        $open = DbScanSweep::query()
            ->where('platform_id', $platformId)
            ->where('profile', $profile)
            ->where('status', 'running')
            ->lockForUpdate()
            ->get();

        foreach ($open as $sweep) {
            $reason = (int) $sweep->config_version_id !== $configVersionId ? 'configuration_changed' : ($trigger === 'manual' || $trigger === 'rescan' ? 'superseded_by_new_scan' : 'superseded');
            $sweep->forceFill(['status' => 'stopped', 'stop_reason' => $reason, 'finished_at' => now()])->save();
        }

        return DbScanSweep::query()->create([
            'platform_id' => $platformId,
            'profile' => $profile,
            'config_version_id' => $configVersionId,
            'status' => 'running',
            'expires_at' => now()->addDays((int) config('db_scanner.envelope.sweep_days', 7)),
            'last_served_at' => now(),
        ]);
    }
}
