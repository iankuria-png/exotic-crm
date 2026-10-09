<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_containment_markets', function (Blueprint $t) {
            $t->unsignedBigInteger('platform_id')->primary();
            $t->boolean('enabled')->default(false);
            $t->boolean('filesystem_enabled')->default(false);
            $t->boolean('quarantine_enabled')->default(false);
            $t->text('configuration')->nullable(); // encrypted model cast, never a settings response
            $t->timestamps();
        });
        Schema::create('db_market_operation_leases', function (Blueprint $t) {
            $t->unsignedBigInteger('platform_id')->primary(); // 0 is the one global writer slot
            $t->uuid('operation_id')->nullable();
            $t->uuid('owner_token')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->unsignedBigInteger('generation')->default(0);
            $t->timestamps();
        });
        Schema::create('db_containment_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 80);
            $t->string('status', 40)->default('preview');
            $t->json('members');
            $t->char('preview_digest', 64);
            $t->timestamp('expires_at');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'request_key']);
        });
        Schema::create('db_containment_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('campaign_id')->nullable()->index();
            $t->uuid('parent_id')->nullable()->index();
            $t->unsignedBigInteger('finding_id')->nullable();
            $t->unsignedBigInteger('platform_id')->index();
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 80);
            $t->string('kind', 20)->default('database');
            $t->string('status', 40)->default('preview')->index();
            $t->string('result_code', 80)->nullable();
            $t->json('selection');
            $t->json('preview');
            $t->json('result')->nullable();
            $t->text('sealed_intent');
            $t->text('cache_requests')->nullable();
            $t->char('preview_digest', 64);
            $t->char('credential_fingerprint', 64);
            $t->string('policy_version', 30);
            $t->uuid('backup_id')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('cancel_requested_at')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'request_key']);
        });
        Schema::create('db_containment_operation_findings', function (Blueprint $t) {
            $t->uuid('operation_id');
            $t->unsignedBigInteger('finding_id')->index();
            $t->primary(['operation_id', 'finding_id']);
        });
        Schema::create('db_containment_backups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('operation_id')->unique();
            $t->string('storage_key');
            $t->string('key_version', 30);
            $t->char('cipher_digest', 64);
            $t->unsignedBigInteger('byte_size');
            $t->timestamp('expires_at');
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
        });
        Schema::create('db_containment_outbox', function (Blueprint $t) {
            $t->uuid('operation_id')->primary();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('db_containment_file_observations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('platform_id')->index();
            $t->json('metadata');
            $t->text('sealed_identity');
            $t->timestamp('observed_at');
            $t->timestamps();
        });
        Schema::table('db_scan_audit_events', function (Blueprint $t) {
            $t->uuid('operation_id')->nullable()->index();
            $t->char('preview_digest', 64)->nullable();
            $t->uuid('backup_id')->nullable();
            $t->string('result_code', 80)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('db_containment_operations')->exists() || DB::table('db_containment_backups')->whereNull('purged_at')->exists()) {
            throw new RuntimeException('Retain containment journals and backups; disable writes instead of dropping recovery state.');
        }
        Schema::table('db_scan_audit_events', fn (Blueprint $t) => $t->dropColumn(['operation_id', 'preview_digest', 'backup_id', 'result_code']));
        foreach (['db_containment_file_observations', 'db_containment_outbox', 'db_containment_backups', 'db_containment_operation_findings', 'db_containment_operations', 'db_containment_campaigns', 'db_market_operation_leases', 'db_containment_markets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
