<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-market switch for the WordPress site's first-party profile analytics
     * tracker, managed from CRM settings and pushed to the market's sync plugin.
     * Defaults on so existing markets keep collecting until switched off; the
     * tracker's admin-ajax beacons exhausted Tanzania's database connections.
     */
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->boolean('analytics_collection_enabled')->default(true)->after('sync_shared_key_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn('analytics_collection_enabled');
        });
    }
};
