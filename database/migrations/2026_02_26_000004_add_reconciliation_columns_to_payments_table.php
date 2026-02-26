<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'reconciliation_confidence')) {
                $table->string('reconciliation_confidence', 16)
                    ->default('low')
                    ->after('import_legacy_hash');
                $table->index('reconciliation_confidence', 'payments_recon_confidence_idx');
            }

            if (!Schema::hasColumn('payments', 'reconciliation_state')) {
                $table->string('reconciliation_state', 20)
                    ->default('open')
                    ->after('reconciliation_confidence');
                $table->index('reconciliation_state', 'payments_recon_state_idx');
            }
        });

        DB::table('payments')
            ->whereIn('match_confidence', ['manual', 'auto_high'])
            ->update(['reconciliation_confidence' => 'high']);

        DB::table('payments')
            ->where('match_confidence', 'auto_low')
            ->update(['reconciliation_confidence' => 'medium']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'reconciliation_state')) {
                $table->dropIndex('payments_recon_state_idx');
                $table->dropColumn('reconciliation_state');
            }

            if (Schema::hasColumn('payments', 'reconciliation_confidence')) {
                $table->dropIndex('payments_recon_confidence_idx');
                $table->dropColumn('reconciliation_confidence');
            }
        });
    }
};
