<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_active_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->unsignedInteger('count');
            $table->timestamp('created_at')->nullable();

            $table->unique(['date', 'platform_id']);
            $table->index('platform_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_active_snapshots');
    }
};
