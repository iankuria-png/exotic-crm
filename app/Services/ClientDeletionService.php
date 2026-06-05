<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientSyncExclusion;
use App\Models\TimelineEvent;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientDeletionService
{
    public function __construct(
        private readonly AuditService $auditService
    ) {
    }

    public function previewDeletion(Client $client): array
    {
        $client->loadMissing('platform');

        return [
            'client_id' => (int) $client->id,
            'name' => (string) $client->name,
            'platform_id' => (int) $client->platform_id,
            'platform_name' => $client->platform?->name,
            'deals_count' => $client->deals()->count(),
            'payments_count' => $client->payments()->count(),
            'notes_count' => $client->notes()->count(),
            'leads_count' => $client->leads()->count(),
            'timeline_events_count' => TimelineEvent::query()
                ->forEntity('client', (int) $client->id)
                ->count(),
            'has_active_deal' => $client->deals()->where('status', 'active')->exists(),
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
        ];
    }

    public function deleteClient(Client $client, int $actorId, string $reason): array
    {
        return $this->deleteClientInternal(
            $client,
            $actorId,
            $reason,
            createSyncExclusion: true,
            deleteFromWordPress: true,
            auditAction: CrmAuditAction::CLIENT_DELETE
        );
    }

    public function deleteClientFromSourcePrune(Client $client, ?int $actorId, string $reason): array
    {
        return $this->deleteClientInternal(
            $client,
            $actorId,
            $reason,
            createSyncExclusion: false,
            deleteFromWordPress: false,
            auditAction: CrmAuditAction::CLIENT_AUTO_PURGE
        );
    }

    private function deleteClientInternal(
        Client $client,
        ?int $actorId,
        string $reason,
        bool $createSyncExclusion,
        bool $deleteFromWordPress,
        string $auditAction
    ): array {
        $client->loadMissing('platform');

        $impact = $this->previewDeletion($client);
        $clientId = (int) $client->id;
        $platformId = (int) $client->platform_id;
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        $dealIds = $client->deals()->pluck('id');

        $beforeState = [
            'client' => [
                'id' => $clientId,
                'name' => $client->name,
                'platform_id' => $platformId,
                'platform_name' => $client->platform?->name,
                'wp_post_id' => $wpPostId,
                'phone_normalized' => $client->phone_normalized,
                'email' => $client->email,
                'profile_status' => $client->profile_status,
            ],
            'impact' => $impact,
        ];

        DB::transaction(function () use ($client, $clientId, $dealIds, $wpPostId, $actorId, $reason, $createSyncExclusion) {
            if ($createSyncExclusion && $wpPostId > 0) {
                ClientSyncExclusion::query()->updateOrCreate(
                    [
                        'platform_id' => (int) $client->platform_id,
                        'wp_post_id' => $wpPostId,
                    ],
                    [
                        'reason' => $reason,
                        'deleted_by' => $actorId,
                        'created_at' => now(),
                    ]
                );
            }

            Client::query()
                ->where('duplicate_of', $clientId)
                ->update(['duplicate_of' => null]);

            DB::table('push_campaign_items')
                ->where('client_id', $clientId)
                ->update(['client_id' => null]);

            DB::table('leads')
                ->where('converted_client_id', $clientId)
                ->update(['converted_client_id' => null]);

            DB::table('payments')
                ->where('client_id', $clientId)
                ->update(['client_id' => null]);

            if ($dealIds->isNotEmpty()) {
                DB::table('payments')
                    ->whereIn('deal_id', $dealIds->all())
                    ->update(['deal_id' => null]);
            }

            DB::table('client_notes')
                ->where('client_id', $clientId)
                ->delete();

            DB::table('client_credential_dispatches')
                ->where('client_id', $clientId)
                ->delete();

            DB::table('wallet_transactions')
                ->where('client_id', $clientId)
                ->delete();

            DB::table('client_retention_insight_history')
                ->where('client_id', $clientId)
                ->delete();

            DB::table('client_retention_insights')
                ->where('client_id', $clientId)
                ->delete();

            $client->deals()->delete();
            $client->delete();
        });

        $wpDeleted = false;
        if ($deleteFromWordPress && $wpPostId > 0) {
            try {
                WpSyncService::forPlatform($platformId)->deleteClient($wpPostId);
                $wpDeleted = true;
            } catch (\Throwable $exception) {
                Log::warning('WordPress client delete failed after CRM delete', [
                    'client_id' => $clientId,
                    'platform_id' => $platformId,
                    'wp_post_id' => $wpPostId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->auditService->record([
            'platform_id' => $platformId,
            'actor_id' => $actorId,
            'action' => $auditAction,
            'entity_type' => 'client',
            'entity_id' => $clientId,
            'before_state' => $beforeState,
            'after_state' => [
                'deleted' => true,
                'wp_deleted' => $wpDeleted,
                'source_pruned' => !$deleteFromWordPress,
                'impact' => $impact,
            ],
            'reason' => $reason,
        ]);

        return [
            'deleted' => true,
            'wp_deleted' => $wpDeleted,
            'source_pruned' => !$deleteFromWordPress,
            'impact' => $impact,
        ];
    }

    public function bulkPreview(array $filters, array $clientIds, ?array $platformIds): array
    {
        $query = Client::query()
            ->select('clients.*')
            ->with('platform:id,name')
            ->withCount(['deals', 'payments', 'notes', 'leads'])
            ->withCount(['deals as active_deals_count' => fn ($builder) => $builder->where('status', 'active')]);

        $query->selectSub(
            TimelineEvent::query()
                ->selectRaw('count(*)')
                ->whereColumn('entity_id', 'clients.id')
                ->where('entity_type', 'client'),
            'timeline_events_count'
        );

        if (is_array($platformIds)) {
            if (empty($platformIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('platform_id', $platformIds);
            }
        }

        if (!empty($clientIds)) {
            $query->whereIn('id', $clientIds);
        } else {
            if (!empty($filters['platform_id'])) {
                $query->where('platform_id', (int) $filters['platform_id']);
            }

            if (!empty($filters['inactive_days'])) {
                $query->inactiveFor((int) $filters['inactive_days']);
            }

            if (!empty($filters['has_no_chat'])) {
                $query->hasNoChat();
            }

            if (!empty($filters['has_no_subscription_or_payment'])) {
                $query->hasNoSubscriptionOrPayment();
            }
        }

        $totalCount = (clone $query)->count();
        $clients = $query
            ->orderBy('updated_at', 'desc')
            ->limit(500)
            ->get();

        return [
            'total_count' => $totalCount,
            'capped' => $totalCount > 500,
            'clients' => $clients->map(fn (Client $client) => [
                'client_id' => (int) $client->id,
                'name' => (string) $client->name,
                'platform_id' => (int) $client->platform_id,
                'platform_name' => $client->platform?->name,
                'deals_count' => (int) ($client->deals_count ?? 0),
                'payments_count' => (int) ($client->payments_count ?? 0),
                'notes_count' => (int) ($client->notes_count ?? 0),
                'leads_count' => (int) ($client->leads_count ?? 0),
                'timeline_events_count' => (int) ($client->timeline_events_count ?? 0),
                'has_active_deal' => (int) ($client->active_deals_count ?? 0) > 0,
                'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            ])->values()->all(),
        ];
    }

    public function bulkDelete(array $clientIds, int $actorId, string $reason): array
    {
        $deletedCount = 0;
        $failed = [];
        $deletedByPlatform = [];

        $clients = Client::query()
            ->with('platform:id,name')
            ->whereIn('id', $clientIds)
            ->orderBy('id')
            ->get();

        foreach ($clients as $client) {
            try {
                $result = $this->deleteClient($client, $actorId, $reason);
                $deletedCount++;
                $deletedByPlatform[(int) $client->platform_id][] = [
                    'client_id' => (int) $client->id,
                    'name' => (string) $client->name,
                    'wp_deleted' => (bool) ($result['wp_deleted'] ?? false),
                ];
            } catch (\Throwable $exception) {
                $failed[] = [
                    'id' => (int) $client->id,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        foreach ($deletedByPlatform as $platformId => $entries) {
            $this->auditService->record([
                'platform_id' => $platformId,
                'actor_id' => $actorId,
                'action' => CrmAuditAction::CLIENT_BULK_DELETE,
                'entity_type' => 'platform',
                'entity_id' => $platformId,
                'before_state' => [
                    'client_ids' => array_map(fn ($entry) => $entry['client_id'], $entries),
                ],
                'after_state' => [
                    'deleted_count' => count($entries),
                    'clients' => $entries,
                    'failed_count' => count($failed),
                ],
                'reason' => $reason,
            ]);
        }

        return [
            'deleted_count' => $deletedCount,
            'failed' => $failed,
        ];
    }
}
