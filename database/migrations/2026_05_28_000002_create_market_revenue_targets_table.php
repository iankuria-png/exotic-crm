<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('market_revenue_targets')) {
            Schema::create('market_revenue_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
                $table->string('period', 16);
                $table->decimal('target', 14, 2);
                $table->string('target_currency', 8);
                $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['platform_id', 'period'], 'market_revenue_targets_platform_period_unique');
                $table->index(['period', 'target_currency'], 'market_revenue_targets_period_currency_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('market_revenue_targets');
    }
};
