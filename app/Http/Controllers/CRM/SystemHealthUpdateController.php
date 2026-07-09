<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\SendPaymentFailureAlertRecipientJob;
use App\Jobs\SendPaymentFailureAlertsJob;
use App\Models\Client;
use App\Models\ClientSyncRun;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\DeploymentStatusService;
use App\Support\CrmAuditAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemHealthUpdateController extends Controller
{
    public function __construct(
        private readonly DeploymentStatusService $deploymentStatusService,
        private readonly AuditService $auditService,
    ) {
    }

    public function show()
    {
        return response()->json($this->deploymentStatusService->snapshot());
    }

    public function log()
    {
        return response()->json($this->deploymentStatusService->logSnapshot());
    }

    public function commitHistory(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));

        return response()->json($this->deploymentStatusService->commitHistory($page, $perPage));
    }

    public function deploy(Request $request)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $snapshot = $this->deploymentStatusService->snapshot();
        $response = $this->deploymentStatusService->startManualDeploy(
            $request->user(),
            $validated['reason'] ?? 'Manual deployment triggered from CRM System Health'
        );

        $platformId = (int) (Platform::query()->orderBy('id')->value('id') ?? 0);
        if ($platformId > 0) {
            $this->auditService->fromRequest(
                $request,
                $platformId,
                CrmAuditAction::SYSTEM_DEPLOY_START,
                'deployment',
                1,
                $snapshot['manual_deploy'] ?? null,
                $response['manual_deploy'] ?? null,
                $validated['reason'] ?? 'Manual deployment triggered from CRM System Health'
            );
        }

        return response()->json($response);
    }

    public function deploymentHistory(): JsonResponse
    {
        return response()->json($this->deploymentStatusService->deploymentHistory());
    }

    public function backups(): JsonResponse
    {
        return response()->json(['backups' => $this->deploymentStatusService->availableBackups()]);
    }

    public function uploadBackup(Request $request): JsonResponse
    {
        $request->validate([
            'backup' => 'required|file|max:512000',
        ]);

        $file = $request->file('backup');
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension !== 'sql') {
            return response()->json(['message' => 'Only .sql files are accepted.'], 422);
        }

        $result = $this->deploymentStatusService->storeBackup($file);

        return response()->json($result);
    }

    public function deleteBackup(string $filename): JsonResponse
    {
        $this->deploymentStatusService->deleteBackup($filename);

        return response()->json(['message' => 'Backup deleted.']);
    }

    public function rollback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deployment_id' => 'required|string|max:100',
            'backup_filename' => 'nullable|string|max:255',
        ]);

        $snapshot = $this->deploymentStatusService->snapshot();
        $response = $this->deploymentStatusService->startRollback(
            $validated['deployment_id'],
            $request->user(),
            $validated['backup_filename'] ?? null
        );

        $platformId = (int) (Platform::query()->orderBy('id')->value('id') ?? 0);
        if ($platformId > 0) {
            $this->auditService->fromRequest(
                $request,
                $platformId,
                CrmAuditAction::SYSTEM_DEPLOY_START,
                'deployment',
                1,
                $snapshot['manual_deploy'] ?? null,
                $response['manual_deploy'] ?? null,
                'Rollback to deployment ' . $validated['deployment_id']
            );
        }

        return response()->json($response);
    }

    public function queueStatus(): JsonResponse
    {
        $pending = DB::table('jobs')->whereNull('reserved_at')->count();
        $processing = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $pendingAlerts = DB::table('jobs')->where('queue', 'alerts')->whereNull('reserved_at')->count();
        $processingAlerts = DB::table('jobs')->where('queue', 'alerts')->whereNotNull('reserved_at')->count();

        $failedCount = DB::table('failed_jobs')->count();
        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->get(['payload', 'exception', 'failed_at']);
        $latestFailed = $failedJobs->first();

        $recentActivity = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>=', now()->subMinutes(5)->timestamp)
            ->exists();

        $oldestUnreserved = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->subMinutes(2)->timestamp)
            ->exists();

        $status = 'idle';
        if ($pending > 0 || $processing > 0) {
            if ($recentActivity || $processing > 0) {
                $status = 'processing';
            } elseif ($oldestUnreserved) {
                $status = 'stalled';
            } else {
                $status = 'pending';
            }
        }

        $jobBreakdown = DB::table('jobs')
            ->get(['payload'])
            ->map(function ($job): ?string {
                return $this->extractJobDisplayName((string) ($job->payload ?? ''));
            })
            ->filter(fn (?string $jobClass): bool => filled($jobClass))
            ->countBy()
            ->sortDesc()
            ->all();

        $latestFailedPayload = $latestFailed ? json_decode($latestFailed->payload, true) : null;
        $alertFailedJobs = $failedJobs
            ->map(function ($job): array {
                return [
                    'display_name' => $this->extractJobDisplayName((string) ($job->payload ?? '')),
                    'failed_at' => $job->failed_at,
                    'exception' => $job->exception,
                ];
            })
            ->filter(fn (array $job): bool => $this->isPaymentFailureAlertJob($job['display_name'] ?? null))
            ->values();
        $latestFailedAlertJob = $alertFailedJobs->first();
        $recentAlertAttempts = DB::table('payment_attempts')
            ->leftJoin('payments', 'payments.id', '=', 'payment_attempts.payment_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->whereIn('payment_attempts.attempt_type', [
                'payment_failure_alert_enqueue',
                'payment_failure_alert_sms',
            ])
            ->orderByDesc('payment_attempts.id')
            ->limit(10)
            ->get([
                'payment_attempts.id',
                'payment_attempts.payment_id',
                'payment_attempts.attempt_type',
                'payment_attempts.status',
                'payment_attempts.error_code',
                'payment_attempts.error_message',
                'payment_attempts.request_meta',
                'payment_attempts.response_meta',
                'payment_attempts.created_at',
                'payments.reference_number',
                'payments.transaction_reference',
                'payments.platform_id',
                'platforms.name as platform_name',
            ])
            ->map(function ($attempt): array {
                $requestMeta = json_decode((string) ($attempt->request_meta ?? ''), true);
                $responseMeta = json_decode((string) ($attempt->response_meta ?? ''), true);

                return [
                    'id' => (int) $attempt->id,
                    'payment_id' => (int) $attempt->payment_id,
                    'attempt_type' => (string) $attempt->attempt_type,
                    'status' => (string) $attempt->status,
                    'error_code' => $attempt->error_code,
                    'error_message' => $attempt->error_message,
                    'created_at' => $attempt->created_at,
                    'reference' => $attempt->reference_number ?: $attempt->transaction_reference,
                    'platform_id' => $attempt->platform_id ? (int) $attempt->platform_id : null,
                    'platform_name' => $attempt->platform_name,
                    'event_key' => is_array($requestMeta) ? ($requestMeta['event_key'] ?? null) : null,
                    'recipient_name' => is_array($requestMeta) ? ($requestMeta['user_name'] ?? null) : null,
                    'recipient_role' => is_array($requestMeta) ? ($requestMeta['user_role'] ?? null) : null,
                    'recipient_phone' => is_array($requestMeta) ? ($requestMeta['phone'] ?? null) : null,
                    'trigger_source' => is_array($requestMeta) ? ($requestMeta['trigger_source'] ?? null) : null,
                    'skip_reason' => is_array($responseMeta) ? ($responseMeta['skip_reason'] ?? null) : null,
                    'provider_result' => is_array($responseMeta) ? ($responseMeta['provider_result'] ?? null) : null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'status' => $status,
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failedCount,
            'alerts_pending' => $pendingAlerts,
            'alerts_processing' => $processingAlerts,
            'alerts_failed' => $alertFailedJobs->count(),
            'latest_failed_at' => $latestFailed?->failed_at,
            'latest_failed_exception' => $latestFailed ? Str::limit($latestFailed->exception, 300) : null,
            'latest_failed_job' => $latestFailedPayload ? class_basename($latestFailedPayload['displayName'] ?? '') : null,
            'latest_failed_alert_at' => $latestFailedAlertJob['failed_at'] ?? null,
            'latest_failed_alert_exception' => isset($latestFailedAlertJob['exception'])
                ? Str::limit((string) $latestFailedAlertJob['exception'], 300)
                : null,
            'latest_failed_alert_job' => isset($latestFailedAlertJob['display_name'])
                ? class_basename((string) $latestFailedAlertJob['display_name'])
                : null,
            'job_breakdown' => $jobBreakdown,
            'recent_alert_attempts' => $recentAlertAttempts,
            'queue_cron_command' => ($queueConnection = (string) config('queue.default', 'sync')) !== 'sync'
                ? sprintf(
                    // auto_optimize is LAST (lowest priority) so it never delays payment/push/alert
                    // jobs — but it MUST be listed or the optimize queue is never consumed (jobs
                    // pile up unreserved → "stalled"). This was the root cause of the optimizer stall.
                    '* * * * * cd %s && %s artisan queue:work %s --queue=push,alerts,default,auto_optimize --max-time=55 --max-jobs=100 --tries=3 --sleep=3 >> /dev/null 2>&1',
                    base_path(),
                    config('deployment.php_binary', '/usr/local/bin/php'),
                    $queueConnection
                )
                : null,
            'pulse_url' => url('/' . ltrim((string) config('pulse.path', 'pulse'), '/')),
            'pulse_check_command' => sprintf(
                'cd %s && %s artisan pulse:check',
                base_path(),
                config('deployment.php_binary', '/usr/local/bin/php')
            ),
            'pulse_restart_command' => sprintf(
                'cd %s && %s artisan pulse:restart',
                base_path(),
                config('deployment.php_binary', '/usr/local/bin/php')
            ),
        ]);
    }

    public function clientSyncStatus(): JsonResponse
    {
        $syncQueues = ['sync-clients', 'sync-clients-reconcile'];
        $config = config('services.client_sync', []);

        $platforms = Platform::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'domain',
                'country',
                'is_active',
                'wp_api_url',
                'wp_api_user',
                'wp_api_password',
                'sync_last_synced_at',
                'sync_last_status',
                'sync_last_error',
                'client_sync_protocol',
                'client_sync_contract_version',
                'client_sync_capability_status',
                'client_sync_capability_checked_at',
                'client_sync_checkpoint_at',
                'client_sync_last_reconciled_at',
            ]);

        $platformIds = $platforms->pluck('id')->map(fn ($id) => (int) $id)->all();
        $clientCounts = Client::query()
            ->selectRaw('platform_id, COUNT(*) AS total, SUM(CASE WHEN profile_status = ? THEN 1 ELSE 0 END) AS published', ['publish'])
            ->whereIn('platform_id', $platformIds)
            ->groupBy('platform_id')
            ->get()
            ->keyBy('platform_id');

        $latestRunIds = ClientSyncRun::query()
            ->selectRaw('MAX(id) AS id')
            ->whereIn('platform_id', $platformIds)
            ->groupBy('platform_id')
            ->pluck('id');

        $latestRuns = ClientSyncRun::query()
            ->with('initiatedBy:id,name,email')
            ->whereIn('id', $latestRunIds)
            ->get()
            ->keyBy('platform_id');

        $recentRuns = ClientSyncRun::query()
            ->with('platform:id,name,domain')
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (ClientSyncRun $run): array => $this->serializeClientSyncRun($run))
            ->values();

        $queueRows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) AS total, SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS processing')
            ->whereIn('queue', $syncQueues)
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $syncFailedJobs = DB::table('failed_jobs')
            ->where(function ($query) use ($syncQueues) {
                $query->whereIn('queue', $syncQueues)
                    ->orWhere('payload', 'like', '%RunClientSyncJob%');
            })
            ->count();

        $runStatusCounts = ClientSyncRun::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $runModeCounts = ClientSyncRun::query()
            ->selectRaw('mode, COUNT(*) AS total')
            ->groupBy('mode')
            ->pluck('total', 'mode')
            ->map(fn ($count) => (int) $count)
            ->all();

        $staleAfter = now()->subHours(2);
        $rows = $platforms
            ->map(function (Platform $platform) use ($clientCounts, $latestRuns, $staleAfter): array {
                $latestRun = $latestRuns->get((int) $platform->id);
                $counts = $clientCounts->get((int) $platform->id);
                $credentialsReady = filled($platform->wp_api_url)
                    && filled($platform->wp_api_user)
                    && filled($platform->wp_api_password);
                $lastSyncedAt = $platform->sync_last_synced_at;
                $isStale = (bool) $platform->is_active
                    && $credentialsReady
                    && (! $lastSyncedAt || $lastSyncedAt->lt($staleAfter));

                return [
                    'platform_id' => (int) $platform->id,
                    'platform_name' => $platform->name,
                    'domain' => $platform->domain,
                    'country' => $platform->country,
                    'is_active' => (bool) $platform->is_active,
                    'credentials_ready' => $credentialsReady,
                    'status' => $this->platformClientSyncStatus($platform, $credentialsReady, $isStale, $latestRun),
                    'protocol' => $platform->client_sync_protocol ?: 'v1',
                    'contract_version' => $platform->client_sync_contract_version,
                    'capability_status' => $platform->client_sync_capability_status,
                    'capability_checked_at' => optional($platform->client_sync_capability_checked_at)->toDateTimeString(),
                    'last_synced_at' => optional($lastSyncedAt)->toDateTimeString(),
                    'last_status' => $platform->sync_last_status ?: 'unknown',
                    'last_error' => $platform->sync_last_error ? Str::limit((string) $platform->sync_last_error, 180) : null,
                    'checkpoint_at' => optional($platform->client_sync_checkpoint_at)->toDateTimeString(),
                    'last_reconciled_at' => optional($platform->client_sync_last_reconciled_at)->toDateTimeString(),
                    'clients_total' => (int) ($counts->total ?? 0),
                    'clients_published' => (int) ($counts->published ?? 0),
                    'latest_run' => $latestRun ? $this->serializeClientSyncRun($latestRun) : null,
                ];
            })
            ->values();

        $summary = [
            'total_platforms' => $rows->count(),
            'active_platforms' => $rows->where('is_active', true)->count(),
            'wp_ready_platforms' => $rows->where('credentials_ready', true)->count(),
            'active_wp_ready_platforms' => $rows->where('is_active', true)->where('credentials_ready', true)->count(),
            'healthy_platforms' => $rows->where('is_active', true)->where('status', 'healthy')->count(),
            'attention_platforms' => $rows
                ->where('is_active', true)
                ->whereIn('status', ['error', 'failed', 'stale', 'missing_credentials'])
                ->count(),
            'stale_platforms' => $rows->where('status', 'stale')->count(),
            'error_platforms' => $rows->whereIn('status', ['error', 'failed'])->count(),
            'running_runs' => (int) ($runStatusCounts[ClientSyncRun::STATUS_RUNNING] ?? 0),
            'queued_runs' => (int) ($runStatusCounts[ClientSyncRun::STATUS_QUEUED] ?? 0),
            'failed_jobs' => (int) $syncFailedJobs,
        ];

        $status = 'healthy';
        if ($summary['error_platforms'] > 0 || $summary['failed_jobs'] > 0) {
            $status = 'degraded';
        } elseif ($summary['stale_platforms'] > 0) {
            $status = 'stale';
        } elseif ($summary['running_runs'] > 0 || $summary['queued_runs'] > 0) {
            $status = 'processing';
        }

        return response()->json([
            'status' => $status,
            'summary' => $summary,
            'configuration' => [
                'delta_schedule' => 'every 30 minutes',
                'full_schedule' => 'daily at 02:05 UTC',
                'per_page' => (int) ($config['per_page'] ?? 100),
                'delta_max_platforms_per_run' => (int) ($config['delta_max_platforms_per_run'] ?? 3),
                'delta_stagger_seconds' => (int) ($config['delta_stagger_seconds'] ?? 120),
                'reconcile_stagger_seconds' => (int) ($config['reconcile_stagger_seconds'] ?? 180),
                'queue_connection' => (string) config('queue.default', 'sync'),
                'delta_queue' => 'sync-clients',
                'full_queue' => 'sync-clients-reconcile',
            ],
            'queues' => [
                'sync-clients' => [
                    'pending' => (int) ($queueRows->get('sync-clients')->pending ?? 0),
                    'processing' => (int) ($queueRows->get('sync-clients')->processing ?? 0),
                    'total' => (int) ($queueRows->get('sync-clients')->total ?? 0),
                ],
                'sync-clients-reconcile' => [
                    'pending' => (int) ($queueRows->get('sync-clients-reconcile')->pending ?? 0),
                    'processing' => (int) ($queueRows->get('sync-clients-reconcile')->processing ?? 0),
                    'total' => (int) ($queueRows->get('sync-clients-reconcile')->total ?? 0),
                ],
                'failed' => (int) $syncFailedJobs,
            ],
            'run_counts' => [
                'by_status' => $runStatusCounts,
                'by_mode' => $runModeCounts,
            ],
            'platforms' => $rows,
            'recent_runs' => $recentRuns,
        ]);
    }

    private function platformClientSyncStatus(
        Platform $platform,
        bool $credentialsReady,
        bool $isStale,
        ?ClientSyncRun $latestRun
    ): string {
        if (! $platform->is_active) {
            return 'inactive';
        }

        if (! $credentialsReady) {
            return 'missing_credentials';
        }

        if ($latestRun && in_array($latestRun->status, [ClientSyncRun::STATUS_QUEUED, ClientSyncRun::STATUS_RUNNING], true)) {
            return $latestRun->status;
        }

        if (in_array((string) $platform->sync_last_status, ['error', 'failed'], true)) {
            return 'error';
        }

        if ($latestRun && in_array($latestRun->status, [ClientSyncRun::STATUS_FAILED, ClientSyncRun::STATUS_STALE], true)) {
            return $latestRun->status;
        }

        if ($isStale) {
            return 'stale';
        }

        return 'healthy';
    }

    private function serializeClientSyncRun(ClientSyncRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'platform_id' => (int) $run->platform_id,
            'platform_name' => $run->platform?->name,
            'origin' => $run->origin,
            'mode' => $run->mode,
            'protocol' => $run->protocol,
            'status' => $run->status,
            'processed' => (int) ($run->processed ?? 0),
            'created' => (int) ($run->created ?? 0),
            'updated' => (int) ($run->updated ?? 0),
            'skipped' => (int) ($run->skipped ?? 0),
            'errors' => (int) ($run->errors ?? 0),
            'reason' => $run->reason,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'last_heartbeat_at' => optional($run->last_heartbeat_at)->toDateTimeString(),
            'created_at' => optional($run->created_at)->toDateTimeString(),
        ];
    }

    private function extractJobDisplayName(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? ($decoded['displayName'] ?? null) : null;
    }

    private function isPaymentFailureAlertJob(?string $displayName): bool
    {
        return in_array($displayName, [
            SendPaymentFailureAlertsJob::class,
            SendPaymentFailureAlertRecipientJob::class,
        ], true);
    }

    public function retryFailedJobs(): JsonResponse
    {
        $failedCount = DB::table('failed_jobs')->count();

        if ($failedCount === 0) {
            return response()->json(['retried' => 0, 'message' => 'No failed jobs to retry.']);
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        return response()->json([
            'retried' => $failedCount,
            'message' => sprintf('Retried %d failed job(s).', $failedCount),
        ]);
    }

    public function flushFailedJobs(): JsonResponse
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();

        return response()->json([
            'flushed' => $count,
            'message' => $count > 0
                ? sprintf('Flushed %d failed job(s).', $count)
                : 'No failed jobs to flush.',
        ]);
    }

    public function clearPendingJobs(Request $request): JsonResponse
    {
        $jobClass = $request->input('job_class');

        $query = DB::table('jobs')->whereNull('reserved_at');

        if ($jobClass) {
            $query->where(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName'))"),
                $jobClass
            );
        }

        $count = $query->count();
        $query->delete();

        return response()->json([
            'cleared' => $count,
            'message' => $count > 0
                ? sprintf('Cleared %d pending job(s).%s', $count, $jobClass ? " ({$jobClass})" : '')
                : 'No matching pending jobs to clear.',
        ]);
    }

    public function clearAllJobs(): JsonResponse
    {
        $pendingCount = DB::table('jobs')->count();
        $failedCount = DB::table('failed_jobs')->count();

        DB::table('jobs')->truncate();
        DB::table('failed_jobs')->truncate();

        return response()->json([
            'cleared_pending' => $pendingCount,
            'cleared_failed' => $failedCount,
            'message' => sprintf('Cleared %d pending and %d failed job(s).', $pendingCount, $failedCount),
        ]);
    }

    /**
     * Manually trigger a short-lived queue worker run.
     * Processes up to 20 jobs or 45 seconds, whichever comes first.
     * This clears any stale scheduler lock and immediately processes pending jobs.
     */
    public function nudgeWorker(): JsonResponse
    {
        $pendingBefore = DB::table('jobs')->whereNull('reserved_at')->count();

        if ($pendingBefore === 0) {
            return response()->json([
                'processed' => 0,
                'message' => 'No pending jobs to process.',
            ]);
        }

        // Clear any stale scheduler mutex lock so the cron worker can resume normally.
        $mutexKey = 'framework/schedule-' . sha1('queue_worker');
        cache()->forget($mutexKey);

        // Run a short-lived worker synchronously — processes jobs immediately.
        // auto_optimize listed last so it drains too without delaying push.
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'push,default,auto_optimize',
            '--stop-when-empty' => true,
            '--max-time' => 45,
            '--max-jobs' => 20,
            '--tries' => 3,
        ]);

        $pendingAfter = DB::table('jobs')->whereNull('reserved_at')->count();
        $processed = max(0, $pendingBefore - $pendingAfter);

        return response()->json([
            'processed' => $processed,
            'remaining' => $pendingAfter,
            'message' => $processed > 0
                ? sprintf('Processed %d job(s). %d remaining.', $processed, $pendingAfter)
                : 'Worker started but no jobs were completed yet. They may still be processing.',
        ]);
    }
}
