<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('support_board_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 20)->default('incremental');
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('cleared')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->json('error_details')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedBigInteger('last_processed_client_id')->nullable();
            $table->string('last_processed_client_name')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_board_sync_runs');
    }
};
