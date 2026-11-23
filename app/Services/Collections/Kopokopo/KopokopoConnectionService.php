<?php

namespace App\Services\Collections\Kopokopo;

use App\Models\Collections\CollectionProviderEvent;
use App\Models\Collections\CollectionProviderFeed;
use App\Models\Collections\CollectionProviderPoll;
use App\Models\User;
use App\Services\Collections\CollectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KopokopoConnectionService
{
    public function subscribe(CollectionProviderFeed $feed, User $actor, int $version): CollectionProviderFeed
    {
        app(KopokopoFeedService::class)->scope($actor, true);
        if (! $feed->enabled || ! config('collection.capture_enabled') || ! config('collection_kopokopo.enabled')) {
            throw new CollectionException('provider_capture_disabled', 404);
        }
        foreach ([KopokopoReceiptParser::RECEIVED, KopokopoReceiptParser::REVERSED] as $topic) {
            $send = DB::transaction(function () use ($feed, $topic, &$version) {
                $f = app(KopokopoIngestService::class)->lockedFeed($feed);
                if ($f->version !== $version) {
                    throw new CollectionException('provider_connection_version_changed', 409);
                }
                $subscriptions = $f->subscriptions ?? [];
                $state = data_get($subscriptions, $topic.'.state');
                if ($state === 'registered') {
                    return false;
                }
                if (in_array($state, ['requesting', 'needs_review'], true)) {
                    throw new CollectionException('provider_subscription_result_requires_review', 409);
                }
                $subscriptions[$topic] = ['state' => 'requesting', 'requested_at' => now()->toIso8601String()];
                $f->update(['subscriptions' => $subscriptions, 'version' => $f->version + 1]);
                $version = $f->version;

                return true;
            }, 3);
            if (! $send) {
                continue;
            }
            $state = 'needs_review';
            $location = null;
            $error = 'provider_subscription_result_unknown';
            try {
                $response = app(KopokopoCollectionClient::class)->request($feed, 'POST', '/api/v2/webhook_subscriptions', [
                    'event_type' => $topic, 'url' => app(KopokopoFeedService::class)->callback($feed), 'scope' => 'till',
                    'scope_reference' => '268491', 'enable_daraja_payload' => false]);
                if ($response->status() === 201) {
                    $location = app(KopokopoCollectionClient::class)->location((string) $response->header('Location'), 'webhook_subscriptions')['url'];
                    $state = 'registered';
                    $error = null;
                } elseif (in_array($response->status(), [400, 401, 403, 422], true)) {
                    $state = 'failed';
                    $error = 'provider_subscription_rejected';
                }
            } catch (CollectionException $e) {
                $error = $e->reason;
                if (in_array($e->reason, ['provider_authorization_failed', 'provider_credentials_required', 'provider_environment_mismatch'], true)) {
                    $state = 'failed'; // No subscription request was made.
                }
            }
            DB::transaction(function () use ($feed, $topic, $state, $error, $location, &$version, $actor) {
                $f = app(KopokopoIngestService::class)->lockedFeed($feed);
                $subscriptions = $f->subscriptions ?? [];
                $subscriptions[$topic] = array_merge($subscriptions[$topic], ['state' => $state, 'location' => $location, 'error' => $error]);
                $f->update(['subscriptions' => $subscriptions, 'last_error_code' => $error, 'version' => $f->version + 1]);
                $version = $f->version;
                app(KopokopoFeedService::class)->audit($f, $actor->id, 'kopokopo_subscription_result', ['topic' => $topic, 'state' => $state]);
            }, 3);
            if ($state !== 'registered') {
                break;
            }
        }

        return $feed->refresh();
    }

    /** Adopt only an authenticated, active subscription belonging to this exact callback and till. */
    public function verifySubscription(CollectionProviderFeed $feed, User $actor, string $topic, string $location, int $version): CollectionProviderFeed
    {
        app(KopokopoFeedService::class)->scope($actor, true);
        if (! in_array($topic, [KopokopoReceiptParser::RECEIVED, KopokopoReceiptParser::REVERSED], true)) {
            throw new CollectionException('provider_subscription_topic_invalid', 422);
        }
        $safe = app(KopokopoCollectionClient::class)->location($location, 'webhook_subscriptions');
        $response = app(KopokopoCollectionClient::class)->request($feed, 'GET', $safe['path']);
        if (! $response->successful() || strlen($response->body()) > config('collection_kopokopo.max_webhook_bytes')) {
            throw new CollectionException('provider_subscription_status_unavailable', 502);
        }
        $data = app(KopokopoReceiptParser::class)->decode($response->body());
        $a = data_get($data, 'data.attributes', []);
        if (data_get($data, 'data.id') !== $safe['id'] || data_get($data, 'data.type') !== 'webhook_subscription'
            || ($a['event_type'] ?? null) !== $topic || ($a['webhook_uri'] ?? null) !== app(KopokopoFeedService::class)->callback($feed)
            || ! is_string($a['status'] ?? null) || strtolower($a['status']) !== 'active' || ($a['scope'] ?? null) !== 'till'
            || ($a['scope_reference'] ?? null) !== '268491' || ! array_key_exists('enable_daraja_payload', $a)
            || $a['enable_daraja_payload'] !== false) {
            throw new CollectionException('provider_subscription_ownership_or_format_unverified', 409);
        }

        return DB::transaction(function () use ($feed, $actor, $topic, $safe, $version) {
            $f = app(KopokopoIngestService::class)->lockedFeed($feed);
            if ($f->version !== $version) {
                throw new CollectionException('provider_connection_version_changed', 409);
            }
            $subscriptions = $f->subscriptions ?? [];
            $subscriptions[$topic] = ['state' => 'registered', 'location' => $safe['url'], 'verified_at' => now()->toIso8601String(), 'error' => null];
            $f->update(['subscriptions' => $subscriptions, 'version' => $f->version + 1]);
            app(KopokopoFeedService::class)->audit($f, $actor->id, 'kopokopo_subscription_verified', ['topic' => $topic, 'provider_id' => $safe['id']]);

            return $f;
        }, 3);
    }

    public function reconcile(CollectionProviderFeed $feed, ?User $actor = null): CollectionProviderPoll
    {
        if ($actor) {
            app(KopokopoFeedService::class)->scope($actor, true);
        }
        $poll = DB::transaction(function () use ($feed) {
            $f = app(KopokopoIngestService::class)->lockedFeed($feed);
            if (! $f->enabled || ! config('collection.capture_enabled') || ! config('collection_kopokopo.enabled')) {
                throw new CollectionException('provider_capture_disabled', 404);
            }
            if ($pending = CollectionProviderPoll::where('feed_id', $f->id)->whereIn('state', ['requesting', 'accepted', 'needs_review'])->oldest()->first()) {
                return $pending;
            }
            $cursor = $f->reconciled_until ?? $f->cutover_at;
            $from = $cursor->copy()->subSeconds(config('collection_kopokopo.poll_overlap_seconds'))->max($f->cutover_at);
            $until = now()->subSeconds(30)->min($from->copy()->addSeconds(config('collection_kopokopo.poll_window_seconds')));
            if ($until->lte($from)) {
                throw new CollectionException('provider_reconciliation_not_due', 409);
            }

            return CollectionProviderPoll::create(['id' => (string) Str::uuid(), 'feed_id' => $f->id, 'from_at' => $from,
                'until_at' => $until, 'state' => 'requesting', 'historical_only' => false]);
        }, 3);
        if (! $poll->wasRecentlyCreated) {
            if ($poll->location || $poll->pending_result) {
                return $this->recover($feed, $poll);
            }
            if ($poll->created_at->lt(now()->subMinutes(2))) {
                $poll->update(['state' => 'needs_review', 'last_error_code' => 'provider_poll_request_result_unknown']);
            }

            return $poll;
        }
        try {
            $response = app(KopokopoCollectionClient::class)->request($feed, 'POST', '/api/v2/polling', [
                'scope' => 'till', 'scope_reference' => '268491', 'from_time' => $poll->from_at->toIso8601String(),
                'to_time' => $poll->until_at->toIso8601String(), '_links' => ['callback_url' => app(KopokopoFeedService::class)->callback($feed, $poll)]]);
            if ($response->status() !== 201) {
                $state = in_array($response->status(), [400, 401, 403, 422], true) ? 'failed' : 'needs_review';
                $poll->update(['state' => $state, 'last_error_code' => 'provider_poll_request_rejected']);

                return $poll;
            }
            $location = app(KopokopoCollectionClient::class)->location((string) $response->header('Location'), 'polling');
            $pending = DB::transaction(function () use ($feed, $poll, $location) {
                app(KopokopoIngestService::class)->lockedFeed($feed);
                $p = CollectionProviderPoll::lockForUpdate()->findOrFail($poll->id);
                $p->update(['provider_id' => $location['id'], 'location' => $location['url'], 'state' => 'accepted', 'last_error_code' => null]);

                return $p->pending_result;
            }, 3);
            if ($pending) {
                $this->result($feed, $poll->refresh(), $pending, false);
            }
        } catch (CollectionException $e) {
            $poll->refresh()->update(['state' => in_array($e->reason, ['provider_authorization_failed', 'provider_credentials_required', 'provider_environment_mismatch'], true) ? 'failed' : 'needs_review',
                'last_error_code' => $e->reason]);
        }

        return $poll->refresh();
    }

    public function recover(CollectionProviderFeed $feed, CollectionProviderPoll $poll, ?string $providedLocation = null): CollectionProviderPoll
    {
        if ($poll->feed_id !== $feed->id) {
            throw new CollectionException('provider_poll_scope_mismatch', 404);
        }
        if ($poll->state === 'complete') {
            return $poll;
        }
        $location = $poll->location ?: $providedLocation;
        if (! $location && $poll->pending_result) {
            $pending = app(KopokopoReceiptParser::class)->decode($poll->pending_result);
            $location = data_get($pending, 'data.attributes._links.self');
        }
        if (! is_string($location) || $location === '') {
            throw new CollectionException('provider_poll_location_requires_review', 409);
        }
        $safe = app(KopokopoCollectionClient::class)->location($location, 'polling');
        $response = app(KopokopoCollectionClient::class)->request($feed, 'GET', $safe['path']);
        if (! $response->successful()) {
            throw new CollectionException('provider_poll_status_unavailable', 502);
        }
        $body = $response->body();
        if (strlen($body) > config('collection_kopokopo.max_poll_bytes')) {
            throw new CollectionException('provider_body_too_large', 413);
        }
        $data = app(KopokopoReceiptParser::class)->decode($body);
        if (data_get($data, 'data.id') !== $safe['id']) {
            throw new CollectionException('provider_poll_identity_mismatch', 409);
        }
        $this->validateWindow($feed, $poll, $data);
        if (! $poll->provider_id && data_get($data, 'data.attributes._links.callback_url') !== app(KopokopoFeedService::class)->callback($feed, $poll)) {
            throw new CollectionException('provider_poll_callback_ownership_unverified', 409);
        }
        if ($poll->provider_id && $poll->provider_id !== $safe['id']) {
            throw new CollectionException('provider_poll_identity_mismatch', 409);
        }
        DB::transaction(function () use ($feed, $poll, $safe) {
            app(KopokopoIngestService::class)->lockedFeed($feed);
            $p = CollectionProviderPoll::lockForUpdate()->findOrFail($poll->id);
            if ($p->provider_id && $p->provider_id !== $safe['id']) {
                throw new CollectionException('provider_poll_identity_mismatch', 409);
            }
            if ($p->state !== 'complete') {
                $p->update(['provider_id' => $safe['id'], 'location' => $safe['url'], 'state' => 'accepted']);
            }
        }, 3);
        $this->result($feed, $poll->refresh(), $body, false);

        return $poll->refresh();
    }

    public function result(CollectionProviderFeed $feed, CollectionProviderPoll $poll, string $body, bool $signed): array
    {
        if (strlen($body) > config('collection_kopokopo.max_poll_bytes')) {
            throw new CollectionException('provider_body_too_large', 413);
        }
        if ($poll->feed_id !== $feed->id) {
            throw new CollectionException('provider_poll_scope_mismatch', 404);
        }
        try {
            $data = app(KopokopoReceiptParser::class)->decode($body);
            $this->validateWindow($feed, $poll, $data);
            $providerId = app(KopokopoReceiptParser::class)->identifier(data_get($data, 'data.id'));
            if ($poll->provider_id && $providerId !== $poll->provider_id) {
                throw new CollectionException('provider_poll_identity_mismatch', 409);
            }
            $attributes = data_get($data, 'data.attributes');
            if (! is_string($attributes['status'] ?? null)) {
                throw new CollectionException('provider_poll_status_requires_review', 422);
            }
            $status = strtolower($attributes['status']);
            if (! in_array($status, ['success', 'pending', 'failed'], true)) {
                throw new CollectionException('provider_poll_status_requires_review', 422);
            }
            $transactions = $attributes['transactions'] ?? [];
            if (! is_array($transactions) || ! array_is_list($transactions) || count($transactions) >= config('collection_kopokopo.max_poll_rows') || ! empty($attributes['_links']['next'])) {
                throw new CollectionException('provider_poll_coverage_incomplete', 422);
            }
            $observations = [];
            $resourcesSeen = [];
            $referencesSeen = [];
            foreach ($transactions as $row) {
                if (($row['type'] ?? null) !== 'Buygoods Transaction') {
                    throw new CollectionException('provider_poll_transaction_type_requires_review', 422);
                }
                $topic = in_array(data_get($row, 'resource.status'), ['Reversed', 'reversed'], true) ? KopokopoReceiptParser::REVERSED : KopokopoReceiptParser::RECEIVED;
                $observation = app(KopokopoReceiptParser::class)->resource($row['resource'] ?? null, $topic);
                if (isset($resourcesSeen[$observation['resource_id']]) || isset($referencesSeen[$observation['reference']])) {
                    throw new CollectionException('provider_poll_row_identity_conflict', 422);
                }
                $resourcesSeen[$observation['resource_id']] = true;
                $referencesSeen[$observation['reference']] = true;
                $at = Carbon::parse($observation['provider_at']);
                if ($at->lt($poll->from_at) || $at->gt($poll->until_at)) {
                    throw new CollectionException('provider_poll_row_outside_window', 422);
                }
                $observations[] = ['topic' => $topic, 'observation' => $observation, 'body' => json_encode($row, JSON_THROW_ON_ERROR)];
            }
        } catch (CollectionException $e) {
            $this->retainResult($feed, $poll, $body, 'quarantined', $e->reason);
            $poll->update(['state' => 'needs_review', 'last_error_code' => $e->reason]);

            return ['status' => 'quarantined', 'reason' => $e->reason];
        }

        $this->retainResult($feed, $poll, $body, 'accepted');

        return DB::transaction(function () use ($feed, $poll, $body, $status, $observations, $signed, $providerId) {
            $f = app(KopokopoIngestService::class)->lockedFeed($feed);
            $p = CollectionProviderPoll::lockForUpdate()->findOrFail($poll->id);
            if ($p->provider_id && $p->provider_id !== $providerId) {
                $this->retainResult($f, $p, $body, 'quarantined', 'provider_poll_identity_mismatch');
                $p->update(['state' => 'needs_review', 'last_error_code' => 'provider_poll_identity_mismatch']);

                return ['status' => 'quarantined', 'reason' => 'provider_poll_identity_mismatch'];
            }
            $financial = array_map(fn ($row) => array_intersect_key($row['observation'], array_flip(['resource_id', 'reference', 'principal_minor', 'currency', 'provider_at', 'reversed'])), $observations);
            usort($financial, fn ($a, $b) => strcmp($a['resource_id'], $b['resource_id']));
            // Equivalent GET/callback JSON formatting and payer enrichment are not a conflicting result.
            $digest = hash('sha256', json_encode([$providerId, $status, $financial], JSON_THROW_ON_ERROR));
            if ($p->state === 'complete') {
                if ($p->result_sha256 !== $digest) {
                    $this->retainResult($f, $p, $body, 'quarantined', 'provider_poll_result_conflict');
                    $p->update(['state' => 'needs_review', 'last_error_code' => 'provider_poll_result_conflict']);

                    return ['status' => 'quarantined', 'reason' => 'provider_poll_result_conflict'];
                }

                return ['status' => 'duplicate'];
            }
            if ($signed) {
                $f->last_delivery_at = now();
            }
            $p->pending_result = $body; // Retain the exact whole-result evidence once, not once per transaction.
            if (! $p->provider_id) {
                $p->save();
                $f->save();

                return ['status' => 'awaiting_request_identity'];
            }
            if ($status !== 'success') {
                $p->state = $status === 'failed' ? 'failed' : 'accepted';
                $p->last_error_code = $status === 'failed' ? 'provider_poll_failed' : null;
                $p->save();
                $f->save();

                return ['status' => $status];
            }
            foreach ($observations as $row) {
                $o = $row['observation'];
                $result = app(KopokopoIngestService::class)->observation($f, 'poll:'.$p->id.':'.hash('sha256', $o['resource_id']), $row['topic'], $o,
                    $row['body'], 'poll', $p->historical_only);
                if ($result['status'] === 'quarantined') {
                    $p->update(['state' => 'needs_review', 'last_error_code' => 'provider_poll_receipt_conflict']);

                    return ['status' => 'quarantined', 'reason' => 'provider_poll_receipt_conflict'];
                }
            }
            $p->update(['state' => 'complete', 'result_sha256' => $digest, 'last_error_code' => null]);
            $f->refresh();
            $cursor = $f->reconciled_until ?? $f->cutover_at;
            if (! $p->historical_only && $p->from_at->lte($cursor) && $p->until_at->gte($cursor)) {
                $f->reconciled_until = $p->until_at;
                $f->last_poll_completed_at = now();
                $f->last_error_code = null;
            }
            $f->version++;
            $f->save();

            return ['status' => 'complete', 'transactions' => count($observations)];
        }, 3);
    }

    private function retainResult(CollectionProviderFeed $feed, CollectionProviderPoll $poll, string $body, string $state, ?string $reason = null): void
    {
        $row = CollectionProviderEvent::firstOrCreate(['feed_id' => $feed->id, 'event_key' => 'poll-result:'.hash('sha256', $poll->id.$body)],
            ['source_kind' => 'poll_result', 'topic' => 'polling', 'state' => $state, 'raw_body' => $body,
                'raw_sha256' => hash('sha256', $body), 'quarantine_reason' => $reason, 'received_at' => now()]);
        if ($state === 'quarantined' && $row->state !== 'quarantined') {
            $row->update(['state' => $state, 'quarantine_reason' => $reason]);
        }
    }

    private function validateWindow(CollectionProviderFeed $feed, CollectionProviderPoll $poll, array $data): void
    {
        $a = data_get($data, 'data.attributes');
        if (data_get($data, 'data.type') !== 'polling' || ! is_array($a) || ! is_string($a['scope'] ?? null) || strtolower($a['scope']) !== 'till'
            || ($a['scope_reference'] ?? null) !== '268491'
            || ! app(KopokopoReceiptParser::class)->timestamp($a['from_time'] ?? null)->equalTo($poll->from_at)
            || ! app(KopokopoReceiptParser::class)->timestamp($a['to_time'] ?? null)->equalTo($poll->until_at)
            || (isset($a['_links']['callback_url']) && $a['_links']['callback_url'] !== app(KopokopoFeedService::class)->callback($feed, $poll))) {
            throw new CollectionException('provider_poll_window_or_scope_mismatch', 422);
        }
    }
}
