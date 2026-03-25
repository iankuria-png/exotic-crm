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
                 ->onOneServer() 
                 ->sendOutputTo(storage_path('logs/subscription_check.log'));
                 
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
        ->onOneServer()
        ->sendOutputTo(storage_path('logs/payment_timeouts.log'));

        // Keep CRM clients in sync with WordPress profile changes across active markets.
        $schedule->command('crm:sync-clients')
            ->name('crm_sync_clients_delta')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_sync_clients.log'));

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

        // Keep lead intake synced from WordPress profiles flagged needs_payment=1.
        $schedule->command('crm:import-leads --per-page=100')
            ->name('crm_import_leads')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_import_leads.log'));

        // Sprint 3: execute renewal campaigns for day -7/-3/0/+3 SMS reminders
        $schedule->command('crm:run-renewals')
            ->name('crm_run_renewals')
            ->hourly()
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_run_renewals.log'));

        // Push campaign phased dispatcher: activates scheduled campaigns and queues next 24h items.
        $schedule->command('crm:dispatch-scheduled-pushes')
            ->name('crm_dispatch_scheduled_pushes')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_dispatch_scheduled_pushes.log'));

        $schedule->command('crm:reconcile-pending-payments')
            ->name('crm_reconcile_pending_payments')
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_reconcile_pending_payments.log'));

        // Daily subscriber snapshot sync across configured push providers.
        $schedule->command('crm:sync-push-subscribers')
            ->name('crm_sync_push_subscribers')
            ->daily()
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_sync_push_subscribers.log'));

        $schedule->command('crm:refresh-retention-insights')
            ->name('crm_refresh_retention_insights')
            ->dailyAt('02:20')
            ->withoutOverlapping(30)
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/crm_refresh_retention_insights.log'));

        // Queue worker: processes push queue first (time-sensitive), then default queue.
        // Runs for up to 55 seconds then exits; next schedule:run cycle starts a new one.
        // --queue=push,default ensures push notifications are never blocked by slow sync jobs.
        // --max-jobs=100 prevents memory leaks during long-running batches.
        $schedule->command('queue:work database --queue=push,default --max-time=55 --max-jobs=100 --tries=3 --sleep=3')
            ->name('queue_worker')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->sendOutputTo(storage_path('logs/queue_worker.log'));
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
