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

    /**
     * Consecutive failures on one market before this run stops trying it.
     *
     * Stuck profiles are processed oldest-expiry-first, and a market whose
     * WordPress is unreachable fails every one of its profiles while leaving
     * them stuck — so they stay at the head of the queue and consume the whole
     * budget again next hour. In the seven days to 2026-09-11 the Tanzania pair
     * alone accounted for 5,916 of 10,030 recorded failures, which is budget
     * that healthy markets never got. Giving up on a market for the rest of the
     * run lets the remaining budget reach profiles that can actually be served.
     */
    private const MAX_CONSECUTIVE_MARKET_FAILURES = 5;

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
        $skipped = 0;
        $breakdown = [];
        $consecutiveFailures = [];
        $skippedByMarket = [];

        foreach ($stuck as $client) {
            $marketId = (int) $client->platform_id;

            if (($consecutiveFailures[$marketId] ?? 0) >= self::MAX_CONSECUTIVE_MARKET_FAILURES) {
                $skipped++;
                $skippedByMarket[$marketId] = ($skippedByMarket[$marketId] ?? 0) + 1;

                continue;
            }

            try {
                $row = $reconciler->reconcileClient($client, null, $dryRun);

                // Only a success clears the market's failure streak, so one
                // profile that happens to work does not re-open a dead market
                // for the rest of the run unless it is genuinely serving again.
                $consecutiveFailures[$marketId] = 0;

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
                $consecutiveFailures[$marketId] = ($consecutiveFailures[$marketId] ?? 0) + 1;

                if ($consecutiveFailures[$marketId] === self::MAX_CONSECUTIVE_MARKET_FAILURES) {
                    $this->warn(sprintf(
                        '  Market #%d failed %d profiles in a row — skipping the rest of it this run.',
                        $marketId,
                        self::MAX_CONSECUTIVE_MARKET_FAILURES
                    ));
                }

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
            'breakdown' => $skipped > 0
                ? $breakdown + ['_skipped_after_market_failures' => [
                    'count' => $skipped,
                    'by_platform_id' => $skippedByMarket,
                ]]
                : $breakdown,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        $this->info(sprintf(
            'Done. processed=%d failed=%d skipped=%d (%s)',
            $processed,
            $failed,
            $skipped,
            $dryRun ? 'dry-run' : 'live'
        ));
        Log::info('Expired-subscription reconciliation complete', [
            'mode' => $dryRun ? 'dry' : 'live',
            'platform_id' => $platformId,
            'candidates' => $stuck->count(),
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
