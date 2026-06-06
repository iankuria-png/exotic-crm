<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-operation reservation ownership for crashed-job reclamation
        Schema::create('auto_optimize_write_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('auto_optimize_items')->cascadeOnDelete();
            $table->unsignedBigInteger('platform_id');
            $table->timestamp('window_start');
            $table->enum('operation', ['apply', 'revert']);
            $table->unsignedInteger('reserved');       // slots claimed upfront
            $table->unsignedInteger('consumed')->default(0); // actual writes performed
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            // Driver-safe one-active-reservation-per-item guard (model saving hook)
            // = item_id while released_at IS NULL, else NULL
            $table->unsignedBigInteger('reservation_active_key')->nullable()->unique();

            $table->index(['platform_id', 'window_start']);
            $table->index(['expires_at', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_optimize_write_reservations');
    }
};
