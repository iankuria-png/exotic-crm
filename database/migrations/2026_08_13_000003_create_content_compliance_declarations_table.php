<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_compliance_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->unsignedBigInteger('wp_user_id')->nullable();
            $table->unsignedBigInteger('wp_post_id');
            $table->unsignedBigInteger('wp_attachment_id')->nullable();
            $table->string('content_kind', 80);
            $table->string('participant_status', 80);
            $table->string('status', 80);
            $table->timestamp('declared_at');
            $table->string('ip_address', 120)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('wp_idempotency_key', 160);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'wp_idempotency_key'], 'content_declaration_idempotency_unique');
            $table->index(['client_id', 'status']);
            $table->index(['platform_id', 'wp_post_id']);
            $table->index(['platform_id', 'wp_attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_compliance_declarations');
    }
};
