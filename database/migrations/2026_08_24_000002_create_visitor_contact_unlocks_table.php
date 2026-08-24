<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_contact_unlocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pricing_rule_id')->nullable()->constrained('contact_unlock_pricing_rules')->nullOnDelete();
            $table->string('scope', 40);
            $table->string('status', 40)->default('initiated');
            $table->string('visitor_phone_hash', 64);
            $table->string('visitor_phone_masked', 32);
            $table->string('visitor_email_hash', 64)->nullable();
            $table->string('visitor_email_masked', 190)->nullable();
            $table->string('idempotency_key_hash', 64)->unique();
            $table->string('session_token_hash', 64);
            $table->string('public_token_hash', 64)->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_revealed_at')->nullable();
            $table->unsignedInteger('reveal_count')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'scope', 'status'], 'visitor_unlocks_platform_scope_status_idx');
            $table->index(['client_id', 'status'], 'visitor_unlocks_client_status_idx');
            $table->index(['wp_post_id', 'status'], 'visitor_unlocks_wp_post_status_idx');
            $table->index(['visitor_phone_hash', 'status'], 'visitor_unlocks_phone_status_idx');
            $table->index(['payment_id'], 'visitor_unlocks_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_contact_unlocks');
    }
};
