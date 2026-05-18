<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->date('dob')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->enum('status', ['unverified', 'in_review', 'info_requested', 'approved', 'rejected', 'expired'])->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('last_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('last_reason_user')->nullable();
            $table->text('last_reason_internal')->nullable();
            $table->timestamp('last_info_request_at')->nullable();
            $table->timestamp('grace_started_at')->nullable();
            $table->timestamp('last_escalation_at')->nullable();
            $table->string('last_escalation_rule', 50)->nullable();
            $table->timestamps();

            $table->index(['status', 'verified_at']);
            $table->index(['expires_at']);
            $table->index(['grace_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_subjects');
    }
};
