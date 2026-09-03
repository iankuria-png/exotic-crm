<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per degradation transition — never per sample.
     *
     * The sampler runs every minute, but the level only moves a handful of
     * times a day at worst, so this table stays small enough that the incident
     * timeline can read it directly without aggregation.
     */
    public function up(): void
    {
        Schema::create('system_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('from_level');
            $table->unsignedTinyInteger('to_level');
            $table->string('trigger_signal', 64);
            $table->decimal('trigger_value', 12, 2)->nullable();
            $table->decimal('threshold', 12, 2)->nullable();
            $table->string('origin', 16)->default('automatic');
            $table->unsignedBigInteger('actor_id')->nullable();
            // All nine signals at transition time — the post-mortem payload.
            $table->json('snapshot')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['started_at']);
            $table->index(['resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_incidents');
    }
};
