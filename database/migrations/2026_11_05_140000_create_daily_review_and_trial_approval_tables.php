<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_review_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
        DB::table('payment_review_settings')->insert(['key' => 'launch_date', 'value' => now('Africa/Nairobi')->toDateString()]);
        Schema::create('payment_review_grants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('platform_id');
            $t->string('capability', 20);
            $t->unsignedBigInteger('granted_by');
            $t->timestamps();
            $t->unique(['user_id', 'platform_id', 'capability'], 'payment_review_grant_identity');
        });
        Schema::create('daily_payment_reviews', function (Blueprint $t) {
            $t->id();
            $t->string('unit_type', 16);
            $t->unsignedBigInteger('unit_id');
            $t->json('platform_ids_json');
            $t->date('business_date')->index();
            $t->string('reporting_timezone', 64)->default('Africa/Nairobi');
            $t->string('status', 24)->default('pending')->index();
            $t->unsignedInteger('version')->default(0);
            $t->char('source_digest', 64);
            $t->json('snapshot_json');
            $t->text('flag_reason')->nullable();
            $t->unsignedBigInteger('flagged_by')->nullable();
            $t->timestamp('flagged_at')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->boolean('self_approval')->default(false);
            $t->text('self_approval_reason')->nullable();
            $t->timestamps();
            $t->unique(['unit_type', 'unit_id']);
        });
        Schema::create('payment_review_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('review_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 50);
            $t->json('before_json')->nullable();
            $t->json('after_json');
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('payment_correction_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('review_id')->index();
            $t->unsignedBigInteger('open_review_id')->nullable()->unique();
            $t->unsignedBigInteger('assigned_to')->index();
            $t->unsignedBigInteger('requested_by');
            $t->char('financial_digest', 64)->nullable();
            $t->string('reason_code', 50);
            $t->json('required_fields');
            $t->text('message');
            $t->timestamp('due_at')->nullable();
            $t->string('status', 16)->default('open');
            $t->unsignedInteger('version')->default(0);
            $t->json('response_revisions_json');
            $t->timestamps();
        });
        Schema::create('payment_review_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('review_id')->index();
            $t->string('disk', 30)->default('local');
            $t->string('path');
            $t->string('mime', 80);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->unsignedBigInteger('uploaded_by');
            $t->timestamps();
        });
        Schema::create('payment_review_operations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id');
            $t->uuid('idempotency_key');
            $t->char('payload_digest', 64);
            $t->json('result_json');
            $t->timestamps();
            $t->unique(['actor_id', 'idempotency_key']);
        });
        Schema::create('market_trial_approval_policies', function (Blueprint $t) {
            $t->unsignedBigInteger('platform_id')->primary();
            $t->string('approval_mode', 24)->default('admin_only');
            $t->unsignedInteger('version')->default(1);
            $t->text('pin_exception_reason')->nullable();
            $t->date('review_on')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        DB::table('platforms')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('market_trial_approval_policies')->insert([
                    'platform_id' => $row->id, 'approval_mode' => 'admin_only', 'version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        Schema::create('free_trial_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->char('active_identity', 64)->nullable()->unique();
            $t->unsignedBigInteger('requester_id')->index();
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->unsignedBigInteger('platform_id')->index();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->string('action', 24);
            $t->json('terms_json');
            $t->char('source_version', 64);
            $t->unsignedInteger('policy_version');
            $t->unsignedBigInteger('pin_generation')->nullable();
            $t->string('status', 20)->default('pending')->index();
            $t->string('approved_via', 10)->nullable();
            $t->unsignedBigInteger('authorized_actor_id')->nullable();
            $t->text('reason');
            $t->text('decision_reason')->nullable();
            $t->timestamp('authorized_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->json('activation_result_json')->nullable();
            $t->timestamps();
        });
        Schema::create('free_trial_request_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 50);
            $t->json('detail_json');
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('trial_activation_operations', function (Blueprint $t) {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->unsignedBigInteger('request_id')->index();
            $t->string('item_key', 80);
            $t->unsignedBigInteger('platform_id');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->string('state', 24)->default('prepared');
            $t->unsignedInteger('version')->default(0);
            $t->json('command_json');
            $t->json('baseline_json')->nullable();
            $t->char('source_digest', 64);
            $t->json('response_json')->nullable();
            $t->json('verified_effect_json')->nullable();
            $t->string('error_code', 100)->nullable();
            $t->uuid('claimed_by')->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('finalized_at')->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'item_key']);
        });
    }

    public function down(): void
    {
        // Deliberately preserve financial decisions and trial operation identities on rollback.
        throw new RuntimeException('Review/approval history is durable. Disable the workspace rather than dropping its tables.');
    }
};
