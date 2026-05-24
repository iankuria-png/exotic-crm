<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'origin')) {
                $table->string('origin', 16)->default('admin')->after('is_archived');
            }
            if (!Schema::hasColumn('products', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('origin');
                $table->foreign('created_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
            $table->index(['platform_id', 'origin'], 'products_platform_origin_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_platform_origin_index');
            if (Schema::hasColumn('products', 'created_by_user_id')) {
                $table->dropForeign(['created_by_user_id']);
                $table->dropColumn('created_by_user_id');
            }
            if (Schema::hasColumn('products', 'origin')) {
                $table->dropColumn('origin');
            }
        });
    }
};
