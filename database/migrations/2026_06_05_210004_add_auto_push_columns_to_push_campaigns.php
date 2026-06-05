<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_campaigns', function (Blueprint $table) {
            $table->foreignId('auto_push_plan_id')->nullable()->after('created_by')
                ->constrained('auto_push_plans')->nullOnDelete();
            $table->foreignId('auto_push_run_id')->nullable()->after('auto_push_plan_id')
                ->constrained('auto_push_runs')->nullOnDelete();
            $table->index('auto_push_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('push_campaigns', function (Blueprint $table) {
            $table->dropIndex(['auto_push_plan_id']);
            $table->dropConstrainedForeignId('auto_push_run_id');
            $table->dropConstrainedForeignId('auto_push_plan_id');
        });
    }
};
