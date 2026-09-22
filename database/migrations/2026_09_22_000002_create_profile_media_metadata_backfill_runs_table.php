<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_media_metadata_backfill_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 20);
            $table->json('client_ids')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('attachments_updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedBigInteger('cursor_client_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_media_metadata_backfill_runs');
    }
};
