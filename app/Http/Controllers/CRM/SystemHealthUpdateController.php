<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\AuditService;
use App\Services\DeploymentStatusService;
use App\Support\CrmAuditAction;
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
}
