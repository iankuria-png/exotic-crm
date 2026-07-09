<?php

namespace App\Console;

use App\Http\Controllers\API\PaymentController;
use App\Models\Platform;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        try {
            file_put_contents(
                storage_path('app/scheduler-heartbeat.json'),
                json_encode([
                    'ran_at' => now()->toIso8601String(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $exception) {
            Log::warning('Unable to update scheduler heartbeat file.', [
                'error' => $exception->getMessage(),
            ]);
        }

        // Subscription check command - RUNS DAILY AT 12:05 AM
        $schedule->command('subscriptions:check')
                 ->name('check_subscriptions')
                 ->dailyAt('00:05')
                 ->withoutOverlapping(60)
                 ->onOneServer()
                 ->sendOutputTo(storage_path('logs/subscription_check.log'));

        // CRM safety net: force-expire profiles past their WP expiry but still
        // publicly active, in case the WordPress check_expired() sweep didn't run
        // (low-traffic markets / wp-cron not firing). Runs after subscriptions:check.
        $schedule->command('crm:reconcile-expired-subscriptions')
                 ->name('crm_reconcile_expired_subscriptions')
                 ->dailyAt('00:25')
                 ->withoutOverlapping(30)
                 ->onOneServer()
                 ->sendOutputTo(storage_path('logs/crm_reconcile_expired_subscriptions.log'));

        $schedule->command('crm:compute-daily-stats')
            ->name('crm_compute_daily_stats')
            ->dailyAt('00:07')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_compute_daily_stats.log'));

        // Weekly AI briefings — Monday morning (Africa/Nairobi), after daily stats
        // have been computed. The feature also self-guards on ai.briefings.enabled.
        $schedule->command('crm:ai-briefing --audience=ceo --period=weekly')
            ->name('crm_ai_briefing_ceo')
            ->weeklyOn(1, '07:30')
            ->timezone('Africa/Nairobi')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_ai_briefing.log'), true);

        $schedule->command('crm:ai-briefing --audience=sales --period=weekly')
            ->name('crm_ai_briefing_sales')
            ->weeklyOn(1, '07:45')
            ->timezone('Africa/Nairobi')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_ai_briefing.log'), true);

        $schedule->command('crm:snapshot-active-clients')
            ->name('crm_snapshot_active_clients')
            ->dailyAt('00:15')
            ->withoutOverlapping(20)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_snapshot_active_clients.log'));

        $schedule->command('crm:purge-closed-clients')
            ->name('crm_purge_closed_clients')
            ->dailyAt('03:00')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_purge_closed_clients.log'));

        $schedule->command('crm:close-stale-sessions')
            ->name('crm_close_stale_sessions')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_close_stale_sessions.log'));

        $schedule->command('crm:sweep-stuck-bundles')
            ->name('crm_sweep_stuck_bundles')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_sweep_stuck_bundles.log'));
                 
        // Payment timeout handler - RUNS EVERY 5 MINUTES
        $schedule->call(function () {
            try {
                Log::info('Running payment timeout handler');
                $result = app(PaymentController::class)->handlePendingTimeouts();
                Log::info('Payment timeout handler completed', ['result' => $result]);
            } catch (\Exception $e) {
                Log::error('Payment timeout handler failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        })
        ->name('handle_payment_timeouts')
        ->everyFiveMinutes()
        ->withoutOverlapping(10)
        ->onOneServer()
        ->sendOutputTo(storage_path('logs/payment_timeouts.log'));

        $clientSyncPerPage = max(1, min(100, (int) config('services.client_sync.per_page', 100)));
        $clientSyncDeltaMaxPlatforms = max(0, (int) config('services.client_sync.delta_max_platforms_per_run', 3));
        $clientSyncDeltaStaggerSeconds = max(0, (int) config('services.client_sync.delta_stagger_seconds', 120));
        $clientSyncReconcileStaggerSeconds = max(0, (int) config('services.client_sync.reconcile_stagger_seconds', 180));

        // Keep CRM clients in sync with WordPress profile changes across active markets.
        // Delta syncs are intentionally paced to avoid synchronized bursts against
        // the WordPress source sites' PHP-FPM/MariaDB pools.
        $schedule->command(sprintf(
            'crm:sync-clients --per-page=%d --max-platforms=%d --stagger-seconds=%d --rotate',
            $clientSyncPerPage,
            $clientSyncDeltaMaxPlatforms,
            $clientSyncDeltaStaggerSeconds
        ))
            ->name('crm_sync_clients_delta')
            ->everyThirtyMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_sync_clients.log'));

        $schedule->command(sprintf(
            'crm:sync-clients --full --per-page=%d --stagger-seconds=%d',
            $clientSyncPerPage,
            $clientSyncReconcileStaggerSeconds
        ))
            ->name('crm_sync_clients_reconcile')
            ->dailyAt('02:05')
            ->withoutOverlapping(120)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_sync_clients_reconcile.log'));

        $schedule->command('crm:check-market-health')
            ->name('crm_check_market_health')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_market_health.log'));

        // Backfill Support Board user links shortly after the WordPress client sync completes.
        $schedule->command('crm:sync-sb-users')
            ->name('crm_sync_support_board_users')
            ->cron('2,17,32,47 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->skip(fn () => !Platform::query()
                ->whereNotNull('support_board_api_url')
                ->where('support_board_api_url', '!=', '')
                ->whereNotNull('support_board_token')
                ->exists())
            ->sendOutputTo(storage_path('logs/crm_sync_support_board_users.log'));

        // Lead intake (crm:import-leads) is intentionally NOT scheduled. It is a
        // heavy WordPress-backed import and must be run manually/on-demand only:
        //   php artisan crm:import-leads --per-page=100
        // (It previously ran every 15 minutes and contributed to resource
        // exhaustion on the shared host.)

        // Sprint 3: execute renewal campaigns for day -7/-3/0/+3 SMS reminders
        $schedule->command('crm:run-renewals')
            ->name('crm_run_renewals')
            ->hourly()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_run_renewals.log'));

        // Push campaign phased dispatcher: activates scheduled campaigns and queues next 24h items.
        $schedule->command('crm:dispatch-scheduled-pushes')
            ->name('crm_dispatch_scheduled_pushes')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_dispatch_scheduled_pushes.log'));

        $schedule->command('crm:run-auto-push')
            ->name('crm_run_auto_push')
            ->hourly()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_run_auto_push.log'));

        $schedule->command('crm:maintain-auto-push')
            ->name('crm_maintain_auto_push')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_maintain_auto_push.log'));

        $schedule->command('crm:run-auto-optimize')
            ->name('crm_run_auto_optimize')
            ->hourly()
            ->withoutOverlapping(55)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_run_auto_optimize.log'));

        $schedule->command(sprintf(
            'crm:geocode-cities --rate=%d --limit=%d',
            (int) config('services.nominatim.scheduled_rate_per_minute', 4),
            (int) config('services.nominatim.batch_limit', 50)
        ))
            ->name('crm_geocode_cities')
            ->daily()
            ->withoutOverlapping(1440)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_geocode_cities.log'));

        $schedule->command('crm:maintain-auto-optimize')
            ->name('crm_maintain_auto_optimize')
            ->everySixHours()
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_maintain_auto_optimize.log'));

        $schedule->command('queue:prune-batches --hours=48')
            ->name('crm_prune_job_batches')
            ->daily();

        $schedule->command('crm:reconcile-pending-payments')
            ->name('crm_reconcile_pending_payments')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_reconcile_pending_payments.log'));

        $schedule->command('crm:reconcile-payment-failure-alerts')
            ->name('crm_reconcile_payment_failure_alerts')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_reconcile_payment_failure_alerts.log'));

        // Daily subscriber snapshot sync across configured push providers.
        $schedule->command('crm:sync-push-subscribers')
            ->name('crm_sync_push_subscribers')
            ->daily()
            ->withoutOverlapping(120)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_sync_push_subscribers.log'));

        $schedule->command('crm:refresh-retention-insights')
            ->name('crm_refresh_retention_insights')
            ->dailyAt('02:20')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_refresh_retention_insights.log'));

        $schedule->command('crm:reset-whatsapp-sender-limits')
            ->name('crm_reset_whatsapp_sender_limits')
            ->hourly()
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_reset_whatsapp_sender_limits.log'));

        $schedule->command('crm:kyc-reverify-sweep')
            ->name('crm_kyc_reverify_sweep')
            ->dailyAt('02:25')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_kyc_reverify_sweep.log'));

        $schedule->command('crm:kyc-escalate-overdue')
            ->name('crm_kyc_escalate_overdue')
            ->dailyAt('02:30')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_kyc_escalate_overdue.log'));

        $schedule->command('crm:kyc-recompute-exemptions')
            ->name('crm_kyc_recompute_exemptions')
            ->hourly()
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_kyc_recompute_exemptions.log'));

        $schedule->command('crm:prune-error-logs')
            ->name('crm_prune_error_logs')
            ->dailyAt('02:40')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_prune_error_logs.log'));

        // Queue worker: processes push queue first (time-sensitive), then client sync queues, alert jobs, then default queue.
        // Runs for up to 55 seconds then exits; next schedule:run cycle starts a new one.
        // --queue=push,sync-clients,sync-clients-reconcile,alerts,default,kyc-fanout ensures market syncs are handled in the background without blocking alerts.
        // kyc-fanout is last (lowest priority) so KYC status pushes to WordPress only
        // drain when higher queues are idle. Previously NO worker listened on it, so
        // PushKycStatusJob never ran and KYC status never synced to the WP sites.
        // --max-jobs=100 prevents memory leaks during long-running batches.
        $queueConnection = (string) config('queue.default', 'sync');

        if ($queueConnection !== 'sync') {
            $schedule->command(sprintf(
                'queue:work %s --queue=push,sync-clients,sync-clients-reconcile,alerts,default,kyc-fanout --max-time=55 --max-jobs=100 --tries=3 --sleep=3',
                $queueConnection
            ))
                ->name('queue_worker')
                ->everyMinute()
                ->withoutOverlapping(2)
                ->onOneServer()
                ->sendOutputTo(storage_path('logs/queue_worker.log'));

            // Dedicated worker for the heavy SEO auto-optimize queue, kept separate
            // so long LLM/WP jobs never block the time-sensitive push/alerts queue.
            // Routed through schedule:run (withoutOverlapping + onOneServer) instead
            // of a hand-added cron — a duplicate direct `queue:work` cron for this
            // queue exhausted the account's entry-process limit and 504'd the site.
            $schedule->command(sprintf(
                'queue:work %s --queue=auto_optimize --max-time=55 --max-jobs=30 --tries=3 --sleep=3',
                $queueConnection
            ))
                ->name('queue_worker_auto_optimize')
                ->everyMinute()
                ->withoutOverlapping(2)
                ->onOneServer()
                ->sendOutputTo(storage_path('logs/queue_worker_optimize.log'));
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
