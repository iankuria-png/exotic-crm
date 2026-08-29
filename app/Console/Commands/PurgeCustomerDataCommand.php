<?php

namespace App\Console\Commands;

use App\Models\CustomerActivityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Enforces the signed customer-data retention policy.
 *
 * Phase 2 owns activity events only (180 days). Notification and compare-set
 * retention join this command when those tables ship in Phases 5 and 3.
 */
class PurgeCustomerDataCommand extends Command
{
    protected $signature = 'crm:purge-customer-data
        {--chunk=1000 : Rows deleted per batch}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete customer activity events past the signed 180-day retention window.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays(CustomerActivityEvent::RETENTION_DAYS);

        $expired = CustomerActivityEvent::query()->where('occurred_at', '<', $cutoff);
        $total = (clone $expired)->count();

        if ($total === 0) {
            $this->info('No customer activity events past retention.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Would delete %d customer activity events older than %s.', $total, $cutoff->toDateString()));

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $batch = CustomerActivityEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('Deleted %d customer activity events older than %s.', $deleted, $cutoff->toDateString()));

        return self::SUCCESS;
    }
}
