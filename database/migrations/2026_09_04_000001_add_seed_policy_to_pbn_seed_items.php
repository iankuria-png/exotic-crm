<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item record of the content policy a seeded profile actually received,
     * plus the trickle release time.
     *
     * The decision is resolved once, when the batch is created, rather than
     * recomputed during provisioning: provisioning and the media stage run in
     * separate queued passes and the media stage retries independently, so a
     * recomputed random choice would give an item a different badge on retry
     * than the one the preview promised.
     */
    public function up(): void
    {
        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->json('applied_policy')->nullable()->after('eligibility_snapshot');
            $table->timestamp('release_at')->nullable()->after('applied_policy');
        });

        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->index(['batch_id', 'release_at'], 'pbn_seed_items_batch_release_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pbn_seed_items', function (Blueprint $table) {
            $table->dropIndex('pbn_seed_items_batch_release_idx');
            $table->dropColumn(['applied_policy', 'release_at']);
        });
    }
};
