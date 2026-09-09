<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The old index made a client seedable to a site exactly once, for ever:
     * unique(pbn_site_id, source_platform_id, source_client_id) with no regard
     * for status. A reverted item kept its row, so re-seeding that client threw
     * a 1062 duplicate-entry error and the whole batch 500'd.
     *
     * The invariant that is actually wanted is "at most one LIVE item per client
     * per site". MySQL and MariaDB have no partial indexes, but they do allow
     * repeated NULLs in a unique index, so a maintained column that holds the
     * client id only while the item is live gives exactly that: live rows
     * collide, historical rows (reverted, cancelled, failed) hold NULL and do
     * not. PbnSeedItem keeps it in step on every save.
     */
    private const LIVE_STATUSES = ['selected', 'queued', 'provisioning', 'created', 'media_pending'];

    public function up(): void
    {
        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->unsignedBigInteger('live_source_client_id')->nullable()->after('source_client_id');
        });

        DB::table('pbn_seed_items')
            ->whereIn('status', self::LIVE_STATUSES)
            ->update(['live_source_client_id' => DB::raw('source_client_id')]);

        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->dropUnique('pbn_seed_items_source_unique');
            $table->index(['pbn_site_id', 'source_client_id'], 'pbn_seed_items_site_source_idx');
            $table->unique(
                ['pbn_site_id', 'source_platform_id', 'live_source_client_id'],
                'pbn_seed_items_live_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->dropUnique('pbn_seed_items_live_source_unique');
            $table->dropIndex('pbn_seed_items_site_source_idx');
            $table->dropColumn('live_source_client_id');
        });

        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->unique(['pbn_site_id', 'source_platform_id', 'source_client_id'], 'pbn_seed_items_source_unique');
        });
    }
};
