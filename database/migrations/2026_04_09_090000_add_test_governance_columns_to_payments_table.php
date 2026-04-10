<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'record_classification')) {
                $table->enum('record_classification', ['live', 'test'])
                    ->default('live')
                    ->after('provider_environment');
                $table->index('record_classification', 'payments_record_classification_idx');
            }

            if (!Schema::hasColumn('payments', 'test_reason')) {
                $table->string('test_reason', 500)->nullable()->after('record_classification');
            }

            if (!Schema::hasColumn('payments', 'test_marked_at')) {
                $table->timestamp('test_marked_at')->nullable()->after('test_reason');
                $table->index('test_marked_at', 'payments_test_marked_at_idx');
            }

            if (!Schema::hasColumn('payments', 'test_marked_by')) {
                $table->foreignId('test_marked_by')
                    ->nullable()
                    ->after('test_marked_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'test_marked_by')) {
                $table->dropConstrainedForeignId('test_marked_by');
            }

            if (Schema::hasColumn('payments', 'test_marked_at')) {
                $table->dropIndex('payments_test_marked_at_idx');
                $table->dropColumn('test_marked_at');
            }

            if (Schema::hasColumn('payments', 'test_reason')) {
                $table->dropColumn('test_reason');
            }

            if (Schema::hasColumn('payments', 'record_classification')) {
                $table->dropIndex('payments_record_classification_idx');
                $table->dropColumn('record_classification');
            }
        });
    }
};
