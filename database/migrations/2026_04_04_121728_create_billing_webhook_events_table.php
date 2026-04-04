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
        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_type_key', 80);
            $table->foreignId('provider_profile_id')->nullable()->constrained('billing_provider_profiles')->nullOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('platforms')->nullOnDelete();
            $table->string('provider_event_id', 160)->nullable();
            $table->string('dedupe_key', 160)->unique();
            $table->json('headers_json')->nullable();
            $table->longText('raw_body');
            $table->json('payload_json')->nullable();
            $table->string('signature_status', 32)->default('unchecked');
            $table->json('verification_meta_json')->nullable();
            $table->string('processing_status', 32)->default('pending');
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('billing_provider_transaction_id')->nullable()->constrained('billing_provider_transactions')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider_type_key', 'processing_status'], 'billing_webhook_provider_status_idx');
            $table->index(['payment_id'], 'billing_webhook_payment_idx');
            $table->index(['billing_provider_transaction_id'], 'billing_webhook_tx_idx');
            $table->index(['received_at'], 'billing_webhook_received_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
    }
};
