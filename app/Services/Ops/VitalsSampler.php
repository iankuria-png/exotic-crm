<?php

namespace App\Services\Ops;

use App\Services\MarketHealthService;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;

/**
 * Reads the platform's vitals once a minute and writes them into Pulse.
 *
 * Two rules govern everything here.
 *
 * **Every read is individually failure-tolerant.** A sampler that throws
 * because one signal is unreadable tells you nothing about the other eight,
 * and it would throw hardest during the incident it exists to describe.
 *
 * **A signal that cannot be read records null, never zero.** Process counting
 * in particular is not guaranteed on shared hosting. A silent zero would read
 * as "no pressure" — the most dangerous possible wrong answer — so an
 * unreadable signal is marked unavailable and the degradation rules simply
 * ignore it.
 */
class VitalsSampler
{
    /** Pulse type prefix for every gauge this writes. */
    public const PULSE_TYPE = 'crm_vitals';

    public const LANES = [
        'fast' => ['push', 'alerts', 'default', 'kyc-fanout'],
        'sync' => ['sync-clients', 'sync-clients-reconcile'],
        'optimize' => ['auto_optimize'],
        'heavy' => ['heavy'],
    ];

    /** Cache key holding the most recent sample, which the API serves. */
    public const SAMPLE_CACHE_KEY = 'ops.vitals.latest';

    /** Cache key holding the failed_jobs count from the previous sample. */
    private const FAILED_BASELINE_KEY = 'ops.vitals.failed_baseline';

    /**
     * Rolling per-signal history, for the sparklines on the board.
     *
     * Kept as a bounded array on the sample itself rather than read back out of
     * `pulse_entries` on every page load. That table was already 1.3M rows in
     * production, and querying it per dashboard poll would make the board an
     * instance of the load it exists to detect. 180 samples is three hours at
     * one a minute, and the whole structure is a few kilobytes.
     */
    public const HISTORY_KEY = 'ops.vitals.history';
    private const HISTORY_SAMPLES = 180;

    private const SAMPLE_TTL_MINUTES = 30;

    public function __construct(
        private readonly OperationsSettingsService $settings,
    ) {
    }

    /**
     * Take one sample. Returns the payload that was cached.
     *
     * @return array<string, mixed>
     */
    public function sample(): array
    {
        $sampledAt = now();

        $processes = $this->readProcessCounts();
        $scheduler = $this->readScheduler($processes);
        $lanes = $this->readLanes();
        $failedRate = $this->readFailedJobRate();
        $stalls = $this->readSyncSliceStalls();
        $marketsDown = $this->readMarketsDown();
        $dbThreads = $this->readDatabaseThreads();

        $ceiling = $this->settings->integer('ops.threshold.php_processes.ceiling');
        $ceilingVerified = $this->settings->boolean('ops.threshold.php_processes.ceiling_verified');

        $signals = [
            $this->signal('php_processes', 'PHP processes', $processes['php'], 'processes', $ceiling, $ceilingVerified),
            $this->signal('concurrent_schedule_runs', 'Concurrent scheduler ticks', $scheduler['concurrent'], 'ticks'),
            $this->signal('scheduler_tick_seconds', 'Scheduler tick duration', $scheduler['tick_seconds'], 'seconds'),
            $this->signal('queue_depth', 'Queue depth', $lanes['total_pending'], 'jobs'),
            $this->signal('oldest_job_seconds', 'Oldest waiting job', $lanes['oldest_seconds'], 'seconds'),
            $this->signal('failed_job_rate', 'Failed jobs per minute', $failedRate, 'jobs/min'),
            $this->signal('sync_slice_stalls', 'Stalled sync slices', $stalls['count'], 'runs'),
            $this->signal('markets_down', 'Markets down', $marketsDown['count'], 'markets', $marketsDown['total']),
            $this->signal('db_threads_connected', 'MariaDB threads', $dbThreads, 'threads'),
        ];

        $payload = [
            'sampled_at' => $sampledAt->toIso8601String(),
            'signals' => $signals,
            'lanes' => $lanes['lanes'],
            'markets_down_names' => $marketsDown['names'],
            'stalled_runs' => $stalls['runs'],
            'process_ceiling' => $ceiling,
            'process_ceiling_verified' => $ceilingVerified,
            'scheduler' => $scheduler,
            'process_breakdown' => $processes['breakdown'] ?? [],
            'process_reason' => $processes['reason'] ?? null,
        ];

        $payload['history'] = $this->appendHistory($signals, $sampledAt);

        $this->writeToPulse($signals, $lanes['lanes']);
        $this->cache($payload);

        return $payload;
    }

    /**
     * The most recent sample, or null if none has been taken.
     *
     * The vitals endpoint serves this and never recomputes: a dashboard poll
     * must not itself add the pressure it is reporting on.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        try {
            $payload = Cache::get(self::SAMPLE_CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Append this sample's readings to the rolling window.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<string, mixed>
     */
    private function appendHistory(array $signals, \Illuminate\Support\Carbon $sampledAt): array
    {
        try {
            $history = Cache::get(self::HISTORY_KEY);
        } catch (\Throwable) {
            $history = null;
        }

        $history = is_array($history) ? $history : ['points' => [], 'series' => []];
        $series = is_array($history['series'] ?? null) ? $history['series'] : [];

        foreach ($signals as $signal) {
            $key = $signal['key'];
            $existing = is_array($series[$key] ?? null) ? $series[$key] : [];
            // Unavailable reads append null so a gap renders as a gap rather
            // than as a plausible-looking zero.
            $existing[] = $signal['available'] ? (float) $signal['value'] : null;
            $series[$key] = array_slice($existing, -self::HISTORY_SAMPLES);
        }

        $points = is_array($history['points'] ?? null) ? $history['points'] : [];
        $points[] = $sampledAt->toIso8601String();

        $history = [
            'points' => array_slice($points, -self::HISTORY_SAMPLES),
            'series' => $series,
        ];

        try {
            Cache::put(self::HISTORY_KEY, $history, now()->addHours(6));
        } catch (\Throwable $exception) {
            Log::warning('VitalsSampler could not cache signal history.', ['error' => $exception->getMessage()]);
        }

        return $history;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cache(array $payload): void
    {
        try {
            Cache::put(self::SAMPLE_CACHE_KEY, $payload, now()->addMinutes(self::SAMPLE_TTL_MINUTES));
        } catch (\Throwable $exception) {
            Log::warning('VitalsSampler could not cache its sample.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Build one signal, resolving its watch/shed thresholds from the registry.
     *
     * @return array<string, mixed>
     */
    private function signal(string $key, string $label, int|float|null $value, string $unit, ?int $ceiling = null, bool $ceilingEnforced = false): array
    {
        $watch = $this->settings->integer("ops.threshold.{$key}.watch");
        $shed = $this->settings->integer("ops.threshold.{$key}.shed");

        $available = $value !== null;
        $state = 'unavailable';

        if ($available) {
            if ($value >= $shed) {
                $state = 'shed';
            } elseif ($value >= $watch) {
                $state = 'watch';
            } else {
                $state = 'ok';
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'ceiling' => $ceiling,
            // Whether the ceiling is trustworthy enough to escalate on. An
            // unverified ceiling is still SHOWN — it is useful context — but the
            // evaluator will not take it as evidence of an outage.
            'ceiling_enforced' => $ceiling !== null && $ceilingEnforced,
            'watch' => $watch,
            'shed' => $shed,
            'state' => $state,
            'available' => $available,
        ];
    }

    /**
     * Count the account's PHP processes and, among them, `schedule:run`.
     *
     * cPanel accounts differ in whether `ps` reports other processes in the
     * account and whether `shell_exec` is permitted at all, so this returns
     * nulls rather than zeros when it cannot see.
     *
     * @return array{php:int|null, schedule_run:int|null, available:bool, reason:string|null}
     */
    private function readProcessCounts(): array
    {
        $unavailable = [
            'php' => null,
            'schedule_run' => null,
            'breakdown' => [],
            'available' => false,
            'reason' => 'Process listing is not permitted on this host.',
        ];

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (! function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
            return $unavailable;
        }

        $listing = $this->readProcessListing();

        if ($listing === null) {
            return $unavailable;
        }

        $php = 0;
        $scheduleRun = 0;
        $breakdown = [];

        foreach ($listing as $line) {
            // Match the interpreter the app actually runs under as well as the
            // generic `php` / `php-fpm` / `lsphp` forms, so a cPanel ea-php82
            // path counts.
            if (! preg_match('/(^|\/)(php[0-9.]*|php-fpm[0-9.]*|lsphp[0-9.]*)\b/', $line)) {
                continue;
            }

            $php++;

            if (str_contains($line, 'artisan schedule:run')) {
                $scheduleRun++;
            }

            $bucket = $this->classifyProcess($line);
            $breakdown[$bucket] = ($breakdown[$bucket] ?? 0) + 1;
        }

        arsort($breakdown);

        return [
            'php' => $php,
            'schedule_run' => $scheduleRun,
            'breakdown' => $breakdown,
            'available' => true,
            'reason' => null,
        ];
    }

    /**
     * Name what a PHP process is actually doing.
     *
     * "69 processes" is an alarm; "41 of them are queue workers on the sync
     * lane" is a diagnosis. The process table is already being read to produce
     * the count, so the breakdown costs nothing beyond a regex per line and it
     * is the difference between knowing something is wrong and knowing what.
     */
    private function classifyProcess(string $line): string
    {
        if (str_contains($line, 'artisan schedule:run')) {
            return 'schedule:run';
        }

        if (str_contains($line, 'artisan queue:work')) {
            if (preg_match('/--queue=([^\s]+)/', $line, $matches)) {
                foreach (self::LANES as $lane => $queues) {
                    foreach (explode(',', $matches[1]) as $queue) {
                        if (in_array(trim($queue), $queues, true)) {
                            return 'queue:work ('.$lane.')';
                        }
                    }
                }
            }

            return 'queue:work';
        }

        if (preg_match('/artisan\s+([a-z0-9:_-]+)/i', $line, $matches)) {
            return 'artisan '.$matches[1];
        }

        if (preg_match('/(php-fpm|lsphp)/', $line)) {
            return 'web (php-fpm)';
        }

        return 'other php';
    }

    /**
     * Read the process table, proving first that we can actually see it.
     *
     * `ps -eo cmd` is a Linux spelling; BSD/macOS rejects the `cmd` keyword and
     * some builds put that complaint on stdout. Filtering an error message for
     * PHP processes finds none and reports zero — a silent zero reads as "no
     * pressure", which is the most dangerous wrong answer this class can give.
     *
     * So each candidate spelling is probed against our OWN pid first. If the
     * probe does not return our own command line, that spelling is not
     * understood here and we move on; if none is, the signal is unavailable.
     *
     * @return array<int, string>|null
     */
    private function readProcessListing(): ?array
    {
        $pid = getmypid();

        if ($pid === false) {
            return null;
        }

        foreach (['args', 'command', 'cmd'] as $keyword) {
            $probe = $this->shell(sprintf('ps -o %s= -p %d 2>/dev/null', $keyword, $pid));

            if ($probe === null || trim($probe) === '' || ! str_contains($probe, 'php')) {
                continue;
            }

            $output = $this->shell(sprintf('ps -eo %s 2>/dev/null', $keyword))
                ?? $this->shell(sprintf('ps ax -o %s 2>/dev/null', $keyword));

            if ($output === null) {
                continue;
            }

            $lines = array_values(array_filter(
                array_map(fn ($line): string => trim((string) $line), preg_split('/\R/', $output) ?: []),
                fn (string $line): bool => $line !== '' && ! in_array(strtoupper($line), ['CMD', 'ARGS', 'COMMAND'], true)
            ));

            // A real listing is many lines and includes our own process. Fewer
            // than that means we are looking at an error, not a process table.
            if (count($lines) < 2) {
                continue;
            }

            return $lines;
        }

        return null;
    }

    private function shell(string $command): ?string
    {
        try {
            $output = @shell_exec($command);
        } catch (\Throwable) {
            return null;
        }

        return is_string($output) && trim($output) !== '' ? $output : null;
    }

    /**
     * Scheduler pressure: how many ticks are in flight, and how long the last
     * completed one took.
     *
     * Concurrency prefers the process scan when it is available and falls back
     * to the heartbeat's open-tick entries otherwise, so the signal survives a
     * host where `ps` is blocked.
     *
     * @param  array{php:int|null, schedule_run:int|null, available:bool, reason:string|null}  $processes
     * @return array<string, mixed>
     */
    private function readScheduler(array $processes): array
    {
        try {
            $payload = SchedulerHeartbeat::read();
            $openTicks = SchedulerHeartbeat::openTicks($payload);
            $lastCompleted = SchedulerHeartbeat::lastCompletedTick($payload);
        } catch (\Throwable $exception) {
            Log::warning('VitalsSampler could not read the scheduler heartbeat.', ['error' => $exception->getMessage()]);

            return [
                'concurrent' => null,
                'tick_seconds' => null,
                'due_count' => null,
                'last_ran_at' => null,
                'source' => 'unavailable',
            ];
        }

        $concurrent = $processes['available'] && $processes['schedule_run'] !== null
            ? $processes['schedule_run']
            : (count($openTicks) ?: ($payload === [] ? null : 0));

        $durationMs = $lastCompleted['duration_ms'] ?? null;

        return [
            'concurrent' => $concurrent,
            'tick_seconds' => $durationMs === null ? null : round($durationMs / 1000, 2),
            'due_count' => $lastCompleted['due_count'] ?? ($payload['due_count'] ?? null),
            'last_ran_at' => $payload['ran_at'] ?? null,
            'open_tick_pids' => array_values(array_filter(array_map(
                fn (array $tick): ?int => $tick['pid'] ?? null,
                $openTicks
            ))),
            'source' => $processes['available'] ? 'process_scan' : 'heartbeat',
        ];
    }

    /**
     * Per-lane queue depth and the age of the oldest job waiting in each.
     *
     * Grouped in one query rather than four, and aliased as `entries` because
     * production is MariaDB, where `rows` is a reserved word.
     *
     * @return array{lanes:array<int, array<string, mixed>>, total_pending:int|null, oldest_seconds:int|null}
     */
    private function readLanes(): array
    {
        $empty = [
            'lanes' => array_map(fn (string $lane): array => [
                'lane' => $lane,
                'queues' => self::LANES[$lane],
                'pending' => null,
                'reserved' => null,
                'oldest_seconds' => null,
                'state' => 'unavailable',
            ], array_keys(self::LANES)),
            'total_pending' => null,
            'oldest_seconds' => null,
        ];

        try {
            $now = now()->timestamp;

            $byQueue = DB::table('jobs')
                ->selectRaw('queue, COUNT(*) AS entries, SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS pending_entries, MIN(CASE WHEN reserved_at IS NULL THEN available_at ELSE NULL END) AS oldest_available_at')
                ->groupBy('queue')
                ->get()
                ->keyBy('queue');
        } catch (\Throwable $exception) {
            Log::warning('VitalsSampler could not read queue lanes.', ['error' => $exception->getMessage()]);

            return $empty;
        }

        $lanes = [];
        $totalPending = 0;
        $oldestSeconds = null;

        foreach (self::LANES as $lane => $queues) {
            $pending = 0;
            $reserved = 0;
            $laneOldest = null;

            foreach ($queues as $queue) {
                $row = $byQueue[$queue] ?? null;

                if ($row === null) {
                    continue;
                }

                $queuePending = (int) ($row->pending_entries ?? 0);
                $pending += $queuePending;
                $reserved += max(0, (int) ($row->entries ?? 0) - $queuePending);

                $availableAt = $row->oldest_available_at;
                if ($availableAt !== null) {
                    $age = max(0, $now - (int) $availableAt);
                    $laneOldest = $laneOldest === null ? $age : max($laneOldest, $age);
                }
            }

            $totalPending += $pending;
            if ($laneOldest !== null) {
                $oldestSeconds = $oldestSeconds === null ? $laneOldest : max($oldestSeconds, $laneOldest);
            }

            $lanes[] = [
                'lane' => $lane,
                'queues' => $queues,
                'pending' => $pending,
                'reserved' => $reserved,
                'oldest_seconds' => $laneOldest,
                'state' => $this->laneState($pending, $laneOldest, $reserved),
            ];
        }

        return [
            'lanes' => $lanes,
            'total_pending' => $totalPending,
            'oldest_seconds' => $oldestSeconds,
        ];
    }

    /**
     * A lane with work and a worker holding jobs is draining; one with work
     * nobody has touched for a while is backlogged. The distinction is the
     * whole point of tracking the oldest job age separately from the depth.
     */
    private function laneState(int $pending, ?int $oldestSeconds, int $reserved): string
    {
        if ($pending === 0 && $reserved === 0) {
            return 'idle';
        }

        $watch = $this->settings->integer('ops.threshold.oldest_job_seconds.watch');

        if ($oldestSeconds !== null && $oldestSeconds >= $watch) {
            return 'backlogged';
        }

        return 'draining';
    }

    /**
     * Failures added since the previous sample, expressed per minute.
     *
     * An error storm is upstream of a process storm — jobs that fail and retry
     * are jobs that occupy workers twice.
     */
    private function readFailedJobRate(): ?int
    {
        try {
            $count = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return null;
        }

        try {
            $previous = Cache::get(self::FAILED_BASELINE_KEY);
            Cache::put(self::FAILED_BASELINE_KEY, [
                'count' => $count,
                'at' => now()->timestamp,
            ], now()->addHour());
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($previous) || ! isset($previous['count'], $previous['at'])) {
            return 0;
        }

        $elapsedMinutes = max(1 / 60, (now()->timestamp - (int) $previous['at']) / 60);
        $delta = $count - (int) $previous['count'];

        // A negative delta means the table was flushed between samples, which
        // is an operator action rather than a health signal.
        return $delta <= 0 ? 0 : (int) round($delta / $elapsedMinutes);
    }

    /**
     * Client-sync runs that are still marked active but have stopped
     * heartbeating — a wedged market, visible now that runs carry `phase`
     * and `slices`.
     *
     * @return array{count:int|null, runs:array<int, array<string, mixed>>}
     */
    private function readSyncSliceStalls(): array
    {
        $staleMinutes = $this->settings->integer('ops.sync.stale_run_minutes');

        try {
            $runs = DB::table('client_sync_runs')
                ->leftJoin('platforms', 'platforms.id', '=', 'client_sync_runs.platform_id')
                ->whereIn('client_sync_runs.status', ['running', 'queued'])
                ->where(function ($query) use ($staleMinutes) {
                    $query->whereNull('client_sync_runs.last_heartbeat_at')
                        ->orWhere('client_sync_runs.last_heartbeat_at', '<', now()->subMinutes($staleMinutes));
                })
                ->orderBy('client_sync_runs.last_heartbeat_at')
                ->limit(50)
                ->get([
                    'client_sync_runs.platform_id',
                    'client_sync_runs.status',
                    'client_sync_runs.phase',
                    'client_sync_runs.slices',
                    'client_sync_runs.last_heartbeat_at',
                    'platforms.name as platform_name',
                ]);
        } catch (\Throwable $exception) {
            Log::warning('VitalsSampler could not read client sync runs.', ['error' => $exception->getMessage()]);

            return ['count' => null, 'runs' => []];
        }

        return [
            'count' => $runs->count(),
            'runs' => $runs->map(fn ($run): array => [
                'market' => $run->platform_name ?: ('Market #'.(int) $run->platform_id),
                'status' => (string) $run->status,
                'phase' => $run->phase,
                'slices' => (int) ($run->slices ?? 0),
                'last_heartbeat_at' => $run->last_heartbeat_at,
            ])->all(),
        ];
    }

    /**
     * Markets WordPress is failing on, which separates a CRM problem from a
     * WordPress problem in one glance.
     *
     * @return array{count:int|null, total:int|null, names:array<int, string>}
     */
    private function readMarketsDown(): array
    {
        try {
            $down = DB::table('platforms')
                ->whereIn('health_status', [
                    MarketHealthService::STATUS_DOMAIN_UNREACHABLE,
                    MarketHealthService::STATUS_SERVER_ERROR,
                    MarketHealthService::STATUS_AUTH_ERROR,
                    MarketHealthService::STATUS_WP_ERROR,
                ])
                ->orderBy('name')
                ->limit(60)
                ->pluck('name');

            $total = (int) DB::table('platforms')->count();
        } catch (\Throwable $exception) {
            Log::warning('VitalsSampler could not read market health.', ['error' => $exception->getMessage()]);

            return ['count' => null, 'total' => null, 'names' => []];
        }

        return [
            'count' => $down->count(),
            'total' => $total,
            'names' => $down->map(fn ($name): string => (string) $name)->all(),
        ];
    }

    /**
     * Connection exhaustion, ruled in or out. On 3 September MariaDB sat idle
     * while every dynamic route timed out, which is what pointed at processes
     * rather than the database.
     */
    private function readDatabaseThreads(): ?int
    {
        try {
            $row = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $value = ((array) $row)['Value'] ?? ((array) $row)['value'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Mirror the sample into Pulse.
     *
     * Pulse is the metric store — it brings aggregation, 7-day trimming and a
     * proven schema, so no parallel metrics table exists. Gauges go through
     * `set()`, counters through `record()`. Failures here are logged and
     * swallowed: losing a minute of history must not cost us the cached sample
     * the board and the evaluator actually run on.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<int, array<string, mixed>>  $lanes
     */
    private function writeToPulse(array $signals, array $lanes): void
    {
        try {
            foreach ($signals as $signal) {
                if (! $signal['available']) {
                    continue;
                }

                Pulse::set(self::PULSE_TYPE, $signal['key'], (string) $signal['value']);
                Pulse::record(self::PULSE_TYPE.'_history', $signal['key'], (int) round((float) $signal['value']));
            }

            foreach ($lanes as $lane) {
                if ($lane['pending'] === null) {
                    continue;
                }

                Pulse::set(self::PULSE_TYPE.'_lane', $lane['lane'], (string) json_encode([
                    'pending' => $lane['pending'],
                    'oldest_seconds' => $lane['oldest_seconds'],
                    'state' => $lane['state'],
                ]));
            }
        } catch (\Throwable $exception) {
            Log::warning('VitalsSampler could not write to Pulse.', ['error' => $exception->getMessage()]);
        }
    }
}
