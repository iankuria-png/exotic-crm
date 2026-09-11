<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PruneCrmHistory extends Command
{
    protected $signature = 'crm:prune-history
        {--history-days= : Days of client retention-insight history to retain}
        {--audit-days= : Days of audit logs to retain}
        {--chunk= : Rows deleted in each short transaction}
        {--max-batches= : Maximum batches per table; 0 processes the full backlog}
        {--dry-run : Report candidates without deleting rows}';

    protected $description = 'Prune expired CRM insight history and audit logs in bounded batches.';

    public function handle(): int
    {
        $historyDays = $this->positiveOption('history-days', 'crm_retention.client_retention_insight_history_days');
        $auditDays = $this->positiveOption('audit-days', 'crm_retention.audit_log_days');
        $chunk = $this->positiveOption('chunk', 'crm_retention.prune_chunk_size');
        $maxBatches = max(0, (int) ($this->option('max-batches') ?? config('crm_retention.prune_max_batches', 25)));
        $dryRun = (bool) $this->option('dry-run');

        $this->prune(
            'client_retention_insight_history',
            'recorded_date',
            Carbon::today()->subDays($historyDays),
            $chunk,
            $maxBatches,
            $dryRun,
            'client retention-insight history'
        );

        $this->prune(
            'audit_log',
            'created_at',
            Carbon::now()->subDays($auditDays),
            $chunk,
            $maxBatches,
            $dryRun,
            'audit logs'
        );

        return self::SUCCESS;
    }

    private function positiveOption(string $option, string $configKey): int
    {
        return max(1, (int) ($this->option($option) ?? config($configKey)));
    }

    private function prune(
        string $table,
        string $dateColumn,
        Carbon $cutoff,
        int $chunk,
        int $maxBatches,
        bool $dryRun,
        string $label
    ): void {
        // SQLite compares DATE and DATETIME strings lexically. A date-only
        // cutoff also expresses the intended whole-day history boundary.
        $cutoffValue = $dateColumn === 'recorded_date'
            ? $cutoff->toDateString()
            : $cutoff;
        $query = DB::table($table)->where($dateColumn, '<', $cutoffValue);
        $candidates = (clone $query)->count();

        if ($candidates === 0) {
            $this->info("No {$label} past retention.");

            return;
        }

        if ($dryRun) {
            $this->info(sprintf(
                'Would delete %d %s before %s.',
                $candidates,
                $label,
                $cutoff->toDateTimeString()
            ));

            return;
        }

        $deleted = 0;
        $batches = 0;

        do {
            $ids = (clone $query)
                ->orderBy($dateColumn)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
            $batches++;
        } while (($maxBatches === 0 || $batches < $maxBatches) && count($ids) === $chunk);

        $remaining = max(0, $candidates - $deleted);
        $suffix = $remaining > 0
            ? sprintf(' %d remain for a later run.', $remaining)
            : '';

        $this->info(sprintf(
            'Deleted %d %s before %s in %d batch(es).%s',
            $deleted,
            $label,
            $cutoff->toDateTimeString(),
            $batches,
            $suffix
        ));
    }
}
