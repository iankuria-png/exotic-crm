<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('platform_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->unsignedBigInteger('wp_user_id');
            $table->unsignedBigInteger('trip_id');
            $table->string('generation', 80);
            $table->string('environment', 16);
            $table->string('state', 24)->default('quoted');
            $table->json('snapshot');
            $table->json('quote');
            $table->char('quote_token_hash', 64);
            $table->timestamp('quote_expires_at');
            $table->unsignedInteger('config_revision');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('pass_expires_at');
            $table->unsignedBigInteger('winning_attempt_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
            $table->unique(['platform_id', 'trip_id', 'generation'], 'tour_order_generation_unique');
        });
        Schema::create('tour_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('tour_order_id')->constrained()->cascadeOnDelete();
            $table->string('request_id', 100);
            $table->string('method', 20);
            $table->string('state', 30)->default('initiating');
            $table->foreignId('payment_id')->nullable()->unique()->constrained();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->json('action')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamps();
            $table->unique(['tour_order_id', 'request_id']);
        });
        Schema::create('tour_order_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_platform_id')->constrained('platforms');
            $table->string('source_stop_key', 80);
            $table->char('tour_key', 64)->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedBigInteger('allocation_minor');
            $table->json('payload');
            $table->string('state', 24)->default('pending');
            $table->text('remote_url')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedBigInteger('refund_transaction_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['tour_order_id', 'destination_platform_id', 'source_stop_key'], 'tour_destination_stop_unique');
            $table->index(['state', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_order_stops');
        Schema::dropIfExists('tour_payment_attempts');
        Schema::dropIfExists('tour_orders');
    }
};
