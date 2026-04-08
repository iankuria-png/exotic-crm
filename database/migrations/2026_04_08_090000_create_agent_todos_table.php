<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->enum('status', ['pending', 'done'])->default('pending');
            $table->foreignId('goal_id')->nullable()->constrained('agent_goals')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status', 'sort_order'], 'agent_todos_user_status_sort_index');
            $table->index(['user_id', 'due_at'], 'agent_todos_user_due_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_todos');
    }
};
