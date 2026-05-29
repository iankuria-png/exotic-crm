<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_message_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_message_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('engine', 24);
            $table->unsignedBigInteger('provider_profile_id')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('attempt_uuid', 64)->nullable();
            $table->enum('status', ['accepted', 'failed', 'rejected', 'skipped']);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('whatsapp_message_id')->references('id')->on('whatsapp_messages')->cascadeOnDelete();
            $table->foreign('provider_profile_id')->references('id')->on('whatsapp_provider_profiles')->nullOnDelete();
            $table->foreign('sender_id')->references('id')->on('whatsapp_senders')->nullOnDelete();
            $table->unique('attempt_uuid', 'uniq_whatsapp_attempt_uuid');
            $table->index(['whatsapp_message_id', 'attempt_number'], 'idx_whatsapp_attempt_sequence');
            $table->index(['engine', 'status', 'created_at'], 'idx_whatsapp_attempt_engine_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_attempts');
    }
};
