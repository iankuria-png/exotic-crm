<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $this->pruneDatabaseObservatory($chunk, $maxBatches, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Database Observatory retention: run events and chunk ledgers 30 days,
     * observations of findings resolved more than 180 days ago, governance
     * audit 365 days. Active or suppressed finding evidence is never pruned.
     */
    private function pruneDatabaseObservatory(int $chunk, int $maxBatches, bool $dryRun): void
    {
        if (! Schema::hasTable('db_scan_events')) {
            return;
        }

        $retention = (array) config('db_scanner.retention', []);
        $this->prune('db_scan_events', 'at', Carbon::now()->subDays((int) ($retention['event_days'] ?? 30)), $chunk, $maxBatches, $dryRun, 'scanner run events');
        $this->prune('db_scan_chunks', 'committed_at', Carbon::now()->subDays((int) ($retention['event_days'] ?? 30)), $chunk, $maxBatches, $dryRun, 'scanner chunk ledger rows');
        $summaryCutoff = Carbon::now()->subDays((int) ($retention['run_summary_days'] ?? 180));
        $this->prune('db_scan_rule_coverage', 'created_at', $summaryCutoff, $chunk, $maxBatches, $dryRun, 'scanner rule coverage rows');
        $this->prune('db_scan_surface_coverage', 'created_at', $summaryCutoff, $chunk, $maxBatches, $dryRun, 'scanner surface coverage rows');
        $this->prune('db_scan_audit_events', 'created_at', Carbon::now()->subDays((int) ($retention['audit_days'] ?? 365)), $chunk, $maxBatches, $dryRun, 'scanner audit events');

        $cutoff = Carbon::now()->subDays((int) ($retention['resolved_evidence_days'] ?? 180));
        $resolved = DB::table('db_scan_findings')->where('status', 'resolved')->where('resolved_at', '<', $cutoff)->pluck('id')->all();
        if ($resolved === []) {
            return;
        }

        $candidates = DB::table('db_scan_observations')->whereIn('finding_id', $resolved)->where('created_at', '<', $cutoff)->count();
        if ($dryRun) {
            $this->info(sprintf('Would delete %d scanner observations of findings resolved before %s.', $candidates, $cutoff->toDateTimeString()));

            return;
        }

        $deleted = 0;
        foreach (array_chunk($resolved, 500) as $ids) {
            $deleted += DB::table('db_scan_observations')->whereIn('finding_id', $ids)->where('created_at', '<', $cutoff)->delete();
        }
        $this->info(sprintf('Deleted %d scanner observations of findings resolved before %s.', $deleted, $cutoff->toDateTimeString()));
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
