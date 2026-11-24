<?php

namespace App\Services\Ewms;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EwmsEvidenceConnection
{
    private ?Connection $connection = null;

    public function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $config = DB::connection()->getConfig();
        if (! in_array($config['driver'] ?? null, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported evidence database driver.');
        }
        // Read the primary, not a potentially stale replica. Never mutate the
        // application's shared connection, credentials or global session clock.
        unset($config['url'], $config['read'], $config['write'], $config['name']);
        $config['timezone'] = '+00:00';
        $connection = DB::connectUsing('ewms_evidence', $config, true);
        if ($connection->getDriverName() === 'mysql') {
            if (($connection->selectOne('SELECT @@session.time_zone AS timezone')->timezone ?? null) !== '+00:00') {
                throw new RuntimeException('Evidence database session must be UTC.');
            }
            $connection->statement('SET SESSION TRANSACTION READ ONLY');
        } else {
            // Tests use a disposable on-disk SQLite fixture so both connections
            // see the same schema; the source connection remains write-capable.
            $connection->statement('PRAGMA query_only = ON');
        }

        return $this->connection = $connection;
    }
}
