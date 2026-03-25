<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
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

        $failedCount = DB::table('failed_jobs')->count();
        $latestFailed = DB::table('failed_jobs')->orderByDesc('failed_at')->first();

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
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) as job_class, COUNT(*) as count")
            ->groupBy('job_class')
            ->pluck('count', 'job_class');

        $latestFailedPayload = $latestFailed ? json_decode($latestFailed->payload, true) : null;

        return response()->json([
            'status' => $status,
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failedCount,
            'latest_failed_at' => $latestFailed?->failed_at,
            'latest_failed_exception' => $latestFailed ? Str::limit($latestFailed->exception, 300) : null,
            'latest_failed_job' => $latestFailedPayload ? class_basename($latestFailedPayload['displayName'] ?? '') : null,
            'job_breakdown' => $jobBreakdown,
            'queue_cron_command' => sprintf(
                '* * * * * cd %s && %s artisan queue:work database --queue=push,default --max-time=55 --max-jobs=100 --tries=3 --sleep=3 >> /dev/null 2>&1',
                base_path(),
                config('deployment.php_binary', '/usr/local/bin/php')
            ),
        ]);
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
}
