<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('discount_percentage', 5, 2)->nullable()->after('amount');
            $table->decimal('original_amount', 12, 2)->nullable()->after('discount_percentage');
            $table->unsignedBigInteger('discount_approved_by')->nullable()->after('original_amount');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'discount_percentage',
                'original_amount',
                'discount_approved_by',
            ]);
        });
    }
};
