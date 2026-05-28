<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            if (!Schema::hasColumn('deals', 'linked_deal_id')) {
                $table->unsignedBigInteger('linked_deal_id')->nullable()->after('cancelled_payment_id');
                $table->index('linked_deal_id', 'deals_linked_deal_id_idx');
                $table->foreign('linked_deal_id')->references('id')->on('deals')->nullOnDelete();
            }

            if (!Schema::hasColumn('deals', 'pending_subsidiary_trial')) {
                $table->json('pending_subsidiary_trial')->nullable()->after('linked_deal_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            if (Schema::hasColumn('deals', 'pending_subsidiary_trial')) {
                $table->dropColumn('pending_subsidiary_trial');
            }

            if (Schema::hasColumn('deals', 'linked_deal_id')) {
                $table->dropForeign(['linked_deal_id']);
                $table->dropIndex('deals_linked_deal_id_idx');
                $table->dropColumn('linked_deal_id');
            }
        });
    }
};
