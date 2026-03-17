<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `templates` MODIFY `category` ENUM('payment','renewal','follow_up','welcome','win_back','credential_setup_link','credential_temp_password') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `templates` MODIFY `category` ENUM('payment','renewal','follow_up','welcome','win_back') NOT NULL");
    }
};
