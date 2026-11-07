<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionEvent;
use App\Models\Collections\CollectionReceipt;

/** Staff-only original receiving messages. Never exposes the webhook or general inbox. */
class CollectionMessageService
{
    public function forReceipt(CollectionReceipt $receipt): array
    {
        $events = CollectionEvent::where('receipt_id', $receipt->id)->where('account_id', $receipt->account_id)
            ->where('state', 'parsed')->where('event_kind', 'sms:received')->orderByDesc('id')->limit(201)->get();
        $messages = [];
        $complete = $events->count() <= 200;
        foreach ($events->take(200) as $event) {
            $data = $event->normalized ?? [];
            $sender = (string) ($data['sender'] ?? '');
            $body = (string) ($data['message'] ?? '');
            $credit = app(TzMpesaParser::class)->parse($sender, $body);
            if (($credit['kind'] ?? '') !== 'credit' || $credit['reference'] !== $receipt->reference_norm
                || $credit['currency'] !== $receipt->currency || $credit['principal_minor'] !== $receipt->principal_minor) {
                $complete = false;

                continue;
            }
            $key = hash('sha256', $sender."\0".$body);
            if (isset($messages[$key])) {
                $messages[$key]['copies']++;

                continue;
            }
            $messages[$key] = ['id' => $event->id, 'sender' => $sender, 'message' => $body,
                'received_at' => $event->sms_received_at?->toIso8601String(), 'copies' => 1];
        }

        return ['messages' => array_values($messages), 'complete' => $complete];
    }
}
