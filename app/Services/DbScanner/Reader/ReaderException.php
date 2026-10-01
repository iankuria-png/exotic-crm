<?php

namespace App\Services\DbScanner\Reader;

use RuntimeException;
use Throwable;

/**
 * A typed, redacted reader failure.
 *
 * Driver messages can carry hostnames, usernames, SQL and bound values, so
 * they never leave this class: callers, logs, events and failed jobs only see
 * the code and a fixed human sentence.
 */
class ReaderException extends RuntimeException
{
    public const CONNECT_FAILED = 'connect_failed';

    public const ACCESS_DENIED = 'access_denied';

    public const CONNECTION_LOST = 'connection_lost';

    public const SESSION_SETUP_FAILED = 'session_setup_failed';

    public const TIMEOUT = 'statement_timeout';

    public const QUERY_FAILED = 'query_failed';

    public const REJECTED_TEMPLATE = 'rejected_template';

    public const READER_CLOSED = 'reader_closed';

    public const TLS_REQUIRED = 'tls_required';

    public const UNSUPPORTED_DRIVER = 'unsupported_driver';

    public const CONTROL_ABORT = 'control_abort';

    private const MESSAGES = [
        self::CONNECT_FAILED => 'Could not connect to the market database.',
        self::ACCESS_DENIED => 'The market database refused the scanner credentials.',
        self::CONNECTION_LOST => 'The market database connection was lost; the chunk was abandoned.',
        self::SESSION_SETUP_FAILED => 'The read-only session could not be verified, so the scanner did not read anything.',
        self::TIMEOUT => 'A scanner statement reached its time limit.',
        self::QUERY_FAILED => 'A scanner statement failed.',
        self::REJECTED_TEMPLATE => 'A scanner statement was rejected before reaching the database.',
        self::READER_CLOSED => 'The reader was already closed.',
        self::TLS_REQUIRED => 'Remote scanner connections require verified TLS or a local socket.',
        self::UNSUPPORTED_DRIVER => 'This database driver is not supported by the scanner.',
        self::CONTROL_ABORT => 'The scanner stopped reading because of a pause, stop or budget limit.',
    ];

    public function __construct(
        public readonly string $errorCode,
        ?Throwable $previous = null,
        public readonly ?int $driverCode = null,
    ) {
        parent::__construct(self::MESSAGES[$errorCode] ?? 'Scanner reader error.', 0, $previous);
    }

    public function isTransient(): bool
    {
        return in_array($this->errorCode, [self::CONNECT_FAILED, self::CONNECTION_LOST, self::QUERY_FAILED], true);
    }

    /**
     * Map a driver exception without ever reading its message into output.
     */
    public static function fromThrowable(Throwable $e, string $fallback = self::QUERY_FAILED): self
    {
        if ($e instanceof self) {
            return $e;
        }

        $driver = null;
        if ($e instanceof \PDOException) {
            $info = $e->errorInfo ?? null;
            $driver = is_array($info) && isset($info[1]) ? (int) $info[1] : null;
        }

        $code = match (true) {
            in_array($driver, [1045, 1044, 1142, 1143, 1227], true) => self::ACCESS_DENIED,
            in_array($driver, [3024, 1969, 1317], true) => self::TIMEOUT,
            in_array($driver, [2006, 2013, 2055], true) => self::CONNECTION_LOST,
            in_array($driver, [2002, 2003, 2005], true) => self::CONNECT_FAILED,
            default => $fallback,
        };

        // MySQL reports a lost server with SQLSTATE HY000 and no driver code in
        // some PDO builds; sniff the class of failure, never surface the text.
        if ($code === $fallback && $driver === null && $e instanceof \PDOException) {
            $text = strtolower($e->getMessage());
            if (str_contains($text, 'gone away') || str_contains($text, 'lost connection')) {
                $code = self::CONNECTION_LOST;
            } elseif (str_contains($text, 'max_statement_time') || str_contains($text, 'maximum statement execution time') || str_contains($text, 'interrupted')) {
                $code = self::TIMEOUT;
            }
        }

        return new self($code, null, $driver);
    }
}
