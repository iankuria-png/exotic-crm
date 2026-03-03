<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('products', 'slug')) {
                $table->string('slug', 120)->nullable()->after('display_name');
            }
            if (!Schema::hasColumn('products', 'tier')) {
                $table->string('tier', 32)->default('custom')->after('slug');
            }
            if (!Schema::hasColumn('products', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('products', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_archived');
            }
        });

        if (!$this->indexExists('products', 'products_platform_slug_unique')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->unique(['platform_id', 'slug'], 'products_platform_slug_unique');
            });
        }

        if (!$this->indexExists('products', 'products_platform_archived_sort_index')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->index(['platform_id', 'is_archived', 'sort_order'], 'products_platform_archived_sort_index');
            });
        }

        $this->backfillProductPresentationColumns();

        if (!Schema::hasTable('product_prices')) {
            Schema::create('product_prices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->string('duration_key', 50);
                $table->string('duration_label', 120);
                $table->unsignedSmallInteger('duration_days')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('currency', 3)->default('KES');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['product_id', 'duration_key'], 'product_prices_product_duration_unique');
                $table->index(['product_id', 'is_active'], 'product_prices_product_active_index');
                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();
            });
        }

        $this->backfillProductPrices();
    }

    public function down(): void
    {
        if (Schema::hasTable('product_prices')) {
            Schema::drop('product_prices');
        }

        if ($this->indexExists('products', 'products_platform_archived_sort_index')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropIndex('products_platform_archived_sort_index');
            });
        }

        if ($this->indexExists('products', 'products_platform_slug_unique')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropUnique('products_platform_slug_unique');
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            foreach (['display_name', 'slug', 'tier', 'is_archived', 'sort_order'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillProductPresentationColumns(): void
    {
        $rows = DB::table('products')
            ->select('id', 'platform_id', 'name', 'slug')
            ->orderBy('id')
            ->get();

        $slugUsage = [];

        foreach ($rows as $row) {
            $platformId = (int) ($row->platform_id ?? 0);
            $name = trim((string) $row->name);
            $baseSlug = Str::slug($name);
            if ($baseSlug === '') {
                $baseSlug = 'package';
            }

            $slug = $baseSlug;
            $platformKey = (string) $platformId;
            $slugUsage[$platformKey] = $slugUsage[$platformKey] ?? [];
            $attempt = 2;
            while (array_key_exists($slug, $slugUsage[$platformKey])) {
                $slug = $baseSlug . '-' . $attempt;
                $attempt++;
            }
            $slugUsage[$platformKey][$slug] = true;

            $tier = $this->tierFromSlug($slug);
            $sortOrder = $this->sortOrderFromTier($tier);

            DB::table('products')
                ->where('id', (int) $row->id)
                ->update([
                    'display_name' => $name !== '' ? $name : strtoupper($slug),
                    'slug' => $slug,
                    'tier' => $tier,
                    'sort_order' => $sortOrder,
                ]);
        }
    }

    private function backfillProductPrices(): void
    {
        $products = DB::table('products')
            ->select('id', 'weekly_price', 'biweekly_price', 'monthly_price', 'currency', 'is_active')
            ->orderBy('id')
            ->get();

        $now = now();
        foreach ($products as $product) {
            $entries = [
                [
                    'duration_key' => '1_week',
                    'duration_label' => '1 Week',
                    'duration_days' => 7,
                    'sort_order' => 10,
                    'price' => $product->weekly_price,
                ],
                [
                    'duration_key' => '2_weeks',
                    'duration_label' => '2 Weeks',
                    'duration_days' => 14,
                    'sort_order' => 20,
                    'price' => $product->biweekly_price,
                ],
                [
                    'duration_key' => '1_month',
                    'duration_label' => '1 Month',
                    'duration_days' => 30,
                    'sort_order' => 30,
                    'price' => $product->monthly_price,
                ],
            ];

            foreach ($entries as $entry) {
                if ($entry['price'] === null) {
                    continue;
                }

                $exists = DB::table('product_prices')
                    ->where('product_id', (int) $product->id)
                    ->where('duration_key', $entry['duration_key'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('product_prices')->insert([
                    'product_id' => (int) $product->id,
                    'duration_key' => $entry['duration_key'],
                    'duration_label' => $entry['duration_label'],
                    'duration_days' => $entry['duration_days'],
                    'price' => (float) $entry['price'],
                    'currency' => strtoupper((string) ($product->currency ?: 'KES')),
                    'is_active' => (bool) $product->is_active,
                    'sort_order' => $entry['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function tierFromSlug(string $slug): string
    {
        if (str_contains($slug, 'vvip')) {
            return 'vvip';
        }
        if (str_contains($slug, 'vip')) {
            return 'vip';
        }
        if (str_contains($slug, 'premium')) {
            return 'premium';
        }
        if (str_contains($slug, 'basic')) {
            return 'basic';
        }

        return 'custom';
    }

    private function sortOrderFromTier(string $tier): int
    {
        return match ($tier) {
            'basic' => 10,
            'premium' => 20,
            'vip' => 30,
            'vvip' => 40,
            default => 50,
        };
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $safeTable = str_replace("'", "''", $table);
            $indexes = DB::select("PRAGMA index_list('{$safeTable}')");

            foreach ($indexes as $index) {
                $name = is_array($index) ? ($index['name'] ?? null) : ($index->name ?? null);
                if ((string) $name === $indexName) {
                    return true;
                }
            }

            return false;
        }

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
};
