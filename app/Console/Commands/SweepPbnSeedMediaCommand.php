<?php

namespace App\Console\Commands;

use App\Jobs\RunPbnSeedMediaJob;
use App\Models\PbnSeedBatch;
use App\Models\PbnSeedItem;
use Illuminate\Console\Command;

/**
 * Safety net for batches whose media runner never finished.
 *
 * Provisioning queues the runner itself, so in the normal case this finds
 * nothing. It exists for the cases the dispatch cannot cover: a batch created
 * before the runner shipped, a worker restarted mid-chain, or a run that
 * stopped on a configuration error that has since been fixed.
 *
 * It only ever queues untried work — the runner itself decides how many retry
 * passes a failed profile gets — so a permanently broken profile cannot turn
 * this into an hourly retry loop.
 */
class SweepPbnSeedMediaCommand extends Command
{
    protected $signature = 'crm:sweep-pbn-seed-media {--limit=10 : Maximum batches to queue in one sweep}';

    protected $description = 'Queue the media copy runner for PBN seed batches that still have untried pending media.';

    public function handle(): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));

        $batchIds = PbnSeedItem::query()
            ->where('status', PbnSeedItem::STATUS_MEDIA_PENDING)
            ->whereNotNull('target_wp_post_id')
            ->whereNull('failure_reason')
            ->whereHas('batch', fn ($query) => $query->whereNotIn('status', [
                PbnSeedBatch::STATUS_CANCELLED,
                PbnSeedBatch::STATUS_REVERTED,
            ]))
            ->distinct()
            ->limit($limit)
            ->pluck('batch_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($batchIds as $batchId) {
            RunPbnSeedMediaJob::dispatch($batchId);
        }

        $this->info(sprintf('Queued media copy for %d PBN seed batch(es).', count($batchIds)));

        return self::SUCCESS;
    }
}
