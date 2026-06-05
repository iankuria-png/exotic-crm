<?php

namespace App\Console\Commands;

use App\Services\AutoPush\AutoPushMaintenanceService;
use Illuminate\Console\Command;

class MaintainAutoPush extends Command
{
    protected $signature = 'crm:maintain-auto-push';

    protected $description = 'Replace failed auto-push items from reserve pools and surface alerts';

    public function __construct(
        private readonly AutoPushMaintenanceService $maintenanceService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $replacements = $this->maintenanceService->replaceFailedItems();
        $alerts = $this->maintenanceService->detectAndAlert();

        $this->info(sprintf(
            'Auto-push maintenance complete: %d replacement(s), %d alert event(s).',
            $replacements,
            $alerts
        ));

        return self::SUCCESS;
    }
}
