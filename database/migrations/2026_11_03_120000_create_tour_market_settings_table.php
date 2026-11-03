<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_market_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('settings');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('synced_revision')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_market_settings');
    }
};
