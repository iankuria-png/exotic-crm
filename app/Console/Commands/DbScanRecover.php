<?php

namespace App\Console\Commands;

use App\Services\DbScanner\Engine\Recovery;
use Illuminate\Console\Command;

class DbScanRecover extends Command
{
    protected $signature = 'crm:db-scan-recover';

    protected $description = 'Database Observatory recovery: fence stale owners, re-publish lost slices, close deadlines and orphaned passes.';

    public function handle(Recovery $recovery): int
    {
        $summary = $recovery->sweep();
        $this->line(collect($summary)->map(fn ($v, $k) => $k.'='.$v)->implode(' '));

        return self::SUCCESS;
    }
}
