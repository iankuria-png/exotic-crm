<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-market toggle for sending the X-Exotic-CRM-Sync-Key header on WordPress
     * sync calls, managed from CRM settings. Replaces hand-editing the
     * EXOTIC_CRM_SYNC_SHARED_KEY_PLATFORM_IDS env allowlist per pilot market
     * (the env allowlist keeps working as a fallback).
     */
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->boolean('sync_shared_key_enabled')->default(false)->after('lifecycle_policy_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn('sync_shared_key_enabled');
        });
    }
};
