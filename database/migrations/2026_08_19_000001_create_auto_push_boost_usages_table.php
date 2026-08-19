<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_push_boost_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('auto_push_plan_id')->nullable()->constrained('auto_push_plans')->nullOnDelete();
            $table->unsignedInteger('boost_hours');
            $table->json('limit_snapshot')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'created_at'], 'auto_push_boost_usage_market_window_idx');
            $table->index(['actor_id', 'created_at'], 'auto_push_boost_usage_actor_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_push_boost_usages');
    }
};
