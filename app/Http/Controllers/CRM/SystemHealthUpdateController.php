<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\SendPaymentFailureAlertRecipientJob;
use App\Jobs\SendPaymentFailureAlertsJob;
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
