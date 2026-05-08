<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\Platform;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillActiveClientSnapshotsCommand extends Command
{
    protected $signature = 'crm:backfill-client-snapshots
        {--from= : Start date in YYYY-MM-DD format. Defaults to 90 days ago.}
        {--to= : End date in YYYY-MM-DD format. Defaults to today.}
        {--platform= : Optional platform ID for a narrower backfill.}';

    protected $description = 'Best-effort backfill for historical active-client snapshots using current deal activation and expiry dates.';

    public function handle(): int
    {
        $from = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : now()->subDays(89)->startOfDay();
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->startOfDay()
            : now()->startOfDay();

        if ($to->lt($from)) {
            $this->error('The --to date must be on or after --from.');

            return self::FAILURE;
        }

        $platformIds = $this->resolvePlatformIds();
        $period = CarbonPeriod::create($from, $to);
        $rows = [];
        $createdAt = now();

        foreach ($period as $date) {
            foreach ($platformIds as $platformId) {
                $rows[] = [
                    'date' => $date->toDateString(),
                    'platform_id' => (int) $platformId,
                    'count' => $this->countActiveClientsForDate((int) $platformId, Carbon::instance($date)),
                    'created_at' => $createdAt,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('client_active_snapshots')->upsert(
                $chunk,
                ['date', 'platform_id'],
                ['count', 'created_at']
            );
        }

        $this->info(sprintf(
            'Backfilled %d snapshot row(s) from %s to %s.',
            count($rows),
            $from->toDateString(),
            $to->toDateString()
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function resolvePlatformIds(): array
    {
        $requestedPlatformId = (int) ($this->option('platform') ?: 0);

        if ($requestedPlatformId > 0) {
            return [(int) Platform::query()->findOrFail($requestedPlatformId)->id];
        }

        return Platform::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function countActiveClientsForDate(int $platformId, Carbon $date): int
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        return (int) Deal::query()
            ->where('platform_id', $platformId)
            ->where('status', 'active')
            ->whereNotNull('client_id')
            ->where('activated_at', '<=', $endOfDay)
            ->where(function ($builder) use ($startOfDay) {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $startOfDay);
            })
            ->distinct('client_id')
            ->count('client_id');
    }
}
