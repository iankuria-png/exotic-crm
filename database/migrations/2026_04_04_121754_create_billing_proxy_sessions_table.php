<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_proxy_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('billing_routing_decision_id')->constrained('billing_routing_decisions')->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained('billing_provider_profiles')->cascadeOnDelete();
            $table->string('provider_type_key', 80);
            $table->string('environment', 32)->default('production');
            $table->string('token_hash', 128)->unique();
            $table->timestamp('token_expires_at');
            $table->string('redirect_url', 1024)->nullable();
            $table->string('provider_reference', 160)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedSmallInteger('open_count')->default(0);
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->unsignedSmallInteger('rotation_count')->default(0);
            $table->string('state', 32)->default('created');
            $table->json('legacy_meta_json')->nullable();
            $table->timestamps();

            $table->index(['payment_id'], 'billing_proxy_sessions_payment_idx');
            $table->index(['token_hash'], 'billing_proxy_sessions_token_hash_idx');
            $table->index(['state'], 'billing_proxy_sessions_state_idx');
            $table->index(['token_expires_at'], 'billing_proxy_sessions_expires_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_proxy_sessions');
    }
};
