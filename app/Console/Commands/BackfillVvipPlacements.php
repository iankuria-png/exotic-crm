<?php

namespace App\Console\Commands;

use App\Services\VvipPlacementBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One-time: give CRM-sold VVIP profiles the WordPress homepage campaign that
 * makes the site treat them as VVIP (carousel slot, VVIP section, badge).
 *
 * Dry-run by default. --apply first writes a backup of every profile's
 * current campaign state, then applies, then verifies each profile now reads
 * as VVIP. --revert=<backup.json> restores that state (also dry-run unless
 * --apply). Needs exotic-crm-sync 1.3.19 on the market.
 */
class BackfillVvipPlacements extends Command
{
    protected $signature = 'crm:backfill-vvip-placements
        {--apply : Write changes. Without this flag the command only reports}
        {--platform= : Restrict to a single platform id}
        {--client= : One CRM client id}
        {--limit=500 : Maximum number of profiles}
        {--revert= : Path to a backup file written by an earlier --apply run}';

    protected $description = 'Give active CRM VVIP profiles their WordPress homepage campaign (one-time backfill).';

    public function handle(VvipPlacementBackfillService $backfill): int
    {
        $apply = (bool) $this->option('apply');

        if ($this->option('revert')) {
            return $this->revert($backfill, (string) $this->option('revert'), $apply);
        }

        $clients = $backfill->candidates(
            $this->option('platform') !== null ? (int) $this->option('platform') : null,
            $this->option('client') !== null ? (int) $this->option('client') : null,
            max(1, (int) $this->option('limit'))
        );

        $this->info(sprintf('VVIP placement backfill (%s): %d escort profile(s) with an active VVIP deal.', $apply ? 'LIVE' : 'DRY-RUN', $clients->count()));

        // Pass 1 is always a WordPress dry run: it shows what each market would
        // do and records the current campaign state that --apply backs up.
        $plan = [];
        foreach ($clients as $client) {
            $deal = $backfill->vvipDeal($client);
            if (! $deal) {
                continue;
            }

            try {
                $row = $backfill->place($client, $deal, true, $this->flagOnly());
            } catch (Throwable $e) {
                $row = ['market' => $client->platform?->name ?? $client->platform_id, 'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'name' => $client->name, 'action' => 'failed', 'reason' => $e->getMessage()];
            }

            $plan[] = ['client' => $client, 'deal' => $deal, 'row' => $row];
            $this->printRow($row);
        }

        $pending = array_values(array_filter($plan, fn ($item) => str_starts_with((string) $item['row']['action'], 'would_')));

        if ($this->flagOnly()) {
            // A market still on plugin 1.3.19 ignores flag_only and would
            // create campaign posts. Only rows whose dry run confirmed
            // flag-only handling may be applied.
            $outdated = array_filter($pending, fn ($item) => empty($item['row']['flag_only']));
            foreach ($outdated as $item) {
                $this->warn(sprintf('  [%s] WP #%d skipped: market needs exotic-crm-sync 1.3.20 for flag-only sync.', $item['row']['market'] ?? '-', (int) ($item['row']['wp_post_id'] ?? 0)));
            }
            $pending = array_values(array_filter($pending, fn ($item) => ! empty($item['row']['flag_only'])));
        }
        $this->summary(array_column($plan, 'row'));

        if (! $apply) {
            if ($pending !== []) {
                $this->warn(sprintf('Dry-run only. %d profile(s) would get a placement; re-run with --apply.', count($pending)));
            }

            return self::SUCCESS;
        }

        if ($pending === []) {
            $this->info('Nothing to apply.');

            return self::SUCCESS;
        }

        $backupPath = storage_path('app/vvip-placement-backfill/backup-'.now()->format('Ymd-His').'.json');
        $backup = ['created_at' => now()->toIso8601String(), 'rows' => array_map(fn ($item) => $item['row'], $pending)];
        File::ensureDirectoryExists(dirname($backupPath));
        File::put($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Backup written: {$backupPath}");

        $results = [];
        foreach ($pending as $item) {
            try {
                $row = $backfill->place($item['client'], $item['deal'], false, $this->flagOnly());
                $row['verified'] = (bool) ($row['after']['is_vvip'] ?? false);
            } catch (Throwable $e) {
                $row = array_merge($item['row'], ['action' => 'failed', 'reason' => $e->getMessage(), 'verified' => false]);
            }

            $results[] = $row;
            $this->printRow($row);
        }

        $backup['results'] = $results;
        File::put($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $verified = count(array_filter($results, fn ($row) => ! empty($row['verified'])));
        $this->info(sprintf('Applied and verified %d of %d. Undo with --revert=%s --apply', $verified, count($results), $backupPath));
        if ($verified < count($results)) {
            $this->warn('Some profiles did not verify as VVIP. They are safe to retry: a placed profile reports already_vvip.');
        }

        return self::SUCCESS;
    }

    /**
     * Set only the VVIP plan flag; never create or re-enable campaign posts.
     */
    protected function flagOnly(): bool
    {
        return false;
    }

    private function revert(VvipPlacementBackfillService $backfill, string $path, bool $apply): int
    {
        if (! File::exists($path)) {
            $this->error("Backup not found: {$path}");

            return self::FAILURE;
        }

        $rows = (array) (json_decode((string) File::get($path), true)['rows'] ?? []);
        $this->info(sprintf('Reverting %d profile(s) from %s (%s).', count($rows), $path, $apply ? 'LIVE' : 'DRY-RUN'));

        foreach ($rows as $row) {
            try {
                $response = $backfill->revert((int) $row['platform_id'], (int) $row['wp_post_id'], (array) ($row['before'] ?? []), ! $apply);
                $action = (string) ($response['action'] ?? 'unknown');
            } catch (Throwable $e) {
                $action = 'failed: '.$e->getMessage();
            }

            $this->line(sprintf('  [%s] WP #%d %s -> %s', $row['market'] ?? $row['platform_id'], $row['wp_post_id'], $row['name'] ?? '', $action));
        }

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to revert.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function printRow(array $row): void
    {
        $this->line(sprintf(
            '  [%s] client #%d WP #%d %s -> %s%s',
            $row['market'] ?? '-',
            (int) ($row['client_id'] ?? 0),
            (int) ($row['wp_post_id'] ?? 0),
            $row['name'] ?? '',
            $row['action'] ?? 'unknown',
            ! empty($row['reason']) ? ' ('.$row['reason'].')' : ''
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function summary(array $rows): void
    {
        $counts = array_count_values(array_map(fn ($row) => (string) ($row['action'] ?? 'unknown'), $rows));
        ksort($counts);
        $this->info('Summary: '.($counts === [] ? 'none' : implode(', ', array_map(fn ($action, $count) => "{$action}={$count}", array_keys($counts), $counts))));
    }
}
