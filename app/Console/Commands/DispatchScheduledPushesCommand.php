<?php

namespace App\Console\Commands;

use App\Jobs\SendPushNotificationJob;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
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

        $dueCampaigns = PushCampaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($dueCampaigns as $campaign) {
            $pushCampaignService->executeCampaign($campaign, 0);
            $activatedCampaigns++;
        }

        $dispatchUntil = now()->addDay();

        $runningCampaignIds = PushCampaign::query()
            ->where('status', 'running')
            ->pluck('id');

        foreach ($runningCampaignIds as $campaignId) {
            $items = PushCampaignItem::query()
                ->where('campaign_id', (int) $campaignId)
                ->where('status', 'pending')
                ->where(function ($query) use ($dispatchUntil) {
                    $query->whereNull('scheduled_at')
                        ->orWhere('scheduled_at', '<=', $dispatchUntil);
                })
                ->orderBy('scheduled_at')
                ->get();

            foreach ($items as $item) {
                if ($item->scheduled_at && $item->scheduled_at->isFuture()) {
                    SendPushNotificationJob::dispatch($item->id)->delay($item->scheduled_at);
                } else {
                    SendPushNotificationJob::dispatch($item->id);
                }

                $item->forceFill([
                    'status' => 'scheduled',
                ])->save();

                $queuedItems++;
            }
        }

        $this->info(sprintf(
            'Push dispatcher complete: %d campaign(s) activated, %d item job(s) queued.',
            $activatedCampaigns,
            $queuedItems
        ));

        return self::SUCCESS;
    }
}
