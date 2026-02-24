<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            if (!Schema::hasColumn('deals', 'is_free_trial')) {
                $table->boolean('is_free_trial')->default(false)->after('status');
            }

            if (!Schema::hasColumn('deals', 'free_trial_approved_by')) {
                $table->string('free_trial_approved_by', 255)->nullable()->after('is_free_trial');
            }

            if (!Schema::hasColumn('deals', 'payment_reference')) {
                $table->string('payment_reference', 255)->nullable()->after('payment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $drops = [];
            foreach (['is_free_trial', 'free_trial_approved_by', 'payment_reference'] as $column) {
                if (Schema::hasColumn('deals', $column)) {
                    $drops[] = $column;
                }
            }

            if (!empty($drops)) {
                $table->dropColumn($drops);
            }
        });
    }
};
