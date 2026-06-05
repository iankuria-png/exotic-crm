<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\Client;
use App\Support\AutoPushSlotAllocator;
use App\Support\ClientProfileUrl;
use App\Support\MarketTimezone;
use App\Services\WpSyncService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AutoPushDraftPackageService
{
    public function __construct(
        private readonly AutoPushSelectionService $selectionService,
        private readonly AutoPushMessageService $messageService,
    ) {
    }

    public function load(AutoPushPlan $plan): array
    {
        $plan->loadMissing('platform');
        $stored = is_array($plan->draft_run_package) ? $plan->draft_run_package : [];
        $signature = $this->planSignature($plan);

        if (($stored['plan_signature'] ?? null) !== $signature || empty($stored['items']) || !is_array($stored['items'])) {
            return $this->refresh($plan);
        }

        return $this->normalizeStoredPackage($plan, $stored);
    }

    public function refresh(AutoPushPlan $plan): array
    {
        return $this->persistPackage($plan, $this->buildGeneratedPackage($plan));
    }

    public function shuffle(AutoPushPlan $plan): array
    {
        $ordered = $this->selectionService->orderedCandidatesForPlan($plan);
        $shuffledCandidates = $ordered['clients']->shuffle()->values();

        return $this->persistPackage($plan, $this->buildGeneratedPackage(
            $plan,
            $shuffledCandidates,
            $ordered['bucket_counts']
        ));
    }

    public function replaceItem(AutoPushPlan $plan, string $previewId, ?int $clientId = null): array
    {
        $package = $this->load($plan);
        $items = collect((array) ($package['items'] ?? []));
        $targetIndex = $items->search(fn ($item) => (string) ($item['preview_id'] ?? '') === $previewId);

        if ($targetIndex === false) {
            return $package;
        }

        $target = (array) $items->get($targetIndex);
        $candidate = $clientId
            ? $this->loadReplacementClient($plan, $clientId)
            : $this->nextReplacementCandidate($plan, $package, $target);

        if (!$candidate instanceof Client) {
            return $package;
        }

        if ((int) ($target['client_id'] ?? 0) === (int) $candidate->id) {
            return $package;
        }

        $items->put($targetIndex, $this->makePreviewItem(
            $plan,
            $candidate,
            [
                'preview_id' => (string) ($target['preview_id'] ?? ('slot-' . ((int) $targetIndex + 1))),
                'slot_index' => (int) ($target['slot_index'] ?? $targetIndex),
                'scheduled_at' => $target['scheduled_at'] ?? null,
                'scheduled_at_market' => $target['scheduled_at_market'] ?? null,
            ]
        ));

        $package['items'] = $items->values()->all();
        $package['source'] = 'edited';
        $package['updated_at'] = now()->toIso8601String();
        $package['ui'] = array_merge((array) ($package['ui'] ?? []), [
            'active_preview_id' => (string) ($target['preview_id'] ?? ''),
        ]);

        return $this->persistPackage($plan, $package);
    }

    public function save(AutoPushPlan $plan, array $payload): array
    {
        $package = $this->load($plan);
        $itemsByPreviewId = collect((array) ($package['items'] ?? []))
            ->keyBy(fn ($item) => (string) ($item['preview_id'] ?? ''));

        $incomingItems = collect((array) ($payload['items'] ?? []))
            ->map(function ($item) use ($itemsByPreviewId) {
                $previewId = (string) ($item['preview_id'] ?? '');
                $existing = (array) ($itemsByPreviewId->get($previewId) ?? []);

                return $this->sanitizePreviewItem(array_merge($existing, is_array($item) ? $item : []));
            })
            ->filter(fn ($item) => !empty($item['preview_id']))
            ->values()
            ->all();

        if ($incomingItems !== []) {
            $package['items'] = $incomingItems;
        }

        $package['ui'] = [
            'active_preview_id' => trim((string) data_get($payload, 'ui.active_preview_id', data_get($package, 'ui.active_preview_id', ''))),
            'preview_device' => trim((string) data_get($payload, 'ui.preview_device', data_get($package, 'ui.preview_device', 'mobile'))),
        ];
        $package['source'] = 'edited';
        $package['updated_at'] = now()->toIso8601String();

        return $this->persistPackage($plan, $package);
    }

    public function storedSourceOfTruth(AutoPushPlan $plan): ?array
    {
        $stored = is_array($plan->draft_run_package) ? $plan->draft_run_package : [];

        if (($stored['plan_signature'] ?? null) !== $this->planSignature($plan)) {
            return null;
        }

        if (empty($stored['items']) || !is_array($stored['items'])) {
            return null;
        }

        return $this->normalizeStoredPackage($plan, $stored);
    }

    /**
     * @return \Illuminate\Support\Collection<int,\App\Models\Client>
     */
    public function reserveClientsForSourceOfTruth(AutoPushPlan $plan, array $package): Collection
    {
        $candidateIds = collect((array) ($package['candidate_client_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        if ($candidateIds->isEmpty()) {
            $candidateIds = $this->selectionService->orderedCandidatesForPlan($plan)['clients']
                ->map(fn (Client $client) => (int) $client->id)
                ->values();
        }

        $usedIds = collect((array) ($package['items'] ?? []))
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->flip();

        $reserveIds = $candidateIds
            ->reject(fn (int $id) => $usedIds->has($id))
            ->values();

        return $this->selectionService->loadClientsInOrder($reserveIds);
    }

    /**
     * @param  \Illuminate\Support\Collection<int,\App\Models\Client>|null  $candidates
     * @param  array<string,int>|null  $bucketCounts
     */
    private function buildGeneratedPackage(AutoPushPlan $plan, ?Collection $candidates = null, ?array $bucketCounts = null): array
    {
        $plan->loadMissing('platform');
        $timezone = MarketTimezone::resolve($plan->platform?->timezone, config('app.timezone', 'UTC'));
        $nowMarket = now($timezone);
        $slots = AutoPushSlotAllocator::slotGrid($plan, $nowMarket->copy()->startOfDay(), max(1, (int) data_get($plan->schedule, 'lookahead_days', 1)))
            ->filter(fn (Carbon $slot) => $slot->greaterThan(now()->utc()->subMinutes(5)))
            ->values();

        $selection = $this->selectionService->selectForPlan($plan);
        $candidateSet = $candidates ?? $selection['primary'];
        $previewClients = $candidateSet
            ->take($slots->count() > 0 ? min($selection['primary']->count(), $slots->count()) : $selection['primary']->count())
            ->values();

        $items = $previewClients->map(function (Client $client, int $index) use ($plan, $slots) {
            $slot = $slots->get($index);

            return $this->makePreviewItem($plan, $client, [
                'preview_id' => 'slot-' . ($index + 1),
                'slot_index' => $index,
                'scheduled_at' => $slot?->toIso8601String(),
                'scheduled_at_market' => $slot?->toIso8601String(),
            ]);
        })->values()->all();

        return [
            'version' => 1,
            'plan_signature' => $this->planSignature($plan),
            'generated_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'source' => 'generated',
            'selection' => [
                'primary_count' => $selection['primary']->count(),
                'reserve_count' => $selection['reserve']->count(),
                'bucket_counts' => $bucketCounts ?? $selection['bucket_counts'],
            ],
            'engagement' => $this->engagementSnapshot($plan),
            'candidate_client_ids' => $this->selectionService->orderedCandidatesForPlan($plan)['clients']
                ->map(fn (Client $client) => (int) $client->id)
                ->values()
                ->all(),
            'items' => $items,
            'ui' => [
                'active_preview_id' => $items[0]['preview_id'] ?? null,
                'preview_device' => 'mobile',
            ],
        ];
    }

    private function normalizeStoredPackage(AutoPushPlan $plan, array $package): array
    {
        $items = collect((array) ($package['items'] ?? []))
            ->map(fn ($item) => $this->sanitizePreviewItem(is_array($item) ? $item : []))
            ->filter(fn ($item) => !empty($item['preview_id']))
            ->values()
            ->all();

        $package['version'] = 1;
        $package['plan_signature'] = $this->planSignature($plan);
        $package['items'] = $items;
        $package['selection'] = [
            'primary_count' => (int) data_get($package, 'selection.primary_count', count($items)),
            'reserve_count' => (int) data_get($package, 'selection.reserve_count', 0),
            'bucket_counts' => is_array(data_get($package, 'selection.bucket_counts')) ? data_get($package, 'selection.bucket_counts') : [],
        ];
        $package['candidate_client_ids'] = collect((array) ($package['candidate_client_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
        $package['ui'] = [
            'active_preview_id' => data_get($package, 'ui.active_preview_id', $items[0]['preview_id'] ?? null),
            'preview_device' => data_get($package, 'ui.preview_device', 'mobile'),
        ];
        $package['engagement'] = is_array($package['engagement'] ?? null)
            ? $package['engagement']
            : $this->engagementSnapshot($plan);

        return $package;
    }

    /**
     * @param  array{preview_id:string,slot_index:int,scheduled_at:mixed,scheduled_at_market:mixed}  $meta
     * @return array<string,mixed>
     */
    private function makePreviewItem(AutoPushPlan $plan, Client $client, array $meta): array
    {
        $message = $this->messageService->generateMessage($plan, $client);
        $profileUrl = ClientProfileUrl::resolve($client, $plan->platform);
        $primaryImage = trim((string) ($client->display_image_url ?: $client->main_image_url ?: ''));
        $fallbackImage = trim((string) ($client->main_image_url ?: ''));

        return $this->sanitizePreviewItem([
            'preview_id' => $meta['preview_id'],
            'slot_index' => $meta['slot_index'],
            'client_id' => (int) $client->id,
            'name' => $client->name,
            'city' => $client->city,
            'profile_url' => $profileUrl,
            'profile_image_url' => $primaryImage,
            'fallback_profile_image_url' => $fallbackImage,
            'message' => $message['message'],
            'message_source' => $message['source'],
            'scheduled_at' => $this->normalizeDateString($meta['scheduled_at']),
            'scheduled_at_market' => $this->normalizeDateString($meta['scheduled_at_market']),
        ]);
    }

    private function sanitizePreviewItem(array $item): array
    {
        return [
            'preview_id' => trim((string) ($item['preview_id'] ?? '')),
            'slot_index' => max(0, (int) ($item['slot_index'] ?? 0)),
            'client_id' => isset($item['client_id']) ? (int) $item['client_id'] : null,
            'name' => trim((string) ($item['name'] ?? '')),
            'city' => trim((string) ($item['city'] ?? '')),
            'profile_url' => trim((string) ($item['profile_url'] ?? '')),
            'profile_image_url' => trim((string) ($item['profile_image_url'] ?? '')),
            'fallback_profile_image_url' => trim((string) ($item['fallback_profile_image_url'] ?? '')),
            'message' => trim((string) ($item['message'] ?? '')),
            'message_source' => trim((string) ($item['message_source'] ?? 'seed')),
            'scheduled_at' => $this->normalizeDateString($item['scheduled_at'] ?? null),
            'scheduled_at_market' => $this->normalizeDateString($item['scheduled_at_market'] ?? null),
        ];
    }

    private function normalizeDateString(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function persistPackage(AutoPushPlan $plan, array $package): array
    {
        $plan->forceFill([
            'draft_run_package' => $package,
        ])->save();

        return $package;
    }

    private function planSignature(AutoPushPlan $plan): string
    {
        return sha1(json_encode([
            'platform_id' => (int) $plan->platform_id,
            'buckets' => $plan->buckets,
            'schedule' => $plan->schedule,
            'message_strategy' => $plan->message_strategy,
            'reliability' => $plan->reliability,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: Str::uuid()->toString());
    }

    private function loadReplacementClient(AutoPushPlan $plan, int $clientId): ?Client
    {
        return Client::query()
            ->where('platform_id', (int) $plan->platform_id)
            ->where('id', $clientId)
            ->with(['platform', 'activeDeal.product'])
            ->first();
    }

    private function nextReplacementCandidate(AutoPushPlan $plan, array $package, array $target): ?Client
    {
        $candidateIds = collect((array) ($package['candidate_client_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        if ($candidateIds->isEmpty()) {
            $candidateIds = $this->selectionService->orderedCandidatesForPlan($plan)['clients']
                ->map(fn (Client $client) => (int) $client->id)
                ->values();
        }

        $usedIds = collect((array) ($package['items'] ?? []))
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        $currentClientId = (int) ($target['client_id'] ?? 0);
        $preferredId = $candidateIds
            ->first(fn (int $candidateId) => $candidateId !== $currentClientId && !$usedIds->contains($candidateId));

        if (!$preferredId) {
            $preferredId = $candidateIds
                ->first(fn (int $candidateId) => $candidateId !== $currentClientId);
        }

        if (!$preferredId) {
            return null;
        }

        return $this->loadReplacementClient($plan, $preferredId);
    }

    /**
     * @return array{top_profiles:array<int,array<string,mixed>>,bottom_profiles:array<int,array<string,mixed>>}
     */
    private function engagementSnapshot(AutoPushPlan $plan): array
    {
        $plan->loadMissing('platform');
        if (!$plan->platform?->wp_api_url || !$plan->platform?->wp_api_user || !$plan->platform?->wp_api_password) {
            return [
                'top_profiles' => [],
                'bottom_profiles' => [],
            ];
        }

        try {
            $top = $this->fetchAnalyticsProfiles((int) $plan->platform_id, 'desc');
            $bottom = $this->fetchAnalyticsProfiles((int) $plan->platform_id, 'asc');

            return [
                'top_profiles' => $top,
                'bottom_profiles' => $bottom,
            ];
        } catch (\Throwable) {
            return [
                'top_profiles' => [],
                'bottom_profiles' => [],
            ];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchAnalyticsProfiles(int $platformId, string $order): array
    {
        $payload = WpSyncService::forPlatform($platformId)->getAnalyticsRankings([
            'per_page' => 3,
            'page' => 1,
            'status' => 'publish',
            'sort_by' => 'contact_rate',
            'order' => $order,
        ]);

        $profiles = collect((array) ($payload['profiles'] ?? []))
            ->map(fn ($profile) => is_array($profile) ? $profile : [])
            ->filter(fn ($profile) => (int) ($profile['post_id'] ?? 0) > 0)
            ->values();

        if ($profiles->isEmpty()) {
            return [];
        }

        $postIds = $profiles->pluck('post_id')->map(fn ($id) => (int) $id)->values();
        $clients = Client::query()
            ->where('platform_id', $platformId)
            ->whereIn('wp_post_id', $postIds->all())
            ->with(['platform', 'activeDeal.product'])
            ->get()
            ->keyBy(fn (Client $client) => (int) $client->wp_post_id);

        return $profiles->map(function (array $profile, int $index) use ($clients) {
            /** @var Client|null $client */
            $client = $clients->get((int) ($profile['post_id'] ?? 0));
            $image = trim((string) ($client?->display_image_url ?: $client?->main_image_url ?: ''));

            return [
                'rank' => $index + 1,
                'post_id' => (int) ($profile['post_id'] ?? 0),
                'client_id' => $client ? (int) $client->id : null,
                'name' => $client?->name ?: (string) ($profile['name'] ?? 'Profile'),
                'city' => $client?->city ?: '',
                'profile_url' => $client ? ClientProfileUrl::resolve($client, $client->platform) : null,
                'profile_image_url' => $image,
                'contact_rate_percent' => isset($profile['contact_rate_percent']) ? (float) $profile['contact_rate_percent'] : null,
                'contact_actions_total' => isset($profile['contact_actions_total']) ? (int) $profile['contact_actions_total'] : null,
                'profile_view_total' => (int) data_get($profile, 'totals.profile_view.total', 0),
                'unique_visitors_total' => (int) data_get($profile, 'totals.profile_view.unique', 0),
                'subscription_tier' => $client?->plan_label,
            ];
        })->values()->all();
    }
}
