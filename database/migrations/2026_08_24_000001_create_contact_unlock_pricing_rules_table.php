<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_unlock_pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 40);
            $table->string('label', 120);
            $table->string('currency', 8);
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('duration_days');
            $table->boolean('is_active')->default(false);
            $table->json('provider_policy_json')->nullable();
            $table->json('rate_limit_json')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'scope', 'is_active'], 'contact_unlock_rules_scope_active_idx');
            $table->index(['platform_id', 'currency'], 'contact_unlock_rules_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_unlock_pricing_rules');
    }
};
