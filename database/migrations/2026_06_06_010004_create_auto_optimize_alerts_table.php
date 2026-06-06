<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_optimize_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_optimize_plan_id')->nullable()->constrained('auto_optimize_plans')->nullOnDelete();
            $table->foreignId('platform_id')->nullable()->constrained('platforms')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->string('type', 60);
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['platform_id', 'resolved_at']);
            $table->index(['type', 'created_at']);
            $table->index(['auto_optimize_plan_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_optimize_alerts');
    }
};
