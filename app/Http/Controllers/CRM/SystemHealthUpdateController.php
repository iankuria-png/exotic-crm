<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\DeploymentStatusService;
use App\Support\CrmAuditAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
