<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_retention_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->nullable()->constrained('platforms')->nullOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('band', 40)->default('Stable');
            $table->string('primary_tag', 80)->default('Stable');
            $table->json('secondary_tags')->nullable();
            $table->json('component_scores')->nullable();
            $table->json('top_drivers')->nullable();
            $table->json('signals')->nullable();
            $table->dateTime('computed_at');
            $table->timestamps();

            $table->unique('client_id');
            $table->index(['platform_id', 'band']);
            $table->index(['platform_id', 'primary_tag']);
            $table->index('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_retention_insights');
    }
};
