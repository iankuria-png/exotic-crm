<?php

namespace App\Services;

use App\Models\Platform;
use App\Models\SupportBoardSyncRun;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SupportBoardSyncRunService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly SupportBoardLinkSyncService $supportBoardLinkSyncService,
    ) {
    }

    public function latestRunForPlatform(int $platformId): ?SupportBoardSyncRun
    {
        return SupportBoardSyncRun::query()
            ->with('initiatedBy:id,name,email')
            ->forPlatform($platformId)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<int>  $platformIds
     * @return Collection<int, SupportBoardSyncRun>
     */
    public function latestRunsForPlatforms(array $platformIds): Collection
    {
        if (empty($platformIds)) {
            return collect();
        }

        return SupportBoardSyncRun::query()
            ->with('initiatedBy:id,name,email')
            ->whereIn('platform_id', $platformIds)
            ->orderByDesc('id')
            ->get()
            ->unique('platform_id')
            ->keyBy('platform_id');
    }

    public function activeRunForPlatform(int $platformId): ?SupportBoardSyncRun
    {
        return SupportBoardSyncRun::query()
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
            $issues[] = 'Support Board background sync requires QUEUE_CONNECTION=database or redis.';
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
     * @return array{run: SupportBoardSyncRun, reused: bool}
     */
    public function startRun(Platform $platform, User $user, bool $refresh = false, ?string $reason = null): array
    {
        return $this->startRunInternal(
            $platform,
            $user,
            $refresh,
            $reason,
            'Support Board sync run became stale and was superseded by a new manual run.'
        );
    }

    /**
     * @return array{run: SupportBoardSyncRun, reused: bool}
     */
    public function startAutomatedRun(Platform $platform, bool $refresh = false, ?string $reason = null): array
    {
        return $this->startRunInternal(
            $platform,
            null,
            $refresh,
            $reason ?: 'Scheduled Support Board link sync',
            'Support Board sync run became stale and was superseded by a new scheduled run.'
        );
    }

    public function markRunning(SupportBoardSyncRun $run): SupportBoardSyncRun
    {
        $run->forceFill([
            'status' => SupportBoardSyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'last_heartbeat_at' => now(),
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function recordClientOutcome(SupportBoardSyncRun $run, array $outcome): SupportBoardSyncRun
    {
        $details = is_array($run->error_details) ? $run->error_details : [];
        $errorDetail = $outcome['error_detail'] ?? null;

        if (is_array($errorDetail) && count($details) < 25) {
            $details[] = $errorDetail;
        }

        $run->forceFill([
            'processed' => (int) $run->processed + 1,
            'matched' => (int) $run->matched + (int) ($outcome['matched'] ?? 0),
            'updated' => (int) $run->updated + (int) ($outcome['updated'] ?? 0),
            'cleared' => (int) $run->cleared + (int) ($outcome['cleared'] ?? 0),
            'unchanged' => (int) $run->unchanged + (int) ($outcome['unchanged'] ?? 0),
            'errors' => (int) $run->errors + (int) ($outcome['errors'] ?? 0),
            'error_details' => $details,
            'last_heartbeat_at' => now(),
            'last_processed_client_id' => (int) ($outcome['client_id'] ?? $run->last_processed_client_id),
            'last_processed_client_name' => $outcome['client_name'] ?? $run->last_processed_client_name,
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function recordChunkOutcome(SupportBoardSyncRun $run, array $outcome): SupportBoardSyncRun
    {
        $details = is_array($run->error_details) ? $run->error_details : [];

        foreach (array_values(is_array($outcome['errors_detail'] ?? null) ? $outcome['errors_detail'] : []) as $errorDetail) {
            if (is_array($errorDetail) && count($details) < 25) {
                $details[] = $errorDetail;
            }
        }

        $run->forceFill([
            'processed' => (int) $run->processed + (int) ($outcome['processed'] ?? 0),
            'matched' => (int) $run->matched + (int) ($outcome['matched'] ?? 0),
            'updated' => (int) $run->updated + (int) ($outcome['updated'] ?? 0),
            'cleared' => (int) $run->cleared + (int) ($outcome['cleared'] ?? 0),
            'unchanged' => (int) $run->unchanged + (int) ($outcome['unchanged'] ?? 0),
            'errors' => (int) $run->errors + (int) ($outcome['errors'] ?? 0),
            'error_details' => $details,
            'last_heartbeat_at' => now(),
            'last_processed_client_id' => (int) ($outcome['last_processed_client_id'] ?? $run->last_processed_client_id),
            'last_processed_client_name' => $outcome['last_processed_client_name'] ?? $run->last_processed_client_name,
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function markCompleted(SupportBoardSyncRun $run): SupportBoardSyncRun
    {
        $run->forceFill([
            'status' => SupportBoardSyncRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
        ])->save();

        $run = $run->refresh()->loadMissing('initiatedBy:id,name,email', 'platform:id,name');
        $this->recordCompletionAudit($run, 'completed');

        return $run;
    }

    public function markFailed(SupportBoardSyncRun $run, \Throwable|string $error): SupportBoardSyncRun
    {
        $details = is_array($run->error_details) ? $run->error_details : [];
        $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

        if ($message !== '' && count($details) < 25) {
            $details[] = [
                'client_id' => $run->last_processed_client_id,
                'message' => $message,
            ];
        }

        $run->forceFill([
            'status' => SupportBoardSyncRun::STATUS_FAILED,
            'errors' => max((int) $run->errors, 1),
            'error_details' => $details,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
        ])->save();

        $run = $run->refresh()->loadMissing('initiatedBy:id,name,email', 'platform:id,name');
        $this->recordCompletionAudit($run, 'failed');

        if ($error instanceof \Throwable) {
            Log::error('Support Board sync run failed', [
                'run_id' => (int) $run->id,
                'platform_id' => (int) $run->platform_id,
                'error' => $error->getMessage(),
            ]);
        }

        return $run;
    }

    public function serializeRun(?SupportBoardSyncRun $run): ?array
    {
        if (!$run) {
            return null;
        }

        $queue = $this->queueSnapshot($run);
        $processed = (int) ($run->processed ?? 0);
        $candidates = (int) ($run->candidates ?? 0);
        $progress = $candidates > 0
            ? min(100, (int) floor(($processed / max(1, $candidates)) * 100))
            : ($run->status === SupportBoardSyncRun::STATUS_COMPLETED ? 100 : 0);

        return [
            'id' => (int) $run->id,
            'platform_id' => (int) $run->platform_id,
            'origin' => $run->initiated_by ? 'manual' : 'scheduler',
            'initiated_by' => $run->initiatedBy ? [
                'id' => (int) $run->initiatedBy->id,
                'name' => $run->initiatedBy->name,
                'email' => $run->initiatedBy->email,
            ] : null,
            'mode' => $run->mode,
            'refresh' => $run->isRefresh(),
            'status' => $run->status,
            'in_progress' => $run->isActive(),
            'candidates' => $candidates,
            'processed' => $processed,
            'matched' => (int) ($run->matched ?? 0),
            'updated' => (int) ($run->updated ?? 0),
            'cleared' => (int) ($run->cleared ?? 0),
            'unchanged' => (int) ($run->unchanged ?? 0),
            'errors' => (int) ($run->errors ?? 0),
            'error_details' => array_values(is_array($run->error_details) ? $run->error_details : []),
            'reason' => $run->reason,
            'progress_percent' => $progress,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
            'created_at' => optional($run->created_at)->toDateTimeString(),
            'updated_at' => optional($run->updated_at)->toDateTimeString(),
            'last_processed_client_id' => $run->last_processed_client_id ? (int) $run->last_processed_client_id : null,
            'last_processed_client_name' => $run->last_processed_client_name,
            'queue' => $queue,
        ];
    }

    private function recordCompletionAudit(SupportBoardSyncRun $run, string $state): void
    {
        $this->auditService->record([
            'platform_id' => (int) $run->platform_id,
            'actor_id' => $run->initiated_by ? (int) $run->initiated_by : null,
            'action' => CrmAuditAction::INTEGRATION_SYNC_RUN,
            'entity_type' => 'platform',
            'entity_id' => (int) $run->platform_id,
            'after_state' => [
                'support_board_sync' => [
                    'run_id' => (int) $run->id,
                    'state' => $state,
                    'mode' => $run->mode,
                    'candidates' => (int) $run->candidates,
                    'processed' => (int) $run->processed,
                    'matched' => (int) $run->matched,
                    'updated' => (int) $run->updated,
                    'cleared' => (int) $run->cleared,
                    'unchanged' => (int) $run->unchanged,
                    'errors' => (int) $run->errors,
                ],
            ],
            'reason' => $run->reason ?: 'Background Support Board link sync run',
        ]);
    }

    private function queueSnapshot(?SupportBoardSyncRun $run = null): array
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
                ->where('queue', 'default')
                ->count();
            $reservedJobs = DB::table('jobs')
                ->where('queue', 'default')
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

        $referenceAt = $run->status === SupportBoardSyncRun::STATUS_QUEUED
            ? $run->created_at
            : ($run->last_heartbeat_at ?: $run->started_at ?: $run->created_at);
        $waitSeconds = $referenceAt ? now()->diffInSeconds($referenceAt) : 0;
        $workerLikelyOffline = !$snapshot['worker_detected'] && $pendingJobs > 0 && $waitSeconds >= 90;

        $snapshot['worker_likely_offline'] = $workerLikelyOffline;
        $snapshot['message'] = $workerLikelyOffline
            ? 'No active queue worker detected. Start `php artisan queue:work` (or Horizon) to process Support Board sync runs.'
            : null;

        return $snapshot;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $normalized = trim((string) $reason);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array{run: SupportBoardSyncRun, reused: bool}
     */
    private function startRunInternal(
        Platform $platform,
        ?User $user,
        bool $refresh,
        ?string $reason,
        string $staleReason,
    ): array {
        $activeRun = $this->activeRunForPlatform((int) $platform->id);
        if ($activeRun && $this->isStale($activeRun)) {
            $activeRun = $this->markFailed($activeRun, $staleReason);
            $activeRun = null;
        }

        if ($activeRun) {
            return [
                'run' => $activeRun,
                'reused' => true,
            ];
        }

        $run = SupportBoardSyncRun::query()->create([
            'platform_id' => (int) $platform->id,
            'initiated_by' => $user ? (int) $user->id : null,
            'mode' => $refresh ? 'refresh' : 'incremental',
            'status' => SupportBoardSyncRun::STATUS_QUEUED,
            'candidates' => $this->supportBoardLinkSyncService->countClientsForPlatform($platform, $refresh),
            'processed' => 0,
            'matched' => 0,
            'updated' => 0,
            'cleared' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'error_details' => [],
            'reason' => $this->normalizeReason($reason),
        ]);

        return [
            'run' => $run->load('initiatedBy:id,name,email'),
            'reused' => false,
        ];
    }

    private function isStale(SupportBoardSyncRun $run): bool
    {
        if (!$run->isActive()) {
            return false;
        }

        $referenceAt = $run->last_heartbeat_at ?: $run->started_at ?: $run->created_at;
        if (!$referenceAt) {
            return false;
        }

        return now()->diffInMinutes($referenceAt) >= 15;
    }
}
