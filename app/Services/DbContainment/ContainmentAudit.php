<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentOperation;
use App\Services\DbScanner\DbScanAuditWriter;

class ContainmentAudit
{
    public function record(DbContainmentOperation $op, string $action, ?string $code = null): void
    {
        app(DbScanAuditWriter::class)->record($op->actor_id, 'containment', $op->id, $action, null, ['status' => $op->status], $op->platform_id)
            ->forceFill(['operation_id' => $op->id, 'preview_digest' => $op->preview_digest, 'backup_id' => $op->backup_id, 'result_code' => $code])->save();
    }
}
