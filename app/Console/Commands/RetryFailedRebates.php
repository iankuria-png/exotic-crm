<?php

namespace App\Console\Commands;

use App\Models\WalletRebate;
use App\Services\Rebates\RebateGrantService;
use Illuminate\Console\Command;

class RetryFailedRebates extends Command
{
    protected $signature = 'rebates:retry {--market=} {--limit=100}';

    protected $description = 'Retry failed wallet rebate grants without replaying their base payments';

    public function handle(RebateGrantService $service): int
    {
        $rows = WalletRebate::where('status', 'failed')->when($this->option('market'), fn ($q) => $q->where('platform_id', (int) $this->option('market')))->with('payment')->oldest('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->get();
        $failed = 0;
        foreach ($rows as $row) {
            $result = $row->payment ? $service->grantFor($row->payment) : null;
            if (! $result || $result->status === 'failed') {
                $failed++;
            }
        }
        $this->info(sprintf('Processed %d rebate decisions; %d remain failed.', $rows->count(), $failed));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
