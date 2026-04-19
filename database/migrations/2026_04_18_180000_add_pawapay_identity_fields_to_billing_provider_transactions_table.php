<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_provider_transactions', function (Blueprint $table) {
            $table->string('provider_reported_transaction_id', 160)->nullable()->after('provider_transaction_id');
            $table->string('provider_reported_phone', 40)->nullable()->after('provider_transaction_id');
            $table->string('provider_failure_code', 160)->nullable()->after('provider_status');
            $table->text('provider_failure_message')->nullable()->after('provider_failure_code');

            $table->index('provider_reported_transaction_id', 'billing_provider_tx_reported_tx_id_idx');
            $table->index('provider_reported_phone', 'billing_provider_tx_reported_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('billing_provider_transactions', function (Blueprint $table) {
            $table->dropIndex('billing_provider_tx_reported_tx_id_idx');
            $table->dropIndex('billing_provider_tx_reported_phone_idx');
            $table->dropColumn([
                'provider_reported_transaction_id',
                'provider_reported_phone',
                'provider_failure_code',
                'provider_failure_message',
            ]);
        });
    }
};
