<?php

namespace App\Services\DbScanner;

use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Models\DbScanPass;
use App\Models\DbScanSweep;
use App\Services\DbScanner\Engine\Recovery;
use App\Services\DbScanner\Engine\ScanSliceHeartbeat;
use App\Services\DbScanner\Engine\ScheduleDispatcher;
use Illuminate\Support\Facades\Cache;

/**
 * JSON shapes for the cockpit. Evidence is passed through as the plain,
 * already-redacted text the scanner stored; the browser renders it as text.
 */
class ObservatoryPresenter
{
    public function run(DbScanMarketRun $run, array $names = []): array
    {
        $state = (array) ($run->cursor ?? []);
        $progress = $this->progress($state);
        $metrics = (array) ($run->metrics ?? []);
        unset($metrics['counters']);

        return [
            'id' => $run->id,
            'coverage_scope' => ['scope' => 'database', 'filesystem_inspected' => false, 'live_responses_inspected' => false],
            'pass_id' => $run->pass_id,
            'sweep_id' => $run->sweep_id,
            'platform_id' => $run->platform_id,
            'market' => $names[$run->platform_id] ?? null,
            'mode' => $run->mode,
            'profile' => $run->profile,
            'status' => $run->status,
            'pause_reason' => $run->pause_reason,
            'control' => $run->control,
            'error_code' => $run->error_code,
            'db_engine' => $run->db_engine,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'heartbeat_at' => $run->heartbeat_at?->toIso8601String(),
            'next_attempt_at' => $run->next_attempt_at?->toIso8601String(),
            'deadline_at' => $run->deadline_at?->toIso8601String(),
            'active_seconds' => round((float) $run->active_seconds, 1),
            'budget_seconds' => (int) $run->budget_seconds,
            'progress' => $progress,
            'provenance' => ['code' => array_values($metrics['code_versions'] ?? []), 'pack_hash' => $metrics['pack_hash'] ?? null, 'config_hash' => $metrics['config_hash'] ?? null],
            'metrics' => $metrics,
            'findings_new' => (int) ($run->metrics['findings_new'] ?? 0),
            'test_samples' => $run->mode === 'test' ? (array) ($run->test_samples ?? []) : null,
            'unsupported' => $state['unsupported'] ?? null,
        ];
    }

    public function progress(array $state): array
    {
        $rows = (array) ($state['surfaces'] ?? []);
        $inventory = (array) ($state['inventory']['surfaces'] ?? []);
        $total = count($rows) + ($inventory === [] ? 0 : 1);
        $done = count(array_filter($rows, fn ($s) => ($s['status'] ?? 'pending') !== 'pending')) + (($state['inventory']['done'] ?? false) ? 1 : 0);
        $current = null;
        foreach ((array) ($state['order'] ?? []) as $index => $key) {
            if (($rows[$key]['status'] ?? null) === 'pending' && $index === (int) ($state['rotation'] ?? 0)) {
                $current = $key;
            }
        }
        if (! ($state['inventory']['done'] ?? false) && $inventory !== []) {
            $current = 'inventory';
        }

        $surfaceFractions = [];
        foreach ($rows as $key => $s) {
            $hw = (int) ($s['high_water'] ?? 0);
            $surfaceFractions[$key] = ($s['status'] ?? 'pending') !== 'pending' ? 1.0 : ($hw > 0 ? min(1, (int) ($s['cursor'] ?? 0) / $hw) : 0.0);
        }
        $fraction = $total === 0 ? 0 : (array_sum($surfaceFractions) + (($state['inventory']['done'] ?? false) ? 1 : 0)) / $total;

        return [
            'surfaces_done' => $done,
            'surfaces_total' => $total,
            'current_surface' => $current,
            'fraction' => round($fraction, 3),
            'surfaces' => collect($rows)->map(fn ($s, $key) => [
                'key' => $key,
                'status' => $s['status'] ?? 'pending',
                'reason' => $s['reason'] ?? null,
                'cursor' => $s['cursor'] ?? 0,
                'high_water' => $s['high_water'] ?? null,
                'rows' => $s['rows'] ?? 0,
                'excluded' => $s['excluded'] ?? 0,
                'truncated' => $s['truncated'] ?? 0,
                'batch_rows' => $s['batch_rows'] ?? null,
                'fraction' => round($surfaceFractions[$key] ?? 0, 3),
            ])->values()->all(),
            'inventory' => collect($inventory)->map(fn ($s, $key) => ['key' => $key] + $s)->values()->all(),
            'rule_flags' => $state['rule_flags'] ?? [],
        ];
    }

    public function pass(DbScanPass $pass, $runs, array $names = []): array
    {
        $statuses = collect($runs)->countBy('status')->all();

        return [
            'id' => $pass->id,
            'trigger' => $pass->trigger,
            'mode' => $pass->mode,
            'profile' => $pass->profile,
            'status' => $pass->status,
            'schedule_id' => $pass->schedule_id,
            'sweep_id' => $pass->sweep_id,
            'triggered_by' => $pass->triggered_by,
            'load_override' => $pass->scope['load_override'] ?? null,
            'rules' => $pass->rules,
            'verbose' => (bool) $pass->verbose,
            'created_at' => $pass->created_at?->toIso8601String(),
            'started_at' => $pass->started_at?->toIso8601String(),
            'finished_at' => $pass->finished_at?->toIso8601String(),
            'markets_total' => count($runs),
            'markets_done' => collect($runs)->filter(fn ($r) => $r->isTerminal())->count(),
            'run_statuses' => $statuses,
            'findings_new' => collect($runs)->sum(fn ($r) => (int) ($r->metrics['findings_new'] ?? 0)),
            'runs' => collect($runs)->map(fn ($r) => $this->run($r, $names))->values()->all(),
        ];
    }

    public function finding(DbScanFinding $finding, array $names = []): array
    {
        $evidence = (array) $finding->evidence;
        if ($finding->behavior === 'cross_market_campaign') {
            $details = (array) ($evidence['details'] ?? []);
            $ids = array_values(array_intersect((array) ($details['platform_ids'] ?? []), array_keys($names)));
            $restricted = count($ids) < count($details['platform_ids'] ?? []);
            $details['platform_ids'] = $ids;
            $details['markets'] = count($ids);
            $details['linked_finding_ids'] = DbScanFinding::query()->whereIn('id', (array) ($details['linked_finding_ids'] ?? []))->whereIn('platform_id', $ids)->pluck('id')->all();
            if ($restricted) {
                $details['within_ten_minutes'] = null;
                $details['scope_restricted'] = true;
            }
            $evidence['details'] = $details;
        }

        return [
            'id' => $finding->id,
            'coverage_scope' => ['scope' => 'database', 'filesystem_inspected' => false, 'live_responses_inspected' => false],
            'platform_id' => $finding->platform_id,
            'market' => $names[$finding->platform_id] ?? null,
            'rule_key' => $finding->rule_key,
            'pack' => $finding->pack,
            'pack_version' => $finding->pack_version,
            'rule_version_hash' => substr((string) $finding->rule_version_hash, 0, 12),
            'category' => $finding->category,
            'behavior' => $finding->behavior,
            'severity' => $finding->severity,
            'confidence' => $finding->confidence,
            'title' => $finding->title,
            'subject' => $finding->subject,
            'evidence' => [
                'excerpts' => array_values(array_map('strval', (array) ($evidence['excerpts'] ?? []))),
                'signals' => array_values((array) ($evidence['signals'] ?? [])),
                'transformations' => array_values((array) ($evidence['transformations'] ?? [])),
                'details' => $evidence['details'] ?? null,
                'bytes' => $evidence['bytes'] ?? null,
                'fully_read' => $evidence['fully_read'] ?? null,
                'payload_sha256' => $evidence['payload_sha256'] ?? null,
                'payload_hash_type' => $evidence['payload_hash_type'] ?? null,
                'decoded_sha256' => $evidence['decoded_sha256'] ?? null,
                'remote_host' => $evidence['remote_host'] ?? null,
            ],
            'status' => $finding->status,
            'snoozed_until' => $finding->snoozed_until?->toIso8601String(),
            'assigned_to' => $finding->assigned_to,
            'note' => $finding->note,
            'first_seen_at' => $finding->first_seen_at?->toIso8601String(),
            'last_seen_at' => $finding->last_seen_at?->toIso8601String(),
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'occurrences' => $finding->occurrences,
            'last_run_id' => $finding->last_run_id,
        ];
    }

    public function sweep(?DbScanSweep $sweep): ?array
    {
        if (! $sweep) {
            return null;
        }

        return [
            'id' => $sweep->id,
            'profile' => $sweep->profile,
            'status' => $sweep->status,
            'stop_reason' => $sweep->stop_reason,
            'created_at' => $sweep->created_at?->toIso8601String(),
            'finished_at' => $sweep->finished_at?->toIso8601String(),
            'expires_at' => $sweep->expires_at?->toIso8601String(),
            'oldest_observed_at' => $sweep->oldest_observed_at?->toIso8601String(),
            'newest_observed_at' => $sweep->newest_observed_at?->toIso8601String(),
            'continuation_paused' => (bool) $sweep->continuation_paused,
            'runs' => DbScanMarketRun::query()->where('sweep_id', $sweep->id)->count(),
            'progress' => $this->progress((array) ($sweep->cursors ?? [])),
        ];
    }

    /**
     * Scanner health: heartbeats, waiting work, stalled ownership, timing.
     */
    public function health(?array $platformIds = null): array
    {
        $age = function (?string $iso): ?int {
            return $iso ? max(0, now()->timestamp - (int) strtotime($iso)) : null;
        };

        $scopedRuns = DbScanMarketRun::query()->when(is_array($platformIds), fn ($q) => $q->whereIn('platform_id', $platformIds));

        $oldestWaiting = (clone $scopedRuns)->whereIn('status', ['queued', 'waiting_lock', 'paused'])->min('created_at');
        $stalled = (clone $scopedRuns)->where('status', 'running')->where('heartbeat_at', '<', now()->subSeconds(120))->count();
        $recent = (clone $scopedRuns)->where('finished_at', '>=', now()->subDay())->whereNotNull('metrics')->get(['metrics']);
        $p95 = $recent->map(fn ($r) => (float) ($r->metrics['p95_ms'] ?? 0))->max() ?? 0;
        $lastSweep = DbScanSweep::query()
            ->when(is_array($platformIds), fn ($q) => $q->whereIn('platform_id', $platformIds))
            ->whereIn('status', ['complete', 'complete_with_gaps'])
            ->max('finished_at');

        $dispatcherAge = $age(Cache::get(ScheduleDispatcher::HEARTBEAT_KEY));
        $workerAge = $age(Cache::get(ScanSliceHeartbeat::KEY));
        $recoveryAge = $age(Cache::get(Recovery::HEARTBEAT_KEY));
        $hasWork = (clone $scopedRuns)->whereIn('status', ['queued', 'waiting_lock', 'running'])->exists();

        $status = 'healthy';
        if ($stalled > 0 || ($hasWork && ($workerAge === null || $workerAge > 300))) {
            $status = 'degraded';
        }
        if (config('db_scanner.enabled') && ($dispatcherAge === null || $dispatcherAge > 300)) {
            $status = $status === 'healthy' ? 'idle' : $status;
        }

        return [
            'status' => $status,
            'dispatcher_heartbeat_age_seconds' => $dispatcherAge,
            'worker_heartbeat_age_seconds' => $workerAge,
            'recovery_heartbeat_age_seconds' => $recoveryAge,
            'oldest_waiting_age_seconds' => $oldestWaiting ? max(0, now()->timestamp - strtotime((string) $oldestWaiting)) : null,
            'stalled_runs' => $stalled,
            'queued_runs' => (clone $scopedRuns)->whereIn('status', ['queued', 'waiting_lock'])->count(),
            'running_runs' => (clone $scopedRuns)->where('status', 'running')->count(),
            'p95_query_ms_24h' => round((float) $p95, 1),
            'last_successful_sweep_at' => $lastSweep ? date(DATE_ATOM, strtotime((string) $lastSweep)) : null,
        ];
    }
}
