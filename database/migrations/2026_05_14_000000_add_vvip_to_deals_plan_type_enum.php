<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE deals MODIFY COLUMN plan_type ENUM('basic', 'premium', 'vip', 'vvip') NOT NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE deals MODIFY COLUMN plan_type ENUM('basic', 'premium', 'vip') NOT NULL");
    }
};
