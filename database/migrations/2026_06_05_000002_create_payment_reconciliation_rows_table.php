<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('payment_reconciliation_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_row')->nullable();
            $table->string('external_name')->nullable();
            $table->decimal('external_amount', 12, 2)->nullable();
            $table->string('external_currency', 3)->nullable();
            $table->string('external_paid_at_text')->nullable();
            $table->string('external_reference_raw')->nullable();
            $table->string('transaction_reference_norm', 120)->nullable();
            $table->string('classification', 40)->default('unverifiable');
            $table->json('flags')->nullable();
            $table->foreignId('matched_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('matched_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matched_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('match_basis')->nullable();
            $table->string('review_status', 32)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'row_number'], 'payment_recon_rows_batch_row_unique');
            $table->index(['batch_id', 'classification'], 'payment_recon_rows_batch_class_idx');
            $table->index(['batch_id', 'review_status'], 'payment_recon_rows_batch_review_idx');
            $table->index('transaction_reference_norm', 'payment_recon_rows_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_rows');
    }
};
