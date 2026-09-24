<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bio_text_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total_profiles')->default(0);
            $table->unsignedInteger('profiles_scanned')->default(0);
            $table->unsignedInteger('profiles_unreadable')->default(0);
            $table->unsignedBigInteger('cursor_client_id')->default(0);
            $table->unsignedInteger('profiles_affected')->default(0);
            $table->unsignedInteger('profiles_fixable')->default(0);
            // Profiles per issue kind, e.g. {"broken_accents": 41, "ai_text": 3}.
            $table->json('issue_counts')->nullable();
            $table->foreignId('repair_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('repair_target')->default(0);
            $table->unsignedInteger('repaired')->default(0);
            $table->unsignedInteger('repair_unchanged')->default(0);
            $table->unsignedInteger('repair_failed')->default(0);
            $table->foreignId('restore_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('restore_target')->default(0);
            $table->unsignedInteger('restored')->default(0);
            $table->unsignedInteger('restore_failed')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('repair_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status']);
        });

        Schema::create('bio_text_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained('bio_text_scans')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->unsignedBigInteger('wp_post_id');
            $table->string('client_name')->default('');
            // BioTextIntegrity::inspect() issues for the bio as scanned.
            $table->json('issues');
            // The issue kinds as ",broken_accents,ai_text," so lists filter portably.
            $table->string('kinds', 200)->default('');
            $table->string('severity', 10);
            $table->boolean('fixable')->default(false);
            $table->string('status', 20)->default('found');
            // The bio exactly as it was before the repair wrote to it: the backup.
            $table->longText('original_html');
            $table->char('original_hash', 40);
            $table->longText('repaired_html')->nullable();
            // The CRM's copy of a contact-scrubbed bio, when the repair fixed it too.
            $table->longText('scrub_original_before')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('repaired_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->unique(['scan_id', 'wp_post_id']);
            $table->index(['scan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_text_findings');
        Schema::dropIfExists('bio_text_scans');
    }
};
