<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            if (!Schema::hasColumn('deals', 'base_product_price_id')) {
                $table->unsignedBigInteger('base_product_price_id')->nullable()->after('product_price_id');
                $table->foreign('base_product_price_id')
                    ->references('id')
                    ->on('product_prices')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            if (Schema::hasColumn('deals', 'base_product_price_id')) {
                $table->dropForeign(['base_product_price_id']);
                $table->dropColumn('base_product_price_id');
            }
        });
    }
};
