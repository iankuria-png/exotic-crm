<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionProviderEvent;
use App\Models\Collections\CollectionProviderFeed;
use App\Models\Collections\CollectionProviderResource;
use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\CollectionAutomationEpoch;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionPrivacy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Provider transport never manufactures SMS/device events or a payment/entitlement. */
class KopokopoIngestService
{
    public function webhook(CollectionProviderFeed $feed, string $body, string $signature): array
    {
        if (strlen($body) > config('collection_kopokopo.max_webhook_bytes')) {
            throw new CollectionException('provider_body_too_large', 413);
        }
        app(KopokopoFeedService::class)->authenticate($feed, $body, $signature);
        try {
            $parsed = app(KopokopoReceiptParser::class)->webhook(app(KopokopoReceiptParser::class)->decode($body));
        } catch (CollectionException $e) {
            return $this->quarantine($feed, 'invalid:'.hash('sha256', $body), $body, 'webhook', $e->reason);
        }

        return $this->observation($feed, $parsed['event_key'], $parsed['topic'], $parsed['observation'], $body, 'webhook');
    }

    public function quarantine(CollectionProviderFeed $feed, string $key, string $body, string $source, string $reason): array
    {
        return DB::transaction(function () use ($feed, $key, $body, $source, $reason) {
            $this->lockedFeed($feed);
            $event = CollectionProviderEvent::firstOrCreate(['feed_id' => $feed->id, 'event_key' => $key], [
                'topic' => 'unrecognized', 'source_kind' => $source, 'state' => 'quarantined', 'raw_body' => $body,
                'raw_sha256' => hash('sha256', $body), 'quarantine_reason' => $reason, 'received_at' => now()]);

            return ['status' => 'quarantined', 'event_id' => $event->id, 'reason' => $reason];
        }, 3);
    }

    public function observation(CollectionProviderFeed $feed, string $key, string $topic, array $o, string $body, string $source, bool $historical = false, bool $replay = false): array
    {
        return DB::transaction(function () use ($feed, $key, $topic, $o, $body, $source, $historical, $replay) {
            $feed = $this->lockedFeed($feed);
            if (! $feed->enabled || ! config('collection_kopokopo.enabled') || ! config('collection.capture_enabled')) {
                throw new CollectionException('provider_capture_disabled', 404);
            }
            $hash = hash('sha256', $body);
            $event = CollectionProviderEvent::where('feed_id', $feed->id)->where('event_key', $key)->lockForUpdate()->first();
            if ($event && ! hash_equals($event->raw_sha256, $hash)) {
                if ($event->receipt_id) {
                    $this->conflict(CollectionReceipt::lockForUpdate()->findOrFail($event->receipt_id), 'provider_event_id_content_conflict');
                }
                $event->update(['state' => 'quarantined', 'quarantine_reason' => 'provider_event_id_content_conflict']);

                return $this->quarantine($feed, 'conflict:'.hash('sha256', $key.$hash), $body, $source, 'provider_event_id_content_conflict');
            }
            if ($event && (! $replay || $event->state !== 'quarantined')) {
                return ['status' => $event->state === 'quarantined' ? 'quarantined' : 'duplicate', 'event_id' => $event->id, 'receipt_id' => $event->receipt_id];
            }
            $event ??= new CollectionProviderEvent(['feed_id' => $feed->id, 'event_key' => $key, 'raw_body' => $body, 'raw_sha256' => $hash, 'received_at' => now()]);
            $event->fill(['topic' => $topic, 'source_kind' => $source, 'state' => 'accepted', 'observation' => $o, 'quarantine_reason' => null]);
            $event->save();

            $byRef = CollectionProviderResource::where('feed_id', $feed->id)->where('reference_norm', $o['reference'])->lockForUpdate()->first();
            $byId = $o['resource_id'] ? CollectionProviderResource::where('feed_id', $feed->id)->where('provider_resource_id', $o['resource_id'])->lockForUpdate()->first() : null;
            if (($byId && $byId->reference_norm !== $o['reference']) || ($byRef?->provider_resource_id && $o['resource_id'] && $byRef->provider_resource_id !== $o['resource_id'])) {
                foreach (array_unique(array_filter([$byRef?->receipt_id, $byId?->receipt_id])) as $id) {
                    $this->conflict(CollectionReceipt::lockForUpdate()->findOrFail($id), 'provider_resource_reference_conflict');
                }
                $event->update(['receipt_id' => $byRef?->receipt_id ?? $byId?->receipt_id, 'state' => 'quarantined', 'quarantine_reason' => 'provider_resource_reference_conflict']);

                return ['status' => 'quarantined', 'event_id' => $event->id, 'reason' => 'provider_resource_reference_conflict'];
            }
            $r = CollectionReceipt::where('account_id', $feed->account_id)->where('reference_norm', $o['reference'])->lockForUpdate()->first();
            $new = ! $r;
            $at = Carbon::parse($o['provider_at'])->utc();
            $historical = $historical || $source === 'statement' || $at->lt($feed->cutover_at);
            if (! $r) {
                $r = CollectionReceipt::create(['account_id' => $feed->account_id, 'reference_raw' => $o['reference'], 'reference_norm' => $o['reference'],
                    'first_ingested_at' => now(), 'historical_only' => $historical, 'first_received_at' => now(), 'last_received_at' => now(),
                    'enrichment_deadline' => now(), 'field_provenance' => [], 'conflicts' => [], 'decision_state' => $historical ? 'historical' : 'collecting']
                    + app(CollectionAutomationEpoch::class)->intake($feed->account, ! $historical));
            }
            $resource = $byRef ?? CollectionProviderResource::create(['feed_id' => $feed->id, 'receipt_id' => $r->id, 'reference_norm' => $o['reference'], 'identity_kind' => 'absent']);
            if ((int) $resource->receipt_id !== (int) $r->id) {
                $this->conflict($r, 'provider_resource_reference_conflict');
                $event->update(['state' => 'quarantined', 'quarantine_reason' => 'provider_resource_reference_conflict']);

                return ['status' => 'quarantined', 'event_id' => $event->id];
            }
            if (! $resource->provider_resource_id && $o['resource_id']) {
                $resource->provider_resource_id = $o['resource_id'];
            }
            $conflicts = $r->conflicts ?? [];
            foreach (['principal_minor', 'currency', 'payer_phone'] as $field) {
                if (($o[$field] ?? null) === null) {
                    continue;
                }
                if ($r->$field !== null && (string) $r->$field !== (string) $o[$field]) {
                    $conflicts[] = 'provider_'.$field.'_conflict';
                } else {
                    $r->$field = $o[$field];
                }
            }
            if ($r->provider_at && abs($r->provider_at->diffInSeconds($at, false)) > 120) {
                $conflicts[] = 'provider_time_conflict';
            } else {
                $r->provider_at ??= $at;
                $r->provider_latest_at = $at;
            }
            $oldFingerprint = $r->payer_fingerprint;
            if ($o['sender_hash']) {
                if ($resource->sender_hash && ! hash_equals($resource->sender_hash, $o['sender_hash'])) {
                    $conflicts[] = 'provider_sender_identity_conflict';
                } else {
                    $resource->sender_hash = $o['sender_hash'];
                    $resource->identity_kind = 'hash';
                    $r->payer_fingerprint = $this->fingerprint($feed, 'hash', $o['sender_hash']);
                }
            } elseif ($r->payer_phone && $resource->identity_kind !== 'hash') {
                $resource->identity_kind = 'phone';
                $r->payer_fingerprint = $this->fingerprint($feed, 'phone', $r->payer_phone);
            }
            if ($oldFingerprint && $r->payer_fingerprint !== $oldFingerprint) {
                $r->candidate_snapshot = null;
                app(KopokopoFeedService::class)->audit($feed, null, 'provider_payer_identity_enriched', ['receipt_id' => $r->id, 'identity_kind' => $resource->identity_kind]);
            }
            $resource->save();
            $r->last_received_at = now();
            // First ingest/epoch/historical classification are immutable during overlap and CSV enrichment.
            if ($source !== 'statement') {
                $r->evidence_level = 'provider';
                $feed->last_delivery_at = now();
            } elseif ($new || $r->evidence_level !== 'provider') {
                $r->evidence_level = 'statement';
            }
            if ($o['reversed']) {
                $r->funds_state = 'reversed';
                app(KopokopoFeedService::class)->audit($feed, null, 'receipt_reversed_by_provider', ['receipt_id' => $r->id, 'event_id' => $event->id]);
            } elseif ($source === 'statement' && ! in_array($r->funds_state, ['reversed', 'disputed'], true)) {
                $r->funds_state = 'statement_verified';
            }
            $r->conflicts = array_values(array_unique($conflicts));
            $r->field_provenance = array_replace($r->field_provenance ?? [], ['provider_last_event_id' => $event->id]);
            if ($conflicts || in_array($r->funds_state, ['reversed', 'disputed'], true)) {
                $r->decision_state = 'needs_review';
            }
            $r->version++;
            $r->save();
            $event->update(['receipt_id' => $r->id]);
            $feed->save();
            if (! $r->historical_only) {
                app(CollectionIngestService::class)->outbox('decide', (string) $r->id, 'decide:'.$r->id.':'.$r->version);
            }

            return ['status' => 'accepted', 'event_id' => $event->id, 'receipt_id' => $r->id];
        }, 3);
    }

    public function fingerprint(CollectionProviderFeed $feed, string $kind, string $value): string
    {
        return app(CollectionPrivacy::class)->fingerprint('kopokopo:'.$feed->account_id.':'.$kind.':'.$value);
    }

    public function lockedFeed(CollectionProviderFeed $feed): CollectionProviderFeed
    {
        CollectionAccount::whereKey($feed->account_id)->lockForUpdate()->firstOrFail();

        return CollectionProviderFeed::whereKey($feed->id)->lockForUpdate()->firstOrFail();
    }

    private function conflict(CollectionReceipt $r, string $reason): void
    {
        $r->update(['conflicts' => array_values(array_unique([...($r->conflicts ?? []), $reason])), 'decision_state' => 'needs_review', 'version' => $r->version + 1]);
    }
}
