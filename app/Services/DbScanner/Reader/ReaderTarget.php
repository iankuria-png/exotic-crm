<?php

namespace App\Services\DbScanner\Reader;

/**
 * Everything the reader needs to open one market session. Built only by
 * ScannerCredentialResolver from a provisioned db_scan_connections row, never
 * from the platform's own (writable) payment/sync credentials.
 */
final class ReaderTarget
{
    public function __construct(
        public readonly int $platformId,
        public readonly string $driver,
        public readonly ?string $host,
        public readonly int $port,
        public readonly ?string $socket,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
        public readonly string $prefix,
        public readonly string $tlsMode,
        public readonly ?string $tlsCa,
        public readonly string $hostGroup,
        public readonly int $configVersion,
        public readonly int $statementTimeoutSeconds,
        public readonly int $connectTimeoutSeconds,
    ) {}

    public function isLocal(): bool
    {
        if ($this->driver === 'sqlite') {
            return true;
        }

        if ($this->socket) {
            return true;
        }

        return in_array(strtolower((string) $this->host), ['localhost', '127.0.0.1', '::1'], true);
    }

    public function withStatementTimeout(int $seconds): self
    {
        return new self(
            $this->platformId, $this->driver, $this->host, $this->port, $this->socket, $this->database,
            $this->username, $this->password, $this->prefix, $this->tlsMode, $this->tlsCa, $this->hostGroup,
            $this->configVersion, $seconds, $this->connectTimeoutSeconds,
        );
    }
}
