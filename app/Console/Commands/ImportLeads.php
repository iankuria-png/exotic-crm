<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Services\LeadImportService;
use Illuminate\Console\Command;

class ImportLeads extends Command
{
    protected $signature = 'crm:import-leads
        {--platform= : Platform ID to import from}
        {--per-page=100 : Number of WP records to pull per page}
        {--dry-run : Calculate import stats without writing data}';

    protected $description = 'Import lead candidates from WordPress profiles with needs_payment=1';

    public function __construct(
        private readonly LeadImportService $leadImportService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $platformId = $this->option('platform');
        $perPage = (int) $this->option('per-page');
        $dryRun = (bool) $this->option('dry-run');

        $query = Platform::query()
            ->where('is_active', true)
            ->whereNotNull('wp_api_url');

        if ($platformId) {
            $query->where('id', (int) $platformId);
        }

        $platforms = $query->orderBy('id')->get();

        if ($platforms->isEmpty()) {
            $this->error('No eligible platforms found for lead import.');
            return self::FAILURE;
        }

        $totals = [
            'scanned' => 0,
            'eligible' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'unassigned' => 0,
            'errors' => 0,
        ];

        foreach ($platforms as $platform) {
            $this->info(sprintf('Importing leads for %s (ID %d)%s', $platform->name, $platform->id, $dryRun ? ' [DRY RUN]' : ''));

            $result = $this->leadImportService->importPlatform($platform, $dryRun, $perPage);

            $this->line(sprintf(
                '  scanned=%d eligible=%d created=%d updated=%d unchanged=%d unassigned=%d errors=%d',
                $result['scanned'],
                $result['eligible'],
                $result['created'],
                $result['updated'],
                $result['unchanged'],
                $result['unassigned'],
                count($result['errors'])
            ));

            foreach ($totals as $key => $value) {
                if ($key === 'errors') {
                    $totals[$key] += count($result['errors']);
                    continue;
                }
                $totals[$key] += $result[$key];
            }

            foreach ($result['errors'] as $error) {
                $this->warn('  - ' . $error);
            }
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Scanned', $totals['scanned']],
            ['Eligible (needs_payment=1)', $totals['eligible']],
            ['Created', $totals['created']],
            ['Updated', $totals['updated']],
            ['Unchanged', $totals['unchanged']],
            ['Unassigned', $totals['unassigned']],
            ['Errors', $totals['errors']],
        ]);

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

