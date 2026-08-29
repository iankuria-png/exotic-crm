<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member feedback after a claimed contact reveal.
 *
 * Negative rows create an internal review signal only. They never suppress an
 * advertiser automatically and never become public accusations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_reachability_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('customer_unlock_claim_id')->nullable()->constrained('customer_unlock_claims')->nullOnDelete();
            $table->foreignId('visitor_contact_unlock_id')->nullable()->constrained('visitor_contact_unlocks')->nullOnDelete();
            $table->unsignedBigInteger('wp_post_id');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('outcome', 40);
            $table->string('status', 40)->default('recorded');
            $table->string('review_reason', 80)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_account_id', 'submitted_at'], 'customer_reach_account_submitted_idx');
            $table->index(['platform_id', 'client_id', 'status'], 'customer_reach_platform_client_status_idx');
            $table->index(['customer_unlock_claim_id', 'submitted_at'], 'customer_reach_claim_submitted_idx');
            $table->index(['outcome', 'submitted_at'], 'customer_reach_outcome_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reachability_feedback');
    }
};
