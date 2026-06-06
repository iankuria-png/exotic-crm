<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Atomic per-platform-hour write aggregate (row-locked)
        Schema::create('auto_optimize_write_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->timestamp('window_start'); // hour bucket
            $table->unsignedInteger('writes_count')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['platform_id', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_optimize_write_log');
    }
};
