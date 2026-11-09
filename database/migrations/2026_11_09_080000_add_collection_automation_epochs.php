<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_events', function (Blueprint $t) {
            $t->uuid('automation_epoch')->nullable();
            $t->boolean('automation_eligible_at_ingest')->default(false);
            $t->index(['account_id', 'state', 'ingested_at', 'id'], 'collection_events_intake_order');
        });
        Schema::table('collection_receipts', function (Blueprint $t) {
            $t->dateTime('first_ingested_at')->nullable();
            $t->uuid('automation_epoch')->nullable();
            $t->boolean('automation_eligible_at_ingest')->default(false);
        });
        // Actual retained server arrival only. Existing rows never gain eligibility.
        DB::statement('UPDATE collection_receipts r JOIN (SELECT receipt_id, MIN(ingested_at) AS first_ingested FROM collection_events WHERE receipt_id IS NOT NULL GROUP BY receipt_id) e ON e.receipt_id = r.id SET r.first_ingested_at = e.first_ingested WHERE r.first_ingested_at IS NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('Pause automation and retain intake provenance; destructive epoch rollback is not supported.');
    }
};
