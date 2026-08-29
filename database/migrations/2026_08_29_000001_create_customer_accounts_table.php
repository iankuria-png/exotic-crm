<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer accounts are site members with a My Exotic workspace.
 *
 * This is NOT `visitor_contact_unlocks` (an anonymous unlock buyer keyed by
 * session token) and NOT `clients` (an advertiser being sold to). A customer is
 * a logged-in WordPress member, identified only by (platform_id, wp_user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->unsignedBigInteger('wp_user_id');
            $table->string('display_name', 190)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('email_hash', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'wp_user_id'], 'customer_accounts_platform_wp_user_unique');
            $table->index(['platform_id', 'last_seen_at'], 'customer_accounts_platform_seen_idx');
            $table->index('email_hash', 'customer_accounts_email_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_accounts');
    }
};
