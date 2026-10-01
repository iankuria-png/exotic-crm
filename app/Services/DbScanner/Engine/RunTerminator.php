<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanConfigVersion;
use App\Models\DbScanMarketRun;
use App\Models\DbScanRuleCoverage;
use App\Models\DbScanSurfaceCoverage;
use App\Models\DbScanSweep;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Terminal and paused transitions for market runs, in one place:
 * releases the logical market claim and execution slots, revokes future
 * outbox intents, closes or keeps the sweep, and records final coverage.
 */
class RunTerminator
{
    public function __construct(
        private readonly AdmissionService $admission,
        private readonly Outbox $outbox,
        private readonly RunLogger $log,
        private readonly PassStatus $passStatus,
        private readonly SurfaceRegistry $registry,
    ) {}

    public function terminate(DbScanMarketRun $run, string $status, ?string $errorCode): string
    {
        $result = DB::transaction(function () use ($run, $status, $errorCode) {
            $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || $locked->isTerminal()) {
                return $locked?->status ?? $status;
            }

            return $this->terminateLocked($locked, $status, $errorCode);
        });
        $this->passStatus->refresh((int) $run->pass_id);

        return $result;
    }

    /**
     * Caller holds the row lock inside a transaction.
     */
    public function terminateLocked(DbScanMarketRun $locked, string $status, ?string $errorCode): string
    {
        $locked->forceFill([
            'status' => $status,
            'error_code' => $errorCode ?? $locked->error_code,
            'finished_at' => now(),
            'owner_token' => null,
            'control' => null,
            'next_attempt_at' => null,
            'generation' => (int) $locked->generation + 1,
        ])->save();

        $this->admission->releaseSlots($locked);
        $this->admission->releaseMarket((int) $locked->platform_id, (int) $locked->id);
        $this->outbox->revokeForRuns([(int) $locked->id]);
        $this->finalCoverage($locked);

        if ($locked->sweep_id) {
            $sweep = DbScanSweep::query()->whereKey($locked->sweep_id)->lockForUpdate()->first();
            if ($sweep && $sweep->status === 'running') {
                $updates = ['last_served_at' => now()];
                if ($status === 'stopped') {
                    $updates += ['status' => 'stopped', 'stop_reason' => $errorCode ?? 'stopped', 'finished_at' => now()];
                } elseif (in_array($status, ['failed', 'unreachable', 'skipped_unhealthy', 'skipped_shed'], true)) {
                    $updates += ['status' => 'stopped', 'stop_reason' => 'run_'.$status.':'.($errorCode ?? 'unknown'), 'finished_at' => now()];
                } elseif (in_array($status, ['completed', 'completed_with_gaps'], true)) {
                    $updates += ['status' => $status === 'completed' ? 'complete' : 'complete_with_gaps', 'finished_at' => now()];
                }
                $sweep->forceFill($updates)->save();
            }
        }

        $this->log->log($locked, in_array($status, ['failed', 'unreachable'], true) ? 'error' : 'info', $this->sentence($status, $errorCode), array_filter(['code' => $errorCode]));

        return $status;
    }

    public function pause(DbScanMarketRun $run, string $reason): string
    {
        $result = DB::transaction(function () use ($run, $reason) {
            $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || $locked->isTerminal()) {
                return $locked?->status ?? 'paused';
            }

            return $this->pauseLocked($locked, $reason);
        });
        $this->passStatus->refresh((int) $run->pass_id);

        return $result;
    }

    /**
     * A paused run keeps only its logical market claim.
     */
    public function pauseLocked(DbScanMarketRun $locked, string $reason): string
    {
        $locked->forceFill([
            'status' => 'paused',
            'pause_reason' => $reason,
            'control' => null,
            'owner_token' => null,
            'generation' => (int) $locked->generation + 1,
            'next_attempt_at' => ScannerGate::autoResumable($reason) || $reason === 'window' ? now()->addMinute() : null,
        ])->save();
        $this->admission->releaseSlots($locked);
        $this->outbox->revokeForRuns([(int) $locked->id]);
        $this->log->log($locked, 'info', 'Paused: '.str_replace('_', ' ', $reason).'. Progress is kept at the last committed chunk.', ['reason' => $reason]);

        return 'paused';
    }

    /**
     * Persist per-surface final coverage for the run summary. Surfaces this
     * run did not finish are recorded incomplete with the reason, so a
     * stopped or partial run can never display as clean.
     */
    private function finalCoverage(DbScanMarketRun $run): void
    {
        $state = (array) ($run->cursor ?? []);
        $reasonForPending = match ($run->status) {
            'partial' => $run->error_code === 'deadline' ? 'deadline' : 'continuation',
            'stopped' => 'stopped',
            default => 'not_reached',
        };

        if ($state === [] && $run->mode !== 'preflight') {
            // Ended before its first slice: every surface in scope is unread.
            foreach ($this->registry->forProfile((string) $run->profile) as $key => $surface) {
                $surface->isRows()
                    ? $state['surfaces'][$key] = ['status' => 'pending']
                    : $state['inventory']['surfaces'][$key] = ['status' => 'pending'];
            }
        }

        foreach ((array) ($state['surfaces'] ?? []) as $key => $s) {
            $status = $s['status'] ?? 'pending';
            $values = [
                'status' => $status === 'pending' ? 'incomplete' : $status,
                'reason' => $status === 'pending' ? $reasonForPending : ($s['reason'] ?? null),
                'high_water' => isset($s['high_water']) ? (string) $s['high_water'] : null,
                'values_truncated' => (int) ($s['truncated'] ?? 0),
            ];
            $existing = DbScanSurfaceCoverage::query()->where('market_run_id', $run->id)->where('surface_key', $key)->first();
            if ($existing) {
                $existing->forceFill([
                    'status' => $values['status'],
                    'reason' => $values['reason'],
                ])->save();
            } else {
                DbScanSurfaceCoverage::query()->create($values + [
                    'market_run_id' => $run->id,
                    'sweep_id' => $run->sweep_id,
                    'surface_key' => $key,
                    'adapter_version' => '1',
                ]);
            }
        }

        $this->ruleCoverage($run, $state, $reasonForPending);

        foreach ((array) ($state['inventory']['surfaces'] ?? []) as $key => $s) {
            $status = $s['status'] ?? 'pending';
            DbScanSurfaceCoverage::query()->updateOrCreate(
                ['market_run_id' => $run->id, 'surface_key' => $key],
                [
                    'sweep_id' => $run->sweep_id,
                    'adapter_version' => '1',
                    'status' => $status === 'pending' ? 'incomplete' : $status,
                    'reason' => $status === 'pending' ? $reasonForPending : ($s['reason'] ?? null),
                ]
            );
        }
    }

    /**
     * Rule-specific outcomes are authoritative for resolution and reporting:
     * one row per rule × declared surface, including deliberate omissions.
     */
    private function ruleCoverage(DbScanMarketRun $run, array $state, string $reasonForPending): void
    {
        if (! $run->config_version_id) {
            return;
        }
        $version = DbScanConfigVersion::query()->find($run->config_version_id);
        if (! $version) {
            return;
        }
        $config = $version->decoded();
        $subset = $config['rule_subset'] ?? null;
        $matches = (array) ($run->metrics['counters']['rule_matches'] ?? []);

        foreach ((array) ($config['rules'] ?? []) as $key => $rule) {
            $surfaces = (array) ($rule['all_surfaces'] ?? $rule['surfaces'] ?? []);
            if ($surfaces === []) {
                $surfaces = ['sweep'];
            }
            $inactive = match (true) {
                ! ($rule['enabled'] ?? false) => 'disabled',
                ! ($rule['in_profile'] ?? false) => 'not_in_profile',
                is_array($subset) && ! in_array($key, $subset, true) => 'not_in_subset',
                default => null,
            };
            $flags = (array) ($state['rule_flags'][$key] ?? []);

            foreach ($surfaces as $surface) {
                if ($inactive) {
                    [$status, $reason] = ['excluded', $inactive];
                } elseif (! in_array($surface, (array) ($rule['surfaces'] ?? []), true) && $surface !== 'sweep') {
                    [$status, $reason] = ['excluded', 'not_in_profile'];
                } else {
                    $source = $state['surfaces'][$surface] ?? $state['inventory']['surfaces'][$surface] ?? null;
                    $sourceStatus = $surface === 'sweep' ? ($state['finalized'] ?? false ? 'complete' : 'pending') : ($source['status'] ?? 'pending');
                    [$status, $reason] = match (true) {
                        ($flags['match_cap'] ?? 0) > 0 => ['incomplete', 'match_cap'],
                        isset($flags['matcher_error:'.$surface]) => ['incomplete', 'matcher_error'],
                        $sourceStatus === 'pending' => ['incomplete', $reasonForPending],
                        default => [$sourceStatus, $source['reason'] ?? null],
                    };
                }

                DbScanRuleCoverage::query()->updateOrCreate(
                    ['market_run_id' => $run->id, 'rule_key' => $key, 'surface_key' => $surface],
                    [
                        'rule_version_hash' => (string) ($rule['version_hash'] ?? ''),
                        'scope_hash' => hash('sha256', $version->hash.'|'.$surface),
                        'status' => $status,
                        'reason' => $reason,
                        'rows' => (int) ($state['surfaces'][$surface]['rows'] ?? 0),
                        'candidates' => (int) ($state['surfaces'][$surface]['candidates'] ?? 0),
                        'matches' => (int) ($matches[$key] ?? 0),
                        'decode_capped' => (int) ($state['surfaces'][$surface]['decode_capped'] ?? 0),
                        'values_truncated' => (int) ($state['surfaces'][$surface]['truncated'] ?? 0),
                    ]
                );
            }
        }
    }

    private function sentence(string $status, ?string $code): string
    {
        return match ($status) {
            'completed' => 'Run completed with complete coverage of its surfaces.',
            'completed_with_gaps' => 'Run completed; some surfaces are incomplete or excluded — see coverage.',
            'partial' => $code === 'deadline' ? 'Run reached its 24-hour deadline; committed progress is kept.' : 'Run ended at its budget; coverage so far is kept and the sweep continues.',
            'stopped' => 'Run stopped'.($code ? ' ('.str_replace('_', ' ', $code).')' : '').'. Committed progress is kept; nothing is resolved.',
            'unreachable' => 'Market database unreachable ('.str_replace('_', ' ', (string) $code).').',
            'failed' => 'Run failed ('.str_replace('_', ' ', (string) $code).').',
            default => 'Run ended: '.$status,
        };
    }
}
