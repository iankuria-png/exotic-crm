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
        $walletAutoRenew = $campaignId === null
            ? $this->renewalService->runAutomatedRenewals()
            : [
                'total_targeted' => 0,
                'attempted_count' => 0,
                'succeeded_count' => 0,
                'failed_count' => 0,
                'fallback_count' => 0,
                'escalated_count' => 0,
                'skipped_count' => 0,
                'results' => [],
            ];

        $result = $this->renewalService->runCampaigns($campaignId, null);
        $campaigns = $result['campaigns'] ?? [];
        $totals = $result['totals'] ?? ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0];

        if (empty($campaigns) && (($walletAutoRenew['total_targeted'] ?? 0) === 0)) {
            $this->warn('No enabled campaigns matched the run criteria.');
            return self::SUCCESS;
        }

        if (($walletAutoRenew['total_targeted'] ?? 0) > 0) {
            $this->table(
                ['Auto-Renew Targeted', 'Attempted', 'Succeeded', 'Fallback Sent', 'Escalated', 'Skipped'],
                [[
                    $walletAutoRenew['total_targeted'] ?? 0,
                    $walletAutoRenew['attempted_count'] ?? 0,
                    $walletAutoRenew['succeeded_count'] ?? 0,
                    $walletAutoRenew['fallback_count'] ?? 0,
                    $walletAutoRenew['escalated_count'] ?? 0,
                    $walletAutoRenew['skipped_count'] ?? 0,
                ]]
            );
        }

        if (!empty($campaigns)) {
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
        }

        $this->info(sprintf(
            'Campaign totals: targeted=%d sent=%d failed=%d skipped=%d',
            $totals['targeted'],
            $totals['sent'],
            $totals['failed'],
            $totals['skipped']
        ));

        if (($walletAutoRenew['total_targeted'] ?? 0) > 0) {
            $this->info(sprintf(
                'Wallet auto-renew totals: targeted=%d succeeded=%d fallback_sent=%d escalated=%d skipped=%d',
                $walletAutoRenew['total_targeted'] ?? 0,
                $walletAutoRenew['succeeded_count'] ?? 0,
                $walletAutoRenew['fallback_count'] ?? 0,
                $walletAutoRenew['escalated_count'] ?? 0,
                $walletAutoRenew['skipped_count'] ?? 0
            ));
        }

        return (($totals['failed'] ?? 0) > 0 || ($walletAutoRenew['failed_count'] ?? 0) > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
