<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->nullable()->constrained('platforms')->cascadeOnDelete();
            $table->string('metric', 50);
            $table->unsignedInteger('target');
            $table->enum('period', ['weekly', 'monthly']);
            $table->foreignId('set_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['platform_id', 'metric', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_goals');
    }
};
