<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-market short-cycle guard. When enabled, a pre-expiry renewal reminder is
     * suppressed if its lead time is >= the client's own subscription length, so a
     * "7 days before expiry" reminder never fires on a 7-day (weekly) plan. On by
     * default so every market is protected immediately; a market can opt out.
     */
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->boolean('renewal_reminder_guard_enabled')->default(true)->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn('renewal_reminder_guard_enabled');
        });
    }
};
