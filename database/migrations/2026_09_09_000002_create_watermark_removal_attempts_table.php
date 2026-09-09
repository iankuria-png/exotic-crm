<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per image the watermark remover looked at.
     *
     * Removal declines rather than guessing, which is the right default but
     * makes failure invisible: a batch whose photos all kept their mark looks
     * exactly like a batch that had none. Recording every attempt with its
     * reason and measurements turns that into something readable, and gives the
     * thresholds real data to be tuned against instead of one-off experiments.
     *
     * Deliberately not scoped to PBN seeding. The remover is reusable, and the
     * nullable context columns let any other caller record here too.
     */
    public function up(): void
    {
        Schema::create('watermark_removal_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_platform_id')->nullable();
            $table->unsignedBigInteger('pbn_site_id')->nullable();
            $table->unsignedBigInteger('pbn_seed_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('context', 40)->default('pbn_seed');
            $table->boolean('applied')->default(false);

            // A short, stable slug for grouping, alongside the sentence a human
            // reads. Counting free text would splinter on every number in it.
            $table->string('outcome', 40);
            $table->text('reason')->nullable();

            $table->string('image_url', 500)->nullable();
            $table->string('image_size', 20)->nullable();
            $table->string('stamp_size', 20)->nullable();
            $table->decimal('implausible_ratio', 6, 4)->nullable();
            $table->decimal('out_of_gamut_ratio', 6, 4)->nullable();
            $table->unsignedInteger('landed_px')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->index(['source_platform_id', 'outcome'], 'wm_attempts_platform_outcome_idx');
            $table->index(['batch_id', 'applied'], 'wm_attempts_batch_applied_idx');
            $table->index('created_at', 'wm_attempts_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_removal_attempts');
    }
};
