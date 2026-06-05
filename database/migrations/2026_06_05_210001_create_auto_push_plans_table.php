<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_push_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('autopilot')->default(false);
            $table->json('buckets');
            $table->json('schedule');
            $table->json('message_strategy');
            $table->json('reliability')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_push_plans');
    }
};
