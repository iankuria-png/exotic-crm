<?php

namespace App\Jobs;

use App\Models\CityGeocode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * On-demand geocoding for one market, triggered from the Locations tab "Map cities"
 * button (so admins don't have to open a cPanel terminal).
 *
 * Public Nominatim caps regularly-run bulk jobs at ~4 requests/minute, so we cannot
 * resolve a whole market inside one request. Instead each run resolves a small batch
 * via `crm:geocode-cities` and, while pending rows remain, re-dispatches itself with a
 * short delay. Pending rows decrease monotonically (each run converts a batch to
 * resolved/unresolved/failed; the command only *adds* rows for genuinely new cities),
 * so the chain terminates. `iteration` is a hard backstop against runaway chains.
 */
class GeocodeMarketCitiesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;       // self-chained; do not auto-retry a long batch
    public int $timeout = 300;   // 5 min ceiling per batch

    private const MAX_ITERATIONS = 1000;

    public function __construct(
        public readonly int $platformId,
        public readonly int $batch = 10,
        public readonly int $rate = 4,
        public readonly int $iteration = 0,
    ) {}

    /**
     * One chain per market at a time (until released after timeout).
     */
    public function uniqueId(): string
    {
        return 'geocode-market-' . $this->platformId;
    }

    public function handle(): void
    {
        Artisan::call('crm:geocode-cities', [
            '--platform' => $this->platformId,
            '--limit' => max(1, $this->batch),
            '--rate' => max(1, $this->rate),
        ]);

        $pending = CityGeocode::query()
            ->where('platform_id', $this->platformId)
            ->where('status', 'pending')
            ->count();

        if ($pending > 0 && $this->iteration < self::MAX_ITERATIONS) {
            self::dispatch($this->platformId, $this->batch, $this->rate, $this->iteration + 1)
                ->onQueue('default')
                ->delay(now()->addSeconds(3));

            return;
        }

        Log::info('GeocodeMarketCitiesJob completed', [
            'platform_id' => $this->platformId,
            'iterations' => $this->iteration + 1,
            'pending_remaining' => $pending,
        ]);
    }
}
