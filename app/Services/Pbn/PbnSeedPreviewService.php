<?php

namespace App\Services\Pbn;

use App\Models\Client;
use App\Models\PbnSeedBatch;
use App\Models\PbnSeedItem;
use App\Models\PbnSeedPreview;
use App\Models\PbnSeedTarget;
use App\Models\PbnSite;
use App\Models\Platform;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PbnSeedPreviewService
{
    private const MAX_SOURCE_MARKETS = 5;
    private const MAX_PROFILES_PER_BATCH = 200;
    private const MAX_DESTINATION_TARGETS = 40;
    private const MAX_PREVIEW_CANDIDATES = 250;

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function preview(PbnSite $site, array $payload, User $actor): array
    {
        $sources = $this->authorizedSources($site, $payload['source_platform_ids'] ?? [], $actor);
        $targets = $this->normalizeTargets($payload['targets'] ?? []);
        $targetCount = $this->normalizeTargetCount($payload['target_count'] ?? array_sum(array_column($targets, 'target_count')));
        $copyPolicy = $site->effectiveCopyPolicy($payload['copy_policy'] ?? []);
        $payloadHash = self::payloadHash($sources->pluck('id')->all(), $targets, $targetCount, $copyPolicy);
        $limit = min(max($targetCount * 3, 50), self::MAX_PREVIEW_CANDIDATES);

        $clients = $this->eligibleClientQuery($sources->pluck('id')->all())
            ->limit($limit)
            ->get();

        $duplicateMap = $this->duplicateMap($site, $clients);
        $candidates = $clients
            ->map(fn (Client $client): array => $this->candidatePayload($client, $duplicateMap))
            ->sortByDesc('quality_rank_score')
            ->values();

        $selectedIds = $candidates
            ->filter(fn (array $candidate): bool => ($candidate['duplicate_state'] ?? 'none') !== 'existing_same_site')
            ->take($targetCount)
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $targetedSelection = $this->assignTargets($candidates, $targets, $selectedIds);
        $warnings = $this->warnings($candidates, $targetCount, $selectedIds);

        $preview = PbnSeedPreview::create([
            'preview_token' => hash('sha256', Str::random(80)),
            'pbn_site_id' => (int) $site->id,
            'created_by' => (int) $actor->id,
            'payload_hash' => $payloadHash,
            'expires_at' => now()->addMinutes(15),
            'status' => PbnSeedPreview::STATUS_ACTIVE,
            'source_platform_ids' => $sources->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'targets' => $targets,
            'copy_policy' => $copyPolicy,
            'candidate_summary' => [
                'target_count' => $targetCount,
                'eligible_count' => $candidates->count(),
                'selected_count' => count($selectedIds),
                'selected_client_ids' => $selectedIds,
                'candidate_client_ids' => $candidates->pluck('client_id')->map(fn ($id) => (int) $id)->values()->all(),
                'target_assignments' => $targetedSelection,
                'warnings' => $warnings,
            ],
        ]);

        return [
            'preview_token' => $preview->preview_token,
            'payload_hash' => $payloadHash,
            'expires_at' => optional($preview->expires_at)->toDateTimeString(),
            'pbn_site_id' => (int) $site->id,
            'source_platform_ids' => $preview->source_platform_ids,
            'targets' => $targets,
            'copy_policy' => $copyPolicy,
            'target_count' => $targetCount,
            'eligible_count' => $candidates->count(),
            'selected_count' => count($selectedIds),
            'selected_client_ids' => $selectedIds,
            'warnings' => $warnings,
            'candidates' => $candidates->map(function (array $candidate, int $index) use ($selectedIds, $targetedSelection): array {
                $candidate['rank'] = $index + 1;
                $candidate['selected'] = in_array((int) $candidate['client_id'], $selectedIds, true);
                $candidate['target'] = $targetedSelection[(int) $candidate['client_id']] ?? null;
                unset($candidate['quality_rank_score']);

                return $candidate;
            })->all(),
        ];
    }

    public function createBatch(PbnSite $site, array $payload, User $actor): array
    {
        $sources = $this->authorizedSources($site, $payload['source_platform_ids'] ?? [], $actor);
        $targets = $this->normalizeTargets($payload['targets'] ?? []);
        $targetCount = $this->normalizeTargetCount($payload['target_count'] ?? array_sum(array_column($targets, 'target_count')));
        $copyPolicy = $site->effectiveCopyPolicy($payload['copy_policy'] ?? []);
        $payloadHash = self::payloadHash($sources->pluck('id')->all(), $targets, $targetCount, $copyPolicy);
        $token = (string) ($payload['preview_token'] ?? '');

        /** @var PbnSeedPreview|null $preview */
        $preview = PbnSeedPreview::query()
            ->where('preview_token', $token)
            ->where('pbn_site_id', (int) $site->id)
            ->first();

        if (!$preview || !$preview->isUsableBy($actor, $payloadHash)) {
            throw ValidationException::withMessages([
                'preview_token' => 'Preview expired or no longer matches this seed request. Run preview again.',
            ]);
        }

        $summary = $preview->candidate_summary ?: [];
        $candidateIds = collect($summary['candidate_client_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $selectedIds = array_values(array_unique(array_map(
            'intval',
            $payload['selected_client_ids'] ?? ($summary['selected_client_ids'] ?? [])
        )));
        $selectedIds = array_values(array_intersect($selectedIds, $candidateIds));
        $selectedIds = array_slice($selectedIds, 0, self::MAX_PROFILES_PER_BATCH);

        if (empty($selectedIds)) {
            throw ValidationException::withMessages([
                'selected_client_ids' => 'Select at least one eligible profile before queueing.',
            ]);
        }

        $duplicateSelected = $this->sameSiteDuplicates($site, $selectedIds);
        if (!empty($duplicateSelected) && !($payload['duplicate_acknowledged'] ?? false)) {
            throw new ConflictHttpException('Duplicate profiles were selected. Acknowledge duplicate warnings to queue and skip them.');
        }

        $targetAssignments = collect($summary['target_assignments'] ?? [])->mapWithKeys(
            fn ($target, $clientId) => [(int) $clientId => is_array($target) ? $target : null]
        );
        $warnings = $summary['warnings'] ?? [];

        $batch = DB::transaction(function () use (
            $site,
            $actor,
            $sources,
            $targets,
            $targetCount,
            $selectedIds,
            $duplicateSelected,
            $targetAssignments,
            $copyPolicy,
            $warnings,
            $payload,
            $preview
        ): PbnSeedBatch {
            $batch = PbnSeedBatch::create([
                'pbn_site_id' => (int) $site->id,
                'created_by' => (int) $actor->id,
                'status' => PbnSeedBatch::STATUS_QUEUED,
                'source_platform_ids' => $sources->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'target_count' => $targetCount,
                'selected_count' => 0,
                'created_count' => 0,
                'failed_count' => 0,
                'warnings' => $warnings,
                'copy_policy' => $copyPolicy,
                'notes' => $payload['notes'] ?? null,
            ]);

            $targetModels = [];
            foreach ($targets as $target) {
                $targetModel = PbnSeedTarget::create([
                    'batch_id' => (int) $batch->id,
                    'target_region_id' => $target['region_id'] ?? null,
                    'target_city_id' => $target['city_id'] ?? null,
                    'region_name' => $target['region_name'] ?? null,
                    'city_name' => $target['city_name'] ?? null,
                    'target_count' => (int) $target['target_count'],
                ]);
                $targetModels[$this->targetKey($target)] = $targetModel;
            }

            $clients = Client::query()
                ->with('platform:id,name,country')
                ->whereIn('id', $selectedIds)
                ->get()
                ->keyBy('id');
            $duplicateIds = array_flip($duplicateSelected);
            $createdItems = [];

            foreach ($selectedIds as $index => $clientId) {
                if (isset($duplicateIds[$clientId])) {
                    continue;
                }

                $client = $clients->get($clientId);
                if (!$client || !$this->clientStillEligible($client)) {
                    continue;
                }

                $assignedTarget = $targetAssignments->get($clientId) ?: ($targets[0] ?? []);
                $targetModel = $targetModels[$this->targetKey($assignedTarget)] ?? null;
                $itemPayloadHash = $this->itemPayloadHash($site, $client, $assignedTarget, $copyPolicy);

                $createdItems[] = PbnSeedItem::create([
                    'batch_id' => (int) $batch->id,
                    'target_id' => $targetModel?->id,
                    'pbn_site_id' => (int) $site->id,
                    'source_platform_id' => (int) $client->platform_id,
                    'source_client_id' => (int) $client->id,
                    'source_wp_post_id' => (int) $client->wp_post_id,
                    'target_region_id' => $assignedTarget['region_id'] ?? null,
                    'target_city_id' => $assignedTarget['city_id'] ?? null,
                    'status' => PbnSeedItem::STATUS_QUEUED,
                    'duplicate_state' => 'none',
                    'quality_score' => $client->seo_score,
                    'payload_hash' => $itemPayloadHash,
                    'eligibility_snapshot' => $this->eligibilitySnapshot($client),
                ]);
            }

            $this->stampAppliedPolicy($batch, $createdItems, $copyPolicy);

            $preview->forceFill(['status' => PbnSeedPreview::STATUS_CONSUMED])->save();
            $this->refreshBatchCounts($batch);

            return $batch->fresh(['items.sourceClient', 'targets', 'creator']);
        });

        return [
            'batch' => $batch,
            'summary' => $this->batchSummary($batch),
        ];
    }

    public function showBatch(PbnSeedBatch $batch): array
    {
        $batch->loadMissing([
            'pbnSite:id,name,domain,last_status,is_active',
            'creator:id,name,email,role',
            'targets',
            'items.sourcePlatform:id,name,country',
            'items.sourceClient:id,name,city,display_image_url,main_image_url,wp_profile_permalink',
        ]);

        return [
            'batch' => $batch,
            'summary' => $this->batchSummary($batch),
        ];
    }

    /**
     * Resolve the batch's content policy into one decision per item and record
     * it, so provisioning and the media stage read a decision instead of making
     * one. Retries then cannot hand an item a different badge than the preview
     * promised, and a finished batch can be audited long afterwards.
     *
     * @param  array<int, PbnSeedItem>  $items
     */
    private function stampAppliedPolicy(PbnSeedBatch $batch, array $items, array $copyPolicy): void
    {
        if ($items === []) {
            return;
        }

        $resolved = (new PbnSeedPolicyResolver())->resolve(
            array_map(static fn (PbnSeedItem $item): int => (int) $item->id, $items),
            $copyPolicy,
            CarbonImmutable::now(),
            (int) $batch->id
        );

        foreach ($items as $item) {
            $decision = $resolved[(int) $item->id] ?? null;
            if ($decision === null) {
                continue;
            }

            $releaseAt = $decision['release_at'] ?? null;
            unset($decision['release_at']);

            $item->forceFill([
                'applied_policy' => $decision,
                'release_at' => $releaseAt,
            ])->save();
        }
    }

    public function refreshBatchCounts(PbnSeedBatch $batch): void
    {
        $items = $batch->items()->get(['status', 'target_id']);
        $created = $items->whereIn('status', [PbnSeedItem::STATUS_CREATED, PbnSeedItem::STATUS_MEDIA_PENDING])->count();
        $failed = $items->where('status', PbnSeedItem::STATUS_FAILED)->count();
        $reverted = $items->where('status', PbnSeedItem::STATUS_REVERTED)->count();
        $cancelled = $items->where('status', PbnSeedItem::STATUS_CANCELLED)->count();
        $selected = $items->count();
        $terminal = $selected > 0 && ($created + $failed + $reverted + $cancelled) >= $selected;

        $status = $batch->status;
        if ($batch->status === PbnSeedBatch::STATUS_CANCELLED) {
            $status = PbnSeedBatch::STATUS_CANCELLED;
        } elseif ($selected > 0 && $reverted >= $selected) {
            $status = PbnSeedBatch::STATUS_REVERTED;
        }
        if ($status !== PbnSeedBatch::STATUS_CANCELLED && $terminal) {
            $status = $status === PbnSeedBatch::STATUS_REVERTED
                ? $status
                : ($failed > 0 ? PbnSeedBatch::STATUS_PARTIAL : PbnSeedBatch::STATUS_COMPLETED);
        } elseif ($status !== PbnSeedBatch::STATUS_CANCELLED && ($failed > 0 || $created > 0)) {
            $status = PbnSeedBatch::STATUS_RUNNING;
        }

        $batch->forceFill([
            'selected_count' => $selected,
            'created_count' => $created,
            'failed_count' => $failed,
            'reverted_count' => $reverted,
            'status' => $status,
            'completed_at' => $terminal ? ($batch->completed_at ?: now()) : null,
        ])->save();

        foreach ($batch->targets as $target) {
            $targetItems = $items->where('target_id', (int) $target->id);
            $target->forceFill([
                'selected_count' => $targetItems->count(),
                'created_count' => $targetItems->whereIn('status', [PbnSeedItem::STATUS_CREATED, PbnSeedItem::STATUS_MEDIA_PENDING])->count(),
            ])->save();
        }
    }

    public static function payloadHash(array $sourcePlatformIds, array $targets, int $targetCount, array $copyPolicy): string
    {
        $canonical = [
            'source_platform_ids' => array_values(array_unique(array_map('intval', $sourcePlatformIds))),
            'targets' => $targets,
            'target_count' => $targetCount,
            'copy_policy' => $copyPolicy,
        ];
        sort($canonical['source_platform_ids']);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function authorizedSources(PbnSite $site, array $requestedSourceIds, User $actor): Collection
    {
        $configured = $site->sourcePlatforms()->pluck('platforms.id')->map(fn ($id) => (int) $id)->all();
        if (empty($configured) && $site->default_source_platform_id) {
            $configured = [(int) $site->default_source_platform_id];
        }

        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $requestedSourceIds))));
        if (empty($sourceIds)) {
            $sourceIds = $configured;
        }
        $sourceIds = array_slice($sourceIds, 0, self::MAX_SOURCE_MARKETS);

        $unauthorized = array_diff($sourceIds, $configured);
        if (!empty($unauthorized)) {
            throw ValidationException::withMessages([
                'source_platform_ids' => 'One or more source markets are not configured for this PBN site.',
            ]);
        }

        foreach ($sourceIds as $sourceId) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($actor, $sourceId);
        }

        $sources = Platform::query()->whereIn('id', $sourceIds)->get()->sortBy(fn (Platform $platform) => array_search((int) $platform->id, $sourceIds, true))->values();
        if ($sources->isEmpty()) {
            throw ValidationException::withMessages([
                'source_platform_ids' => 'Select at least one configured source market.',
            ]);
        }

        return $sources;
    }

    private function normalizeTargets(array $targets): array
    {
        $targets = array_slice($targets, 0, self::MAX_DESTINATION_TARGETS);
        $normalized = [];

        foreach ($targets as $target) {
            $count = max(1, min(self::MAX_PROFILES_PER_BATCH, (int) ($target['target_count'] ?? 1)));
            $regionId = isset($target['region_id']) ? (int) $target['region_id'] : (isset($target['target_region_id']) ? (int) $target['target_region_id'] : null);
            $cityId = isset($target['city_id']) ? (int) $target['city_id'] : (isset($target['target_city_id']) ? (int) $target['target_city_id'] : null);
            $regionName = trim((string) ($target['region_name'] ?? $target['region'] ?? ''));
            $cityName = trim((string) ($target['city_name'] ?? $target['city'] ?? ''));

            if (!$regionId && !$cityId && $regionName === '' && $cityName === '') {
                continue;
            }

            $normalized[] = [
                'region_id' => $regionId ?: null,
                'city_id' => $cityId ?: null,
                'region_name' => $regionName ?: null,
                'city_name' => $cityName ?: null,
                'target_count' => $count,
            ];
        }

        if (empty($normalized)) {
            throw ValidationException::withMessages(['targets' => 'Add at least one destination location.']);
        }

        $total = array_sum(array_column($normalized, 'target_count'));
        if ($total > self::MAX_PROFILES_PER_BATCH) {
            throw ValidationException::withMessages(['targets' => 'PBN seed batches are capped at 200 profiles.']);
        }

        return $normalized;
    }

    private function normalizeTargetCount(mixed $value): int
    {
        $targetCount = (int) $value;
        if ($targetCount < 1 || $targetCount > self::MAX_PROFILES_PER_BATCH) {
            throw ValidationException::withMessages([
                'target_count' => 'Target count must be between 1 and 200 profiles.',
            ]);
        }

        return $targetCount;
    }

    private function eligibleClientQuery(array $sourcePlatformIds)
    {
        return Client::query()
            ->with('platform:id,name,country')
            ->whereIn('platform_id', $sourcePlatformIds)
            ->where('profile_status', 'publish')
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->whereNull('closed_at')
            ->whereNull('duplicate_of')
            ->where(function ($query) {
                $query->whereNull('is_high_risk')->orWhere('is_high_risk', false);
            })
            ->where(function ($query) {
                $query->whereNull('source_presence_status')->orWhere('source_presence_status', 'present');
            })
            ->where(function ($query) {
                $query->whereNotNull('display_image_url')->orWhereNotNull('main_image_url');
            })
            ->orderByDesc('seo_score')
            ->orderByDesc('verified')
            ->orderByDesc('last_online_at')
            ->orderBy('name');
    }

    private function duplicateMap(PbnSite $site, Collection $clients): array
    {
        $ids = $clients->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (empty($ids)) {
            return [];
        }

        $sameSite = PbnSeedItem::query()
            ->where('pbn_site_id', (int) $site->id)
            ->whereIn('source_client_id', $ids)
            ->pluck('source_client_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $otherPbn = PbnSeedItem::query()
            ->where('pbn_site_id', '!=', (int) $site->id)
            ->whereIn('source_client_id', $ids)
            ->pluck('source_client_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'same_site' => array_flip($sameSite),
            'other_pbn' => array_flip($otherPbn),
        ];
    }

    private function candidatePayload(Client $client, array $duplicateMap): array
    {
        $duplicateState = 'none';
        if (isset($duplicateMap['same_site'][(int) $client->id])) {
            $duplicateState = 'existing_same_site';
        } elseif (isset($duplicateMap['other_pbn'][(int) $client->id])) {
            $duplicateState = 'existing_other_pbn';
        }

        $score = $client->seo_score !== null ? (int) $client->seo_score : null;
        $rankScore = ($score ?? 0) * 100;
        $rankScore += (bool) $client->verified ? 500 : 0;
        $rankScore += filled($client->display_image_url) ? 250 : 150;
        $rankScore += $client->last_online_at ? max(0, 180 - min(180, now()->diffInDays(\Illuminate\Support\Carbon::createFromTimestamp((int) $client->last_online_at)) * 6)) : 0;

        return [
            'client_id' => (int) $client->id,
            'source_platform_id' => (int) $client->platform_id,
            'source_platform_name' => $client->platform?->name,
            'source_wp_post_id' => (int) $client->wp_post_id,
            'name' => (string) $client->name,
            'city' => (string) $client->city,
            'profile_url' => $client->wp_profile_permalink ?: $client->wp_profile_url,
            'display_image_url' => $client->display_image_url ?: $client->main_image_url,
            'verified' => (bool) $client->verified,
            'seo_score' => $score,
            'last_online_at' => $client->last_online_at,
            'duplicate_state' => $duplicateState,
            'quality_rank_score' => $rankScore,
            'warnings' => $duplicateState === 'none' ? [] : [$duplicateState],
        ];
    }

    private function warnings(Collection $candidates, int $targetCount, array $selectedIds): array
    {
        $sameSiteDuplicates = $candidates->where('duplicate_state', 'existing_same_site')->count();
        $otherPbnDuplicates = $candidates->where('duplicate_state', 'existing_other_pbn')->count();
        $shortfall = max(0, $targetCount - count($selectedIds));
        $warnings = [];

        if ($sameSiteDuplicates > 0 || $otherPbnDuplicates > 0) {
            $warnings[] = [
                'type' => 'duplicates',
                'same_site_count' => $sameSiteDuplicates,
                'other_pbn_count' => $otherPbnDuplicates,
                'message' => "{$sameSiteDuplicates} same-site duplicates and {$otherPbnDuplicates} other-PBN duplicates found.",
            ];
        }
        if ($shortfall > 0) {
            $warnings[] = [
                'type' => 'shortfall',
                'shortfall' => $shortfall,
                'message' => "Only " . count($selectedIds) . " eligible non-duplicate profiles are available for a target of {$targetCount}.",
            ];
        }

        return $warnings;
    }

    private function assignTargets(Collection $candidates, array $targets, array $selectedIds): array
    {
        $candidateById = $candidates->keyBy('client_id');
        $assignments = [];
        $cursor = 0;

        foreach ($targets as $target) {
            for ($i = 0; $i < (int) $target['target_count'] && $cursor < count($selectedIds); $i++) {
                $clientId = (int) $selectedIds[$cursor];
                if ($candidateById->has($clientId)) {
                    $assignments[$clientId] = $target;
                }
                $cursor++;
            }
        }

        return $assignments;
    }

    private function targetKey(array $target): string
    {
        return implode(':', [
            $target['region_id'] ?? '',
            $target['city_id'] ?? '',
            $target['region_name'] ?? '',
            $target['city_name'] ?? '',
        ]);
    }

    private function sameSiteDuplicates(PbnSite $site, array $selectedClientIds): array
    {
        return PbnSeedItem::query()
            ->where('pbn_site_id', (int) $site->id)
            ->whereIn('source_client_id', $selectedClientIds)
            ->pluck('source_client_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function clientStillEligible(Client $client): bool
    {
        return (string) $client->profile_status === 'publish'
            && (int) $client->wp_post_id > 0
            && $client->closed_at === null
            && $client->duplicate_of === null
            && !(bool) $client->is_high_risk
            && ($client->source_presence_status === null || $client->source_presence_status === 'present')
            && (filled($client->display_image_url) || filled($client->main_image_url));
    }

    private function eligibilitySnapshot(Client $client): array
    {
        return [
            'profile_status' => $client->profile_status,
            'source_wp_post_id' => (int) $client->wp_post_id,
            'closed' => $client->closed_at !== null,
            'duplicate_of' => $client->duplicate_of,
            'is_high_risk' => (bool) $client->is_high_risk,
            'has_media' => filled($client->display_image_url) || filled($client->main_image_url),
        ];
    }

    private function itemPayloadHash(PbnSite $site, Client $client, array $target, array $copyPolicy): string
    {
        return hash('sha256', json_encode([
            'pbn_site_id' => (int) $site->id,
            'source_platform_id' => (int) $client->platform_id,
            'source_client_id' => (int) $client->id,
            'source_wp_post_id' => (int) $client->wp_post_id,
            'target' => $target,
            'copy_policy' => $copyPolicy,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function batchSummary(PbnSeedBatch $batch): array
    {
        return [
            'selected' => (int) $batch->selected_count,
            'created' => (int) $batch->created_count,
            'failed' => (int) $batch->failed_count,
            'reverted' => (int) $batch->reverted_count,
            'status' => (string) $batch->status,
            'policy' => $this->appliedPolicySummary($batch),
        ];
    }

    /**
     * What the batch actually applied, counted from the per-item decisions
     * rather than from the requested percentages — so a batch that was queued
     * at 10% VIP and then partly reverted reports what is really out there.
     *
     * @return array<string, mixed>
     */
    private function appliedPolicySummary(PbnSeedBatch $batch): array
    {
        $items = $batch->relationLoaded('items')
            ? $batch->items
            : $batch->items()->get(['applied_policy', 'release_at']);

        $summary = [
            'featured' => 0,
            'premium' => 0,
            'verified' => 0,
            'bio_rewritten' => 0,
            'bio_fallback' => 0,
            'bio_cost_usd' => 0.0,
            'awaiting_release' => 0,
            'next_release_at' => null,
        ];

        foreach ($items as $item) {
            $policy = is_array($item->applied_policy) ? $item->applied_policy : [];

            if (($policy['badge'] ?? '') === PbnSeedPolicyResolver::BADGE_FEATURED) {
                $summary['featured']++;
            }
            if (($policy['badge'] ?? '') === PbnSeedPolicyResolver::BADGE_PREMIUM) {
                $summary['premium']++;
            }
            if (!empty($policy['verified'])) {
                $summary['verified']++;
            }
            if (($policy['bio_result'] ?? null) === PbnSeedBioService::RESULT_REWRITTEN) {
                $summary['bio_rewritten']++;
            }
            if (($policy['bio_result'] ?? null) === PbnSeedBioService::RESULT_FALLBACK) {
                $summary['bio_fallback']++;
            }
            $summary['bio_cost_usd'] += (float) ($policy['bio_cost_usd'] ?? 0);

            if ($item->release_at && $item->release_at->isFuture()) {
                $summary['awaiting_release']++;
                if ($summary['next_release_at'] === null || $item->release_at->lt($summary['next_release_at'])) {
                    $summary['next_release_at'] = $item->release_at;
                }
            }
        }

        $summary['bio_cost_usd'] = round($summary['bio_cost_usd'], 4);
        $summary['next_release_at'] = optional($summary['next_release_at'])->toDateTimeString();

        return $summary;
    }
}
