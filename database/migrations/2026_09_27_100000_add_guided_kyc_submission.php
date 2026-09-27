<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_subjects', function (Blueprint $table) {
            $table->string('document_type', 32)->nullable();
            $table->uuid('capture_set_id')->nullable();
            $table->json('submitted_document_ids')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('ai_consent_at')->nullable();
            $table->string('consent_version', 40)->nullable();
            $table->unsignedBigInteger('review_version')->default(0);
            $table->unsignedBigInteger('ai_last_review_id')->nullable();
        });
        Schema::table('kyc_documents', function (Blueprint $table) {
            // Pose frames remain selfies; no destructive MySQL enum conversion.
            $table->unsignedTinyInteger('sequence')->default(0);
            $table->uuid('capture_set_id')->nullable();
            $table->string('document_type', 32)->nullable();
        });
        Schema::table('kyc_settings', fn (Blueprint $table) => $table->json('ai_review')->nullable());
    }

    public function down(): void
    {
        Schema::table('kyc_settings', fn (Blueprint $table) => $table->dropColumn('ai_review'));
        Schema::table('kyc_documents', fn (Blueprint $table) => $table->dropColumn(['sequence', 'capture_set_id', 'document_type']));
        Schema::table('kyc_subjects', fn (Blueprint $table) => $table->dropColumn(['document_type', 'capture_set_id', 'submitted_document_ids', 'submitted_at', 'ai_consent_at', 'consent_version', 'review_version', 'ai_last_review_id']));
    }
};
