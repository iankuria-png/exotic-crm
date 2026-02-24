<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timeline_events', function (Blueprint $table) {
            $table->index(['entity_type', 'entity_id', 'event_type'], 'timeline_entity_event_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['deal_id', 'status'], 'payments_deal_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('timeline_events', function (Blueprint $table) {
            $table->dropIndex('timeline_entity_event_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_deal_status_idx');
        });
    }
};
