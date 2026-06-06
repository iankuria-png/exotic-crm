<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_optimize_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_optimize_plan_id')->constrained('auto_optimize_plans')->cascadeOnDelete();
            $table->foreignId('auto_optimize_run_id')->constrained('auto_optimize_runs')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->enum('status', [
                'queued', 'building', 'pending', 'applying',
                'applied', 'skipped', 'reverted', 'failed',
            ])->default('queued');
            $table->string('reason')->nullable();

            // Profile snapshot persisted for post-apply score recompute
            $table->json('profile_snapshot')->nullable();

            // Bio before/after
            $table->longText('previous_bio_html')->nullable();
            $table->longText('new_bio_html')->nullable();

            // Score before/after (including breakdown for writeSeoScore)
            $table->smallInteger('previous_score')->nullable();
            $table->smallInteger('new_score')->nullable();
            $table->json('previous_score_breakdown')->nullable();
            $table->json('new_score_breakdown')->nullable();

            // Image before/after
            $table->unsignedBigInteger('previous_main_attachment_id')->nullable();
            $table->string('previous_main_image_url')->nullable();
            $table->unsignedBigInteger('new_main_attachment_id')->nullable();
            $table->string('new_main_image_url')->nullable();

            // Conflict detection hashes
            $table->string('source_bio_hash')->nullable();
            $table->unsignedBigInteger('source_main_attachment_id')->nullable();
            $table->string('applied_bio_hash')->nullable(); // set only when bio was applied
            $table->string('bio_simhash')->nullable();      // 64-bit SimHash for duplicate detection

            // Driver-safe cross-run idempotency guard (maintained by model saving hook)
            // = client_id while status in {queued,building,pending,applying}, else NULL
            $table->unsignedBigInteger('active_client_key')->nullable()->unique();

            // Checkpoints and metadata
            $table->json('actions_applied')->nullable();
            $table->string('provider_used')->nullable();
            $table->string('language_used')->nullable();
            $table->decimal('ai_cost_usd', 10, 6)->default(0);

            // Impact tracking (equal windows anchored on applied_at)
            $table->json('impact_before')->nullable();
            $table->json('impact_after')->nullable();
            $table->json('impact')->nullable();
            $table->timestamp('impact_checked_at')->nullable();

            // Apply audit
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Revert audit
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('error_message')->nullable();
            $table->timestamps();

            // Idempotency: one item per client per run
            $table->unique(['auto_optimize_run_id', 'client_id']);

            $table->index(['auto_optimize_plan_id', 'status']);
            $table->index(['platform_id', 'status']);
            $table->index('client_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_optimize_items');
    }
};
