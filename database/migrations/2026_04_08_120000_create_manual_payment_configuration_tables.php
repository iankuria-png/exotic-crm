<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('billing_manual_payment_methods')) {
            Schema::create('billing_manual_payment_methods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('market_id')->constrained('platforms')->cascadeOnDelete();
                $table->string('method_key', 40);
                $table->boolean('enabled')->default(false);
                $table->string('display_name', 160)->nullable();
                $table->text('instruction_intro')->nullable();
                $table->text('instruction_footer')->nullable();
                $table->boolean('proof_required')->default(true);
                $table->boolean('sender_name_required')->default(true);
                $table->boolean('transaction_id_required')->default(true);
                $table->boolean('auto_activate_on_submission')->default(false);
                $table->json('details_json')->nullable();
                $table->timestamps();

                $table->unique(['market_id', 'method_key'], 'billing_manual_payment_methods_market_method_uq');
                $table->index(['market_id', 'enabled'], 'billing_manual_payment_methods_market_enabled_idx');
            });
        }

        if (!Schema::hasTable('payment_manual_submissions')) {
            Schema::create('payment_manual_submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('duration_key', 50);
                $table->string('manual_method_key', 40);
                $table->boolean('activated_on_submit')->default(false);
                $table->json('destination_snapshot_json')->nullable();
                $table->json('instruction_snapshot_json')->nullable();
                $table->string('sender_name', 160);
                $table->string('transaction_reference', 255);
                $table->text('customer_note')->nullable();
                $table->string('proof_disk', 40)->default('local');
                $table->string('proof_path', 500);
                $table->string('proof_mime', 120);
                $table->unsignedBigInteger('proof_size_bytes')->default(0);
                $table->string('review_decision', 20)->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();

                $table->unique('payment_id', 'payment_manual_submissions_payment_uq');
                $table->index(
                    ['client_id', 'platform_id', 'product_id', 'duration_key'],
                    'payment_manual_submissions_client_market_product_duration_idx'
                );
                $table->index(
                    ['platform_id', 'manual_method_key', 'review_decision'],
                    'payment_manual_submissions_market_method_review_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_manual_submissions');
        Schema::dropIfExists('billing_manual_payment_methods');
    }
};
