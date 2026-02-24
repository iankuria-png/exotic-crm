<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE deals MODIFY COLUMN status ENUM('pending', 'awaiting_payment', 'paid', 'active', 'expired', 'cancelled', 'renewed') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE deals MODIFY COLUMN status ENUM('pending', 'awaiting_payment', 'paid', 'active', 'expired', 'cancelled') NOT NULL DEFAULT 'pending'"
        );
    }
};
