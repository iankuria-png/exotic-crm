<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Models\Collections\CollectionEvent;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CollectionReceiptService
{
    public function process(int $eventId): ?CollectionReceipt
    {
        return DB::transaction(function () use ($eventId) {
            $event = CollectionEvent::lockForUpdate()->findOrFail($eventId);
            if ($event->state !== 'unparsed') {
                return $event->receipt_id ? CollectionReceipt::find($event->receipt_id) : null;
            }
            $account = CollectionAccount::whereKey($event->account_id)->lockForUpdate()->firstOrFail();
            $parser = $account->provider_key === 'vodacom_mpesa_tz' && $account->country === 'TZ' ? app(TzMpesaParser::class) : null;
            $observation = $parser?->parse($event->normalized['sender'], $event->normalized['message']) ?? ['kind' => 'unsupported', 'reason' => 'parser_not_available'];
            $event->parse_version = $parser ? TzMpesaParser::VERSION : null;
            if (! in_array($observation['kind'], ['credit', 'hold']) || empty($observation['reference'])) {
                $event->fill(['state' => 'quarantined', 'quarantine_reason' => $observation['reason'] ?? 'reference_missing'])->save();
                if (($observation['kind'] ?? '') === 'hold') {
                    $account->update(['suspended_at' => now(), 'suspension_reason' => 'unresolved_reversal_reference']);
                } elseif (preg_match('/^\S+\s+(?:confirmed|imethibitishwa).*?(?:received|Umepokea|Receive)/is', $event->normalized['message'] ?? '')) {
                    $account->update(['suspended_at' => now(), 'suspension_reason' => 'receiving_parser_drift']);
                }

                return null;
            }
            $device = CollectionDevice::findOrFail($event->device_id);
            $providerAt = isset($observation['provider_at']) ? Carbon::parse($observation['provider_at'], 'UTC') : null;
            $historical = $event->source_kind !== 'live' || ! $device->cutover_at || ! $providerAt
                || $providerAt->lt($device->cutover_at) || ! $event->sms_received_at || $event->sms_received_at->lt($device->cutover_at);
            $receipt = CollectionReceipt::where('account_id', $account->id)->where('reference_norm', $observation['reference'])->lockForUpdate()->first();
            if (! $receipt) {
                $receipt = CollectionReceipt::create(['account_id' => $account->id, 'reference_raw' => $observation['reference_raw'] ?? $observation['reference'],
                    'reference_norm' => $observation['reference'], 'historical_only' => $historical,
                    'first_received_at' => $event->sms_received_at, 'last_received_at' => $event->sms_received_at,
                    'enrichment_deadline' => now()->addSeconds(config('collection.enrichment_seconds')),
                    'field_provenance' => [], 'conflicts' => []]);
            }
            $conflicts = $receipt->conflicts ?? [];
            $provenance = $receipt->field_provenance ?? [];
            if ($observation['kind'] === 'hold') {
                $account->update(['suspended_at' => now(), 'suspension_reason' => 'receiving_reversal_or_hold']);
                $receipt->funds_state = $observation['reason'] === 'reversal_completed' ? 'reversed' : 'disputed';
                $provenance['funds_state'][] = $event->id;
            } else {
                foreach (['principal_minor', 'currency', 'payer_phone'] as $field) {
                    $value = $observation[$field] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    if ($receipt->$field !== null && (string) $receipt->$field !== (string) $value) {
                        $conflicts[] = $field.'_conflict';
                    } else {
                        $receipt->$field = $value;
                    }
                    $provenance[$field][] = $event->id;
                }
                if ($providerAt) {
                    if ($receipt->provider_at && abs($receipt->provider_at->diffInSeconds($providerAt, false)) > 120
                        || $receipt->provider_latest_at && abs($receipt->provider_latest_at->diffInSeconds($providerAt, false)) > 120) {
                        $conflicts[] = 'provider_time_conflict';
                    }
                    $receipt->provider_at = $receipt->provider_at ? min($receipt->provider_at, $providerAt) : $providerAt;
                    $receipt->provider_latest_at = $receipt->provider_latest_at ? max($receipt->provider_latest_at, $providerAt) : $providerAt;
                    $provenance['provider_at'][] = $event->id;
                    if ($providerAt->gt($event->sms_received_at->copy()->addMinutes(2))) {
                        $conflicts[] = 'provider_time_after_receive';
                    }
                }
                if ($receipt->currency !== $account->currency) {
                    $conflicts[] = 'account_currency_conflict';
                }
                if ($receipt->payer_phone) {
                    $receipt->payer_fingerprint = app(CollectionPrivacy::class)->fingerprint($receipt->payer_phone);
                }
            }
            // Sticky historical classification, including an overlap discovered later.
            $receipt->historical_only = $receipt->historical_only || $historical;
            $receipt->last_received_at = $event->sms_received_at ? max($receipt->last_received_at ?? $event->sms_received_at, $event->sms_received_at) : $receipt->last_received_at;
            $receipt->conflicts = array_values(array_unique($conflicts));
            $receipt->field_provenance = $provenance;
            $receipt->version++;
            if ($conflicts || in_array($receipt->funds_state, ['reversed', 'disputed'])) {
                $receipt->decision_state = 'needs_review';
            }
            $receipt->save();
            $event->fill(['receipt_id' => $receipt->id, 'state' => 'parsed'])->save();
            app(CollectionIngestService::class)->outbox('decide', (string) $receipt->id, 'decide:'.$receipt->id.':'.$receipt->version);

            return $receipt;
        }, 3);
    }
}
