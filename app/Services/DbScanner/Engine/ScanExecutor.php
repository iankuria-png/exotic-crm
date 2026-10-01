<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanConfigVersion;
use App\Models\DbScanConnection;
use App\Models\DbScanDailyBudget;
use App\Models\DbScanMarketRun;
use App\Models\DbScanSnapshot;
use App\Models\DbScanSurfaceCoverage;
use App\Models\DbScanSweep;
use App\Models\Platform;
use App\Services\DbScanner\Malware\HostContext;
use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Reader\ReaderException;
use App\Services\DbScanner\Reader\ScannerCredentialResolver;
use App\Services\DbScanner\Rules\Accumulator;
use App\Services\DbScanner\Rules\InventoryMatchers;
use App\Services\DbScanner\Rules\RuleResolver;
use App\Services\DbScanner\Rules\RuleSet;
use App\Services\DbScanner\ScannerSettings;
use App\Services\DbScanner\Surfaces\SchemaDiscovery;
use App\Services\DbScanner\Surfaces\SchemaInfo;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Executes one bounded slice of a market run.
 *
 * A slice: validates the job's generation, checks controls and gates, takes
 * a fenced lease on one global and one host-group slot, opens a guarded
 * read-only session, then alternates bounded reads with CRM commits until
 * 45 seconds, the run's active budget, a control request or completion.
 * Every commit re-verifies ownership in the same transaction that writes
 * observations, coverage, metrics and the cursor, so a stale worker cannot
 * double count or overwrite a newer owner. The reader is closed before
 * slots are released.
 */
class ScanExecutor
{
    private const SOFT_SLICE_SECONDS = 45;

    private const HARD_SLICE_SECONDS = 55;

    private ?string $abort = null;

    private ?string $currentSurface = null;

    public function __construct(
        private readonly ScannerSettings $settings,
        private readonly ScannerGate $gate,
        private readonly AdmissionService $admission,
        private readonly Outbox $outbox,
        private readonly RunLogger $log,
        private readonly ScannerCredentialResolver $credentials,
        private readonly SchemaDiscovery $discovery,
        private readonly SurfaceRegistry $registry,
        private readonly RuleResolver $resolver,
        private readonly InventoryCollector $inventory,
        private readonly SurfaceScanner $scanner,
        private readonly FindingRecorder $recorder,
        private readonly InventoryMatchers $inventoryMatchers,
        private readonly SweepFinalizer $finalizer,
        private readonly PassStatus $passStatus,
        private readonly RunTerminator $terminator,
    ) {}

    public function runSlice(int $runId, int $expectedGeneration): string
    {
        $this->abort = null;
        $this->currentSurface = null;

        $run = DbScanMarketRun::query()->find($runId);
        if (! $run || $run->isTerminal() || (int) $run->generation !== $expectedGeneration) {
            return 'stale';
        }
        if (! in_array($run->status, ['queued', 'waiting_lock'], true)) {
            return 'stale';
        }

        $platform = Platform::query()->find($run->platform_id);
        $connection = DbScanConnection::query()->where('platform_id', $run->platform_id)->first();
        $sweep = $run->sweep_id ? DbScanSweep::query()->find($run->sweep_id) : null;

        if (! $platform) {
            $this->terminator->terminate($run, 'failed', 'platform_missing');

            return 'failed';
        }
        if ($sweep && ! $sweep->isOpen()) {
            $this->terminator->terminate($run, 'stopped', $sweep->status === 'expired' || $sweep->expires_at?->isPast() ? 'sweep_expired' : 'sweep_stopped');

            return 'stopped';
        }
        if ($run->control === 'stop') {
            $this->terminator->terminate($run, 'stopped', 'stopped_by_operator');

            return 'stopped';
        }
        if ($run->control === 'pause') {
            $this->terminator->pause($run, 'manual');

            return 'paused';
        }
        if ($run->deadline_at && $run->deadline_at->isPast()) {
            $this->terminator->terminate($run, 'partial', 'deadline');

            return 'partial';
        }

        $reason = $this->gate->check($platform, $connection, 'scan', LoadOverride::forRun($run));
        if ($reason !== null) {
            return $this->blocked($run, $reason);
        }
        if ($this->outsideWindow($run, $platform)) {
            $this->terminator->pause($run, 'window');

            return 'paused';
        }

        $token = Str::random(40);
        $claim = DB::transaction(function () use ($run, $expectedGeneration, $token, $connection) {
            $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || (int) $locked->generation !== $expectedGeneration || ! in_array($locked->status, ['queued', 'waiting_lock'], true)) {
                return 'stale';
            }

            $generation = (int) $locked->generation + 1;
            if (! $this->admission->acquireSlots($locked, $token, (string) $connection->host_group)) {
                $backoff = config('db_scanner.envelope.contention_backoff_seconds', [15, 60]);
                $next = now()->addSeconds(random_int((int) $backoff[0], (int) $backoff[1]));
                $locked->forceFill(['status' => 'waiting_lock', 'generation' => $generation, 'next_attempt_at' => $next])->save();
                $this->outbox->enqueue($locked->id, $generation, $next);

                return 'contention';
            }

            $locked->forceFill([
                'status' => 'running',
                'generation' => $generation,
                'owner_token' => $token,
                'heartbeat_at' => now(),
                'started_at' => $locked->started_at ?? now(),
                'next_attempt_at' => null,
            ])->save();

            return 'owned';
        });

        if ($claim !== 'owned') {
            $this->outbox->publishPending();
            if ($claim === 'contention') {
                $this->passStatus->refresh((int) $run->pass_id);
            }

            return $claim;
        }

        $run->refresh();
        $this->passStatus->refresh((int) $run->pass_id);

        return $this->execute($run, $token, $platform, $connection, $sweep);
    }

    private function execute(DbScanMarketRun $run, string $token, Platform $platform, DbScanConnection $connection, ?DbScanSweep $sweep): string
    {
        $sliceStarted = microtime(true);
        $reader = null;
        $outcome = 'yield';
        $error = null;
        $state = null;

        try {
            $rules = $this->resolver->fromVersion(DbScanConfigVersion::query()->findOrFail($run->config_version_id));
            $hosts = $this->resolver->hostContext($rules);
            $target = $this->credentials->forConnection($connection);
            $reader = new MarketDbReader($target);

            $lastControl = 0.0;
            $lastRenew = microtime(true);
            $control = function () use ($run, $token, $sliceStarted, &$lastControl, &$lastRenew): void {
                $this->control($run, $token, $sliceStarted, $lastControl, $lastRenew);
            };
            $reader->setControl($control);
            $reader->open();

            $schema = $this->discovery->discover($reader, $target->prefix);
            $state = $run->cursor ?: $this->initialState($run, $rules, $schema);
            if (LoadOverride::forRun($run) !== null) {
                foreach ($state['surfaces'] as &$surface) {
                    $surface['batch_rows'] = min(250, (int) $surface['batch_rows']);
                }
                unset($surface);
            }
            $state['engine'] = $reader->engine();
            $state['schema'] = $schema->summary();

            if ($schema->unsupportedReason() !== null) {
                $outcome = $this->unsupported($run, $token, $state, $schema, $sweep);
            } else {
                $outcome = $this->work($run, $token, $reader, $schema, $rules, $hosts, $state, $sweep, $platform, $sliceStarted, $control);
            }
        } catch (LeaseLostException) {
            $outcome = 'lease_lost';
        } catch (ReaderException $e) {
            if ($e->errorCode === ReaderException::CONTROL_ABORT) {
                $outcome = $this->abort ?? 'yield';
            } else {
                $outcome = 'reader_error';
                $error = $e;
            }
        } catch (\Throwable $e) {
            // Never loop on a code fault: record a typed failure without the
            // exception message (which could quote data), keep class/location.
            \Illuminate\Support\Facades\Log::error('Database Observatory slice failed.', [
                'run_id' => $run->id,
                'exception' => get_class($e),
                'at' => basename($e->getFile()).':'.$e->getLine(),
            ]);
            $outcome = 'internal_error';
        } finally {
            $metrics = $reader?->metrics() ?? [];
            $reader?->close();
        }

        $elapsed = microtime(true) - $sliceStarted;

        return $this->endSlice($run, $token, $outcome, $elapsed, $metrics, $error, $sweep);
    }

    /**
     * @return string done|yield|budget
     */
    private function work(
        DbScanMarketRun $run,
        string $token,
        MarketDbReader $reader,
        SchemaInfo $schema,
        RuleSet $rules,
        HostContext $hosts,
        array &$state,
        ?DbScanSweep $sweep,
        Platform $platform,
        float $sliceStarted,
        callable $control,
    ): string {
        $steps = 0;
        while (true) {
            $control();
            $elapsed = microtime(true) - $sliceStarted;
            // Limits are checked between steps, after at least one, so every
            // slice makes progress even when a budget is nearly spent.
            if ($steps > 0 && $elapsed >= self::SOFT_SLICE_SECONDS) {
                return 'yield';
            }
            if ($steps > 0 && (float) $run->active_seconds + $elapsed >= (int) $run->budget_seconds) {
                return 'budget';
            }
            $steps++;

            $step = $this->nextStep($state);
            if ($step === null) {
                $status = $this->finalizer->finalize($run, $token, $state, $rules, $reader, $schema, $hosts, $sweep, fn () => $this->commitState($run, $token, $state, $sweep));

                return 'done:'.$status;
            }

            if ($step === '__inventory') {
                $this->inventoryStage($run, $token, $reader, $schema, $rules, $state, $sweep);
            } else {
                $this->currentSurface = $step;
                $this->chunkStage($run, $token, $reader, $schema, $rules, $hosts, $state, $sweep, $step, $control);
            }
        }
    }

    private function inventoryStage(DbScanMarketRun $run, string $token, MarketDbReader $reader, SchemaInfo $schema, RuleSet $rules, array &$state, ?DbScanSweep $sweep): void
    {
        $surfaceKeys = array_keys((array) ($state['inventory']['surfaces'] ?? []));
        $surfaces = array_intersect_key($this->registry->inventorySurfaces($run->profile), array_flip($surfaceKeys));
        $connection = DbScanConnection::query()->where('platform_id', $run->platform_id)->first();

        $collected = $this->inventory->collect($reader, $schema, $surfaces, $this->registry, $rules->siteHost(), (array) ($connection?->capabilities ?? []));
        $components = $collected['components'];
        $coverage = $collected['coverage'];

        $previous = DbScanSnapshot::query()->where('platform_id', $run->platform_id)->orderByDesc('id')->first();
        $previousComponents = $previous?->components ?? [];

        $hits = [];
        foreach ($rules->rules() as $key => $rule) {
            if (! $rules->active($key) || ! in_array($rule['matcher'] ?? '', InventoryMatchers::IDS, true)) {
                continue;
            }
            $needed = array_intersect((array) $rule['surfaces'], $surfaceKeys);
            if ($needed === []) {
                continue;
            }
            $available = array_filter($needed, fn ($s) => in_array($coverage[$s]['status'] ?? null, ['complete', 'incomplete'], true)
                && ($coverage[$s]['reason'] ?? null) !== 'timeout'
                && isset($components[$this->inventory->componentName($s)]));
            if ($available === []) {
                continue;
            }
            foreach ($this->inventoryMatchers->run((string) $rule['matcher'], $key, $rules, $components, $previousComponents) as $hit) {
                $hits[] = $hit;
            }
        }

        DB::transaction(function () use ($run, $token, &$state, $components, $coverage, $hits, $rules, $previous) {
            $locked = $this->lockOwned($run, $token);

            if ($run->mode !== 'test') {
                $snapshot = DbScanSnapshot::query()->create([
                    'platform_id' => $run->platform_id,
                    'market_run_id' => $run->id,
                    'profile' => $run->profile,
                    'components' => $components,
                    'taken_at' => now(),
                ]);
                $locked->snapshot_id = $snapshot->id;
            }

            $counters = (array) ($locked->metrics['counters'] ?? []);
            $stats = $this->recorder->record($hits, $locked, $rules, $counters);
            $metrics = (array) ($locked->metrics ?? []);
            $metrics['counters'] = $counters;
            $metrics['findings_new'] = (int) ($metrics['findings_new'] ?? 0) + $stats['new'] + $stats['reopened'];
            $locked->metrics = $metrics;
            $locked->save();

            foreach ($coverage as $surfaceKey => $status) {
                $this->upsertSurfaceCoverage($locked, $surfaceKey, [
                    'status' => $status['status'],
                    'reason' => $status['reason'],
                    'completed_at' => $status['status'] === 'complete' ? now() : null,
                ]);
            }

            foreach ($stats['capped'] as $rule => $n) {
                $this->bumpFlag($state, $rule, 'match_cap', $n);
            }

            $this->log->log($locked, 'info', sprintf(
                'Inventory read: %d surfaces, %d findings (%d new)%s',
                count($coverage),
                count($hits),
                $stats['new'],
                $previous ? '' : ' · first snapshot recorded as baseline'
            ), ['coverage' => $coverage]);
            foreach (array_slice($hits, 0, 25) as $hit) {
                $this->log->log($locked, 'finding', $hit->title.' · '.($hit->subject['item'] ?? $hit->subject['label'] ?? ''), [], $hit->ruleKey, $hit->subject['surface'] ?? null);
            }
        });

        $state['inventory']['done'] = true;
        $state['inventory']['surfaces'] = array_merge((array) $state['inventory']['surfaces'], $coverage);
        $this->commitState($run, $token, $state, $sweep);
    }

    private function chunkStage(DbScanMarketRun $run, string $token, MarketDbReader $reader, SchemaInfo $schema, RuleSet $rules, HostContext $hosts, array &$state, ?DbScanSweep $sweep, string $key, callable $control): void
    {
        $surface = $this->registry->get($key);
        $s = &$state['surfaces'][$key];

        if (! $surface || ! $schema->has((string) $surface->table)) {
            $s['status'] = $surface && $surface->core ? 'incomplete' : 'not_applicable';
            $s['reason'] = $surface && $surface->core ? 'unsupported_schema' : 'table_absent';
            $this->advanceRotation($state, $key, true);
            $this->commitState($run, $token, $state, $sweep);

            return;
        }

        $table = $schema->table($surface->table);
        if ($s['high_water'] === null) {
            $max = $reader->fetchScalar($reader->compiler()->maxKey($table, (string) $surface->pk), 'k');
            if ($max === null) {
                $s['status'] = 'complete';
                $s['high_water'] = 0;
                $s['completed_at'] = now()->toIso8601String();
                $this->advanceRotation($state, $key, true);
                $this->commitState($run, $token, $state, $sweep);

                return;
            }
            $s['high_water'] = (int) $max;
        }

        $started = microtime(true);
        try {
            $result = $this->scanner->processChunk(
                $reader, $surface, $schema, (int) $s['cursor'], (int) $s['high_water'], (int) $s['batch_rows'],
                $rules, $hosts, $control, $run->mode === 'test' ? (int) config('db_scanner.envelope.test_sample_limit', 20) : null,
            );
        } catch (ReaderException $e) {
            if ($e->errorCode !== ReaderException::TIMEOUT) {
                throw $e;
            }
            $s['timeouts'] = (int) ($s['timeouts'] ?? 0) + 1;
            $s['halvings'] = (int) ($s['halvings'] ?? 0) + 1;
            if ((int) $s['batch_rows'] <= 25 && $s['timeouts'] >= 3) {
                $s['status'] = 'incomplete';
                $s['reason'] = 'timeout';
                $this->advanceRotation($state, $key, true);
                $this->log->log($run, 'warn', 'Surface abandoned after repeated timeouts at the minimum chunk size', ['cursor' => $s['cursor']], null, $key);
            } else {
                $s['batch_rows'] = max(25, intdiv((int) $s['batch_rows'], 2));
                $this->log->log($run, 'warn', 'Query timeout — halved chunk size to '.$s['batch_rows'].', retrying the same range', ['cursor' => $s['cursor']], null, $key);
            }
            $this->commitState($run, $token, $state, $sweep);

            return;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->commitChunk($run, $token, $rules, $state, $sweep, $key, $result, $durationMs);
    }

    private function commitChunk(DbScanMarketRun $run, string $token, RuleSet $rules, array &$state, ?DbScanSweep $sweep, string $key, ChunkResult $result, int $durationMs): void
    {
        $surface = $this->registry->get($key);

        DB::transaction(function () use ($run, $token, $rules, &$state, $sweep, $key, $result, $durationMs, $surface) {
            $locked = $this->lockOwned($run, $token);
            $s = &$state['surfaces'][$key];

            $chunkKey = hash('sha256', implode('|', [$run->id, $key, $surface->adapterVersion, $result->rangeStart, $result->rangeEnd, $rules->hash()]));
            $inserted = DB::table('db_scan_chunks')->insertOrIgnore([
                'chunk_key' => $chunkKey,
                'run_id' => $run->id,
                'surface_key' => $key,
                'adapter_version' => $surface->adapterVersion,
                'range_start' => (string) $result->rangeStart,
                'range_end' => (string) $result->rangeEnd,
                'rows' => $result->rows,
                'candidates' => $result->candidates,
                'bytes' => $result->bytes,
                'committed_at' => now(),
            ]);

            $stats = ['new' => 0, 'reopened' => 0, 'capped' => []];
            if ($inserted) {
                $counters = (array) ($locked->metrics['counters'] ?? []);
                $stats = $this->recorder->record($result->hits, $locked, $rules, $counters);
                $metrics = (array) ($locked->metrics ?? []);
                $metrics['counters'] = $counters;
                $metrics['findings_new'] = (int) ($metrics['findings_new'] ?? 0) + $stats['new'] + $stats['reopened'];
                $metrics['rows_read'] = (int) ($metrics['rows_read'] ?? 0) + $result->rows;
                $metrics['candidates'] = (int) ($metrics['candidates'] ?? 0) + $result->candidates;
                $metrics['bytes_read'] = (int) ($metrics['bytes_read'] ?? 0) + $result->bytes;
                $metrics['chunks'] = (int) ($metrics['chunks'] ?? 0) + 1;
                $locked->metrics = $metrics;

                foreach (['rows' => $result->rows, 'candidates' => $result->candidates, 'bytes' => $result->bytes, 'truncated' => $result->truncated, 'excluded' => $result->excluded, 'decode_capped' => $result->decodeCapped, 'matcher_errors' => $result->matcherErrors] as $counter => $value) {
                    $s[$counter] = (int) ($s[$counter] ?? 0) + $value;
                }
                foreach ($stats['capped'] as $rule => $n) {
                    $this->bumpFlag($state, $rule, 'match_cap', $n);
                }
                if ($result->matcherErrors > 0) {
                    foreach ($rules->rulesForSurface($key) as $rule) {
                        $this->bumpFlag($state, $rule, 'matcher_error:'.$key, $result->matcherErrors);
                    }
                }
                $state['acc'] = Accumulator::merge((array) ($state['acc'] ?? []), $result->accumulator->buckets);
            }

            $s['cursor'] = $result->rangeEnd;
            $s['timeouts'] = 0;
            $s['chunks'] = (int) ($s['chunks'] ?? 0) + 1;
            if ($result->exhausted) {
                $s['status'] = 'complete';
                $s['completed_at'] = now()->toIso8601String();
            }
            $this->advanceRotation($state, $key, $result->exhausted);

            $locked->cursor = $state;
            $locked->heartbeat_at = now();
            $locked->save();

            $this->syncSweep($sweep, $state);

            $existing = DbScanSurfaceCoverage::query()->where('market_run_id', $run->id)->where('surface_key', $key)->first();
            $this->upsertSurfaceCoverage($locked, $key, [
                'status' => $s['status'] === 'pending' ? 'incomplete' : $s['status'],
                'reason' => $s['status'] === 'pending' ? 'in_progress' : ($s['reason'] ?? null),
                'rows_scanned' => (int) ($existing?->rows_scanned ?? 0) + ($inserted ? $result->rows : 0),
                'candidates' => (int) ($existing?->candidates ?? 0) + ($inserted ? $result->candidates : 0),
                'bytes_read' => (int) ($existing?->bytes_read ?? 0) + ($inserted ? $result->bytes : 0),
                'values_truncated' => (int) ($existing?->values_truncated ?? 0) + ($inserted ? $result->truncated : 0),
                'decode_capped' => (int) ($existing?->decode_capped ?? 0) + ($inserted ? $result->decodeCapped : 0),
                'excluded_values' => (int) ($existing?->excluded_values ?? 0) + ($inserted ? $result->excluded : 0),
                'cursor_start' => $existing?->cursor_start ?? (string) $result->rangeStart,
                'cursor_end' => (string) $result->rangeEnd,
                'high_water' => (string) $s['high_water'],
                'completed_at' => $s['status'] === 'complete' ? now() : null,
            ]);

            $this->log->log($locked, 'info', sprintf(
                'chunk ids %d–%d rows %d candidates %d%s · %d ms',
                $result->rangeStart + 1,
                $result->rangeEnd,
                $result->rows,
                $result->candidates,
                $result->truncated ? ' truncated '.$result->truncated : '',
                $durationMs
            ), ['duration_ms' => $durationMs, 'excluded' => $result->excluded], null, $key);
            foreach (array_slice($result->hits, 0, 10) as $hit) {
                $this->log->log($locked, 'finding', $hit->title.' · '.($hit->subject['table'] ?? '').' row '.($hit->subject['row_id'] ?? ''), [], $hit->ruleKey, $key);
            }
        });
    }

    /**
     * Persist traversal state (and its sweep hand-off) under ownership.
     */
    public function commitState(DbScanMarketRun $run, string $token, array $state, ?DbScanSweep $sweep): void
    {
        DB::transaction(function () use ($run, $token, $state, $sweep) {
            $locked = $this->lockOwned($run, $token);
            $locked->cursor = $state;
            $locked->heartbeat_at = now();
            $locked->save();
            $this->syncSweep($sweep, $state);
        });
    }

    private function syncSweep(?DbScanSweep $sweep, array $state): void
    {
        if (! $sweep) {
            return;
        }
        DbScanSweep::query()->whereKey($sweep->id)->update([
            'cursors' => json_encode($state),
            'newest_observed_at' => now(),
            'oldest_observed_at' => $sweep->oldest_observed_at ?? now(),
            'updated_at' => now(),
        ]);
        if (! $sweep->oldest_observed_at) {
            $sweep->oldest_observed_at = now();
        }
    }

    public function lockOwned(DbScanMarketRun $run, string $token): DbScanMarketRun
    {
        $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
        if (! $locked || $locked->owner_token !== $token || $locked->status !== 'running') {
            throw new LeaseLostException;
        }
        if (! $this->admission->renew($locked, $token)) {
            throw new LeaseLostException;
        }

        return $locked;
    }

    private function control(DbScanMarketRun $run, string $token, float $sliceStarted, float &$lastControl, float &$lastRenew): void
    {
        $now = microtime(true);
        if ($now - $sliceStarted > self::HARD_SLICE_SECONDS) {
            $this->abort = 'slice_hard';
            throw new ReaderException(ReaderException::CONTROL_ABORT);
        }

        if ($now - $lastControl >= 2) {
            $lastControl = $now;
            $row = DbScanMarketRun::query()->whereKey($run->id)->first(['id', 'control', 'owner_token', 'status']);
            if (! $row || $row->owner_token !== $token || $row->status !== 'running') {
                $this->abort = 'lease_lost';
                throw new ReaderException(ReaderException::CONTROL_ABORT);
            }
            if ($row->control === 'stop') {
                $this->abort = 'stop';
                throw new ReaderException(ReaderException::CONTROL_ABORT);
            }
            if ($row->control === 'pause') {
                $this->abort = 'pause';
                throw new ReaderException(ReaderException::CONTROL_ABORT);
            }
            $settings = $this->settings->row(true);
            if ($settings->emergency_stop) {
                $this->abort = 'stop';
                throw new ReaderException(ReaderException::CONTROL_ABORT);
            }
            if (! $settings->enabled || $settings->paused || ! $this->settings->deploymentEnabled()) {
                $this->abort = 'global_pause';
                throw new ReaderException(ReaderException::CONTROL_ABORT);
            }
            // An in-flight query retains its normal statement timeout.
            if (($override = LoadOverride::forRun($run)) !== null && ($reason = $this->gate->loadReason($override))) {
                $this->abort = 'gate:'.$reason;
                throw new ReaderException(ReaderException::CONTROL_ABORT);
            }
        }

        if ($now - $lastRenew >= 30) {
            $lastRenew = $now;
            if (! $this->admission->renew($run, $token)) {
                $this->abort = 'lease_lost';
                throw new ReaderException(ReaderException::CONTROL_ABORT);
            }
            DbScanMarketRun::query()->whereKey($run->id)->where('owner_token', $token)->update(['heartbeat_at' => now()]);
        }
    }

    private function endSlice(DbScanMarketRun $run, string $token, string $outcome, float $elapsed, array $metrics, ?ReaderException $error, ?DbScanSweep $sweep): string
    {
        if ($outcome === 'lease_lost') {
            // Someone else owns the run now; only drop what we may still hold.
            $this->admission->releaseSlots($run, $token);
            $this->log->log($run, 'warn', 'Slice lost its lease; another owner continues the run.');

            return 'lease_lost';
        }

        $result = DB::transaction(function () use ($run, $token, $outcome, $elapsed, $metrics, $error, $sweep) {
            $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || $locked->owner_token !== $token) {
                $this->admission->releaseSlots($run, $token);

                return 'lease_lost';
            }

            $locked->active_seconds = (float) $locked->active_seconds + $elapsed;
            $merged = (array) ($locked->metrics ?? []);
            $merged['queries'] = (int) ($merged['queries'] ?? 0) + (int) ($metrics['queries'] ?? 0);
            $merged['timeouts'] = (int) ($merged['timeouts'] ?? 0) + (int) ($metrics['timeouts'] ?? 0);
            $merged['p95_ms'] = max((float) ($merged['p95_ms'] ?? 0), (float) ($metrics['p95_ms'] ?? 0));
            $merged['duration_ms'] = (int) round($locked->active_seconds * 1000);
            $merged['slices'] = (int) ($merged['slices'] ?? 0) + 1;
            $locked->metrics = $merged;
            if (! empty($locked->cursor['engine'])) {
                $locked->db_engine = mb_substr((string) $locked->cursor['engine'], 0, 80);
            }
            $locked->owner_token = null;
            $locked->save();

            $this->chargeDailyBudget((int) $locked->platform_id, $elapsed);
            $this->admission->releaseSlots($locked, $token);

            $generation = (int) $locked->generation + 1;

            if ($outcome === 'yield' || $outcome === 'slice_hard') {
                if ($outcome === 'slice_hard' && $this->currentSurface) {
                    $this->recordAbandon($locked, $this->currentSurface, $sweep);
                }
                $locked->forceFill(['status' => 'queued', 'generation' => $generation, 'control' => null])->save();
                $this->outbox->enqueue($locked->id, $generation);

                return 'yield';
            }

            if ($outcome === 'pause' || $outcome === 'global_pause') {
                return $this->terminator->pauseLocked($locked, $outcome === 'pause' ? 'manual' : 'operator_global');
            }

            if (str_starts_with($outcome, 'gate:')) {
                $reason = substr($outcome, 5);
                $this->log->log($locked, 'warn', ScannerGate::describe($reason), ['gate' => $reason]);

                return $this->terminator->pauseLocked($locked, $reason === 'override_expired' ? $reason : 'load');
            }

            if ($outcome === 'stop') {
                return $this->terminator->terminateLocked($locked, 'stopped', 'stopped_by_operator');
            }

            if ($outcome === 'budget') {
                $this->log->log($locked, 'info', 'Run budget reached; the sweep continues in a new bounded run.');

                return $this->terminator->terminateLocked($locked, 'partial', 'budget');
            }

            if (str_starts_with($outcome, 'done:')) {
                return $this->terminator->terminateLocked($locked, substr($outcome, 5), null);
            }

            if ($outcome === 'reader_error' && $error) {
                return $this->readerFailure($locked, $error, $generation);
            }

            if ($outcome === 'internal_error') {
                return $this->terminator->terminateLocked($locked, 'failed', 'internal_error');
            }

            return $this->terminator->terminateLocked($locked, 'failed', 'unknown_outcome');
        });

        $this->outbox->publishPending();
        $this->passStatus->refresh((int) $run->pass_id);

        return $result;
    }

    private function readerFailure(DbScanMarketRun $locked, ReaderException $error, int $generation): string
    {
        $this->log->log($locked, 'error', $error->getMessage(), ['code' => $error->errorCode]);

        if (! $error->isTransient()) {
            $status = in_array($error->errorCode, [ReaderException::ACCESS_DENIED, ReaderException::CONNECT_FAILED, ReaderException::TLS_REQUIRED], true) ? 'unreachable' : 'failed';

            return $this->terminator->terminateLocked($locked, $status, $error->errorCode);
        }

        $failures = (int) $locked->transient_failures + 1;
        $max = (int) config('db_scanner.envelope.max_transient_failures', 3);
        if ($failures >= $max) {
            $locked->forceFill(['transient_failures' => $failures])->save();
            $status = $error->errorCode === ReaderException::CONNECT_FAILED ? 'unreachable' : 'failed';

            return $this->terminator->terminateLocked($locked, $status, $error->errorCode);
        }

        $backoff = (array) config('db_scanner.envelope.transient_backoff_seconds', [15, 60, 300]);
        $next = now()->addSeconds((int) ($backoff[$failures - 1] ?? end($backoff)));
        $locked->forceFill([
            'status' => 'queued',
            'generation' => $generation,
            'transient_failures' => $failures,
            'next_attempt_at' => $next,
            'error_code' => $error->errorCode,
        ])->save();
        $this->outbox->enqueue($locked->id, $generation, $next);

        return 'retry';
    }

    private function blocked(DbScanMarketRun $run, string $reason): string
    {
        $this->log->log($run, 'warn', ScannerGate::describe($reason), ['gate' => $reason]);

        if ($reason === 'emergency_stop') {
            $this->terminator->terminate($run, 'stopped', 'emergency_stop');

            return 'stopped';
        }
        if ($reason === 'credentials') {
            $this->terminator->terminate($run, 'failed', 'credentials');

            return 'failed';
        }
        if (in_array($reason, ['scanner_off', 'paused_global'], true)) {
            $this->terminator->pause($run, 'operator_global');

            return 'paused';
        }

        $this->terminator->pause($run, $reason === 'override_expired' ? $reason : (in_array($reason, ['health', 'health_stale'], true) ? 'health' : 'load'));

        return 'paused';
    }

    private function unsupported(DbScanMarketRun $run, string $token, array &$state, SchemaInfo $schema, ?DbScanSweep $sweep): string
    {
        $reason = (string) $schema->unsupportedReason();
        foreach ($state['surfaces'] as $key => $s) {
            $state['surfaces'][$key]['status'] = 'incomplete';
            $state['surfaces'][$key]['reason'] = 'unsupported_schema';
        }
        foreach (array_keys((array) $state['inventory']['surfaces']) as $key) {
            $state['inventory']['surfaces'][$key] = ['status' => 'incomplete', 'reason' => 'unsupported_schema'];
        }
        $state['inventory']['done'] = true;
        $state['unsupported'] = $reason;
        $this->commitState($run, $token, $state, $sweep);
        $this->log->log($run, 'error', $reason === 'multisite_unsupported'
            ? 'Multisite tables found; phase 1 scans single-site schemas only, so every surface is recorded incomplete.'
            : 'Core WordPress tables are missing for the configured prefix; check the prefix. Every surface is recorded incomplete.', $schema->summary());

        return 'done:completed_with_gaps';
    }

    private function nextStep(array &$state): ?string
    {
        if (! ($state['inventory']['done'] ?? false)) {
            return '__inventory';
        }

        $order = (array) ($state['order'] ?? []);
        $n = count($order);
        for ($i = 0; $i < $n; $i++) {
            $index = ((int) ($state['rotation'] ?? 0) + $i) % $n;
            $key = $order[$index];
            if (($state['surfaces'][$key]['status'] ?? null) === 'pending') {
                $state['rotation'] = $index;

                return $key;
            }
        }

        return null;
    }

    /**
     * Weighted round robin: a surface gets `weight` consecutive chunks, then
     * every other eligible surface advances before it returns.
     */
    private function advanceRotation(array &$state, string $key, bool $finished): void
    {
        $surface = $this->registry->get($key);
        $weight = max(1, $surface?->weight ?? 1);
        $state['turns'][$key] = (int) ($state['turns'][$key] ?? 0) + 1;
        if ($finished || $state['turns'][$key] >= $weight) {
            $state['turns'][$key] = 0;
            $order = (array) ($state['order'] ?? []);
            $index = array_search($key, $order, true);
            $state['rotation'] = $index === false ? 0 : ($index + 1) % max(1, count($order));
        }
    }

    private function recordAbandon(DbScanMarketRun $locked, string $key, ?DbScanSweep $sweep): void
    {
        $state = (array) $locked->cursor;
        if (! isset($state['surfaces'][$key])) {
            return;
        }
        $s = &$state['surfaces'][$key];
        $s['abandons'] = (int) ($s['abandons'] ?? 0) + 1;
        $s['batch_rows'] = max(25, intdiv((int) $s['batch_rows'], 2));
        if ($s['abandons'] >= 4 && $s['batch_rows'] <= 25) {
            $s['status'] = 'incomplete';
            $s['reason'] = 'timeout';
        }
        $locked->cursor = $state;
        $locked->save();
        $this->syncSweep($sweep, $state);
    }

    private function bumpFlag(?array &$state, string $rule, string $flag, int $n): void
    {
        if ($state === null) {
            return;
        }
        $state['rule_flags'][$rule][$flag] = (int) ($state['rule_flags'][$rule][$flag] ?? 0) + $n;
    }

    private function chargeDailyBudget(int $platformId, float $seconds): void
    {
        $day = now()->utc()->toDateString();
        DB::table('db_scan_daily_budgets')->insertOrIgnore([
            'platform_id' => $platformId, 'day' => $day, 'active_seconds' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DbScanDailyBudget::query()->where('platform_id', $platformId)->where('day', $day)->increment('active_seconds', $seconds);
    }

    private function upsertSurfaceCoverage(DbScanMarketRun $run, string $key, array $values): void
    {
        $surface = $this->registry->get($key);
        DbScanSurfaceCoverage::query()->updateOrCreate(
            ['market_run_id' => $run->id, 'surface_key' => $key],
            $values + ['sweep_id' => $run->sweep_id, 'adapter_version' => $surface?->adapterVersion ?? '1']
        );
    }

    private function outsideWindow(DbScanMarketRun $run, Platform $platform): bool
    {
        $pass = $run->pass;
        if (! $pass || ! $pass->schedule_id || $pass->bypass_window) {
            return false;
        }
        $schedule = \App\Models\DbScanSchedule::query()->find($pass->schedule_id);
        $window = (array) ($schedule?->window ?? []);
        if (empty($window['start']) || empty($window['end'])) {
            return false;
        }

        return ! ScheduleWindow::isOpen($window, $platform->timezone ?: config('app.timezone', 'UTC'), now());
    }

    /**
     * Fresh traversal state for a run that has none (new sweep or test).
     */
    public function initialState(DbScanMarketRun $run, RuleSet $rules, ?SchemaInfo $schema = null): array
    {
        $active = $rules->activeSurfaces();
        $state = [
            'version' => 1,
            'inventory' => ['done' => false, 'surfaces' => []],
            'order' => [],
            'rotation' => 0,
            'surfaces' => [],
            'rule_flags' => [],
            'acc' => [],
            'turns' => [],
        ];

        foreach ($this->registry->inventorySurfaces($run->profile) as $key => $surface) {
            // Scans read every inventory surface in the profile so snapshots
            // stay complete for drift; tests read only what their rule needs.
            if ($run->mode !== 'test' || in_array($key, $active, true)) {
                $state['inventory']['surfaces'][$key] = ['status' => 'pending', 'reason' => null];
            }
        }

        $chunkRows = $this->settings->chunkRows();
        foreach ($this->registry->rowSurfaces($run->profile) as $key => $surface) {
            $hasRules = in_array($key, $active, true);
            $state['surfaces'][$key] = [
                'status' => $hasRules ? 'pending' : 'excluded',
                'reason' => $hasRules ? null : 'no_active_rules',
                'cursor' => 0,
                'high_water' => null,
                'batch_rows' => $chunkRows,
                'timeouts' => 0,
                'rows' => 0,
                'candidates' => 0,
                'bytes' => 0,
                'truncated' => 0,
                'excluded' => 0,
                'decode_capped' => 0,
            ];
            if ($hasRules) {
                $state['order'][] = $key;
            }
        }

        return $state;
    }
}
