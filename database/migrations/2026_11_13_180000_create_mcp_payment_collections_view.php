<?php

use App\Services\Mcp\RevenueReportingQuery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $query = app(RevenueReportingQuery::class)->aggregateView();
        $pdo = DB::connection()->getPdo();
        $bindings = array_map(fn ($value) => is_numeric($value) ? (string) $value : $pdo->quote((string) $value), DB::connection()->prepareBindings($query->getBindings()));
        $sql = Str::replaceArray('?', $bindings, $query->toSql());
        DB::statement('CREATE VIEW '.RevenueReportingQuery::VIEW.' AS '.$sql);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.RevenueReportingQuery::VIEW);
    }
};
