<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('agent_goals', 'target_currency')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->string('target_currency', 8)->nullable()->after('target');
            });
        }

        if (!Schema::hasColumn('agent_goal_overrides', 'target_currency')) {
            Schema::table('agent_goal_overrides', function (Blueprint $table) {
                $table->string('target_currency', 8)->nullable()->after('target');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_goal_overrides', 'target_currency')) {
            Schema::table('agent_goal_overrides', function (Blueprint $table) {
                $table->dropColumn('target_currency');
            });
        }

        if (Schema::hasColumn('agent_goals', 'target_currency')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->dropColumn('target_currency');
            });
        }
    }
};
