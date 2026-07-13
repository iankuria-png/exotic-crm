<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen error_log_groups.source to allow browser-origin errors ('client').
     * Enum changes need a raw MODIFY on MySQL/MariaDB; on SQLite (test runner)
     * the column is plain TEXT so no change is required.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE error_log_groups MODIFY source ENUM('exception','log','queue_job','client') NOT NULL DEFAULT 'exception'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE error_log_groups MODIFY source ENUM('exception','log','queue_job') NOT NULL DEFAULT 'exception'");
        }
    }
};
