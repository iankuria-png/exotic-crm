<?php

namespace App\Console\Commands;

use App\Services\Collections\CollectionException;
use App\Services\Collections\Kopokopo\KopokopoConnectionService;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReconcileKopokopoCollections extends Command
{
    protected $signature = 'collections:kopokopo-reconcile';

    protected $description = 'Recover the Kenya till receiving feed using one bounded asynchronous provider request.';

    public function handle(KopokopoFeedService $feeds, KopokopoConnectionService $connection): int
    {
        if (! config('collection_kopokopo.enabled') || ! config('collection.capture_enabled') || ! ($feed = $feeds->feed()) || ! $feed->enabled) {
            $this->line('Kenya receiving feed is not enabled.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('collections:kopokopo-reconcile:'.$feed->id, 90);
        if (! $lock->get()) {
            $this->line('Reconciliation already running.');

            return self::SUCCESS;
        }
        try {
            $poll = $connection->reconcile($feed);
            $this->line(json_encode(['poll_id' => $poll->id, 'state' => $poll->state, 'error' => $poll->last_error_code], JSON_THROW_ON_ERROR));

            return $poll->state === 'needs_review' ? self::FAILURE : self::SUCCESS;
        } catch (CollectionException $e) {
            $this->error($e->reason);

            return $e->reason === 'provider_reconciliation_not_due' ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
