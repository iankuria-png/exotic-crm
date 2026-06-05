<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\AutoPushRun;
use App\Models\Client;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Services\PushCampaign\PushCampaignService;
use App\Support\AutoPushSlotAllocator;
use Illuminate\Support\Facades\Log;

class AutoPushMaintenanceService
{
    public function __construct(
        private readonly AutoPushMessageService $messageService,
        private readonly AutoPushCampaignBuilder $campaignBuilder,
        private readonly AutoPushAlertService $alertService,
        private readonly PushCampaignService $pushCampaignService,
    ) {
    }

    public function replaceFailedItems(): int
    {
        $replacements = 0;
        $campaigns = PushCampaign::query()
            ->with(['autoPushPlan.platform', 'autoPushRun'])
            ->whereNotNull('auto_push_plan_id')
            ->whereIn('status', ['running', 'scheduled', 'partial', 'failed'])
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                $replacements += $this->replaceFailedItemsForCampaign($campaign);
            } catch (\Throwable $exception) {
                Log::warning('auto_push.maintenance_campaign_failed', [
                    'campaign_id' => (int) $campaign->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $replacements;
    }

    public function detectAndAlert(): int
    {
        $alerts = 0;
        $campaigns = PushCampaign::query()
            ->with(['autoPushPlan.platform', 'autoPushRun'])
            ->whereNotNull('auto_push_plan_id')
            ->get();

        foreach ($campaigns as $campaign) {
            if ($campaign->items()->where('provider_meta->fallback_attempted', true)->exists()) {
                $this->alertService->raise(
                    'provider_failover',
                    'info',
                    'Provider failover used for campaign delivery',
                    'The primary provider failed for at least one item and a fallback provider was used.',
                    ['campaign_id' => (int) $campaign->id],
                    $campaign->autoPushPlan,
                    $campaign,
                    dedupeOpenCampaign: true,
                );
                $alerts++;
            }

            if ((string) $campaign->status === 'failed' && (int) $campaign->sent_count === 0 && !$this->canStillReplace($campaign)) {
                $this->alertService->raise(
                    'campaign_failed',
                    'critical',
                    'Auto-push campaign failed without sends',
                    'The campaign finished failed and no further replacement runway remains.',
                    ['campaign_id' => (int) $campaign->id],
                    $campaign->autoPushPlan,
                    $campaign,
                    dedupeOpenCampaign: true,
                );
                $alerts++;
            }
        }

        return $alerts;
    }

    public function replaceFailedItemsForCampaign(PushCampaign $campaign): int
    {
        $campaign->loadMissing(['autoPushPlan.platform', 'autoPushRun']);
        $plan = $campaign->autoPushPlan;
        $run = $campaign->autoPushRun;
        if (!$plan || !$run) {
            return 0;
        }

        $maxReplacements = max(0, (int) data_get($plan->reliability, 'max_replacements_per_item', 2));
        $failedItems = PushCampaignItem::query()
            ->where('campaign_id', (int) $campaign->id)
            ->where('status', 'failed')
            ->where('replacement_round', '<', $maxReplacements)
            ->whereDoesntHave('replacements')
            ->orderBy('scheduled_at')
            ->get();

        $made = 0;
        foreach ($failedItems as $failedItem) {
            $reserveIds = collect((array) $run->reserve_client_ids)->values();
            if ($reserveIds->isEmpty()) {
                $this->alertExhausted($plan, $campaign);
                continue;
            }

            $occupiedSlots = PushCampaignItem::query()
                ->where('campaign_id', (int) $campaign->id)
                ->whereIn('status', ['pending', 'scheduled'])
                ->pluck('scheduled_at')
                ->filter()
                ->values()
                ->all();

            $slot = AutoPushSlotAllocator::nextFreeSlot(
                $plan,
                $campaign,
                $failedItem->scheduled_at?->copy()?->utc(),
                $occupiedSlots
            );

            if (!$slot) {
                $this->alertExhausted($plan, $campaign);
                continue;
            }

            $reserveClientId = (int) $reserveIds->shift();
            $client = Client::query()->with('platform')->find($reserveClientId);
            if (!$client) {
                $run->forceFill(['reserve_client_ids' => $reserveIds->values()->all()])->save();
                $this->alertExhausted($plan, $campaign);
                continue;
            }

            $message = $this->messageService->generateMessage($plan, $client);
            $payload = $this->campaignBuilder->makePendingItemPayload($campaign, $client, $slot, $failedItem, $message['message']);
            if (!$payload) {
                $run->forceFill(['reserve_client_ids' => $reserveIds->values()->all()])->save();
                $this->alertExhausted($plan, $campaign);
                continue;
            }

            $replacement = PushCampaignItem::query()->create($payload);
            $run->forceFill([
                'reserve_client_ids' => $reserveIds->values()->all(),
                'reserve_count' => $reserveIds->count(),
                'replacements_made' => (int) $run->replacements_made + 1,
                'ai_cost_usd' => (float) $run->ai_cost_usd + (float) $message['cost_usd'],
            ])->save();

            if (in_array((string) $campaign->status, ['partial', 'failed'], true)) {
                $campaign->forceFill([
                    'status' => 'running',
                    'completed_at' => null,
                ])->save();
            }

            $made++;
        }

        if ($made > 0 && (string) $campaign->status === 'running') {
            $this->pushCampaignService->queueRunningCampaignPendingItems($campaign->fresh(), now()->utc());
        }

        return $made;
    }

    private function canStillReplace(PushCampaign $campaign): bool
    {
        $campaign->loadMissing('autoPushRun');
        $run = $campaign->autoPushRun;
        if (!$run) {
            return false;
        }

        return collect((array) $run->reserve_client_ids)->isNotEmpty();
    }

    private function alertExhausted(AutoPushPlan $plan, PushCampaign $campaign): void
    {
        $this->alertService->raise(
            'replacement_exhausted',
            'warning',
            'Auto-push replacement runway exhausted',
            'A failed auto-push item could not be replaced because there was no reserve client or no free future slot.',
            ['campaign_id' => (int) $campaign->id],
            $plan,
            $campaign,
            dedupeOpenCampaign: true,
        );
    }
}
