<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'platform_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('platform_id')->nullable()->after('id');
            });
        }

        if ($this->indexExists('products', 'products_name_unique')) {
            DB::statement('ALTER TABLE products DROP INDEX products_name_unique');
        }

        if (!$this->indexExists('products', 'products_platform_id_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('platform_id');
            });
        }

        $kenyaPlatformId = DB::table('platforms')
            ->whereRaw('LOWER(name) LIKE ?', ['%kenya%'])
            ->value('id');

        if (!$kenyaPlatformId) {
            $kenyaPlatformId = DB::table('platforms')->orderBy('id')->value('id');
        }

        if ($kenyaPlatformId) {
            DB::table('products')
                ->whereNull('platform_id')
                ->update(['platform_id' => (int) $kenyaPlatformId]);
        }

        if (!$this->indexExists('products', 'products_platform_name_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique(['platform_id', 'name'], 'products_platform_name_unique');
            });
        }

        if (!$this->foreignKeyExists('products', 'products_platform_id_foreign')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreign('platform_id')
                    ->references('id')
                    ->on('platforms')
                    ->nullOnDelete();
            });
        }

        $tanzaniaPlatform = DB::table('platforms')
            ->select('id', 'currency_code')
            ->where(function ($query): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%tanzania%'])
                    ->orWhereRaw('LOWER(country) LIKE ?', ['%tanzania%']);
            })
            ->orderBy('id')
            ->first();

        if ($tanzaniaPlatform && !empty($tanzaniaPlatform->id)) {
            $currency = strtoupper((string) ($tanzaniaPlatform->currency_code ?: 'TZS'));
            $requiredPackages = ['BASIC', 'PREMIUM', 'VIP'];
            $now = now();

            foreach ($requiredPackages as $packageName) {
                $exists = DB::table('products')
                    ->where('platform_id', (int) $tanzaniaPlatform->id)
                    ->whereRaw('UPPER(name) = ?', [$packageName])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('products')->insert([
                    'platform_id' => (int) $tanzaniaPlatform->id,
                    'name' => $packageName,
                    'weekly_price' => 0,
                    'biweekly_price' => 0,
                    'monthly_price' => 0,
                    'currency' => $currency,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('products', 'products_platform_id_foreign')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign('products_platform_id_foreign');
            });
        }

        if ($this->indexExists('products', 'products_platform_name_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_platform_name_unique');
            });
        }

        if ($this->indexExists('products', 'products_platform_id_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_platform_id_index');
            });
        }

        DB::statement(
            "DELETE p1 FROM products p1
             INNER JOIN products p2
                ON LOWER(p1.name) = LOWER(p2.name)
               AND p1.id > p2.id"
        );

        if (!$this->indexExists('products', 'products_name_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique('name', 'products_name_unique');
            });
        }

        if (Schema::hasColumn('products', 'platform_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('platform_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $databaseName = DB::getDatabaseName();
        if (empty($databaseName)) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', $databaseName)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $databaseName = DB::getDatabaseName();
        if (empty($databaseName)) {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('table_schema', $databaseName)
            ->where('table_name', $table)
            ->where('constraint_name', $constraintName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
