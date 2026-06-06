<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_optimize_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('autopilot')->default(false);
            $table->json('criteria')->nullable();
            $table->json('actions')->nullable();
            $table->json('schedule')->nullable();
            $table->json('reliability')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            // Driver-safe one-enabled-plan-per-market guard (maintained by model saving hook)
            $table->unsignedBigInteger('enabled_platform_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['platform_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_optimize_plans');
    }
};
