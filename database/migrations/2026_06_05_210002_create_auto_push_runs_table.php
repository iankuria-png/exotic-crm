<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_push_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_push_plan_id')->constrained('auto_push_plans')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('push_campaigns')->nullOnDelete();
            $table->timestamp('window_start_at')->nullable();
            $table->timestamp('window_end_at')->nullable();
            $table->json('bucket_counts')->nullable();
            $table->unsignedInteger('candidates_selected')->default(0);
            $table->unsignedInteger('reserve_count')->default(0);
            $table->json('reserve_client_ids')->nullable();
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('replacements_made')->default(0);
            $table->decimal('ai_cost_usd', 10, 6)->default(0);
            $table->enum('status', ['running', 'completed', 'skipped', 'failed'])->default('running');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['auto_push_plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_push_runs');
    }
};
