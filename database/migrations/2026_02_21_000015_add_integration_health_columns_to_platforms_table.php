<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->timestamp('sync_last_checked_at')->nullable()->after('currency_code');
            $table->timestamp('sync_last_synced_at')->nullable()->after('sync_last_checked_at');
            $table->string('sync_last_scope', 20)->nullable()->after('sync_last_synced_at');
            $table->string('sync_last_status', 20)->default('unknown')->after('sync_last_scope');
            $table->text('sync_last_error')->nullable()->after('sync_last_status');
            $table->json('sync_last_result')->nullable()->after('sync_last_error');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn([
                'sync_last_checked_at',
                'sync_last_synced_at',
                'sync_last_scope',
                'sync_last_status',
                'sync_last_error',
                'sync_last_result',
            ]);
        });
    }
};
