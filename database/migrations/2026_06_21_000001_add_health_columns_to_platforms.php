<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->string('health_status')->nullable()->index()->after('client_sync_last_reconciled_at');
            $table->timestamp('health_checked_at')->nullable()->after('health_status');
            $table->string('health_error', 500)->nullable()->after('health_checked_at');
            $table->unsignedInteger('health_latency_ms')->nullable()->after('health_error');
            $table->unsignedInteger('health_consecutive_failures')->default(0)->after('health_latency_ms');
            $table->timestamp('health_down_since_at')->nullable()->after('health_consecutive_failures');
            $table->timestamp('health_last_down_notified_at')->nullable()->after('health_down_since_at');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->dropColumn([
                'health_status',
                'health_checked_at',
                'health_error',
                'health_latency_ms',
                'health_consecutive_failures',
                'health_down_since_at',
                'health_last_down_notified_at',
            ]);
        });
    }
};
