<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_daily_stats', function (Blueprint $table) {
            $table->unsignedSmallInteger('discounts_given')->default(0)->after('free_trials_given');
        });
    }

    public function down(): void
    {
        Schema::table('agent_daily_stats', function (Blueprint $table) {
            $table->dropColumn('discounts_given');
        });
    }
};
