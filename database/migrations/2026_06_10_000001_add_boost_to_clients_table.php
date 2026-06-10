<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Time-bounded auto-push priority. When boosted_until is in the future,
            // the auto-push engine force-includes this client at the front of the
            // next run(s) for its market, bypassing the recent-push exclusion.
            $table->timestamp('boosted_until')->nullable()->after('is_high_risk');
            $table->timestamp('boosted_at')->nullable()->after('boosted_until');
            $table->unsignedBigInteger('boosted_by')->nullable()->after('boosted_at');

            $table->index(['platform_id', 'boosted_until']);
            $table->foreign('boosted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['boosted_by']);
            $table->dropIndex(['platform_id', 'boosted_until']);
            $table->dropColumn(['boosted_until', 'boosted_at', 'boosted_by']);
        });
    }
};
