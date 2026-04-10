<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'resolution_code')) {
                $table->string('resolution_code', 50)->nullable()->after('record_classification');
                $table->index('resolution_code', 'payments_resolution_code_idx');
            }

            if (!Schema::hasColumn('payments', 'resolution_meta_json')) {
                $table->json('resolution_meta_json')->nullable()->after('resolution_code');
            }
        });

        Schema::table('deals', function (Blueprint $table) {
            if (!Schema::hasColumn('deals', 'cancellation_reason_code')) {
                $table->string('cancellation_reason_code', 50)->nullable()->after('status');
                $table->index('cancellation_reason_code', 'deals_cancellation_reason_code_idx');
            }

            if (!Schema::hasColumn('deals', 'cancellation_notes')) {
                $table->string('cancellation_notes', 500)->nullable()->after('cancellation_reason_code');
            }

            if (!Schema::hasColumn('deals', 'cancelled_payment_id')) {
                $table->foreignId('cancelled_payment_id')
                    ->nullable()
                    ->after('cancellation_notes')
                    ->constrained('payments')
                    ->nullOnDelete();
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'is_high_risk')) {
                $table->boolean('is_high_risk')->default(false)->after('profile_status');
                $table->index('is_high_risk', 'clients_is_high_risk_idx');
            }

            if (!Schema::hasColumn('clients', 'risk_reason_code')) {
                $table->string('risk_reason_code', 50)->nullable()->after('is_high_risk');
            }

            if (!Schema::hasColumn('clients', 'risk_marked_at')) {
                $table->timestamp('risk_marked_at')->nullable()->after('risk_reason_code');
                $table->index('risk_marked_at', 'clients_risk_marked_at_idx');
            }

            if (!Schema::hasColumn('clients', 'risk_marked_by')) {
                $table->foreignId('risk_marked_by')
                    ->nullable()
                    ->after('risk_marked_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'risk_marked_by')) {
                $table->dropConstrainedForeignId('risk_marked_by');
            }

            if (Schema::hasColumn('clients', 'risk_marked_at')) {
                $table->dropIndex('clients_risk_marked_at_idx');
                $table->dropColumn('risk_marked_at');
            }

            if (Schema::hasColumn('clients', 'risk_reason_code')) {
                $table->dropColumn('risk_reason_code');
            }

            if (Schema::hasColumn('clients', 'is_high_risk')) {
                $table->dropIndex('clients_is_high_risk_idx');
                $table->dropColumn('is_high_risk');
            }
        });

        Schema::table('deals', function (Blueprint $table) {
            if (Schema::hasColumn('deals', 'cancelled_payment_id')) {
                $table->dropConstrainedForeignId('cancelled_payment_id');
            }

            if (Schema::hasColumn('deals', 'cancellation_notes')) {
                $table->dropColumn('cancellation_notes');
            }

            if (Schema::hasColumn('deals', 'cancellation_reason_code')) {
                $table->dropIndex('deals_cancellation_reason_code_idx');
                $table->dropColumn('cancellation_reason_code');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'resolution_meta_json')) {
                $table->dropColumn('resolution_meta_json');
            }

            if (Schema::hasColumn('payments', 'resolution_code')) {
                $table->dropIndex('payments_resolution_code_idx');
                $table->dropColumn('resolution_code');
            }
        });
    }
};
