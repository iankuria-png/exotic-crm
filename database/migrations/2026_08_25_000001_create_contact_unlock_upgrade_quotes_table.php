<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_unlock_upgrade_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_rule_id')->constrained('contact_unlock_pricing_rules')->cascadeOnDelete();
            $table->string('quote_token_hash', 64)->unique();
            $table->string('session_token_hash', 64);
            $table->string('visitor_phone_hash', 64)->nullable();
            $table->string('currency', 8);
            $table->decimal('full_access_amount', 12, 2);
            $table->decimal('eligible_credit', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2);
            $table->unsignedSmallInteger('credit_window_days')->default(7);
            $table->json('credit_sources_json')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['platform_id', 'expires_at'], 'contact_unlock_quotes_platform_expires_idx');
            $table->index(['visitor_phone_hash', 'expires_at'], 'contact_unlock_quotes_phone_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_unlock_upgrade_quotes');
    }
};
