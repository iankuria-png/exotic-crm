<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\AutoPushRun;
use App\Models\Client;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Services\PushCampaign\ProfileExtractionService;
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
        private readonly ProfileExtractionService $profileExtractionService,
    ) {
    }

    /**
     * @param  array{primary:\Illuminate\Support\Collection<int,\App\Models\Client>,reserve:\Illuminate\Support\Collection<int,\App\Models\Client>,bucket_counts:array<string,int>}  $selection
     */
    public function build(AutoPushPlan $plan, AutoPushRun $run, array $selection): PushCampaign
    {
        $plan->loadMissing('platform');
        $timezone = MarketTimezone::resolve($plan->platform?->timezone, config('app.timezone', 'UTC'));
        $slots = $this->slotGrid($plan, $timezone);
        $campaign = $this->createCampaignSkeleton($plan, $run);

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
            $this->hydrateAgesFromWp($campaign, $plan);
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

    public function buildFromDraftPackage(AutoPushPlan $plan, AutoPushRun $run, array $draftPackage): PushCampaign
    {
        $plan->loadMissing('platform');
        $campaign = $this->createCampaignSkeleton($plan, $run);
        $clientIds = collect((array) ($draftPackage['items'] ?? []))
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();
        $clients = Client::query()
            ->whereIn('id', $clientIds->all())
            ->get()
            ->keyBy('id');

        $items = [];
        $now = now();
        $scheduledAtValues = [];

        foreach ((array) ($draftPackage['items'] ?? []) as $index => $draftItem) {
            $draftItem = is_array($draftItem) ? $draftItem : [];
            $scheduledAt = $this->resolveDraftScheduledAt($draftItem);
            if (!$scheduledAt instanceof Carbon) {
                continue;
            }

            $clientId = isset($draftItem['client_id']) ? (int) $draftItem['client_id'] : null;
            /** @var Client|null $client */
            $client = $clientId ? $clients->get($clientId) : null;
            $payload = $this->makeDraftPendingItemPayload($campaign, $draftItem, $scheduledAt, $client);
            if ($payload === null) {
                continue;
            }

            $items[] = $payload + [
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $scheduledAtValues[] = $scheduledAt->copy();
        }

        if ($items !== []) {
            PushCampaignItem::query()->insert($items);
            $this->hydrateAgesFromWp($campaign, $plan);
        }

        $campaign->forceFill([
            'total_items' => count($items),
        ])->save();

        $run->forceFill([
            'campaign_id' => (int) $campaign->id,
            'window_start_at' => collect($scheduledAtValues)->sort()->first(),
            'window_end_at' => collect($scheduledAtValues)->sort()->last(),
            'items_created' => count($items),
            'ai_cost_usd' => 0,
        ])->save();

        return $campaign->fresh();
    }

    /**
     * Backfill profile_age on the freshly-inserted items from WordPress, using each
     * item's known wp_post_id. Mirrors how the manual push route resolves age, so auto
     * campaigns show the same data. Best-effort: never blocks campaign creation.
     */
    private function hydrateAgesFromWp(PushCampaign $campaign, AutoPushPlan $plan): void
    {
        $platform = $plan->platform;
        if (!$platform) {
            return;
        }

        $items = $campaign->items()
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        try {
            $this->profileExtractionService->hydrateAgeFromWp($items, $platform);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Auto-push age hydration failed', [
                'campaign_id' => (int) $campaign->id,
                'platform_id' => (int) $platform->id,
                'error' => $exception->getMessage(),
            ]);
        }
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
            'profile_city' => $client->city,
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

    private function createCampaignSkeleton(AutoPushPlan $plan, AutoPushRun $run): PushCampaign
    {
        return PushCampaign::query()->create([
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
    }

    /**
     * @return \Illuminate\Support\Collection<int,\Carbon\Carbon>
     */
    private function slotGrid(AutoPushPlan $plan, string $timezone): Collection
    {
        $needed = max(1, (int) data_get($plan->schedule, 'max_items_per_day', 1));

        return AutoPushSlotAllocator::futureSlots($plan, $needed, 14, now($timezone)->utc());
    }

    private function resolveDraftScheduledAt(array $draftItem): ?Carbon
    {
        $value = $draftItem['scheduled_at_market'] ?? $draftItem['scheduled_at'] ?? null;
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function makeDraftPendingItemPayload(
        PushCampaign $campaign,
        array $draftItem,
        Carbon $scheduledAtUtc,
        ?Client $client = null,
    ): ?array {
        $profileUrl = trim((string) ($draftItem['profile_url'] ?? ''));
        if ($profileUrl === '') {
            return null;
        }

        $campaign->loadMissing('platform');
        $timezone = MarketTimezone::resolve($campaign->platform?->timezone, config('app.timezone', 'UTC'));
        $profileImageUrl = trim((string) (($draftItem['profile_image_url'] ?? '') ?: ($draftItem['fallback_profile_image_url'] ?? '')));

        return [
            'campaign_id' => (int) $campaign->id,
            'client_id' => $client?->id,
            'profile_url' => $profileUrl,
            'wp_post_id' => $client?->wp_post_id ? (int) $client->wp_post_id : null,
            'profile_name' => trim((string) ($draftItem['name'] ?? $client?->name ?? '')),
            'profile_city' => trim((string) ($draftItem['city'] ?? $client?->city ?? '')),
            'profile_phone' => $client?->phone_normalized,
            'profile_image_url' => $profileImageUrl,
            'profile_age' => null,
            'custom_message' => trim((string) ($draftItem['message'] ?? '')),
            'scheduled_at' => $scheduledAtUtc->toDateTimeString(),
            'date_label' => $scheduledAtUtc->copy()->setTimezone($timezone)->toDateString(),
            'status' => 'pending',
            'provider_meta' => null,
            'replaces_item_id' => null,
            'replacement_round' => 0,
        ];
    }
}
