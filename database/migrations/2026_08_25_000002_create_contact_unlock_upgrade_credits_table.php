<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_unlock_upgrade_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('upgrade_unlock_id')->constrained('visitor_contact_unlocks')->cascadeOnDelete();
            $table->foreignId('source_unlock_id')->constrained('visitor_contact_unlocks')->cascadeOnDelete();
            $table->foreignId('source_payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 8);
            $table->decimal('credited_amount', 12, 2);
            $table->string('status', 30)->default('reserved');
            $table->timestamp('applied_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['upgrade_unlock_id', 'source_unlock_id'], 'contact_unlock_credit_upgrade_source_unique');
            $table->index(['source_unlock_id', 'status'], 'contact_unlock_credit_source_status_idx');
            $table->index(['platform_id', 'status'], 'contact_unlock_credit_platform_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_unlock_upgrade_credits');
    }
};
