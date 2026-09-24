<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_slug_alias_repair_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            // The audit summary the admin approved, kept for the run history.
            $table->json('audit_summary')->nullable();
            $table->unsignedInteger('target_urls')->default(0);
            $table->unsignedInteger('urls_processed')->default(0);
            $table->unsignedInteger('aliases_released')->default(0);
            // Every released `_wp_old_slug` row ({meta_id, post_id, slug, kind}):
            // the backup a restore replays.
            $table->json('backup')->nullable();
            $table->unsignedInteger('restored_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_slug_alias_repair_runs');
    }
};
