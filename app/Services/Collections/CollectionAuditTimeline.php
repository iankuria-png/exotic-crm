<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Facades\DB;

class CollectionAuditTimeline
{
    public function forReceipt(CollectionReceipt $receipt, ?int $before = null): array
    {
        $rows = DB::table('collection_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.account_id', $receipt->account_id)->where('a.receipt_id', $receipt->id)
            ->when($before, fn ($q) => $q->where('a.id', '<', $before))->orderByDesc('a.id')->limit(26)
            ->get(['a.id', 'a.action', 'a.actor_id', 'u.name as actor_name', 'a.context', 'a.created_at']);
        $items = $rows->take(25)->map(function ($row) {
            $context = json_decode($row->context, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => $row->id, 'action' => $row->action, 'actor' => $row->actor_id ? ($row->actor_name ?: 'Staff member') : 'System',
                'at' => $row->created_at, 'reason' => $context['reason'] ?? null,
                'client_id' => $context['client_id'] ?? data_get($context, 'purchase.client_id'),
                'package' => isset($context['purchase']) ? array_intersect_key($context['purchase'], array_flip(['tier', 'duration_days', 'amount_minor', 'currency'])) : null,
                'correction_id' => $context['correction_id'] ?? null, 'policy_version' => $context['policy_version'] ?? null,
                'match_score' => $context['match_score'] ?? null, 'state' => $context['state'] ?? null];
        })->values()->all();

        return ['items' => $items, 'next_before' => $rows->count() > 25 ? end($items)['id'] : null];
    }
}
