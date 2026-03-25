<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_goal_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('metric', 50);
            $table->unsignedInteger('target');
            $table->enum('period', ['weekly', 'monthly']);
            $table->foreignId('set_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'platform_id', 'metric', 'period'], 'agent_goal_overrides_user_metric_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_goal_overrides');
    }
};
