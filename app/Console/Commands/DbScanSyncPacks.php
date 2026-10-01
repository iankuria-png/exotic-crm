<?php

namespace App\Console\Commands;

use App\Services\DbScanner\PackSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class DbScanSyncPacks extends Command
{
    protected $signature = 'crm:db-scan-sync-packs {--dry-run : Validate the packs without writing}';

    protected $description = 'Validate and upsert the Database Observatory rule packs, lists and disabled seed schedules.';

    public function handle(PackSynchronizer $packs): int
    {
        try {
            $summary = $packs->sync((bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error('Pack sync blocked: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($summary['packs'] as $pack => $version) {
            $this->line(sprintf('  %-8s v%s', $pack, $version));
        }
        $this->info(sprintf(
            '%sRules: %d created, %d updated, %d new versions, %d retired · lists seeded %d · schedules seeded %d',
            ($summary['dry_run'] ?? false) ? '[dry run] ' : '',
            $summary['created'],
            $summary['updated'],
            $summary['versions'],
            $summary['retired'],
            $summary['lists_seeded'],
            $summary['schedules_seeded']
        ));

        return self::SUCCESS;
    }
}
