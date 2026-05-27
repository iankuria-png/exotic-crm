<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_ceo')) {
                $table->boolean('is_ceo')->default(false)->index()->after('role');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['platform_id', 'status', 'created_at'], 'payments_platform_status_created_idx');
            $table->index(['status', 'completed_at'], 'payments_status_completed_idx');
            $table->index(['platform_id', 'completed_at'], 'payments_platform_completed_idx');
            $table->index(['deal_id', 'status', 'created_at'], 'payments_deal_status_created_idx');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->index(['assigned_to', 'activated_at'], 'deals_assigned_activated_idx');
            $table->index(['platform_id', 'status', 'expires_at'], 'deals_platform_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex('deals_assigned_activated_idx');
            $table->dropIndex('deals_platform_status_expires_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_platform_status_created_idx');
            $table->dropIndex('payments_status_completed_idx');
            $table->dropIndex('payments_platform_completed_idx');
            $table->dropIndex('payments_deal_status_created_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_ceo')) {
                $table->dropColumn('is_ceo');
            }
        });
    }
};
