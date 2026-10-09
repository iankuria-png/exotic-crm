<?php

namespace App\Console\Commands;

use App\Models\DbContainmentBackup;
use App\Models\DbContainmentOperation;
use App\Services\DbContainment\BackupVault;
use App\Services\DbContainment\ContainmentException;
use App\Services\DbContainment\ContainmentService;
use Illuminate\Console\Command;

class DbContainmentTick extends Command
{
    protected $signature = 'crm:db-containment-tick';

    protected $description = 'Publish containment outbox, recover stalled operations and purge only eligible expired backups';

    public function handle(ContainmentService $service, BackupVault $vault): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('db_containment_operations')) {
            return self::SUCCESS;
        }
        $service->publish();
        DbContainmentOperation::query()->where('status', 'preview')->where('expires_at', '<', now())->update(['status' => 'expired']);
        DbContainmentOperation::query()->whereNull('backup_id')->whereIn('status', ['expired', 'cancelled', 'conflict'])->where('updated_at', '<', now()->subDays((int) config('db_containment.retention_days')))->update(['sealed_intent' => '', 'cache_requests' => null]);
        foreach (DbContainmentOperation::query()->whereIn('status', ['approved', 'waiting_for_scan', 'waiting_for_paused_scan', 'executing', 'commit_intent', 'outcome_unknown', 'committed', 'cache_pending', 'recovery_pending'])->where('updated_at', '<', now()->subSeconds(180))->limit(100)->get() as $op) {
            \App\Jobs\DbContainment\ContainmentJob::dispatch($op->id)->onQueue(config('db_containment.queue'));
        }
        foreach (DbContainmentBackup::query()->whereNull('purged_at')->where('expires_at', '<', now())->limit(100)->get() as $backup) {
            try {
                $vault->purge(DbContainmentOperation::query()->findOrFail($backup->operation_id));
            } catch (ContainmentException) { /* Recovery-pinned manifests are retained. */
            }
        }
        $this->info('Containment outbox/recovery/eligible retention processed.');

        return self::SUCCESS;
    }
}
