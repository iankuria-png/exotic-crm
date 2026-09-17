<?php

namespace App\Services;

use App\Exceptions\ClientLifecycleMutationException;
use App\Models\Client;
use App\Models\ClientSyncExclusion;
use App\Models\TimelineEvent;
use App\Support\CrmAuditAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ClientDeletionService
{
    private const PENDING_REASON_PREFIX = '[pending_client_delete] ';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly ClientLifecycleMutationLock $lifecycleLock,
    ) {}

    public function previewDeletion(Client $client): array
    {
        $client->loadMissing('platform');
        $hasEntitlement = $client->deals()->currentlyActive()->exists();
        $isAgency = (string) ($client->client_type ?? 'escort') === 'agency';

        return [
            'client_id' => (int) $client->id,
            'name' => (string) $client->name,
            'platform_id' => (int) $client->platform_id,
            'platform_name' => $client->platform?->name,
            'deals_count' => $client->deals()->count(),
            'payments_count' => $client->payments()->count(),
            'notes_count' => $client->notes()->count(),
            'leads_count' => $client->leads()->count(),
            'timeline_events_count' => TimelineEvent::query()->forEntity('client', (int) $client->id)->count(),
            'has_active_deal' => $hasEntitlement,
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'can_delete' => ! $isAgency && ! $hasEntitlement,
            'delete_blocked_reason_code' => $isAgency ? 'agency_protected' : ($hasEntitlement ? 'paid_entitlement' : null),
            'delete_blocked_reason' => $isAgency
                ? 'Agency profiles cannot be deleted from the CRM. Delete or reassign them in WordPress, then let tombstone sync prune the CRM row.'
                : ($hasEntitlement ? ClientLifecycleMutationException::paidEntitlement()->getMessage() : null),
        ];
    }

    public function deleteClient(Client $client, int $actorId, string $reason, array $filters = [], ?array $platformIds = null): array
    {
        return $this->lifecycleLock->run($client, function () use ($client, $actorId, $reason, $filters, $platformIds): array {
            $fresh = $client->fresh(['platform']) ?? $client;
            if ($filters !== [] && ! $this->matchesFilters($fresh, $filters, $platformIds)) {
                throw new ClientLifecycleMutationException('This profile no longer matches the deletion filters.', 'no_longer_matching');
            }
            $this->assertOperatorDeleteAllowed($fresh);
            $this->assertNoPaidEntitlement($fresh);

            return $this->deleteOperatorClientLocked($fresh, $actorId, $reason);
        });
    }

    public function deleteClientFromSourcePrune(Client $client, ?int $actorId, string $reason): array
    {
        return $this->lifecycleLock->run($client, function () use ($client, $actorId, $reason): array {
            $fresh = $client->fresh(['platform']) ?? $client;
            $this->assertNoPaidEntitlement($fresh);

            return $this->finalizeLocalDeletion($fresh, $actorId, $reason, CrmAuditAction::CLIENT_AUTO_PURGE, true, false);
        });
    }

    public function bulkPreview(array $filters, array $clientIds, ?array $platformIds): array
    {
        $query = $this->previewQuery($platformIds);
        if ($clientIds !== []) {
            $query->whereIn('clients.id', $clientIds);
        } else {
            $this->applyFilters($query, $filters);
        }

        $totalCount = (clone $query)->count();
        $clients = $query->orderByDesc('clients.updated_at')->limit(500)->get();
        $cutoff = ! empty($filters['inactive_days']) ? now()->subDays((int) $filters['inactive_days']) : null;
        $baseCount = $totalCount;
        $neverSeenExcluded = 0;

        if ($clientIds === [] && $filters !== []) {
            $base = $this->previewQuery($platformIds);
            $this->applyBaseCleanupFilters($base, $filters);
            $baseCount = (clone $base)->count();
            if (empty($filters['include_never_seen'])) {
                $neverSeenExcluded = (clone $base)->whereNull('last_online_at')->count();
            }
        }

        return [
            'total_count' => $totalCount,
            'capped' => $totalCount > 500,
            'never_seen_included' => $clients->whereNull('last_online_at')->count(),
            'never_seen_excluded' => $neverSeenExcluded,
            'blocked_count' => max(0, $baseCount - $totalCount),
            'cutoff_at' => $cutoff?->toDateTimeString(),
            'clients' => $clients->map(fn (Client $client) => $this->presentPreviewClient($client, $filters))->values()->all(),
        ];
    }

    public function bulkDelete(array $clientIds, array $filters, int $actorId, string $reason, ?array $platformIds = null): array
    {
        $deletedCount = 0;
        $failed = [];
        $skipped = [];
        $deletedByPlatform = [];

        foreach ($clientIds as $clientId) {
            $client = Client::query()->with('platform:id,name')->find((int) $clientId);
            if (! $client || ! $this->isPlatformAccessible($client, $platformIds)) {
                $skipped[] = ['id' => (int) $clientId, 'reason' => 'changed'];

                continue;
            }
            if ($filters !== [] && ! $this->matchesFilters($client, $filters, $platformIds)) {
                $skipped[] = ['id' => (int) $client->id, 'name' => (string) $client->name, 'reason' => 'no_longer_matching'];

                continue;
            }

            try {
                $result = $this->deleteClient($client, $actorId, $reason, $filters, $platformIds);
                if (! ($result['deleted'] ?? false)) {
                    $failed[] = ['id' => (int) $client->id, 'name' => (string) $client->name, 'state' => (string) ($result['state'] ?? 'source_delete_unknown')];

                    continue;
                }
                $deletedCount++;
                $deletedByPlatform[(int) $client->platform_id][] = [
                    'client_id' => (int) $client->id,
                    'name' => (string) $client->name,
                    'wp_deleted' => (bool) ($result['wp_deleted'] ?? false),
                ];
            } catch (ClientLifecycleMutationException $exception) {
                $skipped[] = ['id' => (int) $client->id, 'name' => (string) $client->name, 'reason' => $exception->reasonCode];
            } catch (ValidationException) {
                $skipped[] = ['id' => (int) $client->id, 'name' => (string) $client->name, 'reason' => 'agency_protected'];
            } catch (\Throwable $exception) {
                $failed[] = ['id' => (int) $client->id, 'state' => 'source_delete_unknown', 'error' => $exception->getMessage()];
            }
        }

        foreach ($deletedByPlatform as $platformId => $entries) {
            $this->auditService->record([
                'platform_id' => $platformId,
                'actor_id' => $actorId,
                'action' => CrmAuditAction::CLIENT_BULK_DELETE,
                'entity_type' => 'platform',
                'entity_id' => $platformId,
                'before_state' => ['client_ids' => array_column($entries, 'client_id'), 'filters' => $filters],
                'after_state' => [
                    'deleted_count' => count($entries),
                    'clients' => $entries,
                    'failed_count' => count($failed),
                    'skipped_count' => count($skipped),
                ],
                'reason' => $reason,
            ]);
        }

        return ['deleted_count' => $deletedCount, 'failed' => $failed, 'skipped' => $skipped];
    }

    private function deleteOperatorClientLocked(Client $client, int $actorId, string $reason): array
    {
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return $this->finalizeLocalDeletion($client, $actorId, $reason, CrmAuditAction::CLIENT_DELETE, false, false);
        }

        ClientSyncExclusion::query()->updateOrCreate(
            ['platform_id' => (int) $client->platform_id, 'wp_post_id' => $wpPostId],
            ['reason' => mb_strimwidth(self::PENDING_REASON_PREFIX.$reason, 0, 500), 'deleted_by' => $actorId, 'created_at' => now()],
        );

        $source = WpSyncService::forPlatform((int) $client->platform_id)->deleteClientWithPresenceCheck($wpPostId);
        $sourceState = (string) ($source['state'] ?? 'source_delete_unknown');
        if ($sourceState === 'source_not_deleted') {
            ClientSyncExclusion::query()->where(['platform_id' => (int) $client->platform_id, 'wp_post_id' => $wpPostId])->delete();

            return ['deleted' => false, 'wp_deleted' => false, 'state' => 'source_not_deleted', 'impact' => $this->previewDeletion($client)];
        }
        if ($sourceState === 'source_delete_unknown') {
            return ['deleted' => false, 'wp_deleted' => false, 'state' => 'source_delete_unknown', 'impact' => $this->previewDeletion($client)];
        }

        try {
            return $this->finalizeLocalDeletion($client, $actorId, $reason, CrmAuditAction::CLIENT_DELETE, false, $sourceState === 'source_deleted');
        } catch (\Throwable $exception) {
            Log::error('WordPress client deleted but CRM deletion remains pending.', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $client->platform_id,
                'wp_post_id' => $wpPostId,
                'error' => $exception->getMessage(),
            ]);

            return ['deleted' => false, 'wp_deleted' => true, 'state' => 'source_deleted_crm_pending', 'error' => $exception->getMessage(), 'impact' => $this->previewDeletion($client)];
        }
    }

    private function finalizeLocalDeletion(Client $client, ?int $actorId, string $reason, string $auditAction, bool $sourcePruned, bool $wpDeleted): array
    {
        $client->loadMissing('platform');
        $impact = $this->previewDeletion($client);
        $clientId = (int) $client->id;
        $platformId = (int) $client->platform_id;
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        $dealIds = $client->deals()->pluck('id');
        $beforeState = ['client' => $client->only(['id', 'name', 'platform_id', 'wp_post_id', 'phone_normalized', 'email', 'profile_status']), 'impact' => $impact];

        DB::transaction(function () use ($client, $clientId, $dealIds): void {
            Client::query()->where('duplicate_of', $clientId)->update(['duplicate_of' => null]);
            DB::table('push_campaign_items')->where('client_id', $clientId)->update(['client_id' => null]);
            DB::table('leads')->where('converted_client_id', $clientId)->update(['converted_client_id' => null]);
            DB::table('payments')->where('client_id', $clientId)->update(['client_id' => null]);
            if ($dealIds->isNotEmpty()) {
                DB::table('payments')->whereIn('deal_id', $dealIds->all())->update(['deal_id' => null]);
            }
            DB::table('client_notes')->where('client_id', $clientId)->delete();
            DB::table('client_credential_dispatches')->where('client_id', $clientId)->delete();
            DB::table('wallet_transactions')->where('client_id', $clientId)->delete();
            DB::table('client_retention_insight_history')->where('client_id', $clientId)->delete();
            DB::table('client_retention_insights')->where('client_id', $clientId)->delete();
            $client->deals()->delete();
            $client->delete();
        });

        if (! $sourcePruned && $wpPostId > 0) {
            ClientSyncExclusion::query()->where(['platform_id' => $platformId, 'wp_post_id' => $wpPostId])
                ->update(['reason' => mb_strimwidth('[client_deleted] '.$reason, 0, 500)]);
        }

        $this->auditService->record([
            'platform_id' => $platformId,
            'actor_id' => $actorId,
            'action' => $auditAction,
            'entity_type' => 'client',
            'entity_id' => $clientId,
            'before_state' => $beforeState,
            'after_state' => ['deleted' => true, 'wp_deleted' => $wpDeleted, 'source_pruned' => $sourcePruned, 'impact' => $impact],
            'reason' => $reason,
        ]);

        return ['deleted' => true, 'wp_deleted' => $wpDeleted, 'source_pruned' => $sourcePruned, 'state' => 'completed', 'impact' => $impact];
    }

    private function previewQuery(?array $platformIds): Builder
    {
        $query = Client::query()->select('clients.*')->with('platform:id,name')
            ->withCount(['deals', 'payments', 'notes', 'leads'])
            ->withCount(['deals as active_deals_count' => fn ($builder) => $builder->currentlyActive()]);
        $query->selectSub(
            TimelineEvent::query()->selectRaw('count(*)')->whereColumn('entity_id', 'clients.id')->where('entity_type', 'client'),
            'timeline_events_count',
        );
        if (is_array($platformIds)) {
            $platformIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('clients.platform_id', $platformIds);
        }

        return $query;
    }

    private function applyBaseCleanupFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['platform_id'])) {
            $query->where('clients.platform_id', (int) $filters['platform_id']);
        }
        if (! empty($filters['offline_only'])) {
            $query->where('profile_status', 'private');
        }
        if (! empty($filters['inactive_days'])) {
            $query->inactiveFor((int) $filters['inactive_days'], true);
        }
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['platform_id'])) {
            $query->where('clients.platform_id', (int) $filters['platform_id']);
        }
        if (! empty($filters['offline_only'])) {
            $query->where('profile_status', 'private');
        }
        if (! empty($filters['inactive_days'])) {
            $query->inactiveFor((int) $filters['inactive_days'], ! empty($filters['include_never_seen']));
        }
        if (! empty($filters['has_no_chat'])) {
            $query->hasNoChat();
        }
        if (! empty($filters['has_no_subscription_or_payment'])) {
            $query->hasNoSubscriptionOrPayment();
        }
        if (! empty($filters['seo_placeholders'])) {
            ClientSeoPlaceholderService::applyCandidateScope($query);
        }
        $query->whereDoesntHave('deals', fn ($deal) => $deal->currentlyActive());
    }

    private function matchesFilters(Client $client, array $filters, ?array $platformIds): bool
    {
        $query = Client::query()->whereKey($client->id);
        if (is_array($platformIds)) {
            $platformIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('platform_id', $platformIds);
        }
        $this->applyFilters($query, $filters);

        return $query->exists();
    }

    private function presentPreviewClient(Client $client, array $filters): array
    {
        $hasEntitlement = (int) ($client->active_deals_count ?? 0) > 0;
        $isAgency = (string) ($client->client_type ?? 'escort') === 'agency';

        return [
            'client_id' => (int) $client->id,
            'name' => (string) $client->name,
            'platform_id' => (int) $client->platform_id,
            'platform_name' => $client->platform?->name,
            'deals_count' => (int) ($client->deals_count ?? 0),
            'payments_count' => (int) ($client->payments_count ?? 0),
            'notes_count' => (int) ($client->notes_count ?? 0),
            'leads_count' => (int) ($client->leads_count ?? 0),
            'timeline_events_count' => (int) ($client->timeline_events_count ?? 0),
            'has_active_deal' => $hasEntitlement,
            'last_online_at' => $client->last_online_at,
            'wp_created_at' => optional($client->wp_created_at)->toDateTimeString(),
            'match_reasons' => array_values(array_filter([
                ! empty($filters['offline_only']) ? 'offline' : null,
                ! empty($filters['inactive_days']) ? 'inactive_'.(int) $filters['inactive_days'].'_days' : null,
                $client->last_online_at === null ? 'never_seen' : null,
            ])),
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'client_type' => (string) ($client->client_type ?? 'escort'),
            'can_delete' => ! $isAgency && ! $hasEntitlement,
            'delete_blocked_reason_code' => $isAgency ? 'agency_protected' : ($hasEntitlement ? 'paid_entitlement' : null),
        ];
    }

    private function isPlatformAccessible(Client $client, ?array $platformIds): bool
    {
        return ! is_array($platformIds) || in_array((int) $client->platform_id, $platformIds, true);
    }

    private function assertNoPaidEntitlement(Client $client): void
    {
        if ($client->deals()->currentlyActive()->exists()) {
            throw ClientLifecycleMutationException::paidEntitlement();
        }
    }

    private function assertOperatorDeleteAllowed(Client $client): void
    {
        if ((string) ($client->client_type ?? 'escort') !== 'agency') {
            return;
        }

        throw ValidationException::withMessages([
            'client_type' => 'Agency profiles cannot be deleted from the CRM. Delete or reassign them in WordPress, then let tombstone sync prune the CRM row.',
        ]);
    }
}
