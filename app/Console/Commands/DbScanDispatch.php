<?php

namespace App\Console\Commands;

use App\Services\DbScanner\Engine\ScheduleDispatcher;
use Illuminate\Console\Command;

class DbScanDispatch extends Command
{
    protected $signature = 'crm:db-scan-dispatch';

    protected $description = 'Database Observatory minute dispatcher: outbox, schedules, continuations and auto-resume (CRM-only, bounded).';

    public function handle(ScheduleDispatcher $dispatcher): int
    {
        $summary = $dispatcher->tick();
        $this->line(collect($summary)->map(fn ($v, $k) => $k.'='.$v)->implode(' '));

        return self::SUCCESS;
    }
}
