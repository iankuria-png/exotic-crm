<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ClientDeletionService;
use App\Support\CrmAuditAction;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PurgeClosedClientsCommand extends Command
{
    protected $signature = 'crm:purge-closed-clients {--limit=200 : Maximum number of clients to purge in one run} {--dry-run : Log intent without mutating}';

    protected $description = 'Hard-delete clients whose case was closed >30 days ago (purge_after is in the past). Mirrors the existing CLIENT_DELETE flow.';

    public function handle(ClientDeletionService $deletionService, AuditService $auditService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Client::query()
            ->readyForPurge()
            ->orderBy('purge_after', 'asc')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No clients ready for purge.');
            return self::SUCCESS;
        }

        $runId = (string) Str::uuid();
        $this->info(sprintf('Purge run %s — %d candidate(s).', $runId, $candidates->count()));

        if ($dryRun) {
            foreach ($candidates as $client) {
                $this->line(sprintf(
                    '[dry-run] would purge client #%d (%s) closed=%s reason=%s purge_after=%s',
                    $client->id,
                    $client->name,
                    optional($client->closed_at)?->toDateTimeString(),
                    (string) $client->close_reason_code,
                    optional($client->purge_after)?->toDateTimeString(),
                ));
            }
            return self::SUCCESS;
        }

        $actorId = $this->resolveSystemActorId();
        $purgedByPlatform = [];
        $failedByPlatform = [];

        foreach ($candidates as $client) {
            $platformId = (int) $client->platform_id;
            $clientId = (int) $client->id;
            $reasonCode = (string) $client->close_reason_code;
            $reason = sprintf('Auto-purge: case closed > 30 days ago (%s) [run:%s]', $reasonCode, $runId);

            try {
                $result = $deletionService->deleteClient($client, $actorId, $reason);
                $purgedByPlatform[$platformId][] = [
                    'client_id' => $clientId,
                    'name' => (string) $client->name,
                    'close_reason_code' => $reasonCode,
                    'wp_deleted' => (bool) ($result['wp_deleted'] ?? false),
                ];
                $this->line(sprintf(
                    'Purged client #%d (%s) wp_deleted=%s',
                    $clientId,
                    $client->name,
                    ($result['wp_deleted'] ?? false) ? 'yes' : 'no',
                ));
            } catch (\Throwable $exception) {
                $failedByPlatform[$platformId][] = [
                    'client_id' => $clientId,
                    'error' => $exception->getMessage(),
                ];
                $this->error(sprintf(
                    'Failed to purge client #%d: %s',
                    $clientId,
                    $exception->getMessage(),
                ));
            }
        }

        // One CLIENT_AUTO_PURGE summary per platform — entity_type='platform' since
        // the per-client CLIENT_DELETE audits cover individual rows.
        $platformIds = array_unique(array_merge(array_keys($purgedByPlatform), array_keys($failedByPlatform)));
        foreach ($platformIds as $platformId) {
            $purged = $purgedByPlatform[$platformId] ?? [];
            $failed = $failedByPlatform[$platformId] ?? [];

            $auditService->record([
                'platform_id' => (int) $platformId,
                'actor_id' => $actorId,
                'action' => CrmAuditAction::CLIENT_AUTO_PURGE,
                'entity_type' => 'platform',
                'entity_id' => (int) $platformId,
                'after_state' => [
                    'run_id' => $runId,
                    'purged_count' => count($purged),
                    'failed_count' => count($failed),
                    'purged' => $purged,
                    'failed' => $failed,
                ],
                'reason' => 'Daily auto-purge of clients with closed cases >30 days old',
            ]);
        }

        $this->info(sprintf(
            'Purge complete: %d purged, %d failed (run %s).',
            collect($purgedByPlatform)->flatten(1)->count(),
            collect($failedByPlatform)->flatten(1)->count(),
            $runId,
        ));

        return self::SUCCESS;
    }

    private function resolveSystemActorId(): int
    {
        $configured = (int) config('crm.system_actor_id', 0);
        if ($configured > 0) {
            return $configured;
        }

        $admin = User::query()->where('role', 'admin')->orderBy('id')->value('id');
        if ($admin) {
            return (int) $admin;
        }

        $anyUser = User::query()->orderBy('id')->value('id');
        if ($anyUser) {
            return (int) $anyUser;
        }

        throw new \RuntimeException('No user exists to serve as the system actor for auto-purge audit logs.');
    }
}
