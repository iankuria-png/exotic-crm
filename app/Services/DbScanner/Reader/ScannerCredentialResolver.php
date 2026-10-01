<?php

namespace App\Services\DbScanner\Reader;

use App\Models\DbScanConnection;
use App\Services\DbScanner\ScannerSettings;

/**
 * Builds reader targets from provisioned scanner connections only.
 *
 * There is deliberately no fallback to `platforms.db_user/db_pass`: those are
 * the writable credentials payments and sync rely on, and the scanner must
 * never run on them.
 */
class ScannerCredentialResolver
{
    public function __construct(private readonly ScannerSettings $settings) {}

    public function forConnection(DbScanConnection $connection): ReaderTarget
    {
        $driver = $connection->driver === 'sqlite' ? 'sqlite' : 'mysql';

        return new ReaderTarget(
            platformId: (int) $connection->platform_id,
            driver: $driver,
            host: $connection->host,
            port: (int) ($connection->port ?: 3306),
            socket: $connection->socket ?: null,
            database: (string) $connection->database,
            username: (string) ($connection->username ?? ''),
            password: (string) ($connection->password ?? ''),
            prefix: (string) ($connection->prefix ?: 'wp_'),
            tlsMode: (string) ($connection->tls_mode ?: 'none'),
            tlsCa: $connection->tls_ca ?: null,
            hostGroup: (string) $connection->host_group,
            configVersion: (int) $connection->config_version,
            statementTimeoutSeconds: $this->settings->statementTimeout(),
            connectTimeoutSeconds: (int) config('db_scanner.envelope.connect_timeout_seconds', 5),
        );
    }

    /**
     * A normalized host-group key. Aliases of the local server collapse into
     * one group so two "different" markets on the same MySQL never run at once.
     */
    public static function normalizeHostGroup(?string $requested, ?string $host, ?string $socket): string
    {
        $requested = strtolower(trim((string) $requested));
        if ($requested !== '') {
            return preg_replace('/[^a-z0-9._:-]/', '-', $requested) ?: 'unknown';
        }

        $host = strtolower(trim((string) $host));
        if ($socket || in_array($host, ['localhost', '127.0.0.1', '::1', ''], true)) {
            return 'local';
        }

        return preg_replace('/[^a-z0-9._:-]/', '-', $host) ?: 'unknown';
    }
}
