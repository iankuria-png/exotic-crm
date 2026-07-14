<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-market opt-in for the SEO-preserving profile lifecycle. Off by default so
     * every existing market keeps the legacy "expire = take offline (private)"
     * behaviour until it is deliberately enabled for a pilot market.
     */
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->boolean('lifecycle_policy_enabled')->default(false)->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn('lifecycle_policy_enabled');
        });
    }
};
