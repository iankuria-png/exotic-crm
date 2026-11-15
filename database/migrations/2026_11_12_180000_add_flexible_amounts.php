<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscription_rules', function (Blueprint $table) {
            $table->json('flexible_amounts')->nullable(); // Missing rules always resolve to Off.
        });
        Schema::create('flexible_payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained();
            $table->text('phone')->nullable();
            $table->text('body');
            $table->string('state', 30)->default('pending');
            $table->string('outcome', 100)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('flexible_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('platform_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('payment_id')->nullable()->unique()->constrained();
            $table->char('idempotency_hash', 64);
            $table->char('fingerprint', 64);
            $table->unique(['platform_id', 'idempotency_hash'], 'flexible_platform_idempotency');
            $table->unsignedBigInteger('blocking_client_id')->nullable()->unique();
            $table->string('state', 30)->default('reserved');
            $table->timestamp('dispatch_started_at')->nullable();
            $table->json('action')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain flexible checkout payment and recovery history on code rollback.');
    }
};
