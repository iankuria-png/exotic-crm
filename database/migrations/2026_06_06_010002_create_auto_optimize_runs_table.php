<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_optimize_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_optimize_plan_id')->constrained('auto_optimize_plans')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->enum('status', ['pending', 'running', 'completed', 'completed_with_failures', 'failed', 'skipped'])->default('pending');
            $table->string('batch_id')->nullable();
            $table->unsignedInteger('candidates_scanned')->default(0);
            $table->unsignedInteger('candidates_selected')->default(0);
            $table->unsignedInteger('jobs_total')->default(0);
            $table->unsignedInteger('jobs_completed')->default(0);
            $table->unsignedInteger('jobs_failed')->default(0);
            $table->unsignedInteger('items_applied')->default(0);
            $table->decimal('ai_cost_usd', 10, 6)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['auto_optimize_plan_id', 'created_at']);
            $table->index(['platform_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_optimize_runs');
    }
};
