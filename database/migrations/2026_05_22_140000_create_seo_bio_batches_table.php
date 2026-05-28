<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk bio generation batches.
 *
 * A batch represents one editor request to "generate bios for these N URLs".
 * Each pasted URL becomes a `seo_bio_batch_rows` entry. A background job
 * walks the rows, calls BioGenerationService for each, and stores the
 * resulting bio + score per row.
 *
 * The editor reviews results in a modal, then either accepts (push back to
 * WP) or discards individual rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_bio_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('language', 8)->default('en');
            $table->json('generation_options')->nullable();
            // queued|processing|ready|completed|cancelled|failed
            // ready = generation finished, awaiting editor acceptance
            // completed = editor accepted (some or all) rows
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('succeeded_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->text('source_paste')->nullable(); // raw editor paste for audit
            $table->boolean('auto_save_to_wp')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('seo_bio_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('row_index'); // 1-based position in editor's paste
            $table->string('input_url', 600)->nullable();
            $table->string('input_text', 600)->nullable(); // raw cell content (might not be a URL)
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('profile_name', 200)->nullable();
            // queued|processing|generated|failed|accepted|skipped|unresolved
            $table->string('status', 20)->default('queued');
            $table->mediumText('bio_html')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('breakdown')->nullable();
            $table->string('provider_used', 40)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'row_index']);
            $table->index(['batch_id', 'status']);
            $table->foreign('batch_id')->references('id')->on('seo_bio_batches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_bio_batch_rows');
        Schema::dropIfExists('seo_bio_batches');
    }
};
