<?php

namespace App\Services\Ewms;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use RuntimeException;

class EwmsKpiReadAudit
{
    public function record(Request $request, string $action, array $input, array $result): void
    {
        $audit = new AuditLog([
            'platform_id' => count($input['platform_ids']) === 1 ? $input['platform_ids'][0] : null,
            'actor_id' => $request->user()->getAuthIdentifier(),
            'entity_type' => 'ewms_kpi_read',
            'entity_id' => $request->attributes->get('ewms_token_id'),
            'action' => $action,
            'after_state' => [
                'endpoint' => $request->path(),
                'mode' => isset($input['payment_ids']) ? 'payment_ids' : 'period',
                'platform_ids' => $input['platform_ids'],
                'payment_ids' => $input['payment_ids'] ?? null,
                'from' => $input['from'] ?? null,
                'to' => $input['to'] ?? null,
                'limit' => $input['limit'] ?? null,
                'contract' => EwmsKpiAccess::CONTRACT,
                'population' => 'crm_cs_revenue',
                'generated_at' => $result['meta']['generated_at'],
                'returned_count' => count($result['data']),
                'request_id' => $request->attributes->get('request_id'),
                'truncated' => $result['meta']['truncated'] ?? false,
                'missing_count' => count($result['meta']['missing_payment_ids'] ?? []),
            ],
            'created_at' => now(),
        ]);
        if (! $audit->save()) {
            throw new RuntimeException('Evidence audit was not persisted.');
        }
    }
}
