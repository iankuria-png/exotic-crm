<?php

namespace App\Console\Commands;

use App\Services\RenewalService;
use Illuminate\Console\Command;

class RunRenewals extends Command
{
    protected $signature = 'crm:run-renewals
        {--campaign= : Run a specific renewal campaign ID only}';

    protected $description = 'Execute enabled renewal campaigns and record run metrics';

    public function __construct(
        private readonly RenewalService $renewalService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $campaignId = $this->option('campaign') ? (int) $this->option('campaign') : null;

        $result = $this->renewalService->runCampaigns($campaignId, null);
        $campaigns = $result['campaigns'] ?? [];
        $totals = $result['totals'] ?? ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0];

        if (empty($campaigns)) {
            $this->warn('No enabled campaigns matched the run criteria.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($campaigns as $campaign) {
            $rows[] = [
                $campaign['campaign_id'],
                $campaign['trigger_days'],
                $campaign['total_targeted'],
                $campaign['sent_count'],
                $campaign['failed_count'],
                $campaign['skipped_count'],
                $campaign['status'],
            ];
        }

        $this->table(
            ['Campaign', 'Trigger Days', 'Targeted', 'Sent', 'Failed', 'Skipped', 'Status'],
            $rows
        );

        $this->info(sprintf(
            'Totals: targeted=%d sent=%d failed=%d skipped=%d',
            $totals['targeted'],
            $totals['sent'],
            $totals['failed'],
            $totals['skipped']
        ));

        return ($totals['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}

