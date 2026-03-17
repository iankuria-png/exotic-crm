<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasColumn('payments', 'provider_key')
            || !Schema::hasColumn('payments', 'reference_number')
            || !Schema::hasColumn('payments', 'transaction_reference')
        ) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['provider_key', 'reference_number'], 'payments_provider_reference_idx');
            $table->index(['provider_key', 'transaction_reference'], 'payments_provider_tx_reference_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_provider_reference_idx');
            $table->dropIndex('payments_provider_tx_reference_idx');
        });
    }
};
