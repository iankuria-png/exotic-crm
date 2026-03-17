<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return; // SQLite doesn't support ENUM — column accepts any string already
        }
        DB::statement("ALTER TABLE `templates` MODIFY `category` ENUM('payment','renewal','follow_up','welcome','win_back','credential_setup_link','credential_temp_password') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement("ALTER TABLE `templates` MODIFY `category` ENUM('payment','renewal','follow_up','welcome','win_back') NOT NULL");
    }
};
