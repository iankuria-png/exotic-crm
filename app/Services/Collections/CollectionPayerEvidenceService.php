<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionEvent;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Facades\DB;

/** Explicit staff-only presentation. Never serialize event payloads or modify capture records. */
class CollectionPayerEvidenceService
{
    public function forReceipts(iterable $receipts): array
    {
        $receipts = collect($receipts)->keyBy('id');
        if ($receipts->isEmpty()) {
            return [];
        }
        $ranked = DB::table('collection_events')->whereIn('receipt_id', $receipts->keys())
            ->where('state', 'parsed')->where('event_kind', 'sms:received')
            ->select(['id', 'receipt_id', 'account_id', 'normalized'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY receipt_id ORDER BY id DESC) AS evidence_rank');
        $events = CollectionEvent::fromSub($ranked, 'collection_events')->where('evidence_rank', '<=', 201)->get()->groupBy('receipt_id');
        $result = [];
        foreach ($receipts as $receipt) {
            $names = [];
            $sources = [];
            $parties = [];
            $rows = $events->get($receipt->id, collect());
            $complete = $rows->count() <= 200;
            foreach ($rows->take(200) as $event) {
                if ($event->account_id !== $receipt->account_id) {
                    $complete = false;

                    continue;
                }
                $data = $event->normalized;
                $payer = app(TzMpesaParser::class)->payerDetails($data['sender'] ?? '', $data['message'] ?? '');
                if ($payer['reference'] !== $receipt->reference_norm
                    || ($payer['phone'] && $payer['phone'] !== $receipt->payer_phone)) {
                    $complete = false;

                    continue;
                }
                if ($payer['name']) {
                    $names[mb_strtolower($payer['name'])] = $payer['name'];
                    $sources[] = $event->id;
                } elseif ($payer['party']) {
                    $parties[] = $payer['party'];
                }
            }
            $result[$receipt->id] = [
                'name' => $complete && count($names) === 1 ? array_values($names)[0] : null,
                'phone' => $receipt->payer_phone ? '+'.$receipt->payer_phone : null,
                'name_state' => ! $complete ? 'incomplete' : (count($names) > 1 ? 'conflicting' : (count($names) ? 'supplied' : 'missing')),
                'party' => count($names) ? null : ($parties[0] ?? null),
                'complete' => $complete && count($names) <= 1,
                'source_event_ids' => array_values(array_unique($sources)),
            ];
        }

        return $result;
    }

    public function forReceipt(CollectionReceipt $receipt): array
    {
        return $this->forReceipts([$receipt])[$receipt->id];
    }
}
