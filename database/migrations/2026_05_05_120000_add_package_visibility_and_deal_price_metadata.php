<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('is_active');
            }
        });

        Schema::table('deals', function (Blueprint $table): void {
            if (!Schema::hasColumn('deals', 'product_price_id')) {
                $table->unsignedBigInteger('product_price_id')->nullable()->after('product_id');
                $table->index('product_price_id', 'deals_product_price_id_index');
            }

            if (!Schema::hasColumn('deals', 'duration_days')) {
                $table->unsignedSmallInteger('duration_days')->nullable()->after('duration');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            if (Schema::hasColumn('deals', 'product_price_id')) {
                $table->dropIndex('deals_product_price_id_index');
                $table->dropColumn('product_price_id');
            }

            if (Schema::hasColumn('deals', 'duration_days')) {
                $table->dropColumn('duration_days');
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'is_public')) {
                $table->dropColumn('is_public');
            }
        });
    }
};
