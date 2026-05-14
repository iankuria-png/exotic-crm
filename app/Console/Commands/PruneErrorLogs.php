<?php

namespace App\Console\Commands;

use App\Models\ErrorLogGroup;
use App\Models\ErrorLogOccurrence;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneErrorLogs extends Command
{
    protected $signature = 'crm:prune-error-logs {--occurrence-days=30} {--resolved-group-days=90}';

    protected $description = 'Prune old error log occurrences and resolved groups';

    public function handle(): int
    {
        $occurrenceDays = max(1, (int) $this->option('occurrence-days'));
        $resolvedDays = max(1, (int) $this->option('resolved-group-days'));

        $occurrenceCutoff = Carbon::now()->subDays($occurrenceDays);
        $resolvedCutoff = Carbon::now()->subDays($resolvedDays);

        $occurrencesDeleted = ErrorLogOccurrence::query()
            ->where('occurred_at', '<', $occurrenceCutoff)
            ->delete();

        $groupsDeleted = ErrorLogGroup::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', $resolvedCutoff)
            ->delete();

        $this->info("Pruned {$occurrencesDeleted} occurrences and {$groupsDeleted} resolved groups.");

        return self::SUCCESS;
    }
}
