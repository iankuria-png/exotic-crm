<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-owned contact unlocks.
 *
 * This table is the ownership bridge between an anonymous
 * `visitor_contact_unlocks` payment/reveal and a logged-in WordPress member.
 * It does not store contact snapshots; contact details are resolved from the
 * current client row only when the rightful member reveals a still-active claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_unlock_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('visitor_contact_unlock_id')->constrained('visitor_contact_unlocks')->cascadeOnDelete();
            $table->unsignedBigInteger('wp_post_id');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('scope', 60);
            $table->string('status', 30)->default('active');
            $table->timestamp('claimed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_revealed_at')->nullable();
            $table->string('source', 60);
            $table->timestamps();

            $table->unique(['visitor_contact_unlock_id', 'wp_post_id'], 'customer_unlock_claims_unlock_profile_unique');
            $table->index(['customer_account_id', 'status', 'expires_at'], 'customer_unlock_claims_account_status_exp_idx');
            $table->index(['platform_id', 'wp_post_id', 'status'], 'customer_unlock_claims_platform_profile_status_idx');
            $table->index(['client_id', 'status'], 'customer_unlock_claims_client_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_unlock_claims');
    }
};
