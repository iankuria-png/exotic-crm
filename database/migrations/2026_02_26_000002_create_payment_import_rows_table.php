<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('payment_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 32)->default('invalid');
            $table->json('raw_row')->nullable();
            $table->json('normalized_row')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('duplicate_type', 80)->nullable();
            $table->foreignId('duplicate_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('transaction_reference_norm', 120)->nullable();
            $table->string('legacy_hash', 64)->nullable();
            $table->json('suggested_match')->nullable();
            $table->foreignId('applied_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->unique(['batch_id', 'row_number'], 'payment_import_rows_batch_row_unique');
            $table->index(['batch_id', 'status'], 'payment_import_rows_batch_status_idx');
            $table->index(['batch_id', 'transaction_reference_norm'], 'payment_import_rows_batch_ref_idx');
            $table->index(['batch_id', 'legacy_hash'], 'payment_import_rows_batch_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_import_rows');
    }
};
