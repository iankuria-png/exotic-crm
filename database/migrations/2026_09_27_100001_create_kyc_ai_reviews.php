<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('kyc_subjects')->cascadeOnDelete();
            $table->json('document_ids');
            $table->unsignedBigInteger('review_version');
            $table->string('mode', 24);
            $table->string('status', 24)->default('queued');
            $table->string('model')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->string('second_opinion_model')->nullable();
            $table->json('second_opinion')->nullable();
            $table->string('prompt_version', 30)->default('kyc-v1');
            $table->json('observations')->nullable();
            $table->string('face_match', 24)->nullable();
            $table->decimal('face_match_confidence', 4, 3)->nullable();
            $table->string('recommendation', 24)->default('human');
            $table->json('reason_codes')->nullable();
            $table->json('retake')->nullable();
            $table->text('advertiser_message')->nullable();
            $table->string('action_taken', 24)->default('none');
            $table->boolean('qa_sample')->default(false);
            $table->string('human_decision', 24)->nullable();
            $table->boolean('human_agreed')->nullable();
            $table->text('feedback_note')->nullable();
            $table->foreignId('feedback_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->decimal('reserved_usd', 10, 6)->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error', 100)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_ai_reviews');
    }
};
