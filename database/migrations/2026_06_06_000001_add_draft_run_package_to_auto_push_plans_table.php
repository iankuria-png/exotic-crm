<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_push_plans', function (Blueprint $table) {
            $table->json('draft_run_package')->nullable()->after('reliability');
        });
    }

    public function down(): void
    {
        Schema::table('auto_push_plans', function (Blueprint $table) {
            $table->dropColumn('draft_run_package');
        });
    }
};
