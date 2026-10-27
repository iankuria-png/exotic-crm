<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_owner_checkout_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('platform_id');
            $t->unsignedBigInteger('wp_user_id');
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->string('state', 24);
            $t->json('response_json')->nullable();
            $t->timestamps();
            $t->unique(['platform_id', 'wp_user_id', 'request_key'], 'owner_checkout_request_unique');
        });
        Schema::create('payment_recovery_actions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('case_id');
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->json('response_json');
            $t->timestamps();
            $t->unique(['case_id', 'request_key']);
        });
        Schema::create('payment_attributions', function (Blueprint $t) {
            $t->foreignId('payment_id')->primary()->constrained('payments')->restrictOnDelete();
            $t->unsignedBigInteger('platform_id')->index();
            $t->unsignedBigInteger('original_client_id')->nullable();
            $t->unsignedBigInteger('wp_user_id')->nullable();
            $t->unsignedBigInteger('wp_post_id')->nullable();
            $t->dateTime('client_first_seen_at')->nullable();
            $t->string('attribution_state', 24);
            $t->json('evidence_json');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['platform_id', 'original_client_id']);
        });
        Schema::create('payment_recovery_cases', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_reference')->unique();
            $t->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $t->unsignedBigInteger('platform_id')->index();
            $t->string('state', 32);
            $t->string('reason_code', 80);
            $t->string('queue_key', 80);
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('opened_at');
            $t->dateTime('next_retry_at')->nullable();
            $t->dateTime('escalated_at')->nullable();
            $t->dateTime('alerted_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->string('disposition', 40)->nullable();
            $t->json('evidence_json')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['state', 'next_retry_at']);
        });
        Schema::create('payment_receipt_observations', function (Blueprint $t) {
            $t->id();
            $t->char('receipt_key', 64)->unique();
            $t->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $t->unsignedBigInteger('platform_id');
            $t->json('evidence_json');
            $t->timestamps();
        });
        Schema::create('payment_fulfillment_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $t->unsignedBigInteger('platform_id');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('wp_post_id');
            $t->unsignedBigInteger('wp_user_id');
            $t->string('state', 32)->default('prepared');
            $t->json('purchase_snapshot');
            $t->json('target_snapshot');
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->json('result_json')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('next_retry_at')->nullable();
            $t->dateTime('dispatch_started_at')->nullable();
            $t->string('last_error_code', 80)->nullable();
            $t->timestamps();
            $t->index(['state', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        foreach (['payment_recovery_actions', 'payment_owner_checkout_requests', 'payment_attributions', 'payment_recovery_cases', 'payment_receipt_observations', 'payment_fulfillment_operations'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Financial recovery records must survive code rollback.');
            }
        }
        foreach (['payment_recovery_actions', 'payment_fulfillment_operations', 'payment_receipt_observations', 'payment_recovery_cases', 'payment_attributions', 'payment_owner_checkout_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
