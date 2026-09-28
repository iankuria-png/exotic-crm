<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('kyc_subjects')->cascadeOnDelete();
            $table->foreignId('ai_review_id')->nullable()->constrained('kyc_ai_reviews')->nullOnDelete();
            $table->string('event', 80);
            $table->string('level', 16)->default('info');
            $table->string('summary', 500);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['subject_id', 'occurred_at']);
            $table->index(['ai_review_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_review_events');
    }
};
