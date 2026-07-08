<?php

namespace App\Console\Commands;

use App\Exceptions\PushCampaignActivationBlockedException;
use App\Models\PushCampaign;
use App\Services\PushCampaign\PushCampaignService;
use Illuminate\Console\Command;

class DispatchScheduledPushesCommand extends Command
{
    protected $signature = 'crm:dispatch-scheduled-pushes';

    protected $description = 'Activate scheduled push campaigns and queue upcoming campaign items.';

    public function handle(PushCampaignService $pushCampaignService): int
    {
        $activatedCampaigns = 0;
        $queuedItems = 0;
        $blockedCampaigns = 0;

        $dueCampaigns = PushCampaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($dueCampaigns as $campaign) {
            try {
                $pushCampaignService->activateDueScheduledCampaign($campaign, 0);
                $activatedCampaigns++;
            } catch (PushCampaignActivationBlockedException $exception) {
                $blockedCampaigns++;
                $readiness = $exception->readiness();
                $this->warn(sprintf(
                    'Campaign #%d activation blocked: %s',
                    (int) $campaign->id,
                    (string) ($readiness['message'] ?? $exception->getMessage())
                ));
            }
        }

        $runningCampaignIds = PushCampaign::query()
            ->where('status', 'running')
            ->pluck('id');

        foreach ($runningCampaignIds as $campaignId) {
            $campaign = PushCampaign::query()->find((int) $campaignId);
            if (!$campaign) {
                continue;
            }

            $queuedItems += $pushCampaignService->queueRunningCampaignPendingItems($campaign, now()->utc());
        }

        $this->info(sprintf(
            'Push dispatcher complete: %d campaign(s) activated, %d blocked, %d item job(s) queued.',
            $activatedCampaigns,
            $blockedCampaigns,
            $queuedItems
        ));

        return self::SUCCESS;
    }
}
