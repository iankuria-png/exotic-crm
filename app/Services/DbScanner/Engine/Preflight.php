<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanConnection;
use App\Models\DbScanMarketRun;
use App\Models\DbScanPass;
use App\Models\Platform;
use App\Services\DbScanner\DbScanAuditWriter;
use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Reader\ReaderException;
use App\Services\DbScanner\Reader\ScannerCredentialResolver;
use App\Services\DbScanner\Surfaces\SchemaDiscovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Credential preflight: a bounded, metadata-only capability probe.
 *
 * Allowed while the scanner is off or globally paused (that is how the first
 * credential gets approved), but never during the emergency stop, and still
 * under fresh ops/market health and the same host/market leases as scans.
 * It reads identity, grants, server/session settings and schema names —
 * never application row values — and creates no findings.
 *
 * Proof is valid only for the exact connection config version it tested;
 * rotating credentials invalidates it.
 */
class Preflight
{
    private const FORBIDDEN_PRIVILEGES = [
        'ALL PRIVILEGES', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'FILE', 'EXECUTE',
        'SUPER', 'PROCESS', 'RELOAD', 'SHUTDOWN', 'TRIGGER', 'EVENT', 'CREATE TEMPORARY TABLES', 'LOCK TABLES',
        'REFERENCES', 'GRANT OPTION', 'CREATE ROUTINE', 'ALTER ROUTINE', 'CREATE VIEW', 'CREATE USER',
        'REPLICATION SLAVE', 'REPLICATION CLIENT', 'REPLICATION', 'BINLOG', 'CONNECTION ADMIN', 'SYSTEM_VARIABLES_ADMIN',
        'DELETE HISTORY', 'SET USER', 'FEDERATED ADMIN', 'READ_ONLY ADMIN', 'SLAVE MONITOR', 'BINLOG ADMIN',
    ];

    public function __construct(
        private readonly ScannerGate $gate,
        private readonly AdmissionService $admission,
        private readonly ScannerCredentialResolver $credentials,
        private readonly SchemaDiscovery $discovery,
        private readonly RunTerminator $terminator,
        private readonly RunLogger $log,
        private readonly DbScanAuditWriter $audit,
    ) {}

    /**
     * @return array{status: string, code: ?string, message: string, capabilities: array, run_id: ?int}
     */
    public function run(DbScanConnection $connection, ?int $actorId, ?array $loadOverride = null): array
    {
        $platform = Platform::query()->findOrFail($connection->platform_id);

        $reason = $this->gate->check($platform, $connection, 'preflight', $loadOverride);
        if ($reason !== null) {
            return ['status' => 'blocked', 'code' => $reason, 'message' => ScannerGate::describe($reason), 'capabilities' => [], 'run_id' => null, 'load' => $this->gate->loadStatus()];
        }

        $token = Str::random(40);
        $run = DB::transaction(function () use ($connection, $actorId, $token, $loadOverride) {
            if ($this->admission->marketBusy((int) $connection->platform_id)) {
                throw new MarketBusyException([(int) $connection->platform_id]);
            }
            $pass = DbScanPass::query()->create([
                'trigger' => 'manual', 'mode' => 'preflight', 'profile' => 'quick', 'triggered_by' => $actorId,
                'scope' => ['platform_ids' => [(int) $connection->platform_id], 'load_override' => $loadOverride], 'status' => 'running', 'started_at' => now(),
            ]);
            if ($loadOverride !== null) {
                $this->audit->record($actorId, 'pass', $pass->id, 'load_override_granted', null, $loadOverride, (int) $connection->platform_id);
            }
            $run = DbScanMarketRun::query()->create([
                'pass_id' => $pass->id, 'platform_id' => $connection->platform_id, 'mode' => 'preflight', 'profile' => 'quick',
                'status' => 'running', 'generation' => 1, 'owner_token' => $token, 'started_at' => now(), 'heartbeat_at' => now(),
                'connection_config_version' => $connection->config_version, 'budget_seconds' => 30,
                'deadline_at' => now()->addMinutes(5), 'metrics' => [],
            ]);
            $this->admission->claimMarket((int) $connection->platform_id, (int) $run->id);
            if (! $this->admission->acquireSlots($run, $token, (string) $connection->host_group)) {
                throw new HostBusyException;
            }

            return $run;
        });

        $reader = new MarketDbReader($this->credentials->forConnection($connection));
        $capabilities = [];
        $code = null;
        $message = 'Preflight passed: read-only session, schema-scoped SELECT grants and core tables verified.';

        $controlReason = null;
        if ($loadOverride !== null) {
            $reader->setControl(function () use ($platform, $connection, $loadOverride, &$controlReason): void {
                $controlReason = $this->gate->check($platform, $connection, 'preflight', $loadOverride);
                if ($controlReason !== null) {
                    throw new ReaderException(ReaderException::CONTROL_ABORT);
                }
            });
        }
        try {
            $reader->open();
            $capabilities['engine'] = mb_substr($reader->engine(), 0, 80);
            $capabilities['mariadb'] = $reader->isMariaDb();
            $capabilities['read_only_verified'] = true;
            $capabilities['statement_timeout_seconds'] = $reader->target()->statementTimeoutSeconds;
            $capabilities['tls'] = $reader->target()->tlsMode === 'verify' ? 'verified' : ($reader->target()->isLocal() ? 'local' : 'none');

            $database = (string) $reader->fetchScalar($reader->compiler()->databaseName(), 'db');
            $grants = array_map(fn ($row) => (string) array_values($row)[0], $reader->select($reader->compiler()->grants()));
            [$grantsOk, $grantProblem] = $this->evaluateGrants($grants, $database, $reader->compiler()->dialect());
            $capabilities['grants_ok'] = $grantsOk;
            $capabilities['grant_summary'] = array_map(fn ($g) => mb_substr(preg_replace('/IDENTIFIED BY .*/i', 'IDENTIFIED BY [redacted]', $g) ?? $g, 0, 200), array_slice($grants, 0, 10));

            $schema = $this->discovery->discover($reader, $reader->target()->prefix);
            $capabilities['schema'] = $schema->summary();
            $capabilities['trigger_visibility_exhaustive'] = $reader->compiler()->dialect() === 'sqlite';
            $capabilities['routine_visibility_exhaustive'] = false;

            if (! $grantsOk) {
                $code = 'grants_not_select_only';
                $message = 'Rejected: '.$grantProblem.' The scanner requires a dedicated account with SELECT on this schema only.';
            } elseif ($schema->unsupportedReason() === 'missing_core_tables') {
                $code = 'prefix_mismatch';
                $message = 'Rejected: core WordPress tables were not found for prefix '.$schema->prefix.'.'.($schema->prefixes ? ' Found prefixes: '.implode(', ', array_slice($schema->prefixes, 0, 5)).'.' : '');
            } elseif ($schema->unsupportedReason() === 'multisite_unsupported') {
                $code = 'multisite_unsupported';
                $message = 'Rejected: multisite tables found; phase 1 supports single-site schemas only.';
            }
        } catch (ReaderException $e) {
            $code = $controlReason ?? $e->errorCode;
            $message = $controlReason !== null ? ScannerGate::describe($controlReason) : $e->getMessage();
        } catch (\Throwable) {
            $code = 'internal_error';
            $message = 'Preflight failed unexpectedly; nothing was read beyond metadata.';
        } finally {
            $metrics = $reader->metrics();
            $reader->close();
        }

        $passed = $code === null;
        DB::transaction(function () use ($connection, $run, $passed, $code, $capabilities, $actorId, $message, $metrics) {
            $before = ['preflight_status' => $connection->preflight_status, 'preflight_config_version' => $connection->preflight_config_version];
            DbScanConnection::query()->whereKey($connection->id)->update([
                'preflight_status' => $passed ? 'passed' : 'failed',
                'preflight_at' => now(),
                'preflight_config_version' => $passed ? $connection->config_version : null,
                'preflight_error_code' => $code,
                'preflight_error' => $passed ? null : mb_substr($message, 0, 300),
                'capabilities' => json_encode($capabilities),
                'updated_at' => now(),
            ]);

            $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
            $locked->forceFill(['metrics' => $metrics, 'db_engine' => $capabilities['engine'] ?? null])->save();
            $this->log->log($locked, $passed ? 'info' : 'warn', $message, ['code' => $code]);
            $this->terminator->terminateLocked($locked, $passed ? 'completed' : 'failed', $code);

            $this->audit->record($actorId, 'connection', $connection->id, 'preflight', $before, [
                'preflight_status' => $passed ? 'passed' : 'failed', 'code' => $code, 'config_version' => $connection->config_version,
            ], (int) $connection->platform_id);
        });
        DbScanPass::query()->whereKey($run->pass_id)->update(['status' => $passed ? 'completed' : 'completed_with_errors', 'finished_at' => now()]);

        return ['status' => $passed ? 'passed' : 'failed', 'code' => $code, 'message' => $message, 'capabilities' => $capabilities, 'run_id' => (int) $run->id];
    }

    /**
     * Schema-scoped SELECT only. Global privileges, roles we cannot expand and
     * anything write- or execute-capable are rejected.
     *
     * @param  array<int, string>  $grants
     * @return array{0: bool, 1: ?string}
     */
    public function evaluateGrants(array $grants, string $database, string $dialect): array
    {
        if ($dialect === 'sqlite') {
            return [true, null];
        }
        if ($grants === []) {
            return [false, 'Grants could not be read, so least privilege cannot be verified.'];
        }

        $hasSchemaSelect = false;
        foreach ($grants as $grant) {
            $g = strtoupper(preg_replace('/\s+/', ' ', $grant) ?? $grant);
            if (! str_starts_with($g, 'GRANT ')) {
                continue;
            }
            if (! str_contains($g, ' ON ')) {
                return [false, 'A role grant was found; roles cannot be verified as SELECT-only.'];
            }
            $privileges = trim(substr($g, 6, strpos($g, ' ON ') - 6));
            $on = trim(substr($g, strpos($g, ' ON ') + 4));
            $on = trim(explode(' TO ', $on)[0]);

            foreach (self::FORBIDDEN_PRIVILEGES as $forbidden) {
                if (preg_match('/(^|,\s*)'.preg_quote($forbidden, '/').'(\s*\(|\s*,|$)/', $privileges)) {
                    return [false, 'The account has '.$forbidden.' privileges.'];
                }
            }
            if (str_contains($g, 'WITH GRANT OPTION')) {
                return [false, 'The account can grant privileges.'];
            }

            if ($privileges === 'USAGE') {
                continue;
            }
            if (str_starts_with($on, '*.*')) {
                return [false, 'The account has global privileges ('.$privileges.' ON *.*).'];
            }
            $schema = trim(explode('.', $on)[0], '`"\' ');
            if (strcasecmp(str_replace('\\_', '_', $schema), $database) !== 0) {
                return [false, 'The account has privileges on another schema ('.$schema.').'];
            }
            $remaining = array_diff(array_map('trim', explode(',', preg_replace('/\([^)]*\)/', '', $privileges) ?? $privileges)), ['SELECT', 'SHOW VIEW']);
            if ($remaining !== []) {
                return [false, 'Unexpected privileges: '.implode(', ', $remaining).'.'];
            }
            if (str_contains($privileges, 'SELECT')) {
                $hasSchemaSelect = true;
            }
        }

        return $hasSchemaSelect ? [true, null] : [false, 'No SELECT grant on this schema was found.'];
    }
}
