<?php

namespace App\Services;

use App\Models\DbScanAdmission;
use App\Models\DbScanMarketRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared lock order: ascending coordinator IDs, then scanner sweep/run/admission/slots. */
class MarketOperationCoordinator
{
    public static function lock(array $platformIds): void
    {
        if (! Schema::hasTable('db_market_operation_leases')) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $platformIds)));
        sort($ids);
        foreach ($ids as $id) {
            DB::table('db_market_operation_leases')->insertOrIgnore(['platform_id' => $id, 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('db_market_operation_leases')->whereIn('platform_id', $ids)->orderBy('platform_id')->lockForUpdate()->get();
        \App\Models\DbScanSweep::query()->whereIn('platform_id', $ids)->where('status', 'running')->orderBy('id')->lockForUpdate()->get();
    }

    public static function blocksNewScan(int $id): bool
    {
        if (! Schema::hasTable('db_market_operation_leases')) {
            return false;
        }

        return DB::table('db_market_operation_leases')->where('platform_id', $id)->whereNotNull('operation_id')->exists();
    }

    public static function blocksExecution(int $id): bool
    {
        if (! Schema::hasTable('db_market_operation_leases')) {
            return false;
        }

        return DB::table('db_market_operation_leases')->where('platform_id', $id)->whereNotNull('owner_token')->exists();
    }

    public static function scanClaim(int $id): ?DbScanMarketRun
    {
        $admission = DbScanAdmission::query()->where('platform_id', $id)->lockForUpdate()->first();
        $run = $admission?->active_run_id ? DbScanMarketRun::query()->find($admission->active_run_id) : null;

        return $run && ! $run->isTerminal() ? $run : null;
    }
}
