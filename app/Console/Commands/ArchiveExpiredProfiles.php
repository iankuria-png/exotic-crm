<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientLifecycleService;
use App\Support\ClientLifecycleState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Move long-term Expired profiles to Archived. Archived profiles stay published
 * and indexed (URL retains SEO value) but are excluded from city/category
 * listings. The dwell time is configurable via config('crm.lifecycle.archive_after_days').
 */
class ArchiveExpiredProfiles extends Command
{
    protected $signature = 'crm:archive-expired
        {--days= : Override the Expired dwell time (days) before archiving}
        {--limit=500 : Maximum number of profiles to archive this run}
        {--platform= : Restrict to a single platform id}
        {--dry-run : Report what would be archived without writing anything}';

    protected $description = 'Archive profiles that have been Expired longer than the configured threshold (keeps them indexed, removes from listings).';

    public function handle(ClientLifecycleService $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = $this->option('days') !== null
            ? max(0, (int) $this->option('days'))
            : (int) config('crm.lifecycle.archive_after_days', 90);
        $limit = max(1, (int) $this->option('limit'));
        $platformId = $this->option('platform') !== null ? (int) $this->option('platform') : null;

        // Global kill switch: with the policy disabled everywhere, there is nothing to archive.
        if (! \App\Support\LifecyclePolicy::masterEnabled()) {
            $this->info('Profile lifecycle master switch is off — skipping.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $query = Client::query()
            ->lifecycle(ClientLifecycleState::EXPIRED)
            ->whereNotNull('lifecycle_expired_at')
            ->where('lifecycle_expired_at', '<=', $cutoff)
            // Only markets that have opted in to the lifecycle policy.
            ->whereHas('platform', fn ($q) => $q->where('lifecycle_policy_enabled', true))
            ->orderBy('lifecycle_expired_at')
            ->limit($limit);

        if ($platformId) {
            $query->forPlatform($platformId);
        }

        $candidates = $query->get();

        $this->info(sprintf(
            'Archiving profiles Expired for >= %d day(s)%s (%s). Found %d candidate(s).',
            $days,
            $platformId ? " on platform #{$platformId}" : '',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $candidates->count()
        ));

        $archived = 0;
        $failed = 0;

        foreach ($candidates as $client) {
            if ($dryRun) {
                $this->line(sprintf('  would archive #%d %s', $client->id, (string) $client->name));
                $archived++;
                continue;
            }

            try {
                $lifecycle->archive($client, null, 'auto_archive');
                $archived++;
                $this->line(sprintf('  archived #%d %s', $client->id, (string) $client->name));
            } catch (Throwable $e) {
                $failed++;
                $this->error("  Failed client #{$client->id}: " . $e->getMessage());
                Log::error('Auto-archive failed for client', [
                    'client_id' => $client->id,
                    'wp_post_id' => $client->wp_post_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf('Done. %s=%d failed=%d', $dryRun ? 'would_archive' : 'archived', $archived, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
