<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSubsidiaryProfileCopy;
use App\Models\SubsidiaryProfileCopyRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Re-queues copy runs whose worker stopped mid-slice. The run lease and item ledgers keep this idempotent. */
class ResumeStalledSubsidiaryProfileCopies extends Command
{
    protected $signature = 'crm:resume-stalled-profile-copies';

    protected $description = 'Re-queue paired-market profile copies whose worker lease expired';

    public function handle(): int
    {
        if (! config('subsidiary_trials.profile_copy_enabled') || ! Schema::hasTable('subsidiary_profile_copy_runs')) {
            return self::SUCCESS;
        }
        $count = 0;
        SubsidiaryProfileCopyRun::whereIn('state', ['copying', 'verifying'])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->where('updated_at', '<', now()->subMinutes(2))
            ->orderBy('updated_at')->limit(50)->pluck('id')
            ->each(function (string $id) use (&$count) {
                ProcessSubsidiaryProfileCopy::dispatch($id);
                $count++;
            });
        $this->info("Re-queued {$count} profile copies.");

        return self::SUCCESS;
    }
}
