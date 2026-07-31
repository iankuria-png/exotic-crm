<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEO Recovery: bulk republish of profiles that were taken offline
     * (post_status = private) by the legacy expiry sweep, before the
     * Active → Expired → Archived lifecycle existed.
     *
     * Each run records the exact eligibility config it used so a batch is
     * auditable, repeatable and — via the cohort columns on `clients` —
     * revertible.
     *
     * NOTE: the cap column is `batch_limit`, not `limit`. `limit` is a
     * reserved word on MariaDB (production) and would break any raw SQL.
     */
    public function up(): void
    {
        Schema::create('lifecycle_restore_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 12)->default('dry');        // dry | live
            $table->string('status', 20)->default('queued');   // queued|running|completed|failed|reverted
            $table->string('target_state', 12)->nullable();    // null = auto (age rule) | expired | archived
            $table->unsignedInteger('batch_limit')->default(200);
            $table->json('filters')->nullable();               // eligibility config used
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('restored_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
            $table->index(['requested_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_restore_runs');
    }
};
