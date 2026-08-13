<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_agreement_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_version_id')->constrained('creator_agreement_versions')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->unsignedBigInteger('wp_user_id')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->unsignedBigInteger('actor_wp_user_id')->nullable();
            $table->string('source_context', 80);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 120)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('wp_idempotency_key', 160);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'wp_idempotency_key'], 'creator_acceptance_idempotency_unique');
            $table->index(['platform_id', 'wp_user_id']);
            $table->index(['platform_id', 'wp_post_id']);
            $table->index(['client_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_agreement_acceptances');
    }
};
