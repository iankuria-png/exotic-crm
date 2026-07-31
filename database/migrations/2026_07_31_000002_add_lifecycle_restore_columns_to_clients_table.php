<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cohort markers for SEO Recovery. A restored profile stays identifiable
     * as part of the batch that restored it, so the recovery can be measured
     * and reverted wholesale.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('lifecycle_restored_at')->nullable()->after('bio_redactions');
            $table->foreignId('lifecycle_restore_run_id')
                ->nullable()
                ->after('lifecycle_restored_at')
                ->constrained('lifecycle_restore_runs')
                ->nullOnDelete();
            $table->index('lifecycle_restored_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_restored_at']);
            $table->dropConstrainedForeignId('lifecycle_restore_run_id');
            $table->dropColumn('lifecycle_restored_at');
        });
    }
};
