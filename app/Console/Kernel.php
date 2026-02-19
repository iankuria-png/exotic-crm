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