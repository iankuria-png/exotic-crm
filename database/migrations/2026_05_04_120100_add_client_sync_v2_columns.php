<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->timestamp('client_sync_checkpoint_at')->nullable()->after('sync_last_result');
            $table->unsignedBigInteger('client_sync_checkpoint_post_id')->nullable()->after('client_sync_checkpoint_at');
            $table->timestamp('client_sync_tombstone_checkpoint_at')->nullable()->after('client_sync_checkpoint_post_id');
            $table->unsignedBigInteger('client_sync_tombstone_checkpoint_post_id')->nullable()->after('client_sync_tombstone_checkpoint_at');
            $table->string('client_sync_protocol', 10)->nullable()->after('client_sync_tombstone_checkpoint_post_id');
            $table->string('client_sync_contract_version', 20)->nullable()->after('client_sync_protocol');
            $table->timestamp('client_sync_capability_checked_at')->nullable()->after('client_sync_contract_version');
            $table->string('client_sync_capability_status', 40)->nullable()->after('client_sync_capability_checked_at');
            $table->timestamp('client_sync_last_reconciled_at')->nullable()->after('client_sync_capability_status');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('source_presence_status', 20)->default('present')->after('wp_modified_at');
            $table->timestamp('source_missing_at')->nullable()->after('source_presence_status');
            $table->unsignedInteger('source_missing_count')->default(0)->after('source_missing_at');
            $table->timestamp('last_seen_in_reconcile_at')->nullable()->after('source_missing_count');

            $table->index(['platform_id', 'source_presence_status'], 'clients_platform_source_presence_idx');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_platform_source_presence_idx');
            $table->dropColumn([
                'source_presence_status',
                'source_missing_at',
                'source_missing_count',
                'last_seen_in_reconcile_at',
            ]);
        });

        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn([
                'client_sync_checkpoint_at',
                'client_sync_checkpoint_post_id',
                'client_sync_tombstone_checkpoint_at',
                'client_sync_tombstone_checkpoint_post_id',
                'client_sync_protocol',
                'client_sync_contract_version',
                'client_sync_capability_checked_at',
                'client_sync_capability_status',
                'client_sync_last_reconciled_at',
            ]);
        });
    }
};
