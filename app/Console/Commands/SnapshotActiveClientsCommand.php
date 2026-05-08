<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\Platform;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SnapshotActiveClientsCommand extends Command
{
    protected $signature = 'crm:snapshot-active-clients
        {--date= : Snapshot date in YYYY-MM-DD format. Defaults to today.}
        {--platform= : Optional platform ID for ad-hoc reruns.}';

    protected $description = 'Snapshot distinct active subscriber counts per platform for one calendar day.';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        $platformIds = $this->resolvePlatformIds();
        $rows = [];
        $createdAt = now();

        foreach ($platformIds as $platformId) {
            $count = $this->countActiveClientsForDate((int) $platformId, $date);

            $rows[] = [
                'date' => $date->toDateString(),
                'platform_id' => (int) $platformId,
                'count' => $count,
                'created_at' => $createdAt,
            ];
        }

        if ($rows !== []) {
            DB::table('client_active_snapshots')->upsert(
                $rows,
                ['date', 'platform_id'],
                ['count', 'created_at']
            );
        }

        $this->info(sprintf(
            'Snapshotted %d platform(s) for %s.',
            count($platformIds),
            $date->toDateString()
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
