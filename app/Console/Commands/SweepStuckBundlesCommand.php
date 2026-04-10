<?php

namespace App\Console\Commands;

use App\Services\ManualPaymentBundleService;
use Illuminate\Console\Command;

class SweepStuckBundlesCommand extends Command
{
    protected $signature = 'crm:sweep-stuck-bundles {--minutes=10 : Minimum age in minutes before a committing bundle is swept}';

    protected $description = 'Marks stale shared manual payment bundles as compensation-failed after rollback attempts.';

    public function __construct(private readonly ManualPaymentBundleService $manualPaymentBundleService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $result = $this->manualPaymentBundleService->sweepStuckBundles($minutes);

        $this->info(sprintf(
            'Found %d stale bundle(s); swept %d.',
            (int) ($result['found'] ?? 0),
            (int) ($result['swept'] ?? 0)
        ));

        return self::SUCCESS;
    }
}
