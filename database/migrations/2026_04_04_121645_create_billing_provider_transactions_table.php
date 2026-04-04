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
        Schema::create('billing_provider_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('provider_type_key', 80);
            $table->foreignId('provider_profile_id')->nullable()->constrained('billing_provider_profiles')->nullOnDelete();
            $table->string('normalized_status', 64)->default('pending');
            $table->string('provider_transaction_id', 160)->nullable();
            $table->string('provider_session_id', 160)->nullable();
            $table->string('provider_invoice_id', 160)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->decimal('requested_amount', 15, 2)->nullable();
            $table->string('requested_currency', 8)->nullable();
            $table->decimal('charge_amount', 15, 2)->nullable();
            $table->string('charge_currency', 8)->nullable();
            $table->decimal('settled_amount', 15, 2)->nullable();
            $table->string('settled_currency', 8)->nullable();
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->string('fee_currency', 8)->nullable();
            $table->decimal('fx_rate', 10, 6)->nullable();
            $table->string('fx_source', 80)->nullable();
            $table->timestamp('fx_locked_at')->nullable();
            $table->string('settlement_status', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('confirmation_state_json')->nullable();
            $table->json('upstream_reference_json')->nullable();
            $table->string('attempt_group_key', 160)->nullable();
            $table->unsignedSmallInteger('attempt_sequence')->nullable();
            $table->foreignId('retry_of_provider_transaction_id')->nullable();
            $table->foreignId('fallback_from_provider_transaction_id')->nullable();
            $table->foreign('retry_of_provider_transaction_id')->references('id')->on('billing_provider_transactions')->nullOnDelete()->name('billing_tx_retry_fk');
            $table->foreign('fallback_from_provider_transaction_id')->references('id')->on('billing_provider_transactions')->nullOnDelete()->name('billing_tx_fallback_fk');
            $table->string('compatibility_reference', 160)->nullable();
            $table->unsignedInteger('state_version')->default(1);
            $table->json('raw_state_json')->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->timestamps();

            $table->index(['payment_id'], 'billing_provider_tx_payment_idx');
            $table->index(['provider_type_key', 'normalized_status'], 'billing_provider_tx_provider_status_idx');
            $table->index(['provider_profile_id'], 'billing_provider_tx_profile_idx');
            $table->index(['attempt_group_key'], 'billing_provider_tx_attempt_group_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_provider_transactions');
    }
};
