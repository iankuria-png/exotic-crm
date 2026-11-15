<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_pay_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->constrained();
            $t->char('code_hash', 64)->unique();
            $t->text('code_encrypted');
            $t->string('status', 20)->default('active');
            $t->unsignedBigInteger('active_client_id')->nullable()->unique();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('revoked_by_user_id')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason', 1000)->nullable();
            $t->unsignedInteger('open_count')->default(0);
            $t->timestamp('last_opened_at')->nullable();
            $t->timestamps();
        });
        Schema::create('pay_link_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_id')->unique()->constrained();
            $t->string('rollout_mode', 10)->default('off');
            $t->json('test_client_ids')->nullable();
            $t->json('test_device_hashes')->nullable();
            $t->json('offers_json')->nullable();
            $t->json('provider_order')->nullable();
            $t->json('limits_json')->nullable();
            $t->boolean('switch_package_while_live')->default(false);
            $t->string('support_whatsapp', 30)->nullable();
            $t->unsignedInteger('config_revision')->default(1);
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->string('reason', 1000)->nullable();
            $t->timestamps();
        });
        Schema::create('pay_link_charges', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_ref')->unique();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->constrained();
            $t->foreignId('pay_link_id')->constrained('client_pay_links');
            $t->foreignId('deal_id')->nullable()->constrained();
            $t->foreignId('payment_id')->nullable()->unique()->constrained();
            $t->unsignedBigInteger('blocking_client_id')->nullable()->unique();
            $t->char('idempotency_hash', 64);
            $t->char('request_fingerprint', 64);
            $t->unique(['platform_id', 'idempotency_hash'], 'pay_link_platform_idempotency');
            $t->unsignedBigInteger('provider_profile_id');
            $t->unsignedBigInteger('binding_id');
            $t->string('environment', 20);
            $t->uuid('deposit_id')->unique();
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->unsignedInteger('duration_days');
            $t->string('tier', 20);
            $t->unsignedBigInteger('product_price_id');
            $t->text('phone');
            $t->char('session_proof_hash', 64);
            $t->char('recovery_token_hash', 64);
            $t->text('recovery_token_encrypted');
            $t->text('request_json');
            $t->string('payment_state', 30)->default('reserved');
            $t->string('activation_state', 30)->default('not_started');
            $t->string('review_reason', 120)->nullable();
            $t->json('provider_evidence')->nullable();
            $t->json('activation_result')->nullable();
            $t->timestamp('next_reconcile_at')->nullable()->index();
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('dispatch_started_at')->nullable();
            $t->timestamp('final_at')->nullable();
            $t->timestamps();
        });
        Schema::create('pay_link_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained();
            $t->foreignId('charge_id')->constrained('pay_link_charges');
            $t->string('kind', 30)->default('pay_link_receipt');
            $t->unique(['payment_id', 'kind']);
            $t->text('phone')->nullable();
            $t->text('body');
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->string('outcome', 120)->nullable();
            $t->timestamps();
        });
        Schema::create('pay_link_device_pairings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_id')->constrained();
            $t->char('token_hash', 64)->unique();
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Money/recovery history must survive a code rollback. Removal is a separately reviewed operation.
        throw new RuntimeException('Pay-link settlement history cannot be rolled back automatically.');
    }
};
