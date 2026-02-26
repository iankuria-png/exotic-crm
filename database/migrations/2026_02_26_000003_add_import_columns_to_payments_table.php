<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'source')) {
                $table->string('source', 40)->default('gateway')->after('status');
                $table->index('source', 'payments_source_idx');
            }

            if (!Schema::hasColumn('payments', 'import_batch_id')) {
                $table->foreignId('import_batch_id')
                    ->nullable()
                    ->after('source')
                    ->constrained('payment_import_batches')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('payments', 'import_legacy_hash')) {
                $table->string('import_legacy_hash', 64)->nullable()->after('import_batch_id');
                $table->index('import_legacy_hash', 'payments_import_legacy_hash_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'import_batch_id')) {
                $table->dropForeign(['import_batch_id']);
                $table->dropColumn('import_batch_id');
            }

            if (Schema::hasColumn('payments', 'import_legacy_hash')) {
                $table->dropIndex('payments_import_legacy_hash_idx');
                $table->dropColumn('import_legacy_hash');
            }

            if (Schema::hasColumn('payments', 'source')) {
                $table->dropIndex('payments_source_idx');
                $table->dropColumn('source');
            }
        });
    }
};
