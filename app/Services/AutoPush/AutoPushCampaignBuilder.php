<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\AutoPushRun;
use App\Models\Client;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Support\AutoPushSlotAllocator;
use App\Support\ClientProfileUrl;
use App\Support\MarketTimezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AutoPushCampaignBuilder
{
    public function __construct(
        private readonly AutoPushMessageService $messageService,
    ) {
    }

    /**
     * @param  array{primary:\Illuminate\Support\Collection<int,\App\Models\Client>,reserve:\Illuminate\Support\Collection<int,\App\Models\Client>,bucket_counts:array<string,int>}  $selection
     */
    public function build(AutoPushPlan $plan, AutoPushRun $run, array $selection): PushCampaign
    {
        $plan->loadMissing('platform');
        $timezone = MarketTimezone::resolve($plan->platform?->timezone, config('app.timezone', 'UTC'));
        $lookaheadDays = max(1, (int) data_get($plan->schedule, 'lookahead_days', 1));
        $slots = AutoPushSlotAllocator::slotGrid($plan, now($timezone)->startOfDay(), $lookaheadDays)
            ->filter(fn (Carbon $slot) => $slot->greaterThan(now()->utc()->subMinutes(5)))
            ->values();

        $campaign = PushCampaign::query()->create([
            'name' => trim($plan->name . ' - ' . now()->toDateString()),
            'platform_id' => (int) $plan->platform_id,
            'status' => 'draft',
            'total_items' => 0,
            'created_by' => $plan->created_by,
            'upload_batch_id' => (string) Str::uuid(),
            'source_filename' => 'auto_push_engine',
            'auto_push_plan_id' => (int) $plan->id,
            'auto_push_run_id' => (int) $run->id,
        ]);

        $items = [];
        $now = now();
        $cost = 0.0;

        foreach ($selection['primary']->values() as $index => $client) {
            $slot = $slots->get($index);
            if (!$slot instanceof Carbon) {
                break;
            }

            $payload = $this->makePendingItemPayload($campaign, $client, $slot);
            if ($payload === null) {
                continue;
            }

            $message = $this->messageService->generateMessage($plan, $client);
            $payload['custom_message'] = $message['message'];
            $items[] = $payload + [
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $cost += (float) ($message['cost_usd'] ?? 0);
        }

        if ($items !== []) {
            PushCampaignItem::query()->insert($items);
        }

        $campaign->forceFill([
            'total_items' => count($items),
        ])->save();

        $run->forceFill([
            'campaign_id' => (int) $campaign->id,
            'window_start_at' => $slots->first(),
            'window_end_at' => $slots->last(),
            'items_created' => count($items),
            'ai_cost_usd' => $cost,
        ])->save();

        return $campaign->fresh();
    }

    public function persistReserve(AutoPushRun $run, Collection $reserveClients): void
    {
        $reserveIds = $reserveClients->map(fn (Client $client) => (int) $client->id)->values()->all();
        $run->forceFill([
            'reserve_count' => count($reserveIds),
            'reserve_client_ids' => $reserveIds,
        ])->save();
    }

    public function makePendingItemPayload(
        PushCampaign $campaign,
        Client $client,
        Carbon $scheduledAtUtc,
        ?PushCampaignItem $replacementParent = null,
        ?string $customMessage = null,
    ): ?array {
        $campaign->loadMissing('platform');
        $profileUrl = ClientProfileUrl::resolve($client, $campaign->platform);
        if (!$profileUrl) {
            return null;
        }

        $timezone = MarketTimezone::resolve($campaign->platform?->timezone, config('app.timezone', 'UTC'));

        return [
            'campaign_id' => (int) $campaign->id,
            'client_id' => (int) $client->id,
            'profile_url' => $profileUrl,
            'wp_post_id' => $client->wp_post_id ? (int) $client->wp_post_id : null,
            'profile_name' => $client->name,
            'profile_phone' => $client->phone_normalized,
            'profile_image_url' => $client->main_image_url,
            'profile_age' => null,
            'custom_message' => $customMessage ?? '',
            'scheduled_at' => $scheduledAtUtc->toDateTimeString(),
            'date_label' => $scheduledAtUtc->copy()->setTimezone($timezone)->toDateString(),
            'status' => 'pending',
            'provider_meta' => null,
            'replaces_item_id' => $replacementParent?->id,
            'replacement_round' => $replacementParent ? ((int) $replacementParent->replacement_round + 1) : 0,
        ];
    }
}
