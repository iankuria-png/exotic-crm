<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('premium_content_offers', function (Blueprint $t) {
            $t->char('creation_attempt_hash', 64)->nullable()->unique();
            $t->index(['platform_id', 'status', 'id'], 'premium_catalog_lookup');
        });
        Schema::table('visitor_content_purchases', fn (Blueprint $t) => $t->index(['platform_id', 'client_id', 'status'], 'premium_creator_sales_lookup'));
    }

    public function down(): void
    {
        Schema::table('premium_content_offers', function (Blueprint $t) {
            $t->dropUnique(['creation_attempt_hash']);
            $t->dropColumn('creation_attempt_hash');
            $t->dropIndex('premium_catalog_lookup');
        });
        Schema::table('visitor_content_purchases', fn (Blueprint $t) => $t->dropIndex('premium_creator_sales_lookup'));
    }
};
