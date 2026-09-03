<?php

namespace App\Console;

use App\Http\Controllers\API\PaymentController;
use App\Models\Platform;
use App\Services\Ops\LoadShedder;
use App\Services\Ops\OperationsSettingsService;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * Three invariants hold this file together. Breaking any of them has taken
     * production down before:
     *
     * 1. EVERY task that can block must call `runInBackground()`.
     *    `schedule:run` executes foreground events SEQUENTIALLY inside a single
     *    process with no timeout. Nineteen foreground tasks were due at the top
     *    of every hour, two of them 55-second queue workers, so a single tick
     *    took minutes while cron started a fresh one every 60 seconds. The
     *    resulting pile-up exhausted the cPanel account's entry processes:
     *    dynamic requests 504'd while static files kept serving normally.
     *
     * 2. `withoutOverlapping()` takes MINUTES and is a cache TTL, not a held
     *    lock. It must exceed the task's realistic worst-case runtime, or it
     *    expires mid-run and the next tick starts a duplicate.
     *
     * 3. Cadences are spread across the hour on purpose. `everyMinute`,
     *    `everyFiveMinutes`, `everyFifteenMinutes`, `hourly` and `daily` all
     *    fire on minute 0, which is exactly the pile-up above. Prefer explicit
     *    `cron()` / `hourlyAt()` / `dailyAt()` offsets when adding a task, and
     *    check the minute you pick is not already crowded.
     *
     * The production cron itself is wrapped in `flock` as a backstop; see
     * docs/DEPLOYMENT.md. That is a safety net, not a substitute for the above.
     */
    protected function schedule(Schedule $schedule)
    {
        $tickStartedAt = microtime(true);

        // Live operations settings, resolved once per tick. Every option below
        // reads these first and falls back to config, which is what makes a
        // tuning change take effect on the next tick with no deploy and no
        // `config:cache`. A settings failure — a missing table mid-migration,
        // a cache outage — must never stop the scheduler from defining tasks,
        // so this is deliberately best-effort.
        $ops = null;
        try {
            $ops = $this->app->make(OperationsSettingsService::class);
        } catch (\Throwable $exception) {
            Log::warning('Scheduler could not resolve operations settings; using config defaults.', [
                'error' => $exception->getMessage(),
            ]);
        }

        $opsInt = function (string $key, int $fallback) use ($ops): int {
            try {
                return $ops?->integer($key) ?? $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        // The shed gate. Resolved once so a skip closure is a cache read, never
        // a container resolution, on every tick.
        $shedder = $this->app->make(LoadShedder::class);
        $allows = fn (string $capability): bool => $shedder->allows($capability);

        // NOTE: `subscriptions:check` used to run here daily at 00:05 and has been
        // removed deliberately. It decided expiry from a single payments row
        // (status=completed AND end_date <= now) and never read the profile's
        // actual escort_expire, so any renewal — which advances the deal and the
        // WordPress expiry but leaves the original payment row behind — caused it
        // to privatise a fully paid profile on its pre-renewal date. Expiry is now
        // owned solely by crm:reconcile-expired-subscriptions below, which reads
        // escort_expire with a market-timezone end-of-day cutoff and refuses to
        // act on any client holding a future active deal.

        // Platform vitals. Never shed and never skipped — the sampler is what
        // notices that everything else should be. Backgrounded and cheap: one
        // process-list read, a handful of aggregate queries and a Pulse write,
        // which is the entire per-minute cost of the observability stack.
        $schedule->command('crm:sample-vitals')
            ->name('crm_sample_vitals')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_sample_vitals.log'));

        // CRM: force-expire profiles past their WP expiry but still publicly
        // active. This is the ONLY actor that transitions a lapsed profile — on
        // lifecycle markets to Expired (published, contacts hidden), elsewhere to
        // the legacy offline state. Hourly, so the window in which a lapsed
        // profile still shows its contact details stays capped.
        $schedule->command('crm:reconcile-expired-subscriptions')
            ->name('crm_reconcile_expired_subscriptions')
            ->hourlyAt(25)
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_reconcile_expired_subscriptions.log'));

        $schedule->command('crm:compute-daily-stats')
            ->name('crm_compute_daily_stats')
            ->dailyAt('00:07')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_compute_daily_stats.log'));

        // Weekly AI briefings. Poll every minute so Settings can own the actual
        // audience time; the command self-guards on enablement, due time, and
        // duplicate weekly sends.
        $schedule->command('crm:ai-briefing --audience=ceo --period=weekly --scheduled')
            ->name('crm_ai_briefing_ceo')
            ->everyMinute()
            ->timezone('Africa/Nairobi')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->skip(fn () => ! $allows('ai_briefings'))
            ->sendOutputTo(storage_path('logs/crm_ai_briefing.log'), true);

        $schedule->command('crm:ai-briefing --audience=sales --period=weekly --scheduled')
            ->name('crm_ai_briefing_sales')
            ->everyMinute()
            ->timezone('Africa/Nairobi')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->skip(fn () => ! $allows('ai_briefings'))
            ->sendOutputTo(storage_path('logs/crm_ai_briefing.log'), true);

        $schedule->command('crm:snapshot-active-clients')
            ->name('crm_snapshot_active_clients')
            ->dailyAt('00:15')
            ->withoutOverlapping(20)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_snapshot_active_clients.log'));

        $schedule->command('crm:purge-closed-clients')
            ->name('crm_purge_closed_clients')
            ->dailyAt('03:00')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_purge_closed_clients.log'));

        // Signed customer-data retention: activity events expire at 180 days.
        $schedule->command('crm:purge-customer-data')
            ->name('crm_purge_customer_data')
            ->dailyAt('03:10')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_purge_customer_data.log'));

        // Archive long-term Expired profiles (keeps them indexed, removes from listings).
        $schedule->command('crm:archive-expired')
            ->name('crm_archive_expired')
            ->dailyAt('03:20')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_archive_expired.log'));

        // SEO Recovery: markets on `daily_trickle` pacing work through their
        // backlog of legacy-offline profiles a quota at a time. Markets on
        // manual pacing (the default) are untouched by this.
        $schedule->command('crm:restore-offline-profiles --trickle')
            ->name('crm_restore_offline_profiles')
            ->dailyAt('04:10')
            ->withoutOverlapping(120)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_restore_offline_profiles.log'));

        $schedule->command('crm:close-stale-sessions')
            ->name('crm_close_stale_sessions')
            ->everyMinute()
            ->withoutOverlapping(1)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_close_stale_sessions.log'));

        $schedule->command('crm:sweep-stuck-bundles')
            ->name('crm_sweep_stuck_bundles')
            ->cron('2,7,12,17,22,27,32,37,42,47,52,57 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
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
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        })
            ->name('handle_payment_timeouts')
            ->cron('4,9,14,19,24,29,34,39,44,49,54,59 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/payment_timeouts.log'));

        $clientSyncPerPage = max(1, min(100, (int) config('services.client_sync.per_page', 100)));
        $clientSyncDeltaMaxPlatforms = max(0, $opsInt('ops.sync.delta_max_platforms', (int) config('services.client_sync.delta_max_platforms_per_run', 3)));
        $clientSyncDeltaStaggerSeconds = max(0, $opsInt('ops.sync.delta_stagger_seconds', (int) config('services.client_sync.delta_stagger_seconds', 120)));
        $clientSyncReconcileStaggerSeconds = max(0, $opsInt('ops.sync.reconcile_stagger_seconds', (int) config('services.client_sync.reconcile_stagger_seconds', 180)));

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
            ->cron('11,41 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
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
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_sync_clients_reconcile.log'));

        // Budgeted so a handful of slow markets cannot stretch one pass across
        // several ticks; least-recently-checked markets are probed first, so
        // coverage rotates rather than starving the tail of the list.
        $schedule->command(sprintf(
            'crm:check-market-health --max-seconds=%d',
            $opsInt('ops.sync.market_health_max_seconds', 120)
        ))
            ->name('crm_check_market_health')
            ->cron('3,8,13,18,23,28,33,38,43,48,53,58 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_market_health.log'));

        // Backfill Support Board user links shortly after the WordPress client sync completes.
        $schedule->command('crm:sync-sb-users')
            ->name('crm_sync_support_board_users')
            ->cron('26,56 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->skip(fn () => ! $allows('support_board_sync')
                || ! Platform::query()
                    ->whereNotNull('support_board_api_url')
                    ->where('support_board_api_url', '!=', '')
                    ->whereNotNull('support_board_token')
                    ->exists())
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_sync_support_board_users.log'));

        // Lead intake (crm:import-leads) is intentionally NOT scheduled. It is a
        // heavy WordPress-backed import and must be run manually/on-demand only:
        //   php artisan crm:import-leads --per-page=100
        // (It previously ran every 15 minutes and contributed to resource
        // exhaustion on the shared host.)

        // Sprint 3: execute renewal campaigns for day -7/-3/0/+3 SMS reminders
        $schedule->command('crm:run-renewals')
            ->name('crm_run_renewals')
            ->hourlyAt(17)
            ->withoutOverlapping(55)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_run_renewals.log'));

        // Lifecycle SMS sweeps (onboarding welcomes, recovery reconcile,
        // reactivation win-backs). Hourly; the service's dedup + state gates +
        // quiet hours make repeated runs idempotent, and nothing sends unless
        // a market is explicitly enabled in Settings → SMS Routing → Lifecycle.
        $schedule->command('crm:run-lifecycle-sms')
            ->name('crm_run_lifecycle_sms')
            ->hourlyAt(35)
            ->withoutOverlapping(55)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_lifecycle_sms.log'));

        // Per-client analytics snapshot powering dynamic lifecycle copy
        // ("your profile got 145 views last week") — one bulk call per market.
        $schedule->command('crm:snapshot-profile-metrics')
            ->name('crm_snapshot_profile_metrics')
            ->dailyAt('06:10')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_lifecycle_metrics.log'));

        // Push campaign phased dispatcher: activates scheduled campaigns and queues next 24h items.
        $schedule->command('crm:dispatch-scheduled-pushes')
            ->name('crm_dispatch_scheduled_pushes')
            ->cron('5,20,35,50 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('push_campaigns'))
            ->sendOutputTo(storage_path('logs/crm_dispatch_scheduled_pushes.log'));

        $schedule->command('crm:run-auto-push')
            ->name('crm_run_auto_push')
            ->hourlyAt(47)
            ->withoutOverlapping(55)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('push_campaigns'))
            ->sendOutputTo(storage_path('logs/crm_run_auto_push.log'));

        $schedule->command('crm:maintain-auto-push')
            ->name('crm_maintain_auto_push')
            ->cron('10,25,40,55 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('push_campaigns'))
            ->sendOutputTo(storage_path('logs/crm_maintain_auto_push.log'));

        $schedule->command('crm:run-auto-optimize')
            ->name('crm_run_auto_optimize')
            ->hourlyAt(52)
            ->withoutOverlapping(55)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('auto_optimize'))
            ->sendOutputTo(storage_path('logs/crm_run_auto_optimize.log'));

        $schedule->command(sprintf(
            'crm:geocode-cities --rate=%d --limit=%d',
            (int) config('services.nominatim.scheduled_rate_per_minute', 4),
            (int) config('services.nominatim.batch_limit', 50)
        ))
            ->name('crm_geocode_cities')
            ->dailyAt('01:10')
            ->withoutOverlapping(1440)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('geocoding'))
            ->sendOutputTo(storage_path('logs/crm_geocode_cities.log'));

        $schedule->command('crm:maintain-auto-optimize')
            ->name('crm_maintain_auto_optimize')
            ->cron('9 */6 * * *')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('auto_optimize'))
            ->sendOutputTo(storage_path('logs/crm_maintain_auto_optimize.log'));

        $schedule->command('queue:prune-batches --hours=48')
            ->name('crm_prune_job_batches')
            ->dailyAt('01:40')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground();

        $schedule->command('crm:reconcile-pending-payments')
            ->name('crm_reconcile_pending_payments')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_reconcile_pending_payments.log'));

        $schedule->command('crm:reconcile-payment-failure-alerts')
            ->name('crm_reconcile_payment_failure_alerts')
            ->cron('1,6,11,16,21,26,31,36,41,46,51,56 * * * *')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_reconcile_payment_failure_alerts.log'));

        // Daily subscriber snapshot sync across configured push providers.
        $schedule->command('crm:sync-push-subscribers')
            ->name('crm_sync_push_subscribers')
            ->dailyAt('01:25')
            ->withoutOverlapping(120)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('push_campaigns'))
            ->sendOutputTo(storage_path('logs/crm_sync_push_subscribers.log'));

        $schedule->command('crm:refresh-retention-insights')
            ->name('crm_refresh_retention_insights')
            ->dailyAt('02:20')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->skip(fn () => ! $allows('retention_insights'))
            ->sendOutputTo(storage_path('logs/crm_refresh_retention_insights.log'));

        $schedule->command('crm:reset-whatsapp-sender-limits')
            ->name('crm_reset_whatsapp_sender_limits')
            ->hourlyAt(57)
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_reset_whatsapp_sender_limits.log'));

        $schedule->command('crm:kyc-reverify-sweep')
            ->name('crm_kyc_reverify_sweep')
            ->dailyAt('02:25')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_kyc_reverify_sweep.log'));

        $schedule->command('crm:kyc-escalate-overdue')
            ->name('crm_kyc_escalate_overdue')
            ->dailyAt('02:30')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_kyc_escalate_overdue.log'));

        $schedule->command('crm:kyc-recompute-exemptions')
            ->name('crm_kyc_recompute_exemptions')
            ->hourlyAt(22)
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_kyc_recompute_exemptions.log'));

        $schedule->command('crm:prune-error-logs')
            ->name('crm_prune_error_logs')
            ->dailyAt('02:40')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->runInBackground()
            ->sendOutputTo(storage_path('logs/crm_prune_error_logs.log'));

        // Queue workers.
        //
        // Three rules govern this block, all of them learned the hard way:
        //
        // 1. `runInBackground()` is mandatory. `schedule:run` executes events
        //    in the FOREGROUND and SEQUENTIALLY. Two foreground `--max-time=55`
        //    workers put a 110-second floor under every scheduler tick, so the
        //    once-a-minute cron could never keep up and `schedule:run`
        //    processes stacked up until the account ran out of entry processes.
        //
        // 2. The overlap window must exceed the longest job a worker can pick
        //    up. `withoutOverlapping()` takes MINUTES and the mutex is only a
        //    TTL — it is not held for the life of the process. Set to 2, it
        //    expired while a long job was still running and the next tick
        //    happily started a second worker on the same queue, then a third.
        //
        // 3. Lanes are separated by job duration, not by feature. A slow job on
        //    a shared queue starves everything behind it.
        //
        // Lane 1 (fast): short jobs only — nothing here should exceed ~5 min.
        // kyc-fanout is last so KYC status pushes to WordPress only drain when
        // the higher queues are idle.
        $queueConnection = (string) config('queue.default', 'sync');

        if ($queueConnection !== 'sync') {
            // Lane sizing is live-editable, but `max-time` is bounded to 55 by
            // the registry: a worker that outlives the minute that started it
            // is the pile-up all over again.
            $workerMaxTime = min(55, max(15, $opsInt('ops.lanes.worker_max_time', 55)));

            $schedule->command(sprintf(
                'queue:work %s --queue=push,alerts,default,kyc-fanout --max-time=%d --max-jobs=%d --tries=3 --sleep=3',
                $queueConnection,
                $workerMaxTime,
                $opsInt('ops.lanes.fast.max_jobs', 100)
            ))
                ->name('queue_worker')
                ->everyMinute()
                ->withoutOverlapping(5)
                ->onOneServer()
                ->runInBackground()
                ->sendOutputTo(storage_path('logs/queue_worker.log'));

            // Lane 2 (sync): client sync slices. Separated from the fast lane so
            // a market with a large backlog can never delay a push or an alert.
            $schedule->command(sprintf(
                'queue:work %s --queue=sync-clients,sync-clients-reconcile --max-time=%d --max-jobs=%d --tries=3 --sleep=3',
                $queueConnection,
                $workerMaxTime,
                $opsInt('ops.lanes.sync.max_jobs', 50)
            ))
                ->name('queue_worker_sync')
                ->everyMinute()
                ->withoutOverlapping(10)
                ->onOneServer()
                ->runInBackground()
                ->sendOutputTo(storage_path('logs/queue_worker_sync.log'));

            // Lane 3 (heavy): long LLM/WordPress work — auto-optimize items plus
            // the `heavy` queue on the `database_long` connection, whose wider
            // `retry_after` matches these jobs' timeouts. Two workers because the
            // two queues live on different connections.
            // At Critical these two workers are not started at all. Every other
            // lane keeps running: payments, alerts, market health and client
            // sync are never gated, and the fast lane is what carries them.
            $schedule->command(sprintf(
                'queue:work %s --queue=auto_optimize --max-time=%d --max-jobs=%d --tries=3 --sleep=3',
                $queueConnection,
                $workerMaxTime,
                $opsInt('ops.lanes.optimize.max_jobs', 30)
            ))
                ->name('queue_worker_auto_optimize')
                ->everyMinute()
                ->withoutOverlapping(10)
                ->onOneServer()
                ->runInBackground()
                ->skip(fn () => ! $allows('optimize_queue_worker'))
                ->sendOutputTo(storage_path('logs/queue_worker_optimize.log'));

            $schedule->command(sprintf(
                'queue:work database_long --queue=heavy --max-time=%d --max-jobs=%d --tries=2 --sleep=3',
                $workerMaxTime,
                $opsInt('ops.lanes.heavy.max_jobs', 10)
            ))
                ->name('queue_worker_heavy')
                ->everyMinute()
                ->withoutOverlapping(75)
                ->onOneServer()
                ->runInBackground()
                ->skip(fn () => ! $allows('heavy_queue_worker'))
                ->sendOutputTo(storage_path('logs/queue_worker_heavy.log'));
        }

        $this->recordHeartbeat($schedule, $tickStartedAt);
    }

    /**
     * Open a heartbeat entry for this tick and close it when the process ends.
     *
     * The count of due events is only knowable once every task above has been
     * defined, and the tick's duration only once it has finished, so this runs
     * last and finishes its own work in a terminating callback. Overlapping
     * ticks leave two open entries with different pids — the signature of the
     * 3 September outage, which the previous `ran_at`-only payload could not
     * distinguish from healthy operation.
     */
    private function recordHeartbeat(Schedule $schedule, float $tickStartedAt): void
    {
        // Only a real tick counts. Laravel resolves the Schedule singleton for
        // `schedule:list`, `schedule:test` and anything else that inspects the
        // schedule, and the test suite builds it directly — none of those are
        // a tick, and recording them would inflate the concurrency signal into
        // reporting the outage signature during ordinary work.
        if (! $this->isSchedulerTick()) {
            return;
        }

        try {
            $dueCount = count($schedule->dueEvents($this->app));
        } catch (\Throwable) {
            $dueCount = 0;
        }

        $tickId = SchedulerHeartbeat::open($dueCount);

        $this->app->terminating(function () use ($tickId, $tickStartedAt): void {
            SchedulerHeartbeat::close($tickId, $tickStartedAt);
        });
    }

    private function isSchedulerTick(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) && in_array('schedule:run', $argv, true);
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
