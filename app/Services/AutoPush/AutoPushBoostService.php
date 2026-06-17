<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\Client;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Services\PushCampaign\PushCampaignService;
use App\Support\AutoPushSlotAllocator;
use App\Support\MarketTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoPushBoostService
{
    private const SOURCE_FILENAME = 'auto_push_boost';
    private const DEFAULT_SPACING_MINUTES = 30;

    public function __construct(
        private readonly AutoPushCampaignBuilder $campaignBuilder,
        private readonly AutoPushMessageService $messageService,
        private readonly PushCampaignService $pushCampaignService,
    ) {
    }

    /**
     * @return array{status:string,campaign_id:?int,campaign_item_id:?int,reshuffled_items:int,message:string}
     */
    public function dispatchNow(Client $client, int $actorId): array
    {
        $client->loadMissing('platform');
        $plan = $this->boostPlanForClient($client);
        $reshuffled = $plan instanceof AutoPushPlan ? $this->reshufflePendingCollisions($plan) : 0;

        $campaign = PushCampaign::query()->create([
            'name' => sprintf('%s - Boosted - %s', $this->marketLabel($client), now()->format('Y-m-d H:i')),
            'platform_id' => (int) $client->platform_id,
            'status' => 'draft',
            'total_items' => 0,
            'created_by' => $actorId,
            'upload_batch_id' => (string) Str::uuid(),
            'source_filename' => self::SOURCE_FILENAME,
        ]);
        $campaign->setRelation('platform', $client->platform);

        $message = $this->boostMessage($plan, $client);
        $payload = $this->campaignBuilder->makePendingItemPayload(
            $campaign,
            $client,
            now()->utc(),
            null,
            $message,
        );

        if ($payload === null) {
            $campaign->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
            ])->save();

            return [
                'status' => 'skipped',
                'campaign_id' => (int) $campaign->id,
                'campaign_item_id' => null,
                'reshuffled_items' => $reshuffled,
                'message' => 'Boost was saved, but no push was queued because the profile has no pushable URL.',
            ];
        }

        $payload['scheduled_at'] = null;
        $payload['date_label'] = now($this->timezone($client))->toDateString();

        $item = PushCampaignItem::query()->create($payload);
        $campaign->forceFill(['total_items' => 1])->save();

        $this->pushCampaignService->executeCampaign($campaign->fresh('platform'), $actorId);

        return [
            'status' => 'queued',
            'campaign_id' => (int) $campaign->id,
            'campaign_item_id' => (int) $item->id,
            'reshuffled_items' => $reshuffled,
            'message' => 'Boost push queued for immediate dispatch.',
        ];
    }

    private function boostPlanForClient(Client $client): ?AutoPushPlan
    {
        return AutoPushPlan::query()
            ->with('platform')
            ->where('platform_id', (int) $client->platform_id)
            ->where('enabled', true)
            ->orderByDesc('autopilot')
            ->latest('updated_at')
            ->first()
            ?: AutoPushPlan::query()
                ->with('platform')
                ->where('platform_id', (int) $client->platform_id)
                ->latest('updated_at')
                ->first();
    }

    private function boostMessage(?AutoPushPlan $plan, Client $client): string
    {
        if ($plan instanceof AutoPushPlan) {
            try {
                return (string) data_get($this->messageService->generateMessage($plan, $client), 'message', '');
            } catch (\Throwable $exception) {
                Log::warning('auto_push.boost_message_failed', [
                    'client_id' => (int) $client->id,
                    'plan_id' => (int) $plan->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $name = trim((string) $client->name) ?: 'New profile';
        $city = trim((string) $client->city);

        return $city !== ''
            ? sprintf('%s is live in %s now.', $name, $city)
            : sprintf('%s is live now.', $name);
    }

    private function reshufflePendingCollisions(AutoPushPlan $plan): int
    {
        $spacingMinutes = max(0, (int) data_get($plan->reliability, 'boost_spacing_minutes', self::DEFAULT_SPACING_MINUTES));
        if ($spacingMinutes === 0) {
            return 0;
        }

        $from = now()->utc()->subMinute();
        $through = now()->utc()->addMinutes($spacingMinutes);
        $items = PushCampaignItem::query()
            ->with('campaign.platform')
            ->where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from->toDateTimeString(), $through->toDateTimeString()])
            ->whereHas('campaign', function ($query) use ($plan) {
                $query->where('platform_id', (int) $plan->platform_id)
                    ->whereIn('status', ['draft', 'scheduled', 'running'])
                    ->where(function ($sourceQuery) {
                        $sourceQuery->whereNull('source_filename')
                            ->orWhere('source_filename', '!=', self::SOURCE_FILENAME);
                    });
            })
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        $reshuffled = 0;
        foreach ($items as $item) {
            $campaign = $item->campaign;
            if (!$campaign instanceof PushCampaign) {
                continue;
            }

            $occupiedSlots = PushCampaignItem::query()
                ->where('campaign_id', (int) $campaign->id)
                ->where('id', '!=', (int) $item->id)
                ->whereIn('status', ['pending', 'scheduled'])
                ->pluck('scheduled_at')
                ->filter()
                ->values()
                ->all();

            $slot = AutoPushSlotAllocator::nextFreeSlot(
                $plan,
                $campaign,
                now()->utc()->addMinutes($spacingMinutes),
                $occupiedSlots,
            );

            if (!$slot instanceof Carbon) {
                continue;
            }

            $timezone = MarketTimezone::resolve($campaign->platform?->timezone, config('app.timezone', 'UTC'));
            $item->forceFill([
                'scheduled_at' => $slot->toDateTimeString(),
                'date_label' => $slot->copy()->setTimezone($timezone)->toDateString(),
            ])->save();
            $reshuffled++;
        }

        return $reshuffled;
    }

    private function marketLabel(Client $client): string
    {
        return $client->platform?->name
            ?: $client->platform?->country
            ?: ('Market ' . (int) $client->platform_id);
    }

    private function timezone(Client $client): string
    {
        return MarketTimezone::resolve($client->platform?->timezone, config('app.timezone', 'UTC'));
    }
}
