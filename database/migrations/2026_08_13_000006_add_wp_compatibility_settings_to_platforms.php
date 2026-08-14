<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-market WordPress compatibility flags. These let CRM opt a pilot market
     * into bridge writes needed by older WordPress themes while untouched markets
     * keep their existing behavior.
     */
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->json('wp_compatibility_settings')->nullable()->after('wallet_settings');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn('wp_compatibility_settings');
        });
    }
};
