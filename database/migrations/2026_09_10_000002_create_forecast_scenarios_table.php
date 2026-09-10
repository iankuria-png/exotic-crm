<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('platform_id')->nullable()->constrained('platforms')->nullOnDelete();
            $table->enum('mode', ['replay', 'project', 'target']);
            $table->date('baseline_from');
            $table->date('baseline_to');
            $table->unsignedInteger('horizon_days')->nullable();
            $table->date('horizon_ends_on')->nullable();
            $table->string('reporting_currency', 8);
            $table->decimal('target_amount', 14, 2)->nullable();
            $table->enum('risk_band', ['conservative', 'balanced', 'stretch', 'downside'])->nullable();
            $table->json('levers');
            $table->json('snapshot');
            $table->string('config_digest', 80);
            $table->decimal('actual_total', 14, 2)->nullable();
            $table->decimal('variance_percent', 6, 2)->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'name'], 'forecast_scenarios_owner_name_unique');
            $table->index(['horizon_ends_on', 'scored_at'], 'forecast_scenarios_score_idx');
            $table->index('created_by', 'forecast_scenarios_creator_idx');
            $table->index(['mode', 'created_at'], 'forecast_scenarios_mode_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_scenarios');
    }
};
