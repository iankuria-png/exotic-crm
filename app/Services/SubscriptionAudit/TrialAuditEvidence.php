<?php

namespace App\Services\SubscriptionAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reads durable trial authorisation; none of these sources prove a human executor. */
class TrialAuditEvidence
{
    public function available(): bool
    {
        return Schema::hasTable('free_trial_requests') && Schema::hasTable('trial_activation_operations') && Schema::hasTable('free_trial_request_events');
    }

    public function forPayments(array $payments): array
    {
        if (! $this->available()) {
            return [];
        }
        $keys = [];
        foreach ($payments as $payment) {
            if (! empty($payment->audit_trial_operation)) {
                $keys[] = $payment->audit_trial_operation;
            }
        }
        if ($keys === []) {
            return [];
        }
        $operations = DB::table('trial_activation_operations')->whereIn('operation_key', $keys)->get()->keyBy('operation_key');
        $requests = DB::table('free_trial_requests')->whereIn('id', $operations->pluck('request_id')->unique())->get()->keyBy('id');
        $authorizations = DB::table('free_trial_request_events')->whereIn('request_id', $requests->keys())->whereIn('action', ['authorized', 'approve'])->limit(30001)->get()->groupBy('request_id');
        if ($authorizations->sum(fn ($rows) => $rows->count()) > 30000) {
            throw new SubscriptionAuditException('Trial approval source limit exceeded.', 'audit_source_limit', 503, -32020);
        }
        $result = [];
        foreach ($payments as $payment) {
            $op = $operations[$payment->audit_trial_operation ?? ''] ?? null;
            $r = $op ? ($requests[$op->request_id] ?? null) : null;
            if (! $r || (int) ($payment->audit_trial_request ?? 0) !== (int) $r->id) {
                continue;
            }
            $terms = AuditValue::json($r->terms_json);
            $command = AuditValue::json($op->command_json);
            $effect = AuditValue::json($op->verified_effect_json);
            $same = (int) $payment->platform_id === (int) $r->platform_id && (int) $op->platform_id === (int) $r->platform_id
                && (int) $payment->client_id === (int) $op->client_id && (int) $payment->deal_id === (int) $op->deal_id
                && ($r->client_id === null || (int) $r->client_id === (int) $op->client_id)
                && in_array((int) $op->client_id, array_map('intval', $terms['client_ids'] ?? []), true)
                && (int) ($terms['product_id'] ?? 0) === (int) ($command['product_id'] ?? -1)
                && (int) ($terms['duration_days'] ?? 0) === (int) ($command['duration_days'] ?? -1)
                && (int) ($effect['wp_post_id'] ?? 0) === (int) ($command['post_id'] ?? -1)
                && (int) ($effect['wp_user_id'] ?? 0) === (int) ($command['wp_user_id'] ?? -1)
                && ($effect['post_status'] ?? null) === 'publish'
                && (int) ($effect['escort_expire'] ?? 0) > 0
                && ($command['method'] ?? null) === ($r->action === 'extend' ? 'extend' : 'activate');
            $authorization = collect($authorizations[$r->id] ?? [])->first(fn ($e) => (int) $e->actor_id === (int) $r->authorized_actor_id
                && $e->created_at <= $op->dispatched_at
                && ($e->action === 'approve' ? $r->approved_via === 'admin' : (AuditValue::json($e->detail_json)['method'] ?? null) === $r->approved_via));
            $valid = $same && $authorization && $op->source_digest === $r->source_version
                && ($terms['action'] ?? null) === $r->action && $r->expires_at && $r->expires_at >= $op->dispatched_at && $op->state === 'succeeded' && $op->verified_at && $op->finalized_at && $op->dispatched_at
                && $r->authorized_at && $r->authorized_at <= $op->dispatched_at && $op->dispatched_at <= $op->verified_at
                && $op->verified_at <= $op->finalized_at && $r->authorized_actor_id
                && in_array($r->approved_via, ['admin', 'pin'], true);
            if (! $valid) {
                $result[$payment->id] = ['state' => 'approval_effect_link_unverified'];

                continue;
            }
            $result[$payment->id] = [
                '_platform_id' => (int) $op->platform_id, '_client_id' => (int) $op->client_id, '_deal_id' => (int) $op->deal_id, '_payment_id' => (int) $payment->id,
                '_dispatched_at' => $op->dispatched_at, '_finalized_at' => $op->finalized_at,
                '_expiry' => (int) $effect['escort_expire'], '_authorised_id' => (int) $r->authorized_actor_id,
                'state' => 'approval_recorded', 'approval_method' => $r->approved_via,
                'requested_by' => AuditValue::handle('staff', $r->requester_id),
                'authorised_by' => AuditValue::handle('staff', $r->authorized_actor_id),
                'approver' => $r->approved_via === 'admin' ? AuditValue::handle('staff', $r->approver_id ?: $r->authorized_actor_id) : null,
                'executed_by' => null, 'execution_evidence' => 'unknown_system_mediated',
                'authorised_at' => AuditValue::iso($r->authorized_at), 'effect_verified_at' => AuditValue::iso($op->verified_at),
                'reason_recorded' => trim((string) $r->reason) !== '', 'reason_category' => 'approved_trial',
                'source_ref' => AuditValue::handle('src', 'trial_operation:'.$op->id),
            ];
        }

        return $result;
    }
}
