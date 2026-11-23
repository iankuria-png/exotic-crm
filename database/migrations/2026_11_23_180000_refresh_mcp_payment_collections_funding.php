<?php

use App\Services\Mcp\RevenueReportingQuery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // MariaDB view DDL commits implicitly. Never claim a transactional cutover.
    public $withinTransaction = false;

    private const BACKUP = 'ceo_revenue_view_preimage_20261123';

    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $preimage = $mysql
            ? (array) DB::selectOne('SHOW CREATE VIEW '.RevenueReportingQuery::VIEW)
            : (array) DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'view' AND name = ?", [RevenueReportingQuery::VIEW]);
        $ddl = $preimage['Create View'] ?? $preimage['sql'] ?? null;
        if (! $ddl) {
            throw new RuntimeException('The existing reporting view must exist before this refresh.');
        }
        if (! Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->longText('ddl');
                $table->string('character_set_client')->nullable();
                $table->string('collation_connection')->nullable();
            });
        }
        // Keep the original preimage on retry after a partially completed DDL operation.
        DB::table(self::BACKUP)->insertOrIgnore(['id' => 1, 'ddl' => $ddl,
            'character_set_client' => $preimage['character_set_client'] ?? null,
            'collation_connection' => $preimage['collation_connection'] ?? null]);
        $query = app(RevenueReportingQuery::class)->aggregateView();
        $pdo = DB::connection()->getPdo();
        $bindings = array_map(fn ($v) => is_numeric($v) ? (string) $v : $pdo->quote((string) $v), DB::connection()->prepareBindings($query->getBindings()));
        $select = Str::replaceArray('?', $bindings, $query->toSql());
        if (! $mysql) {
            DB::statement('DROP VIEW '.RevenueReportingQuery::VIEW);
        }
        DB::statement(($mysql ? 'CREATE OR REPLACE VIEW ' : 'CREATE VIEW ').RevenueReportingQuery::VIEW.' AS '.$select);
    }

    public function down(): void
    {
        $original = DB::table(self::BACKUP)->where('id', 1)->first();
        if (! $original) {
            throw new RuntimeException('Missing exact reporting-view preimage; refusing a guessed rollback.');
        }
        $mysql = DB::getDriverName() === 'mysql';
        $settings = $mysql ? (array) DB::selectOne('SELECT @@character_set_client AS charset, @@collation_connection AS collation') : [];
        try {
            if ($mysql) {
                $pdo = DB::connection()->getPdo();
                DB::statement('SET character_set_client = '.$pdo->quote($original->character_set_client));
                DB::statement('SET collation_connection = '.$pdo->quote($original->collation_connection));
                // Preserve the original definer, SQL SECURITY and algorithm as captured.
                DB::statement(preg_replace('/^CREATE /i', 'CREATE OR REPLACE ', $original->ddl, 1));
            } else {
                DB::statement('DROP VIEW IF EXISTS '.RevenueReportingQuery::VIEW);
                DB::statement($original->ddl);
            }
        } finally {
            if ($mysql) {
                $pdo = DB::connection()->getPdo();
                DB::statement('SET character_set_client = '.$pdo->quote($settings['charset']));
                DB::statement('SET collation_connection = '.$pdo->quote($settings['collation']));
            }
        }
        Schema::drop(self::BACKUP);
    }
};
