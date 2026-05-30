<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 64)->index(); // briefing_ceo, briefing_sales, insights_chat, project_intelligence
            $table->foreignId('user_id')->nullable()->index(); // null for scheduled jobs
            $table->mediumText('prompt')->nullable();
            $table->string('prompt_hash', 64)->nullable()->index();
            $table->mediumText('generated_sql')->nullable();
            $table->mediumText('result_summary')->nullable();
            $table->string('provider', 40)->nullable(); // null on total failure
            $table->string('status', 16)->index(); // success | failed
            $table->text('error_message')->nullable();
            $table->json('provider_attempts')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('est_cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->index(['feature', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
