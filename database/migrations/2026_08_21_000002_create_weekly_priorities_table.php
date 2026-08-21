<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'completed', 'archived'])->default('pending');
            $table->enum('priority_level', ['critical', 'high', 'normal'])->default('normal');
            $table->enum('audience', ['all', 'ceo', 'admin', 'sales'])->default('all');
            $table->foreignId('platform_id')->nullable()->constrained('platforms')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('week_start');
            $table->date('week_end');
            $table->timestamp('due_at')->nullable();
            $table->enum('completion_mode', ['manual', 'metric', 'hybrid'])->default('manual');
            $table->string('metric_key', 80)->nullable();
            $table->enum('target_operator', ['gte', 'lte'])->nullable();
            $table->decimal('target_value', 16, 2)->nullable();
            $table->string('target_currency', 8)->nullable();
            $table->decimal('current_value', 16, 2)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['week_start', 'week_end', 'status'], 'weekly_priorities_week_status_index');
            $table->index(['audience', 'status', 'due_at'], 'weekly_priorities_audience_status_due_index');
            $table->index(['platform_id', 'status'], 'weekly_priorities_platform_status_index');
            $table->index(['owner_user_id', 'status'], 'weekly_priorities_owner_status_index');
            $table->index(['source_type', 'source_id'], 'weekly_priorities_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_priorities');
    }
};
