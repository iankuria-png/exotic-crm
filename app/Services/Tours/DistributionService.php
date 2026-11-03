<?php

namespace App\Services\Tours;

use App\Models\TourOrder;
use App\Models\TourOrderStop;
use App\Services\WalletService;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DistributionService
{
    public function reconcile(int $orderId): void
    {
        $lock = Cache::lock('tour-distribution:'.$orderId, 600);
        if (! $lock->get()) {
            return;
        }
        try {
            $order = TourOrder::with(['platform', 'client', 'stops.destination'])->findOrFail($orderId);
            if ($order->environment !== 'production' || ! in_array($order->state, ['paid', 'cancelled'], true)) {
                return;
            }
            $source = null;
            $sourceGone = false;
            $sourceUnavailable = false;
            if ($order->state !== 'cancelled' && $order->stops->contains(fn ($stop) => $stop->ends_at->isFuture() && (int) ($stop->payload['pass_expires_at'] ?? $order->pass_expires_at->timestamp) >= now()->timestamp)) {
                try {
                    $source = (new WpSyncService($order->platform))->getTourTrip($order->trip_id);
                } catch (\Throwable $error) {
                    report($error);
                    $status = $error instanceof \Illuminate\Http\Client\RequestException ? $error->response->status() : 0;
                    $sourceGone = in_array($status, [404, 409, 410, 422], true);
                    $sourceUnavailable = ! $sourceGone;
                }
            }
            $paused = $sourceUnavailable || ! empty($source['restricted']);
            $sourceCancelled = $source && (($source['status'] ?? '') === 'cancelled' || (string) ($source['generation'] ?? '') !== $order->generation);

            foreach ($order->stops as $stop) {
                if (in_array($stop->state, ['removed', 'refunded'], true)) {
                    continue;
                }
                $remove = $order->state === 'cancelled' || $stop->ends_at->isPast() || (int) ($stop->payload['pass_expires_at'] ?? $order->pass_expires_at->timestamp) < now()->timestamp || $sourceGone || $sourceCancelled;
                if (! $remove && ! $paused && $stop->next_attempt_at && $stop->next_attempt_at->isFuture()) {
                    continue;
                }
                if ($paused) {
                    $stop->update(['payload' => array_merge($stop->payload, ['safety_paused' => true])]);
                }
                try {
                    $wp = new WpSyncService($stop->destination);
                    if ($remove) {
                        $revision = $stop->revision + ($stop->state !== 'removing' ? 1 : 0);
                        $stop->update(['revision' => $revision, 'state' => 'removing']);
                        $cap = $wp->getTourCapability();
                        $wp->deleteTourVisitor($stop->tour_key, ['revision' => $revision, 'target_site_url' => $cap['site_url'], 'environment' => 'production']);
                        $verified = $wp->getTourVisitor($stop->tour_key);
                        abort_unless(($verified['state'] ?? '') === 'removed' && (int) ($verified['revision'] ?? 0) === $revision, 502, 'Destination removal was not verified.');
                        $failureDue = $order->state === 'paid' && ! $sourceGone && ! $sourceCancelled && ! $paused && empty($stop->payload['safety_paused']) && empty($stop->payload['availability_verified_at']) && $stop->starts_at->copy()->addHours(2)->isPast();
                        $stop->update(['state' => $failureDue ? 'refund_pending' : 'removed', 'verified_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);
                        if ($failureDue) {
                            $this->refund($order, $stop);
                        }

                        continue;
                    }
                    $cap = app(CapabilityService::class)->requireDestination($order->platform, $stop->destination, 'production', $order->snapshot['media']);
                    $payload = $this->payload($order, $stop, $cap['site_url'], $paused);
                    $prior = $stop->payload['receiver_payload'] ?? null;
                    $priorComparable = $prior;
                    $nextComparable = $payload;
                    unset($priorComparable['revision'],$nextComparable['revision']);
                    if ($prior && $priorComparable !== $nextComparable) {
                        $stop->revision++;
                        $payload['revision'] = $stop->revision;
                    }
                    $stop->update(['payload' => array_merge($stop->payload, ['receiver_payload' => $payload]), 'revision' => $stop->revision]);
                    $wp->putTourVisitor($stop->tour_key, $payload);
                    $verified = $wp->getTourVisitor($stop->tour_key);
                    abort_unless((int) ($verified['revision'] ?? 0) === $stop->revision && (int) ($verified['home']['platform_id'] ?? 0) === (int) $order->platform_id && ($verified['environment'] ?? '') === 'production' && (int) ($verified['window']['pass_expires_at'] ?? 0) === (int) $stop->payload['pass_expires_at'], 502, 'Destination profile was not independently verified.');
                    if (in_array($verified['state'] ?? '', ['scheduled', 'live'], true)) {
                        $stop->update(['payload' => array_merge($stop->payload, ['availability_verified_at' => $stop->payload['availability_verified_at'] ?? now()->toIso8601String()])]);
                    }
                    if (($verified['state'] ?? '') === 'live') {
                        $stop->update(['payload' => array_merge($stop->payload, ['first_live_at' => $stop->payload['first_live_at'] ?? now()->toIso8601String()])]);
                    }
                    $stop->update(['state' => $verified['state'], 'remote_url' => $verified['url'] ?? null, 'verified_at' => now(), 'last_error' => null, 'attempt_count' => 0, 'next_attempt_at' => now()->addMinutes(15)]);
                } catch (\Throwable $e) {
                    report($e);
                    $stop->update(['last_error' => 'Destination update is pending verification.', 'attempt_count' => $stop->attempt_count + 1, 'next_attempt_at' => now()->addMinutes(min(60, 2 ** min(6, $stop->attempt_count)))]);
                    // No refund for deliberate safety pauses; failure compensation is immutable/idempotent.
                    if (! $paused && ! $remove && $stop->starts_at->copy()->addHours(2)->isPast() && $stop->state !== 'live') {
                        $this->refund($order, $stop);
                    }
                }
            }
            $order->refresh();
            $stops = $order->stops()->get();
            if ($sourceGone) {
                $order->update(['sync_error' => null]);

                return;
            }
            (new WpSyncService($order->platform))->setTourPaymentState($order->trip_id, ['environment' => $order->environment, 'generation' => $order->generation, 'order_reference' => (string) $order->public_id, 'state' => $order->state === 'cancelled' ? 'cancelled' : 'paid', 'pass_expires_at' => $order->pass_expires_at->timestamp, 'remote_stops' => $stops->map(fn ($s) => ['key' => $s->source_stop_key, 'platform_id' => $s->destination_platform_id, 'url' => $s->remote_url, 'state' => $s->state])->all()]);
            $order->update(['sync_error' => $sourceUnavailable ? 'Source checks are temporarily unavailable; destination profiles are paused.' : null]);
        } catch (\Throwable $e) {
            TourOrder::whereKey($orderId)->update(['sync_error' => 'Tour reconciliation is pending.']);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function refund(TourOrder $order, TourOrderStop $stop): void
    {
        abort_unless($order->environment === 'production' && $order->paid_at, 403, 'Only production paid tours can receive failure compensation.');
        if ($stop->fresh()->refunded_at) {
            return;
        }
        $wp = new WpSyncService($stop->destination);
        $cap = $wp->getTourCapability();
        $revision = $stop->revision + 1;
        $wp->deleteTourVisitor($stop->tour_key, ['revision' => $revision, 'target_site_url' => $cap['site_url'], 'environment' => 'production']);
        $readback = $wp->getTourVisitor($stop->tour_key);
        abort_unless(($readback['state'] ?? '') === 'removed' && (int) ($readback['revision'] ?? 0) === $revision, 502, 'Failure refund waits for confirmed destination removal.');
        $stop->update(['revision' => $revision]);
        DB::transaction(function () use ($order, $stop) {
            $locked = TourOrderStop::whereKey($stop->id)->lockForUpdate()->firstOrFail();
            if ($locked->refunded_at) {
                return;
            }
            $transaction = $locked->allocation_minor > 0 ? app(WalletService::class)->credit($order->client, $order->currency, $locked->allocation_minor / Money::factor($order->currency), ['idempotency_key' => 'tour-refund:'.$locked->id, 'reference_type' => 'tour_failure_refund', 'reference_id' => $locked->id, 'description' => 'Tour destination could not go live'])['transaction'] : null;
            $locked->update(['state' => 'refunded', 'refunded_at' => now(), 'refund_transaction_id' => $transaction?->id, 'next_attempt_at' => null]);
            \App\Jobs\SendTourFailureNotice::dispatch($locked->id)->afterCommit();
            app(\App\Services\AuditService::class)->record(['platform_id' => $order->platform_id, 'entity_type' => 'tour_order_stop', 'entity_id' => $locked->id, 'action' => 'tour_failure_refunded', 'after_state' => ['order_reference' => (string) $order->public_id, 'amount_minor' => $locked->allocation_minor, 'currency' => $order->currency]]);
        });
    }

    private function payload(TourOrder $order, TourOrderStop $stop, string $target, bool $paused): array
    {
        $snapshot = $order->snapshot;
        $fields = $snapshot['profile']['fields'] ?? [];
        $phone = $stop->payload['phone'] ?? '';
        $whatsapp = $stop->payload['whatsapp'] ?? '';
        if ($phone !== '') {
            $fields['phone'] = $phone;
        }if ($whatsapp !== '') {
            $fields['whatsapp'] = $whatsapp;
        }

        return ['contract_version' => 1, 'revision' => $stop->revision, 'target_site_url' => $target, 'environment' => 'production', 'home' => ['platform_id' => (int) $order->platform_id, 'post_id' => (int) $snapshot['profile_id'], 'url' => $snapshot['home_url']], 'window' => ['start' => $stop->starts_at->timestamp, 'end' => $stop->ends_at->timestamp, 'pass_expires_at' => (int) $stop->payload['pass_expires_at']], 'city_id' => $stop->payload['city_id'] ?? null, 'paused' => $paused, 'profile' => ['name' => $snapshot['profile']['name'], 'bio' => $snapshot['profile']['bio'], 'fields' => $fields], 'media' => $snapshot['media'], 'cover_source_id' => $snapshot['cover_source_id']];
    }
}
