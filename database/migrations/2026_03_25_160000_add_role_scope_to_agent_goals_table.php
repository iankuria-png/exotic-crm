<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('agent_goals', 'role_scope')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->enum('role_scope', ['sales', 'marketing', 'all'])
                    ->default('sales')
                    ->after('platform_id');
            });
        }

        DB::table('agent_goals')
            ->whereNull('role_scope')
            ->update(['role_scope' => 'sales']);

        if (!$this->indexExists('agent_goals', 'agent_goals_platform_id_index')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->index('platform_id', 'agent_goals_platform_id_index');
            });
        }

        if ($this->indexExists('agent_goals', 'agent_goals_platform_id_metric_period_unique')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->dropUnique('agent_goals_platform_id_metric_period_unique');
            });
        }

        if (!$this->indexExists('agent_goals', 'agent_goals_scope_metric_period_unique')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->unique(['platform_id', 'role_scope', 'metric', 'period'], 'agent_goals_scope_metric_period_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('agent_goals', 'agent_goals_scope_metric_period_unique')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->dropUnique('agent_goals_scope_metric_period_unique');
            });
        }

        if (!$this->indexExists('agent_goals', 'agent_goals_platform_id_metric_period_unique')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->unique(['platform_id', 'metric', 'period'], 'agent_goals_platform_id_metric_period_unique');
            });
        }

        if ($this->indexExists('agent_goals', 'agent_goals_platform_id_index')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->dropIndex('agent_goals_platform_id_index');
            });
        }

        if (Schema::hasColumn('agent_goals', 'role_scope')) {
            Schema::table('agent_goals', function (Blueprint $table) {
                $table->dropColumn('role_scope');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select(sprintf("PRAGMA index_list('%s')", str_replace("'", "''", $table)));

            return collect($indexes)->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        $database = $connection->getDatabaseName();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return (int) ($result->aggregate ?? 0) > 0;
    }
};
