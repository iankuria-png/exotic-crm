<?php

namespace App\Console\Commands;

use App\Models\TourOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SweepTours extends Command
{
    protected $signature = 'tours:sweep {--limit=50}';

    protected $description = 'Reconcile bounded paid Tours outbox and destination windows';

    public function handle(): int
    {
        foreach (['tour_orders', 'tour_order_stops', 'tour_payment_attempts', 'tour_market_settings'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->line('Tours schema is not installed; no work performed.');

                return self::SUCCESS;
            }
        }
        $limit = max(1, min(100, (int) $this->option('limit')));
        TourOrder::where('environment', 'production')->whereIn('state', ['paid', 'cancelled'])->where(function ($q) {
            $q->whereHas('stops', fn ($s) => $s->whereNotIn('state', ['removed', 'refunded']))->orWhereNotNull('sync_error');
        })->orderBy('updated_at')->limit($limit)->get()->each(fn ($o) => \App\Jobs\ReconcileTourOrder::dispatch($o->id));

        return self::SUCCESS;
    }
}
