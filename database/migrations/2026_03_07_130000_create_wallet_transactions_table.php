<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_transactions')) {
            return;
        }

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('platform_id');
            $table->enum('type', ['credit', 'debit', 'adjustment']);
            $table->string('currency_code', 3);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('description', 500);
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->json('metadata')->nullable();
            $table->string('notification_channel', 10)->nullable();
            $table->dateTime('notification_sent_at')->nullable();
            $table->dateTime('wp_synced_at')->nullable();
            $table->unsignedTinyInteger('wp_sync_attempts')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'created_at'], 'wallet_tx_client_created_idx');
            $table->index(['platform_id', 'created_at'], 'wallet_tx_platform_created_idx');
            $table->index(['reference_type', 'reference_id'], 'wallet_tx_reference_idx');
            $table->index('payment_id', 'wallet_tx_payment_idx');
            $table->index('deal_id', 'wallet_tx_deal_idx');

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('platform_id')->references('id')->on('platforms')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
