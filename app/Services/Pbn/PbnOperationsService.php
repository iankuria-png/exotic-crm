<?php

namespace App\Services\Pbn;

use App\Models\PbnSeedBatch;
use App\Models\PbnSeedEvent;
use App\Models\PbnSeedItem;
use App\Models\PbnSite;
use App\Models\User;
use App\Services\DynamicDatabaseService;
use App\Services\MarketAuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PbnOperationsService
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly PbnSeedPreviewService $previewService
    ) {
    }

    public function overview(User $actor): array
    {
        $siteIds = $this->visibleSiteIds($actor);
        $siteQuery = PbnSite::query()->whereIn('id', $siteIds);
        $batchQuery = PbnSeedBatch::query()->whereIn('pbn_site_id', $siteIds);
        $itemQuery = PbnSeedItem::query()->whereIn('pbn_site_id', $siteIds);

        $activeStatuses = [PbnSeedBatch::STATUS_QUEUED, PbnSeedBatch::STATUS_RUNNING];
        $createdStatuses = [PbnSeedItem::STATUS_CREATED, PbnSeedItem::STATUS_MEDIA_PENDING];

        $lastSevenDays = now()->subDays(7);

        return [
            'sites' => [
                'total' => (clone $siteQuery)->count(),
                'ready' => (clone $siteQuery)->where('is_active', true)->where('last_status', 'ready')->count(),
                'blocked' => (clone $siteQuery)->where(function (Builder $query): void {
                    $query->where('is_active', false)->orWhere('last_status', '!=', 'ready');
                })->count(),
            ],
            'batches' => [
                'active' => (clone $batchQuery)->whereIn('status', $activeStatuses)->count(),
                'completed' => (clone $batchQuery)->where('status', PbnSeedBatch::STATUS_COMPLETED)->count(),
                'partial' => (clone $batchQuery)->where('status', PbnSeedBatch::STATUS_PARTIAL)->count(),
                'failed' => (clone $batchQuery)->where('status', PbnSeedBatch::STATUS_FAILED)->count(),
                'reverted' => (clone $batchQuery)->where('status', PbnSeedBatch::STATUS_REVERTED)->count(),
            ],
            'items' => [
                'created' => (clone $itemQuery)->whereIn('status', $createdStatuses)->count(),
                'created_last_7_days' => (clone $itemQuery)->whereIn('status', $createdStatuses)->where('updated_at', '>=', $lastSevenDays)->count(),
                'media_pending' => (clone $itemQuery)->where('status', PbnSeedItem::STATUS_MEDIA_PENDING)->count(),
                'failed' => (clone $itemQuery)->where('status', PbnSeedItem::STATUS_FAILED)->count(),
                'reverted' => (clone $itemQuery)->where('status', PbnSeedItem::STATUS_REVERTED)->count(),
                'skipped_duplicates' => (clone $itemQuery)->where('status', PbnSeedItem::STATUS_SKIPPED_DUPLICATE)->count(),
            ],
            'recent_failures' => $this->recentFailures($siteIds),
            'can_revert' => $this->marketAuthorizationService->isManager($actor),
        ];
    }

    public function batches(User $actor, array $filters = []): array
    {
        $query = PbnSeedBatch::query()
            ->with(['pbnSite:id,name,domain,last_status,is_active', 'creator:id,name,email,role'])
            ->whereIn('pbn_site_id', $this->visibleSiteIds($actor))
            ->latest('id');

        if (!empty($filters['pbn_site_id'])) {
            $query->where('pbn_site_id', (int) $filters['pbn_site_id']);
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', (string) $filters['status']);
        }
        if (!empty($filters['q'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['q'])) . '%';
            $query->where(function (Builder $query) use ($term): void {
                $query->where('notes', 'like', $term)
                    ->orWhereHas('pbnSite', fn (Builder $siteQuery) => $siteQuery
                        ->where('name', 'like', $term)
                        ->orWhere('domain', 'like', $term))
                    ->orWhereHas('creator', fn (Builder $creatorQuery) => $creatorQuery
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term));
            });
        }

        return $this->serializeBatchPaginator($query->paginate($this->perPage($filters)));
    }

    public function batch(User $actor, PbnSeedBatch $batch): array
    {
        $this->ensureBatchVisible($actor, $batch);

        $batch->loadMissing([
            'pbnSite:id,name,domain,last_status,is_active',
            'creator:id,name,email,role',
            'reverter:id,name,email,role',
            'targets',
        ]);

        return [
            'batch' => $this->serializeBatch($batch),
            'targets' => $batch->targets->map(fn ($target) => [
                'id' => (int) $target->id,
                'region_name' => $target->region_name,
                'city_name' => $target->city_name,
                'target_count' => (int) $target->target_count,
                'selected_count' => (int) $target->selected_count,
                'created_count' => (int) $target->created_count,
            ])->values(),
            'revert_preview' => $this->revertPreview($actor, $batch),
        ];
    }

    public function items(User $actor, array $filters = []): array
    {
        $query = PbnSeedItem::query()
            ->with([
                'batch:id,status,created_by,created_at',
                'pbnSite:id,name,domain',
                'sourcePlatform:id,name,country,domain',
                'sourceClient:id,name,city,phone_normalized,display_image_url,main_image_url,wp_profile_permalink,wp_profile_url',
            ])
            ->whereIn('pbn_site_id', $this->visibleSiteIds($actor))
            ->latest('id');

        if (!empty($filters['pbn_site_id'])) {
            $query->where('pbn_site_id', (int) $filters['pbn_site_id']);
        }
        if (!empty($filters['batch_id'])) {
            $query->where('batch_id', (int) $filters['batch_id']);
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', (string) $filters['status']);
        }
        if (!empty($filters['source_platform_id'])) {
            $query->where('source_platform_id', (int) $filters['source_platform_id']);
        }
        if (!empty($filters['q'])) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['q'])) . '%';
            $query->where(function (Builder $query) use ($term): void {
                $query->where('source_wp_post_id', 'like', $term)
                    ->orWhere('target_wp_post_id', 'like', $term)
                    ->orWhere('failure_reason', 'like', $term)
                    ->orWhereHas('sourceClient', fn (Builder $clientQuery) => $clientQuery
                        ->where('name', 'like', $term)
                        ->orWhere('city', 'like', $term)
                        ->orWhere('phone_normalized', 'like', $term));
            });
        }

        return $this->serializeItemPaginator($query->paginate($this->perPage($filters)));
    }

    public function events(User $actor, array $filters = []): array
    {
        $query = PbnSeedEvent::query()
            ->with(['pbnSite:id,name,domain', 'actor:id,name,email,role'])
            ->whereIn('pbn_site_id', $this->visibleSiteIds($actor))
            ->latest('id');

        if (!empty($filters['pbn_site_id'])) {
            $query->where('pbn_site_id', (int) $filters['pbn_site_id']);
        }
        if (!empty($filters['batch_id'])) {
            $query->where('batch_id', (int) $filters['batch_id']);
        }
        if (!empty($filters['level']) && $filters['level'] !== 'all') {
            $query->where('level', (string) $filters['level']);
        }

        $paginator = $query->paginate($this->perPage($filters, 25));

        return [
            'data' => collect($paginator->items())->map(fn (PbnSeedEvent $event) => [
                'id' => (int) $event->id,
                'pbn_site_id' => (int) $event->pbn_site_id,
                'site_name' => $event->pbnSite?->name,
                'batch_id' => $event->batch_id ? (int) $event->batch_id : null,
                'item_id' => $event->item_id ? (int) $event->item_id : null,
                'type' => (string) $event->type,
                'level' => (string) $event->level,
                'message' => (string) $event->message,
                'context' => $event->context ?: [],
                'actor' => $event->actor ? [
                    'id' => (int) $event->actor->id,
                    'name' => $event->actor->name,
                    'email' => $event->actor->email,
                ] : null,
                'created_at' => optional($event->created_at)->toDateTimeString(),
            ])->values(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    public function revertPreview(User $actor, PbnSeedBatch $batch): array
    {
        if (!$this->marketAuthorizationService->isManager($actor)) {
            return [
                'can_revert' => false,
                'eligible_count' => 0,
                'message' => 'Only admin or sub-admin users can revert PBN seed batches.',
            ];
        }

        $this->ensureBatchVisible($actor, $batch);
        $eligible = $batch->items()
            ->whereIn('status', [PbnSeedItem::STATUS_CREATED, PbnSeedItem::STATUS_MEDIA_PENDING])
            ->whereNotNull('target_wp_post_id')
            ->count();
        $alreadyReverted = $batch->items()->where('status', PbnSeedItem::STATUS_REVERTED)->count();
        $blocked = $batch->items()
            ->whereNotIn('status', [PbnSeedItem::STATUS_CREATED, PbnSeedItem::STATUS_MEDIA_PENDING, PbnSeedItem::STATUS_REVERTED])
            ->count();

        return [
            'can_revert' => $eligible > 0,
            'eligible_count' => $eligible,
            'already_reverted_count' => $alreadyReverted,
            'blocked_count' => $blocked,
            'message' => $eligible > 0
                ? "{$eligible} destination profiles can be moved private."
                : 'No created destination profiles are available to revert.',
        ];
    }

    public function revertBatch(User $actor, PbnSeedBatch $batch, string $reason): array
    {
        $this->marketAuthorizationService->ensureManager($actor, 'Only admin or sub-admin users can revert PBN seed batches.');
        $this->ensureBatchVisible($actor, $batch);
        $batch->loadMissing('pbnSite');

        $site = $batch->pbnSite;
        if (!$site || !$site->databaseCredentialsReady()) {
            throw ValidationException::withMessages([
                'pbn_site_id' => 'PBN database credentials are required before reverting destination profiles.',
            ]);
        }

        $items = $batch->items()
            ->whereIn('status', [PbnSeedItem::STATUS_CREATED, PbnSeedItem::STATUS_MEDIA_PENDING])
            ->whereNotNull('target_wp_post_id')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return [
                'message' => 'No created PBN seed items were available to revert.',
                ...$this->batch($actor, $batch->fresh()),
            ];
        }

        $connectionName = 'pbn_revert_site_' . (int) $site->id;
        DynamicDatabaseService::switchConnection($connectionName, $site->getConnectionConfig());
        if (!Schema::connection($connectionName)->hasTable('posts')) {
            throw ValidationException::withMessages([
                'pbn_site_id' => 'Destination WordPress posts table could not be found.',
            ]);
        }

        $reverted = 0;
        $failed = 0;
        foreach ($items as $item) {
            try {
                $post = DB::connection($connectionName)
                    ->table('posts')
                    ->where('ID', (int) $item->target_wp_post_id)
                    ->first(['ID', 'post_status']);

                if (!$post) {
                    throw new \RuntimeException('Destination WordPress post was not found.');
                }

                DB::connection($connectionName)
                    ->table('posts')
                    ->where('ID', (int) $item->target_wp_post_id)
                    ->update([
                        'post_status' => 'private',
                        'post_modified' => now()->format('Y-m-d H:i:s'),
                        'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    ]);

                $item->forceFill([
                    'status' => PbnSeedItem::STATUS_REVERTED,
                    'original_target_post_status' => (string) $post->post_status,
                    'reverted_at' => now(),
                    'reverted_by' => (int) $actor->id,
                    'revert_reason' => Str::limit($reason, 1000, ''),
                    'revert_failure_reason' => null,
                ])->save();

                $this->recordEvent($batch, $item, $actor, 'item_reverted', 'warning', 'PBN destination profile moved private.', [
                    'target_wp_post_id' => (int) $item->target_wp_post_id,
                    'original_status' => (string) $post->post_status,
                ]);
                $reverted++;
            } catch (\Throwable $exception) {
                $item->forceFill([
                    'revert_failure_reason' => Str::limit($exception->getMessage(), 1000, ''),
                ])->save();
                $this->recordEvent($batch, $item, $actor, 'item_revert_failed', 'error', 'PBN destination profile revert failed.', [
                    'target_wp_post_id' => (int) $item->target_wp_post_id,
                    'failure_reason' => Str::limit($exception->getMessage(), 500, ''),
                ]);
                $failed++;
            }
        }

        $this->previewService->refreshBatchCounts($batch->fresh(['targets']));
        $fresh = $batch->fresh();
        $fresh->forceFill([
            'reverted_at' => $reverted > 0 ? now() : $fresh->reverted_at,
            'reverted_by' => $reverted > 0 ? (int) $actor->id : $fresh->reverted_by,
            'revert_reason' => $reverted > 0 ? Str::limit($reason, 1000, '') : $fresh->revert_reason,
        ])->save();

        $this->recordEvent($fresh, null, $actor, 'batch_reverted', $failed > 0 ? 'warning' : 'info', 'PBN seed batch revert completed.', [
            'reverted_count' => $reverted,
            'failed_count' => $failed,
        ]);

        return [
            'message' => $failed > 0
                ? "{$reverted} PBN profiles reverted; {$failed} failed."
                : "{$reverted} PBN profiles reverted.",
            ...$this->batch($actor, $fresh->fresh()),
        ];
    }

    public function ensureBatchVisible(User $actor, PbnSeedBatch $batch): void
    {
        if (!in_array((int) $batch->pbn_site_id, $this->visibleSiteIds($actor), true)) {
            abort(403, 'You do not have permission to view this PBN seed batch.');
        }

        foreach ($batch->source_platform_ids ?: [] as $sourceId) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($actor, (int) $sourceId);
        }
    }

    private function visibleSiteIds(User $actor): array
    {
        $query = PbnSite::query();
        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($actor);
        if (is_array($allowedPlatformIds)) {
            if (empty($allowedPlatformIds)) {
                return [];
            }

            $query->whereHas('sourcePlatforms', fn (Builder $sourceQuery) => $sourceQuery->whereIn('platforms.id', $allowedPlatformIds));
            if ($actor->role === MarketAuthorizationService::ROLE_SALES) {
                $query->where('is_active', true);
            }
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function recentFailures(array $siteIds): array
    {
        return PbnSeedItem::query()
            ->with(['pbnSite:id,name,domain', 'sourceClient:id,name', 'sourcePlatform:id,name'])
            ->whereIn('pbn_site_id', $siteIds)
            ->where('status', PbnSeedItem::STATUS_FAILED)
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (PbnSeedItem $item) => $this->serializeItem($item))
            ->values()
            ->all();
    }

    private function serializeBatchPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn (PbnSeedBatch $batch) => $this->serializeBatch($batch))->values(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    private function serializeItemPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn (PbnSeedItem $item) => $this->serializeItem($item))->values(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    private function serializeBatch(PbnSeedBatch $batch): array
    {
        return [
            'id' => (int) $batch->id,
            'pbn_site_id' => (int) $batch->pbn_site_id,
            'site' => $batch->pbnSite ? [
                'id' => (int) $batch->pbnSite->id,
                'name' => $batch->pbnSite->name,
                'domain' => $batch->pbnSite->domain,
                'status' => $batch->pbnSite->last_status,
                'is_active' => (bool) $batch->pbnSite->is_active,
            ] : null,
            'creator' => $batch->creator ? [
                'id' => (int) $batch->creator->id,
                'name' => $batch->creator->name,
                'email' => $batch->creator->email,
                'role' => $batch->creator->role,
            ] : null,
            'reverter' => $batch->reverter ? [
                'id' => (int) $batch->reverter->id,
                'name' => $batch->reverter->name,
                'email' => $batch->reverter->email,
                'role' => $batch->reverter->role,
            ] : null,
            'status' => (string) $batch->status,
            'source_platform_ids' => $batch->source_platform_ids ?: [],
            'target_count' => (int) $batch->target_count,
            'selected_count' => (int) $batch->selected_count,
            'created_count' => (int) $batch->created_count,
            'failed_count' => (int) $batch->failed_count,
            'reverted_count' => (int) $batch->reverted_count,
            'warnings' => $batch->warnings ?: [],
            'notes' => $batch->notes,
            'started_at' => optional($batch->started_at)->toDateTimeString(),
            'completed_at' => optional($batch->completed_at)->toDateTimeString(),
            'reverted_at' => optional($batch->reverted_at)->toDateTimeString(),
            'revert_reason' => $batch->revert_reason,
            'created_at' => optional($batch->created_at)->toDateTimeString(),
            'updated_at' => optional($batch->updated_at)->toDateTimeString(),
        ];
    }

    private function serializeItem(PbnSeedItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'batch_id' => (int) $item->batch_id,
            'pbn_site_id' => (int) $item->pbn_site_id,
            'site_name' => $item->pbnSite?->name,
            'site_domain' => $item->pbnSite?->domain,
            'source_platform_id' => (int) $item->source_platform_id,
            'source_platform_name' => $item->sourcePlatform?->name,
            'source_client_id' => (int) $item->source_client_id,
            'source_wp_post_id' => (int) $item->source_wp_post_id,
            'target_wp_post_id' => $item->target_wp_post_id ? (int) $item->target_wp_post_id : null,
            'target_wp_user_id' => $item->target_wp_user_id ? (int) $item->target_wp_user_id : null,
            'target_region_id' => $item->target_region_id ? (int) $item->target_region_id : null,
            'target_city_id' => $item->target_city_id ? (int) $item->target_city_id : null,
            'status' => (string) $item->status,
            'duplicate_state' => (string) $item->duplicate_state,
            'quality_score' => $item->quality_score,
            'failure_reason' => $item->failure_reason,
            'revert_failure_reason' => $item->revert_failure_reason,
            'original_target_post_status' => $item->original_target_post_status,
            'source_client' => $item->sourceClient ? [
                'id' => (int) $item->sourceClient->id,
                'name' => $item->sourceClient->name,
                'city' => $item->sourceClient->city,
                'phone' => $item->sourceClient->phone_normalized,
                'profile_url' => $item->sourceClient->wp_profile_permalink ?: $item->sourceClient->wp_profile_url,
                'display_image_url' => $item->sourceClient->display_image_url ?: $item->sourceClient->main_image_url,
            ] : null,
            'batch_status' => $item->batch?->status,
            'provision_started_at' => optional($item->provision_started_at)->toDateTimeString(),
            'provision_finished_at' => optional($item->provision_finished_at)->toDateTimeString(),
            'reverted_at' => optional($item->reverted_at)->toDateTimeString(),
            'created_at' => optional($item->created_at)->toDateTimeString(),
            'updated_at' => optional($item->updated_at)->toDateTimeString(),
        ];
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function perPage(array $filters, int $default = 50): int
    {
        return max(10, min(100, (int) ($filters['per_page'] ?? $default)));
    }

    private function recordEvent(PbnSeedBatch $batch, ?PbnSeedItem $item, User $actor, string $type, string $level, string $message, array $context = []): void
    {
        PbnSeedEvent::create([
            'pbn_site_id' => (int) $batch->pbn_site_id,
            'batch_id' => (int) $batch->id,
            'item_id' => $item?->id,
            'actor_id' => (int) $actor->id,
            'type' => $type,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
