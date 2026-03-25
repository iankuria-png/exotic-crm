<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_goals', function (Blueprint $table) {
            $table->enum('role_scope', ['sales', 'marketing', 'all'])
                ->default('sales')
                ->after('platform_id');
        });

        DB::table('agent_goals')
            ->whereNull('role_scope')
            ->update(['role_scope' => 'sales']);

        Schema::table('agent_goals', function (Blueprint $table) {
            $table->dropUnique('agent_goals_platform_id_metric_period_unique');
            $table->unique(['platform_id', 'role_scope', 'metric', 'period'], 'agent_goals_scope_metric_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_goals', function (Blueprint $table) {
            $table->dropUnique('agent_goals_scope_metric_period_unique');
            $table->unique(['platform_id', 'metric', 'period'], 'agent_goals_platform_id_metric_period_unique');
            $table->dropColumn('role_scope');
        });
    }
};
