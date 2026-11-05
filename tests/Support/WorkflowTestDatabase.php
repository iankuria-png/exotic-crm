<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Disposable MySQL only: retain the schema, delete test rows in one transaction. */
final class WorkflowTestDatabase
{
    public static function reset(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_starts_with((string) config('database.connections.mysql.database'), 'crm_daily_review_test_')) {
            throw new \RuntimeException('Refusing to reset a non-disposable workflow database.');
        }
        if (! Schema::hasTable('trial_activation_operations')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () {
                foreach (DB::select('SHOW FULL TABLES') as $row) {
                    $values = array_values((array) $row);
                    if (($values[1] ?? '') === 'BASE TABLE' && $values[0] !== 'migrations') {
                        DB::table($values[0])->delete();
                    }
                }
                DB::table('payment_review_settings')->insert(['key' => 'launch_date', 'value' => now('Africa/Nairobi')->toDateString()]);
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
