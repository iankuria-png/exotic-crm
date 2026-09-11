<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_retention_insight_history', function (Blueprint $table): void {
            $table->index('recorded_date', 'client_retention_insight_history_recorded_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_retention_insight_history', function (Blueprint $table): void {
            $table->dropIndex('client_retention_insight_history_recorded_date_idx');
        });
    }
};
