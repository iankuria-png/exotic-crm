<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Services\AuditService;
use App\Support\CrmAuditAction;
use Illuminate\Console\Command;

class RecordDeploymentAudit extends Command
{
    protected $signature = 'crm:record-deploy-audit
        {status : success or failed}
        {--user-id= : The initiating CRM user}
        {--source=manual : Deployment trigger source}
        {--branch= : Tracked branch}
        {--commit= : Commit SHA}
        {--reason= : Human reason for deployment}
        {--message= : Completion message}';

    protected $description = 'Record manual deployment completion in the CRM audit log.';

    public function __construct(private readonly AuditService $auditService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $status = strtolower(trim((string) $this->argument('status')));
        if (!in_array($status, ['success', 'failed'], true)) {
            $this->error('Status must be success or failed.');

            return self::INVALID;
        }

        $platformId = (int) (Platform::query()->orderBy('id')->value('id') ?? 0);
        if ($platformId <= 0) {
            $this->warn('No platform exists to satisfy the audit log constraint. Skipping deployment audit.');

            return self::SUCCESS;
        }

        $action = $status === 'success'
            ? CrmAuditAction::SYSTEM_DEPLOY_SUCCESS
            : CrmAuditAction::SYSTEM_DEPLOY_FAILED;

        $this->auditService->record([
            'platform_id' => $platformId,
            'actor_id' => $this->option('user-id'),
            'action' => $action,
            'entity_type' => 'deployment',
            'entity_id' => 1,
            'after_state' => [
                'status' => $status,
                'source' => $this->option('source'),
                'branch' => $this->option('branch'),
                'commit_sha' => $this->option('commit'),
                'message' => $this->option('message'),
            ],
            'reason' => $this->option('reason'),
        ]);

        $this->info('Deployment audit recorded.');

        return self::SUCCESS;
    }
}
