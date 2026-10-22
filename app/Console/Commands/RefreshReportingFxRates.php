<?php

namespace App\Console\Commands;

use App\Services\ReportingFxRefreshService;
use Illuminate\Console\Command;

class RefreshReportingFxRates extends Command
{
    protected $signature = 'crm:refresh-fx-rates {--date= : Last closed UTC day to fetch (defaults to yesterday)} {--dry-run : List the days that would be fetched without calling CurrencyAPI}';

    protected $description = 'Fetch daily closing FX rates for every market currency and record refresh health';

    public function handle(ReportingFxRefreshService $refreshService): int
    {
        $date = $this->option('date');
        $result = $refreshService->refresh(is_string($date) && $date !== '' ? $date : null, (bool) $this->option('dry-run'));

        $this->line(sprintf(
            '%s: %d currencies, days requested [%s], fetched [%s], %d rows written%s',
            strtoupper($result['status']),
            count($result['currencies']),
            implode(', ', $result['dates_requested']),
            implode(', ', $result['dates_fetched']),
            $result['rows_written'],
            $result['dry_run'] ? ' (dry run)' : ''
        ));
        foreach ($result['issues'] as $issue) {
            $this->warn($issue);
        }

        return in_array($result['status'], ['ok', 'skipped'], true) ? self::SUCCESS : self::FAILURE;
    }
}
