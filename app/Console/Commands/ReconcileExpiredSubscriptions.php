<?php

namespace App\Console\Commands;

use App\Models\ExpiryReconciliationRun;
use App\Services\ExpiredSubscriptionReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileExpiredSubscriptions extends Command
{
    protected $signature = 'crm:reconcile-expired-subscriptions
        {--dry-run : Report what would be deactivated without writing anything}
        {--limit=200 : Maximum number of stuck profiles to process this run}
        {--platform= : Restrict to a single platform id}';

    protected $description = 'Force-expire profiles that are past their WP expiry but still publicly active (CRM safety net for the WP-cron sweep).';

    public function handle(ExpiredSubscriptionReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $platformId = $this->option('platform') !== null ? (int) $this->option('platform') : null;

        $startedAt = now();
        $this->info(sprintf(
            'Reconciling expired subscriptions (%s)%s, limit %d…',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $platformId ? " platform #{$platformId}" : '',
            $limit
        ));

        $stuck = $reconciler->findStuck($platformId, $limit);
        $this->info("Found {$stuck->count()} stuck profile(s).");

        $processed = 0;
        $failed = 0;
        $breakdown = [];

        foreach ($stuck as $client) {
            try {
                $row = $reconciler->reconcileClient($client, null, $dryRun);

                $market = $row['market'];
                $breakdown[$market] ??= ['count' => 0, 'sample_post_ids' => []];
                $breakdown[$market]['count']++;
                if (count($breakdown[$market]['sample_post_ids']) < 5) {
                    $breakdown[$market]['sample_post_ids'][] = $row['wp_post_id'];
                }

                $processed++;
                $this->line(sprintf(
                    '  [%s] #%d %s (%s) escort_expire=%d → %s',
                    $row['market'],
                    $row['client_id'],
                    $row['name'],
                    $row['wp_post_id'],
                    $row['escort_expire'],
                    $row['action']
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error("  Failed client #{$client->id}: " . $e->getMessage());
                Log::error('Expired-subscription reconciliation failed for client', [
                    'client_id' => $client->id,
                    'wp_post_id' => $client->wp_post_id,
                    'platform_id' => $client->platform_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        ExpiryReconciliationRun::create([
            'mode' => $dryRun ? ExpiryReconciliationRun::MODE_DRY : ExpiryReconciliationRun::MODE_LIVE,
            'platform_id' => $platformId,
            'initiated_by' => null,
            'candidates' => $stuck->count(),
            'processed' => $processed,
            'failed' => $failed,
            'breakdown' => $breakdown,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        $this->info(sprintf('Done. processed=%d failed=%d (%s)', $processed, $failed, $dryRun ? 'dry-run' : 'live'));
        Log::info('Expired-subscription reconciliation complete', [
            'mode' => $dryRun ? 'dry' : 'live',
            'platform_id' => $platformId,
            'candidates' => $stuck->count(),
            'processed' => $processed,
            'failed' => $failed,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
