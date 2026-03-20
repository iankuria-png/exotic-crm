<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sb_lead_import_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->string('mode', 30)->default('bootstrap'); // bootstrap | incremental
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created_leads')->default(0);
            $table->unsignedInteger('updated_leads')->default(0);
            $table->unsignedInteger('skipped_existing_client')->default(0);
            $table->unsignedInteger('skipped_existing_lead')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->json('error_details')->nullable();
            $table->json('candidate_user_ids')->nullable();
            $table->unsignedInteger('cursor_position')->default(0);
            $table->string('reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('last_processed_sb_user_id')->nullable();
            $table->string('last_processed_name')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
            $table->index('status');
            $table->foreign('platform_id')->references('id')->on('platforms')->onDelete('cascade');
            $table->foreign('initiated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sb_lead_import_runs');
    }
};
