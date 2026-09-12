<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('lifecycle_archive_deferred_until')
                ->nullable()
                ->after('lifecycle_archived_at');
            $table->index(['lifecycle_state', 'lifecycle_archive_deferred_until'], 'clients_lifecycle_archive_defer_idx');
        });

        Schema::create('lifecycle_archive_recovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 12); // policy | override
            $table->string('scope', 20); // selected | market_archived
            $table->json('client_ids')->nullable();
            $table->unsignedSmallInteger('archive_after_days');
            $table->timestamp('archive_deferred_until')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('restored_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['platform_id', 'status'], 'lifecycle_archive_recovery_platform_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_archive_recovery_runs');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_lifecycle_archive_defer_idx');
            $table->dropColumn('lifecycle_archive_deferred_until');
        });
    }
};
