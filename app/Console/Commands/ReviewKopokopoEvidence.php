<?php

namespace App\Console\Commands;

use App\Models\Collections\CollectionProviderEvent;
use App\Models\Collections\CollectionProviderFeed;
use App\Models\User;
use App\Services\Collections\CollectionException;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use App\Services\Collections\Kopokopo\KopokopoIngestService;
use App\Services\Collections\Kopokopo\KopokopoReceiptParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewKopokopoEvidence extends Command
{
    protected $signature = 'collections:kopokopo-review {event} {--action=dismiss} {--apply} {--actor=} {--expected-sha256=} {--reason=}';

    protected $description = 'Preview or record an administrator review of quarantined evidence; replay remains historical and cannot grant service.';

    public function handle(KopokopoFeedService $feeds, KopokopoIngestService $ingest, KopokopoReceiptParser $parser): int
    {
        try {
            $event = CollectionProviderEvent::findOrFail($this->argument('event'));
            $feed = CollectionProviderFeed::findOrFail($event->feed_id);
            if ($feed->account_id !== config('collection_kopokopo.account_id') || $event->state !== 'quarantined'
                || ! in_array($this->option('action'), ['dismiss', 'replay'], true)) {
                throw new CollectionException('provider_evidence_review_scope_invalid', 409);
            }
            $parsed = null;
            if ($this->option('action') === 'replay') {
                if ($event->source_kind !== 'webhook' || ! str_starts_with($event->event_key, 'invalid:') || $event->receipt_id) {
                    throw new CollectionException('provider_identity_conflict_requires_manual_review', 409);
                }
                $parsed = $parser->webhook($parser->decode($event->raw_body));
            }
            $out = ['mode' => $this->option('apply') ? 'apply' : 'preview', 'action' => $this->option('action'),
                'event_id' => $event->id, 'sha256' => $event->raw_sha256, 'reason' => $event->quarantine_reason, 'grants_service' => false];
            if ($this->option('apply')) {
                if (! ctype_digit((string) $this->option('actor')) || ! hash_equals($event->raw_sha256, (string) $this->option('expected-sha256'))
                    || mb_strlen(trim((string) $this->option('reason'))) < 12 || mb_strlen((string) $this->option('reason')) > 500) {
                    throw new CollectionException('provider_review_actor_hash_reason_required', 422);
                }
                $actor = User::findOrFail((int) $this->option('actor'));
                $feeds->scope($actor, true);
                DB::transaction(function () use ($feed, $event, $actor, $feeds, $ingest, $parsed, &$out) {
                    $ingest->lockedFeed($feed);
                    $e = CollectionProviderEvent::lockForUpdate()->findOrFail($event->id);
                    if ($e->state !== 'quarantined') {
                        throw new CollectionException('provider_evidence_review_changed', 409);
                    }
                    if ($parsed) {
                        $out['replay'] = $ingest->observation($feed, $parsed['event_key'], $parsed['topic'], $parsed['observation'], $e->raw_body, 'webhook', true);
                        if ($out['replay']['status'] === 'quarantined') {
                            throw new CollectionException('provider_replay_still_quarantined', 409);
                        }
                    }
                    // Reviewing an event never removes a receipt's conflict, reversal or ownership hold.
                    $e->update(['state' => 'reviewed']);
                    $feeds->audit($feed, $actor->id, 'kopokopo_evidence_reviewed', ['event_id' => $e->id, 'source_sha256' => $e->raw_sha256,
                        'action' => $this->option('action'), 'reason' => trim($this->option('reason')), 'historical_only' => true]);
                }, 3);
            }
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (CollectionException $e) {
            $this->error($e->reason);

            return self::FAILURE;
        }
    }
}
