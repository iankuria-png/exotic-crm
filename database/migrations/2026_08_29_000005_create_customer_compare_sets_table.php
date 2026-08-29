<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One active compare set per customer — the tray, not a library of saved sets.
 *
 * The set header exists so retention stays truthful: the signed policy is
 * "compare sets: 30 days after last update", and if the timestamp lived on the
 * items then removing the last item would delete the very row that recorded
 * when the set was last touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_compare_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->unique('customer_account_id', 'customer_compare_sets_account_unique');
            $table->index(['platform_id', 'last_activity_at'], 'customer_compare_sets_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_compare_sets');
    }
};
