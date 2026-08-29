<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-owned profile reports (Phase 7 Safety Centre).
 *
 * Only reports submitted while signed in as a member land here. The existing
 * anonymous report path is untouched and stays email-only, so no historical or
 * anonymous report is ever presented as account-owned.
 *
 * The member's free-text reason is deliberately NOT stored: it still reaches
 * staff through the existing admin email. This table holds the category, the
 * profile reference, and a coarse review status only, so a member's private
 * history can never become a public accusation and staff notes never leak back
 * to the member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_safety_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->unsignedBigInteger('wp_post_id');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('category', 40);
            $table->string('status', 40)->default('received');
            $table->string('source', 40)->default('member_profile_report');
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            // Staff-only. Never returned to the member on any endpoint.
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['customer_account_id', 'submitted_at'], 'customer_report_account_submitted_idx');
            $table->index(['platform_id', 'client_id', 'status'], 'customer_report_platform_client_status_idx');
            $table->index(['platform_id', 'status', 'submitted_at'], 'customer_report_platform_status_idx');
            $table->index(['wp_post_id', 'submitted_at'], 'customer_report_post_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_safety_reports');
    }
};
