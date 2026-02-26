<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Http\Controllers\API\PaymentController;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
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
