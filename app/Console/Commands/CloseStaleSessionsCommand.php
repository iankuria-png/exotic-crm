<?php

namespace App\Console\Commands;

use App\Services\TeamActivityService;
use Illuminate\Console\Command;

class CloseStaleSessionsCommand extends Command
{
    protected $signature = 'crm:close-stale-sessions';

    protected $description = 'Close CRM Team sessions whose heartbeats have gone stale.';

    public function handle(TeamActivityService $teamActivityService): int
    {
        $closed = $teamActivityService->closeStaleSessionsJob();

        $this->info(sprintf('Closed %d stale session(s).', $closed));

        return self::SUCCESS;
    }
}
