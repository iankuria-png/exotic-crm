<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reconciliation_batches', function (Blueprint $table) {
            // Set of market ids covered by the batch (transaction codes are matched across all of them).
            $table->json('platform_ids')->nullable()->after('platform_id');
            // Single shared currency across the selected markets, or null when they differ.
            $table->string('fallback_currency', 3)->nullable()->after('platform_ids');
        });

        Schema::table('payment_reconciliation_rows', function (Blueprint $table) {
            // Which market the row actually matched in (null for missing/unverifiable rows).
            $table->foreignId('matched_platform_id')->nullable()->after('matched_confirmed_by')
                ->constrained('platforms')->nullOnDelete();
        });

        // Backfill existing single-market batches so platform_ids is always populated.
        DB::table('payment_reconciliation_batches')->orderBy('id')->chunkById(200, function ($batches) {
            foreach ($batches as $batch) {
                DB::table('payment_reconciliation_batches')
                    ->where('id', $batch->id)
                    ->update(['platform_ids' => json_encode([(int) $batch->platform_id])]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_reconciliation_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_platform_id');
        });

        Schema::table('payment_reconciliation_batches', function (Blueprint $table) {
            $table->dropColumn(['platform_ids', 'fallback_currency']);
        });
    }
};
