<?php

namespace App\Services;

use App\Models\ClientSyncRun;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientSyncRunService
{
    private const STALE_AFTER_MINUTES = 20;

    public function latestRunForPlatform(int $platformId): ?ClientSyncRun
    {
        return ClientSyncRun::query()
            ->with('initiatedBy:id,name,email')
            ->forPlatform($platformId)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<int>  $platformIds
     * @return Collection<int, ClientSyncRun>
     */
    public function latestRunsForPlatforms(array $platformIds): Collection
    {
        if (empty($platformIds)) {
            return collect();
        }

        $latestIds = ClientSyncRun::query()
            ->selectRaw('MAX(id) AS id')
            ->whereIn('platform_id', $platformIds)
            ->groupBy('platform_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return collect();
        }

        return ClientSyncRun::query()
            ->with('initiatedBy:id,name,email')
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('platform_id');
    }

    public function activeRunForPlatform(int $platformId): ?ClientSyncRun
    {
        return ClientSyncRun::query()
            ->with('initiatedBy:id,name,email')
            ->forPlatform($platformId)
            ->active()
            ->latest('id')
            ->first();
    }

    public function queueReadiness(): array
    {
        $connection = (string) config('queue.default', 'sync');
        $issues = [];

        if ($connection === 'sync') {
            $issues[] = 'Background client sync requires QUEUE_CONNECTION=database or redis.';
        }

        if ($connection === 'database' && !Schema::hasTable('jobs')) {
            $issues[] = 'Missing table: jobs (required for QUEUE_CONNECTION=database).';
        }

        return [
            'available' => empty($issues),
            'connection' => $connection,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{run: ClientSyncRun, reused: bool}
     */
    public function startManualRun(
        Platform $platform,
        User $user,
        string $mode = 'delta',
        ?string $reason = null
    ): array {
        return $this->startRunInternal($platform, $user, 'manual', $mode, $reason);
    }

    /**
     * @return array{run: ClientSyncRun, reused: bool}
     */
    public function startAutomatedRun(
        Platform $platform,
        string $mode = 'delta',
        ?string $reason = null
    ): array {
        return $this->startRunInternal($platform, null, 'scheduler', $mode, $reason);
    }

    public function markRunning(ClientSyncRun $run): ClientSyncRun
    {
        $run->forceFill([
            'status' => ClientSyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'last_heartbeat_at' => now(),
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function heartbeat(ClientSyncRun $run): ClientSyncRun
    {
        $run->forceFill([
            'last_heartbeat_at' => now(),
        ])->save();

        return $run;
    }

    public function recordProgress(ClientSyncRun $run, array $delta): ClientSyncRun
    {
        $details = is_array($run->error_details) ? $run->error_details : [];

        foreach (array_values(is_array($delta['error_details'] ?? null) ? $delta['error_details'] : []) as $detail) {
            if (is_array($detail) && count($details) < 25) {
                $details[] = $detail;
            }
        }

        $run->forceFill([
            'processed' => (int) $run->processed + (int) ($delta['processed'] ?? 0),
            'created' => (int) $run->created + (int) ($delta['created'] ?? 0),
            'updated' => (int) $run->updated + (int) ($delta['updated'] ?? 0),
            'skipped' => (int) $run->skipped + (int) ($delta['skipped'] ?? 0),
            'tombstones_processed' => (int) $run->tombstones_processed + (int) ($delta['tombstones_processed'] ?? 0),
            'errors' => (int) $run->errors + (int) ($delta['errors'] ?? 0),
            'error_details' => $details,
            'last_heartbeat_at' => now(),
            'cursor_modified_at' => $delta['cursor_modified_at'] ?? $run->cursor_modified_at,
            'cursor_post_id' => $delta['cursor_post_id'] ?? $run->cursor_post_id,
            'tombstone_cursor_removed_at' => $delta['tombstone_cursor_removed_at'] ?? $run->tombstone_cursor_removed_at,
            'tombstone_cursor_post_id' => $delta['tombstone_cursor_post_id'] ?? $run->tombstone_cursor_post_id,
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function markCompleted(ClientSyncRun $run, array $resultPayload): ClientSyncRun
    {
        $run->forceFill([
            'status' => ClientSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'checkpoint_after_run' => $resultPayload['checkpoint_after_run'] ?? $run->checkpoint_after_run,
        ])->save();

        $run = $run->refresh()->loadMissing('initiatedBy:id,name,email', 'platform');
        $this->projectTerminalState($run, 'success', $resultPayload, null);

        return $run;
    }

    public function markFailed(ClientSyncRun $run, \Throwable|string $error, ?array $resultPayload = null): ClientSyncRun
    {
        $details = is_array($run->error_details) ? $run->error_details : [];
        $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

        if ($message !== '' && count($details) < 25) {
            $details[] = ['message' => $message];
        }

        $run->forceFill([
            'status' => ClientSyncRun::STATUS_FAILED,
            'errors' => max((int) $run->errors, 1),
            'error_details' => $details,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
        ])->save();

        $run = $run->refresh()->loadMissing('initiatedBy:id,name,email', 'platform');
        $this->projectTerminalState($run, 'error', $resultPayload ?? [], $message);

        return $run;
    }

    public function markStale(ClientSyncRun $run, ?string $reason = null): ClientSyncRun
    {
        $run->forceFill([
            'status' => ClientSyncRun::STATUS_STALE,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
            'reason' => $reason ?: $run->reason,
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function serializeRun(?ClientSyncRun $run): ?array
    {
        if (!$run) {
            return null;
        }

        $queue = $this->queueSnapshot($run);
        $processed = (int) ($run->processed ?? 0);
        $completed = in_array($run->status, [ClientSyncRun::STATUS_COMPLETED], true);

        return [
            'id' => (int) $run->id,
            'platform_id' => (int) $run->platform_id,
            'origin' => $run->origin,
            'initiated_by' => $run->initiatedBy ? [
                'id' => (int) $run->initiatedBy->id,
                'name' => $run->initiatedBy->name,
                'email' => $run->initiatedBy->email,
            ] : null,
            'mode' => $run->mode,
            'protocol' => $run->protocol,
            'status' => $run->status,
            'in_progress' => $run->isActive(),
            'processed' => $processed,
            'created' => (int) ($run->created ?? 0),
            'updated' => (int) ($run->updated ?? 0),
            'skipped' => (int) ($run->skipped ?? 0),
            'tombstones_processed' => (int) ($run->tombstones_processed ?? 0),
            'errors' => (int) ($run->errors ?? 0),
            'error_details' => array_values(is_array($run->error_details) ? $run->error_details : []),
            'reason' => $run->reason,
            'fallback_reason' => $run->fallback_reason,
            'capability_snapshot' => is_array($run->capability_snapshot) ? $run->capability_snapshot : null,
            'progress_percent' => $completed ? 100 : ($processed > 0 ? null : 0),
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
            'created_at' => optional($run->created_at)->toDateTimeString(),
            'updated_at' => optional($run->updated_at)->toDateTimeString(),
            'run_upper_bound_modified_at' => optional($run->run_upper_bound_modified_at)->toDateTimeString(),
            'cursor_modified_at' => optional($run->cursor_modified_at)->toDateTimeString(),
            'cursor_post_id' => $run->cursor_post_id ? (int) $run->cursor_post_id : null,
            'tombstone_upper_bound_removed_at' => optional($run->tombstone_upper_bound_removed_at)->toDateTimeString(),
            'tombstone_cursor_removed_at' => optional($run->tombstone_cursor_removed_at)->toDateTimeString(),
            'tombstone_cursor_post_id' => $run->tombstone_cursor_post_id ? (int) $run->tombstone_cursor_post_id : null,
            'checkpoint_before_run' => optional($run->checkpoint_before_run)->toDateTimeString(),
            'checkpoint_after_run' => optional($run->checkpoint_after_run)->toDateTimeString(),
            'queue' => $queue,
        ];
    }

    private function startRunInternal(
        Platform $platform,
        ?User $user,
        string $origin,
        string $mode,
        ?string $reason
    ): array {
        return DB::transaction(function () use ($platform, $user, $origin, $mode, $reason) {
            $activeRun = $this->activeRunForPlatform((int) $platform->id);
            if ($activeRun && !$this->isRunStale($activeRun)) {
                return ['run' => $activeRun, 'reused' => true];
            }

            if ($activeRun && $this->isRunStale($activeRun)) {
                $this->markStale($activeRun, 'Client sync run became stale and was superseded by a new run.');
            }

            $run = ClientSyncRun::query()->create([
                'platform_id' => (int) $platform->id,
                'initiated_by' => $user?->id,
                'origin' => $origin,
                'mode' => $mode,
                'status' => ClientSyncRun::STATUS_QUEUED,
                'reason' => $reason,
                'checkpoint_before_run' => $platform->client_sync_checkpoint_at,
            ]);

            return ['run' => $run->loadMissing('initiatedBy:id,name,email'), 'reused' => false];
        });
    }

    private function isRunStale(ClientSyncRun $run): bool
    {
        $reference = $run->last_heartbeat_at ?: $run->started_at ?: $run->created_at;

        return $reference ? $reference->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)) : false;
    }

    private function queueSnapshot(?ClientSyncRun $run = null): array
    {
        $readiness = $this->queueReadiness();
        $snapshot = array_merge($readiness, [
            'pending_jobs' => null,
            'reserved_jobs' => null,
            'worker_detected' => null,
            'worker_likely_offline' => false,
            'message' => null,
        ]);

        if (($readiness['connection'] ?? null) !== 'database' || !($readiness['available'] ?? false)) {
            return $snapshot;
        }

        try {
            $pendingJobs = DB::table('jobs')
                ->whereIn('queue', ['sync-clients', 'sync-clients-reconcile'])
                ->count();
            $reservedJobs = DB::table('jobs')
                ->whereIn('queue', ['sync-clients', 'sync-clients-reconcile'])
                ->whereNotNull('reserved_at')
                ->count();
        } catch (\Throwable) {
            return $snapshot;
        }

        $snapshot['pending_jobs'] = $pendingJobs;
        $snapshot['reserved_jobs'] = $reservedJobs;
        $snapshot['worker_detected'] = $reservedJobs > 0;

        if (!$run || !$run->isActive()) {
            return $snapshot;
        }

        $referenceAt = $run->status === ClientSyncRun::STATUS_QUEUED
            ? $run->created_at
            : ($run->last_heartbeat_at ?: $run->started_at ?: $run->created_at);
        $waitSeconds = $referenceAt ? now()->diffInSeconds($referenceAt) : 0;
        $workerLikelyOffline = !$snapshot['worker_detected'] && $pendingJobs > 0 && $waitSeconds >= 90;

        $snapshot['worker_likely_offline'] = $workerLikelyOffline;
        $snapshot['message'] = $workerLikelyOffline
            ? 'No active queue worker detected. Start `php artisan queue:work` (or Horizon) to process client sync runs.'
            : null;

        return $snapshot;
    }

    private function projectTerminalState(ClientSyncRun $run, string $status, array $payload, ?string $error): void
    {
        $platform = $run->platform ?: Platform::query()->find($run->platform_id);
        if (!$platform) {
            return;
        }

        $result = array_filter([
            'scope' => 'clients',
            'mode' => $run->mode,
            'protocol' => $run->protocol,
            'trigger' => $run->origin,
            'ran_at' => optional($run->finished_at ?: now())->toDateTimeString(),
            'clients' => [
                'created' => (int) ($payload['created'] ?? $run->created ?? 0),
                'updated' => (int) ($payload['updated'] ?? $run->updated ?? 0),
                'skipped' => (int) ($payload['skipped'] ?? $run->skipped ?? 0),
                'total' => (int) ($payload['processed'] ?? $run->processed ?? 0),
                'tombstones_processed' => (int) ($payload['tombstones_processed'] ?? $run->tombstones_processed ?? 0),
            ],
            'run_id' => (int) $run->id,
            'error' => $error,
        ], static fn ($value) => $value !== null);

        $platform->forceFill([
            'sync_last_synced_at' => now(),
            'sync_last_scope' => 'clients',
            'sync_last_status' => $status,
            'sync_last_error' => $error ? mb_substr($error, 0, 500) : null,
            'sync_last_result' => $result,
            'client_sync_protocol' => $run->protocol ?: $platform->client_sync_protocol,
        ])->save();
    }
}
