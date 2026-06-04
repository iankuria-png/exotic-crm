<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTOR_CREATED_INDEX = 'audit_log_actor_created_id_idx';
    private const PLATFORM_ACTOR_CREATED_INDEX = 'audit_log_platform_actor_created_idx';

    public function up(): void
    {
        if (!$this->indexExists('audit_log', self::ACTOR_CREATED_INDEX)) {
            Schema::table('audit_log', function (Blueprint $table): void {
                $table->index(['actor_id', 'created_at', 'id'], self::ACTOR_CREATED_INDEX);
            });
        }

        if (!$this->indexExists('audit_log', self::PLATFORM_ACTOR_CREATED_INDEX)) {
            Schema::table('audit_log', function (Blueprint $table): void {
                $table->index(['platform_id', 'actor_id', 'created_at'], self::PLATFORM_ACTOR_CREATED_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('audit_log', self::PLATFORM_ACTOR_CREATED_INDEX)) {
            Schema::table('audit_log', function (Blueprint $table): void {
                $table->dropIndex(self::PLATFORM_ACTOR_CREATED_INDEX);
            });
        }

        if ($this->indexExists('audit_log', self::ACTOR_CREATED_INDEX)) {
            Schema::table('audit_log', function (Blueprint $table): void {
                $table->dropIndex(self::ACTOR_CREATED_INDEX);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select(sprintf("PRAGMA index_list('%s')", str_replace("'", "''", $table)));

            return collect($indexes)->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        $database = $connection->getDatabaseName();
        $result = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return (int) ($result->aggregate ?? 0) > 0;
    }
};
