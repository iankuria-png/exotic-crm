<?php

namespace App\Services\DbScanner\Reader;

use PDO;
use Throwable;

/**
 * The only component that touches a market database.
 *
 * - A private PDO connection: no Laravel connection manager, no transparent
 *   reconnect, no persistent connection, no emulated multi-statements.
 * - The session is put in READ ONLY with an engine-specific statement timeout
 *   and both are read back before the first data statement; any doubt fails
 *   closed. This is defence in depth: production also requires SELECT-only
 *   credentials, verified by preflight.
 * - Only CompiledQuery objects from QueryCompiler are executed.
 * - Losing the connection kills the reader. A retry must construct a new
 *   reader, which re-runs and re-verifies session setup.
 * - Before every statement the caller's control callback runs, so pause,
 *   stop, budget and lease checks happen between statements, never mid-way.
 */
class MarketDbReader
{
    private ?PDO $pdo = null;

    private bool $dead = false;

    private string $engine = '';

    private bool $mariadb = false;

    private QueryCompiler $compiler;

    /** @var callable|null */
    private $control = null;

    private array $metrics = [
        'queries' => 0,
        'rows' => 0,
        'bytes' => 0,
        'timeouts' => 0,
        'query_ms_total' => 0.0,
        'durations' => [],
    ];

    public function __construct(private readonly ReaderTarget $target)
    {
        $this->compiler = new QueryCompiler($target->driver === 'sqlite' ? 'sqlite' : 'mysql');
    }

    public function compiler(): QueryCompiler
    {
        return $this->compiler;
    }

    public function target(): ReaderTarget
    {
        return $this->target;
    }

    public function engine(): string
    {
        return $this->engine;
    }

    public function isMariaDb(): bool
    {
        return $this->mariadb;
    }

    public function setControl(?callable $control): void
    {
        $this->control = $control;
    }

    /**
     * Open and verify the session. Throws ReaderException on any failure and
     * leaves nothing open behind it.
     */
    public function open(): void
    {
        if ($this->dead) {
            throw new ReaderException(ReaderException::READER_CLOSED);
        }

        try {
            $this->pdo = $this->connect();
            $this->engine = (string) ($this->fetchScalar($this->compiler->version(), 'v') ?? '');
            $this->mariadb = stripos($this->engine, 'mariadb') !== false;
            $this->setupSession();
        } catch (Throwable $e) {
            $this->close();
            $this->dead = true;

            throw $e instanceof ReaderException ? $e : ReaderException::fromThrowable($e, ReaderException::CONNECT_FAILED);
        }
    }

    public function isOpen(): bool
    {
        return $this->pdo !== null && ! $this->dead;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function select(CompiledQuery $query): array
    {
        if ($this->pdo === null || $this->dead) {
            throw new ReaderException(ReaderException::READER_CLOSED);
        }

        if ($this->control) {
            ($this->control)();
        }

        $started = hrtime(true);
        try {
            $statement = $this->pdo->prepare($query->sql);
            foreach ($query->bindings as $index => $value) {
                $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
        } catch (Throwable $e) {
            $error = ReaderException::fromThrowable($e);
            $this->recordDuration($started);
            if ($error->errorCode === ReaderException::TIMEOUT) {
                $this->metrics['timeouts']++;
            }
            if ($error->errorCode === ReaderException::CONNECTION_LOST) {
                // Never reconnect in place: the session settings would be lost.
                $this->close();
                $this->dead = true;
            }

            throw $error;
        }

        $this->recordDuration($started);
        $this->metrics['rows'] += count($rows);
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value)) {
                    $this->metrics['bytes'] += strlen($value);
                }
            }
        }

        return $rows;
    }

    public function fetchScalar(CompiledQuery $query, string $column): mixed
    {
        $rows = $this->select($query);

        return $rows[0][$column] ?? null;
    }

    public function metrics(): array
    {
        $durations = $this->metrics['durations'];
        sort($durations);
        $p95 = $durations === [] ? 0 : $durations[(int) floor(0.95 * (count($durations) - 1))];

        return [
            'queries' => $this->metrics['queries'],
            'rows_returned' => $this->metrics['rows'],
            'bytes_read' => $this->metrics['bytes'],
            'timeouts' => $this->metrics['timeouts'],
            'query_ms_total' => round($this->metrics['query_ms_total'], 1),
            'p95_ms' => round($p95, 1),
        ];
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function connect(): PDO
    {
        $t = $this->target;

        if ($t->driver === 'sqlite') {
            if (! config('db_scanner.allow_sqlite_fixtures')) {
                throw new ReaderException(ReaderException::UNSUPPORTED_DRIVER);
            }

            $pdo = new PDO('sqlite:'.$t->database, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => $t->connectTimeoutSeconds,
            ]);
            // Fixture sessions are read-only at the engine level too.
            $pdo->exec('PRAGMA query_only = ON');

            return $pdo;
        }

        if ($t->driver !== 'mysql') {
            throw new ReaderException(ReaderException::UNSUPPORTED_DRIVER);
        }

        if (! $t->isLocal() && $t->tlsMode !== 'verify') {
            throw new ReaderException(ReaderException::TLS_REQUIRED);
        }

        $dsn = $t->socket
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $t->socket, $t->database)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $t->host, $t->port, $t->database);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $t->connectTimeoutSeconds,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }
        if (defined('PDO::MYSQL_ATTR_LOCAL_INFILE')) {
            $options[PDO::MYSQL_ATTR_LOCAL_INFILE] = false;
        }
        if ($t->tlsMode === 'verify') {
            if ($t->tlsCa) {
                $caPath = $this->writeCaBundle($t->tlsCa);
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        }

        try {
            return new PDO($dsn, $t->username, $t->password, $options);
        } catch (Throwable $e) {
            throw ReaderException::fromThrowable($e, ReaderException::CONNECT_FAILED);
        }
    }

    private function setupSession(): void
    {
        if ($this->target->driver === 'sqlite') {
            return;
        }

        $timeout = max(2, min(10, $this->target->statementTimeoutSeconds));

        try {
            $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
            if ($this->mariadb) {
                $this->pdo->exec('SET SESSION max_statement_time = '.(int) $timeout);
            } else {
                $this->pdo->exec('SET SESSION max_execution_time = '.(int) ($timeout * 1000));
            }
        } catch (Throwable) {
            throw new ReaderException(ReaderException::SESSION_SETUP_FAILED);
        }

        // Read the settings back; a session we cannot verify is not used.
        $readOnly = null;
        foreach (['transaction_read_only', 'tx_read_only'] as $variable) {
            try {
                $readOnly = $this->fetchScalar($this->compiler->readOnlyState($variable), 'ro');
                break;
            } catch (ReaderException $e) {
                if ($e->errorCode === ReaderException::CONNECTION_LOST) {
                    throw $e;
                }
            }
        }
        if ((string) $readOnly !== '1') {
            throw new ReaderException(ReaderException::SESSION_SETUP_FAILED);
        }

        $actual = (float) $this->fetchScalar($this->compiler->timeoutState($this->mariadb), 't');
        $expected = $this->mariadb ? (float) $timeout : (float) ($timeout * 1000);
        if (abs($actual - $expected) > 0.001) {
            throw new ReaderException(ReaderException::SESSION_SETUP_FAILED);
        }
    }

    private function writeCaBundle(string $pem): string
    {
        $dir = storage_path('app/db-scanner/ca');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/'.hash('sha256', $pem).'.pem';
        if (! is_file($path)) {
            file_put_contents($path, $pem);
            chmod($path, 0600);
        }

        return $path;
    }

    private function recordDuration(int $started): void
    {
        $ms = (hrtime(true) - $started) / 1e6;
        $this->metrics['queries']++;
        $this->metrics['query_ms_total'] += $ms;
        if (count($this->metrics['durations']) < 5000) {
            $this->metrics['durations'][] = $ms;
        }
    }
}
