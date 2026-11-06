<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Models\Collections\CollectionEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CollectionIngestService
{
    /** Native SMSGate signing: exact raw bytes followed by delivery timestamp. */
    public function authenticate(CollectionDevice $device, string $body, string $timestamp, string $signature): void
    {
        if ($device->revoked_at || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > config('collection.signature_max_age_seconds')
            || ! preg_match('/^[a-f0-9]{64}$/Di', $signature)
            || ! hash_equals(hash_hmac('sha256', $body.$timestamp, $device->signing_secret), strtolower($signature))) {
            throw new CollectionException('invalid_device_signature', 401);
        }
    }

    public function accept(CollectionDevice $device, string $rawBody, bool $historical = false): array
    {
        if (strlen($rawBody) > config('collection.max_body_bytes')) {
            throw new CollectionException('event_too_large', 413);
        }
        try {
            $data = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CollectionException('invalid_json', 422);
        }
        Validator::make(is_array($data) ? $data : [], [
            'id' => 'required|string|max:128', 'deviceId' => 'required|string|max:128',
            'event' => 'required|string|max:64', 'payload' => 'required|array',
        ])->validate();
        if ($data['deviceId'] !== $device->external_device_id || $device->revoked_at) {
            throw new CollectionException('device_identity_mismatch', 401);
        }

        return DB::transaction(function () use ($device, $data, $rawBody, $historical) {
            // Serialize transport dedupe and binding anomalies on this enrolled device.
            $device = CollectionDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($device->revoked_at) {
                throw new CollectionException('device_revoked', 401);
            }
            $hash = hash('sha256', $rawBody);
            $existing = CollectionEvent::where('device_id', $device->id)->where('external_event_id', $data['id'])->first();
            if ($existing) {
                if (! hash_equals($existing->raw_sha256, $hash)) {
                    DB::table('collection_event_conflicts')->insertOrIgnore(['event_id' => $existing->id, 'raw_sha256' => $hash, 'raw_body' => Crypt::encryptString($rawBody), 'created_at' => now()]);
                    $this->suspendDeviceAccounts($device, 'transport_id_conflict');

                    return ['status' => 'quarantined', 'event_id' => $existing->id, 'reason' => 'transport_id_conflict'];
                }

                return ['status' => 'duplicate', 'event_id' => $existing->id];
            }
            $reason = null;
            $account = null;
            $received = null;
            if ($data['event'] === 'sms:received') {
                Validator::make($data['payload'], ['message' => 'required|string|max:16000', 'sender' => 'required|string|max:128',
                    'recipient' => 'nullable|string|max:128', 'simNumber' => 'required|integer', 'receivedAt' => 'required|string|max:64'])->validate();
                $bindings = DB::table('collection_sim_bindings')->where('device_id', $device->id)
                    ->where('sim_number', $data['payload']['simNumber'])->whereNull('valid_until')->get();
                if ($bindings->count() !== 1 || ! app(CollectionSimIdentityService::class)->messageMatches($device, $bindings->first(), $data['payload'])) {
                    $reason = 'sim_or_recipient_mismatch';
                    $this->suspendDeviceAccounts($device, $reason);
                } else {
                    $binding = $bindings->first();
                    $account = CollectionAccount::findOrFail($binding->account_id);
                    try {
                        // An offset is mandatory; never interpret a local clock as UTC.
                        if (! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $data['payload']['receivedAt'])) {
                            throw new \InvalidArgumentException;
                        }
                        $received = Carbon::parse($data['payload']['receivedAt'])->utc();
                        if ($received->isAfter(now()->addMinutes(2)) || $received->lt(Carbon::parse($binding->valid_from)->utc()) && ! $historical) {
                            $reason = 'receive_time_outside_binding';
                        }
                    } catch (\Throwable) {
                        $reason = 'invalid_receive_time';
                    }
                }
            } elseif ($data['event'] === 'app:started') {
                $bindings = DB::table('collection_sim_bindings')->where('device_id', $device->id)->whereNull('valid_until')->get();
                foreach ($bindings as $binding) {
                    $cards = collect($data['payload']['simCards'] ?? [])->where('simNumber', $binding->sim_number);
                    $card = $cards->count() === 1 ? $cards->first() : [];
                    if (! app(CollectionSimIdentityService::class)->inventoryMatches($device, $binding, $card)) {
                        $reason = 'sim_inventory_changed';
                        $this->suspendDeviceAccounts($device, $reason);
                    }
                }
            } elseif ($data['event'] === 'system:ping' && app(CollectionDeviceHealthService::class)->freshCaptureObservation($device, $data['payload'])) {
                if (data_get($data, 'payload.capture.formatDrift') === true) {
                    $reason = 'payment_filter_format_drift';
                    $this->suspendDeviceAccounts($device, $reason);
                }
                if (app(CollectionSimIdentityService::class)->mode($device) === 'android_subscription_v1') {
                    $cards = collect(data_get($data, 'payload.capture.simBindings', []));
                    $bindings = DB::table('collection_sim_bindings')->where('device_id', $device->id)->whereNull('valid_until')->get();
                    foreach ($bindings as $binding) {
                        $card = $cards->where('simNumber', $binding->sim_number);
                        if ($card->count() !== 1 || ! app(CollectionSimIdentityService::class)->inventoryMatches($device, $binding, $card->first())) {
                            $reason = 'sim_inventory_changed';
                            $this->suspendDeviceAccounts($device, $reason);
                        }
                    }
                }
            } elseif ($data['event'] !== 'system:ping') {
                $reason = 'unsupported_event_kind';
            }
            $event = CollectionEvent::create(['device_id' => $device->id, 'external_event_id' => $data['id'],
                'event_kind' => $data['event'], 'source_kind' => $historical ? 'historical' : 'live',
                'account_id' => $account?->id, 'raw_body' => $rawBody, 'raw_sha256' => $hash,
                'normalized' => $data['payload'], 'sms_received_at' => $received, 'ingested_at' => now(),
                'state' => $reason ? 'quarantined' : ($data['event'] === 'sms:received' ? 'unparsed' : 'heartbeat'), 'quarantine_reason' => $reason]);
            if (! $reason && $data['event'] === 'sms:received') {
                $this->outbox('parse', (string) $event->id, 'parse:'.$event->id);
            }
            $device->update(['last_seen_at' => now()]);

            return ['status' => $reason ? 'quarantined' : 'accepted', 'event_id' => $event->id];
        }, 3);
    }

    public function outbox(string $kind, string $subject, string $key, $at = null): void
    {
        DB::table('collection_outbox')->insertOrIgnore(['kind' => $kind, 'subject_id' => $subject, 'dedupe_key' => $key, 'available_at' => $at ?? now()]);
    }

    private function suspendDeviceAccounts(CollectionDevice $device, string $reason): void
    {
        CollectionAccount::whereIn('id', DB::table('collection_sim_bindings')->where('device_id', $device->id)->whereNull('valid_until')->select('account_id'))
            ->update(['suspended_at' => now(), 'suspension_reason' => $reason]);
    }
}
