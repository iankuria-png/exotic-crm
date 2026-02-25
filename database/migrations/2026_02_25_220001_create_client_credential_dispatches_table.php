<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_credential_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('platform_id');
            $table->enum('method', ['setup_link', 'temporary_password']);
            $table->enum('channel', ['email', 'sms', 'both']);
            $table->enum('timing', ['send_now', 'manual_send_later']);
            $table->enum('status', ['deferred', 'sent', 'partial', 'failed'])->default('deferred');
            $table->string('recipient_email', 255)->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->json('provider_results')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
            $table->index(['client_id', 'created_at']);
            $table->index(['timing', 'status']);

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_credential_dispatches');
    }
};
