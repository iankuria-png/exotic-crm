<?php

namespace App\Console\Commands;

use App\Models\ErrorLogGroup;
use App\Models\ErrorLogOccurrence;
use App\Services\ErrorLogRecorder;
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

        $overflowOccurrencesDeleted = 0;
        ErrorLogGroup::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(250, function ($groups) use (&$overflowOccurrencesDeleted): void {
                foreach ($groups as $group) {
                    $keepIds = ErrorLogOccurrence::query()
                        ->where('group_id', $group->id)
                        ->orderByDesc('occurred_at')
                        ->orderByDesc('id')
                        ->limit(ErrorLogRecorder::MAX_OCCURRENCES_PER_GROUP)
                        ->pluck('id');

                    if ($keepIds->isEmpty()) {
                        continue;
                    }

                    $overflowOccurrencesDeleted += ErrorLogOccurrence::query()
                        ->where('group_id', $group->id)
                        ->whereNotIn('id', $keepIds)
                        ->delete();
                }
            });

        $groupsDeleted = ErrorLogGroup::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', $resolvedCutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d expired occurrences, %d overflow occurrences, and %d resolved groups.',
            $occurrencesDeleted,
            $overflowOccurrencesDeleted,
            $groupsDeleted
        ));

        return self::SUCCESS;
    }
}
