<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope renewal campaigns to a market so each platform can run its own reminder
     * cadence. A NULL platform_id is the global default set, used for any market that
     * has no campaigns of its own. Existing rows stay NULL, preserving today's
     * behaviour (one global cadence applied everywhere).
     */
    public function up(): void
    {
        Schema::table('renewal_campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_id')->nullable()->after('id');
            $table->index(['platform_id', 'enabled'], 'renewal_campaigns_platform_enabled_idx');
            $table->foreign('platform_id')->references('id')->on('platforms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('renewal_campaigns', function (Blueprint $table) {
            $table->dropForeign(['platform_id']);
            $table->dropIndex('renewal_campaigns_platform_enabled_idx');
            $table->dropColumn('platform_id');
        });
    }
};
