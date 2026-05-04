<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin', 20)->default('manual');
            $table->string('mode', 20)->default('delta');
            $table->string('protocol', 10)->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('tombstones_processed')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->json('error_details')->nullable();
            $table->json('capability_snapshot')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('fallback_reason', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('run_upper_bound_modified_at')->nullable();
            $table->timestamp('cursor_modified_at')->nullable();
            $table->unsignedBigInteger('cursor_post_id')->nullable();
            $table->timestamp('tombstone_upper_bound_removed_at')->nullable();
            $table->timestamp('tombstone_cursor_removed_at')->nullable();
            $table->unsignedBigInteger('tombstone_cursor_post_id')->nullable();
            $table->timestamp('checkpoint_before_run')->nullable();
            $table->timestamp('checkpoint_after_run')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sync_runs');
    }
};
