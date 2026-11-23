<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionProviderEvent;
use App\Models\Collections\CollectionProviderFeed;
use App\Models\Collections\CollectionProviderResource;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Facades\DB;

class KopokopoEvidenceService
{
    public function forReceipts(iterable $receipts): array
    {
        $receipts = collect($receipts)->keyBy('id');
        $feeds = CollectionProviderFeed::whereIn('account_id', $receipts->pluck('account_id'))->get()->keyBy('id');
        $ranked = DB::table('collection_provider_events')->whereIn('feed_id', $feeds->keys())->whereIn('receipt_id', $receipts->keys())
            ->where('state', 'accepted')->select('id')->selectRaw('ROW_NUMBER() OVER (PARTITION BY receipt_id ORDER BY id DESC) AS evidence_rank');
        $ids = DB::query()->fromSub($ranked, 'ranked')->where('evidence_rank', '<=', 201)->pluck('id');
        $events = CollectionProviderEvent::whereIn('id', $ids)->select(['id', 'feed_id', 'receipt_id', 'source_kind', 'observation', 'received_at'])->orderByDesc('id')->get()->groupBy('receipt_id');
        $resources = CollectionProviderResource::whereIn('receipt_id', $receipts->keys())->get()->keyBy('receipt_id');
        $out = [];
        foreach ($receipts as $r) {
            if (! $resources->has($r->id)) {
                continue;
            }
            $rows = $events->get($r->id, collect());
            $names = [];
            $sourceIds = [];
            $complete = $rows->isNotEmpty() && $rows->count() <= 200;
            foreach ($rows->take(200) as $event) {
                if (($feeds[$event->feed_id]->account_id ?? null) !== $r->account_id || ($event->observation['reference'] ?? null) !== $r->reference_norm) {
                    $complete = false;

                    continue;
                }
                if ($name = $event->observation['payer_name'] ?? null) {
                    $names[mb_strtolower($name)] = $name;
                }
                $sourceIds[] = $event->id;
            }
            // A first-name statement entry may enrich into a full provider name without inventing a conflict.
            foreach (array_keys($names) as $short) {
                foreach (array_keys($names) as $long) {
                    if ($short !== $long && str_starts_with($long, $short.' ')) {
                        unset($names[$short]);
                    }
                }
            }
            $kind = $resources[$r->id]->identity_kind;
            $out[$r->id] = ['name' => $complete && count($names) === 1 ? array_values($names)[0] : null,
                'phone' => $r->payer_phone ? '+'.$r->payer_phone : null, 'name_state' => ! $complete ? 'incomplete' : (count($names) > 1 ? 'conflicting' : (count($names) ? 'supplied' : 'missing')),
                'party' => null, 'complete' => $complete && count($names) <= 1, 'source_event_ids' => $sourceIds,
                'payer_reference' => null, 'source' => 'kopokopo', 'identity_kind' => $kind, 'protected_identity' => $kind === 'hash' && ! $r->payer_phone];
        }

        return $out;
    }

    public function messages(CollectionReceipt $receipt): array
    {
        $payer = $this->forReceipts([$receipt])[$receipt->id] ?? [];
        $feedIds = CollectionProviderFeed::where('account_id', $receipt->account_id)->pluck('id');
        $events = CollectionProviderEvent::whereIn('feed_id', $feedIds)->where('receipt_id', $receipt->id)->where('state', 'accepted')->select(['id', 'feed_id', 'receipt_id', 'source_kind', 'observation', 'received_at'])->orderByDesc('id')->limit(201)->get();
        $messages = [];
        foreach ($events->take(200) as $event) {
            $o = $event->observation;
            if (($o['reference'] ?? null) !== $receipt->reference_norm) {
                continue;
            }
            $verification = $event->source_kind === 'statement' ? 'Verified on imported statement' : ($event->source_kind === 'webhook' ? 'Signed KopoKopo notification' : 'KopoKopo reconciliation');
            $body = implode("\n", [$o['reversed'] ? 'Payment reversed' : 'Payment received', 'Till: 268491', 'Reference: '.$receipt->reference_norm,
                'Amount: KES '.number_format($o['principal_minor'] / 100, 2, '.', ','), 'Paid at: '.$o['provider_at'],
                'Payer: '.($o['payer_name'] ?: 'Name not supplied'), 'Phone: '.($payer['phone'] ?? ($payer['protected_identity'] ?? false ? 'Protected by provider' : 'Not supplied')),
                'Evidence: '.$verification]);
            $messages[] = ['id' => $event->id, 'sender' => 'KopoKopo', 'message' => $body, 'received_at' => $event->received_at->toIso8601String(), 'copies' => 1];
        }

        return ['source' => 'kopokopo', 'messages' => $messages, 'complete' => $events->count() <= 200];
    }
}
