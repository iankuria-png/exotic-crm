<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds routing/diagnostics columns to sms_logs so NotificationService can record
 * every routed dispatch (provider, market, HTTP code, purpose, fallback usage)
 * for the Settings → SMS "Recent Dispatches" surface. Purely additive and
 * guarded so it is safe to re-run on both SQLite (tests) and MySQL (prod).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_logs', 'provider')) {
                $table->string('provider', 64)->nullable()->after('status');
            }
            if (!Schema::hasColumn('sms_logs', 'platform_id')) {
                // Column and its lookup index are created together, so this only
                // runs on the first migration and never double-creates the index.
                $table->unsignedBigInteger('platform_id')->nullable()->after('provider');
                $table->index(['platform_id', 'created_at'], 'sms_logs_platform_id_created_at_index');
            }
            if (!Schema::hasColumn('sms_logs', 'http_code')) {
                $table->unsignedSmallInteger('http_code')->nullable()->after('result_code');
            }
            if (!Schema::hasColumn('sms_logs', 'purpose')) {
                $table->string('purpose', 64)->nullable()->after('http_code');
            }
            if (!Schema::hasColumn('sms_logs', 'fallback_used')) {
                $table->boolean('fallback_used')->default(false)->after('purpose');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (Schema::hasColumn('sms_logs', 'platform_id')) {
                $table->dropIndex('sms_logs_platform_id_created_at_index');
            }
            foreach (['provider', 'platform_id', 'http_code', 'purpose', 'fallback_used'] as $column) {
                if (Schema::hasColumn('sms_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
