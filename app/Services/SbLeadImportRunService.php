<?php

namespace App\Services;

use App\Models\Platform;
use App\Models\SbLeadImportRun;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SbLeadImportRunService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly SupportBoardSyncRunService $supportBoardSyncRunService,
    ) {
    }

    public function latestRunForPlatform(int $platformId): ?SbLeadImportRun
    {
        return SbLeadImportRun::query()
            ->with('initiatedBy:id,name,email')
            ->forPlatform($platformId)
            ->latest('id')
            ->first();
    }

    public function activeRunForPlatform(int $platformId): ?SbLeadImportRun
    {
        return SbLeadImportRun::query()
            ->with('initiatedBy:id,name,email')
            ->forPlatform($platformId)
            ->active()
            ->latest('id')
            ->first();
    }

    public function queueReadiness(): array
    {
        return $this->supportBoardSyncRunService->queueReadiness();
    }

    /**
     * @return array{run: SbLeadImportRun, reused: bool}
     */
    public function startRun(
        Platform $platform,
        User $user,
        string $mode = 'bootstrap',
        ?string $reason = null,
        array $candidateUserIds = []
    ): array {
        $activeRun = $this->activeRunForPlatform((int) $platform->id);
        if ($activeRun && $this->isStale($activeRun)) {
            $this->markFailed(
                $activeRun,
                'SB lead import run became stale and was superseded by a new manual run.'
            );
            $activeRun = null;
        }

        if ($activeRun) {
            return [
                'run' => $activeRun,
                'reused' => true,
            ];
        }

        $run = SbLeadImportRun::query()->create([
            'platform_id' => (int) $platform->id,
            'initiated_by' => (int) $user->id,
            'mode' => $mode,
            'status' => SbLeadImportRun::STATUS_QUEUED,
            'candidates' => count($candidateUserIds),
            'processed' => 0,
            'created_leads' => 0,
            'updated_leads' => 0,
            'skipped_existing_client' => 0,
            'skipped_existing_lead' => 0,
            'errors' => 0,
            'error_details' => [],
            'candidate_user_ids' => $candidateUserIds,
            'cursor_position' => 0,
            'reason' => $this->normalizeReason($reason),
        ]);

        return [
            'run' => $run->load('initiatedBy:id,name,email'),
            'reused' => false,
        ];
    }

    public function markRunning(SbLeadImportRun $run): SbLeadImportRun
    {
        $run->forceFill([
            'status' => SbLeadImportRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'last_heartbeat_at' => now(),
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function recordOutcome(SbLeadImportRun $run, array $outcome): SbLeadImportRun
    {
        $details = is_array($run->error_details) ? $run->error_details : [];
        $errorDetail = $outcome['error_detail'] ?? null;

        if (is_array($errorDetail) && count($details) < 25) {
            $details[] = $errorDetail;
        }

        $run->forceFill([
            'processed' => (int) $run->processed + 1,
            'created_leads' => (int) $run->created_leads + (int) ($outcome['created'] ?? 0),
            'updated_leads' => (int) $run->updated_leads + (int) ($outcome['updated'] ?? 0),
            'skipped_existing_client' => (int) $run->skipped_existing_client + (int) ($outcome['skipped_existing_client'] ?? 0),
            'skipped_existing_lead' => (int) $run->skipped_existing_lead + (int) ($outcome['skipped_existing_lead'] ?? 0),
            'errors' => (int) $run->errors + (int) ($outcome['errors'] ?? 0),
            'error_details' => $details,
            'cursor_position' => (int) ($outcome['cursor_position'] ?? $run->cursor_position),
            'last_heartbeat_at' => now(),
            'last_processed_sb_user_id' => (int) ($outcome['sb_user_id'] ?? $run->last_processed_sb_user_id),
            'last_processed_name' => $outcome['name'] ?? $run->last_processed_name,
        ])->save();

        return $run->refresh()->loadMissing('initiatedBy:id,name,email');
    }

    public function markCompleted(SbLeadImportRun $run): SbLeadImportRun
    {
        $run->forceFill([
            'status' => SbLeadImportRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
        ])->save();

        $run = $run->refresh()->loadMissing('initiatedBy:id,name,email', 'platform:id,name');
        $this->recordCompletionAudit($run, 'completed');

        return $run;
    }

    public function markFailed(SbLeadImportRun $run, \Throwable|string $error): SbLeadImportRun
    {
        $details = is_array($run->error_details) ? $run->error_details : [];
        $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

        if ($message !== '' && count($details) < 25) {
            $details[] = [
                'sb_user_id' => $run->last_processed_sb_user_id,
                'message' => $message,
            ];
        }

        $run->forceFill([
            'status' => SbLeadImportRun::STATUS_FAILED,
            'errors' => max((int) $run->errors, 1),
            'error_details' => $details,
            'finished_at' => now(),
            'last_heartbeat_at' => now(),
        ])->save();

        $run = $run->refresh()->loadMissing('initiatedBy:id,name,email', 'platform:id,name');
        $this->recordCompletionAudit($run, 'failed');

        if ($error instanceof \Throwable) {
            Log::error('SB lead import run failed', [
                'run_id' => (int) $run->id,
                'platform_id' => (int) $run->platform_id,
                'error' => $error->getMessage(),
            ]);
        }

        return $run;
    }

    public function serializeRun(?SbLeadImportRun $run): ?array
    {
        if (!$run) {
            return null;
        }

        $queue = $this->queueSnapshot($run);
        $processed = (int) ($run->processed ?? 0);
        $candidates = (int) ($run->candidates ?? 0);
        $progress = $candidates > 0
            ? min(100, (int) floor(($processed / max(1, $candidates)) * 100))
            : ($run->status === SbLeadImportRun::STATUS_COMPLETED ? 100 : 0);

        return [
            'id' => (int) $run->id,
            'platform_id' => (int) $run->platform_id,
            'initiated_by' => $run->initiatedBy ? [
                'id' => (int) $run->initiatedBy->id,
                'name' => $run->initiatedBy->name,
                'email' => $run->initiatedBy->email,
            ] : null,
            'mode' => $run->mode,
            'status' => $run->status,
            'in_progress' => $run->isActive(),
            'candidates' => $candidates,
            'processed' => $processed,
            'created_leads' => (int) ($run->created_leads ?? 0),
            'updated_leads' => (int) ($run->updated_leads ?? 0),
            'skipped_existing_client' => (int) ($run->skipped_existing_client ?? 0),
            'skipped_existing_lead' => (int) ($run->skipped_existing_lead ?? 0),
            'errors' => (int) ($run->errors ?? 0),
            'error_details' => array_values(is_array($run->error_details) ? $run->error_details : []),
            'reason' => $run->reason,
            'progress_percent' => $progress,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
            'created_at' => optional($run->created_at)->toDateTimeString(),
            'updated_at' => optional($run->updated_at)->toDateTimeString(),
            'last_processed_sb_user_id' => $run->last_processed_sb_user_id ? (int) $run->last_processed_sb_user_id : null,
            'last_processed_name' => $run->last_processed_name,
            'queue' => $queue,
        ];
    }

    private function recordCompletionAudit(SbLeadImportRun $run, string $state): void
    {
        $this->auditService->record([
            'platform_id' => (int) $run->platform_id,
            'actor_id' => (int) $run->initiated_by,
            'action' => CrmAuditAction::LEAD_SB_IMPORT_COMMIT,
            'entity_type' => 'platform',
            'entity_id' => (int) $run->platform_id,
            'after_state' => [
                'sb_lead_import' => [
                    'run_id' => (int) $run->id,
                    'state' => $state,
                    'mode' => $run->mode,
                    'candidates' => (int) $run->candidates,
                    'processed' => (int) $run->processed,
                    'created_leads' => (int) $run->created_leads,
                    'updated_leads' => (int) $run->updated_leads,
                    'skipped_existing_client' => (int) $run->skipped_existing_client,
                    'skipped_existing_lead' => (int) $run->skipped_existing_lead,
                    'errors' => (int) $run->errors,
                ],
            ],
            'reason' => $run->reason ?: 'Background Support Board lead import run',
        ]);
    }

    private function queueSnapshot(?SbLeadImportRun $run = null): array
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

        $referenceAt = $run->status === SbLeadImportRun::STATUS_QUEUED
            ? $run->created_at
            : ($run->last_heartbeat_at ?: $run->started_at ?: $run->created_at);
        $waitSeconds = $referenceAt ? now()->diffInSeconds($referenceAt) : 0;
        $workerLikelyOffline = !$snapshot['worker_detected'] && $pendingJobs > 0 && $waitSeconds >= 90;

        $snapshot['worker_likely_offline'] = $workerLikelyOffline;
        $snapshot['message'] = $workerLikelyOffline
            ? 'No active queue worker detected. Start `php artisan queue:work` (or Horizon) to process SB lead import runs.'
            : null;

        return $snapshot;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $normalized = trim((string) $reason);

        return $normalized === '' ? null : $normalized;
    }

    private function isStale(SbLeadImportRun $run): bool
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
