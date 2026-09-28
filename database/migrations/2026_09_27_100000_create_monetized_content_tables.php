<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->unsignedBigInteger('paid_media_revision')->default(0);
        });
        Schema::create('content_monetization_system_settings', function (Blueprint $t) {
            $t->id();
            $t->boolean('enabled')->default(false);
            $t->boolean('activation_kill_switch')->default(false);
            $t->boolean('checkout_kill_switch')->default(false);
            $t->unsignedBigInteger('config_revision')->default(1);
            $t->timestamps();
        });
        Schema::create('content_monetization_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('enabled')->default(false);
            $t->string('rollout_mode')->default('off');
            $t->string('premium_access_environment')->default('sandbox');
            $t->boolean('activation_kill_switch')->default(false);
            $t->boolean('checkout_kill_switch')->default(false);
            $t->boolean('inherits_global_defaults')->default(false);
            $t->string('currency', 3);
            foreach (['offer', 'surface', 'checkout', 'delivery'] as $policy) {
                $t->json($policy.'_policy_json')->nullable();
            }
            $t->json('test_client_ids')->nullable();
            $t->text('grant_secret');
            $t->text('device_pepper');
            $t->unsignedBigInteger('config_revision')->default(1);
            $t->unsignedBigInteger('wp_revision')->default(0);
            $t->timestamp('heartbeat_at')->nullable();
            $t->json('readiness_json')->nullable();
            $t->timestamps();
        });
        Schema::create('content_monetization_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('setting_id')->constrained('content_monetization_settings')->cascadeOnDelete();
            $t->string('duration_key');
            $t->string('duration_label');
            $t->unsignedInteger('duration_days');
            $t->string('currency', 3);
            $t->decimal('price', 12, 2);
            $t->string('subsidy_mode')->default('fixed');
            $t->decimal('subsidy_value', 12, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['setting_id', 'duration_key']);
        });
        Schema::create('client_monetization_passes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('price_id')->nullable()->constrained('content_monetization_prices')->nullOnDelete();
            $t->string('status');
            $t->unsignedTinyInteger('active_marker')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('expires_at');
            $t->string('duration_key');
            $t->unsignedInteger('duration_days');
            $t->string('currency', 3);
            foreach (['list_amount', 'subsidy_amount', 'paid_amount'] as $col) {
                $t->decimal($col, 12, 2);
            }
            $t->json('eligibility_snapshot_json');
            $t->boolean('is_sandbox')->default(false);
            $t->string('idempotency_key_hash', 64)->unique();
            $t->string('hold_reason')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('expiry_notified_at')->nullable();
            $t->timestamps();
            $t->unique(['client_id', 'platform_id', 'active_marker'], 'monetize_one_active_pass');
        });
        Schema::create('premium_content_assets', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('wp_post_id');
            $t->unsignedBigInteger('wp_attachment_id');
            $t->string('media_type');
            $t->string('status')->default('ready');
            $t->text('preview_url');
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->char('content_fingerprint', 64);
            $t->timestamp('held_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['platform_id', 'wp_attachment_id']);
        });
        Schema::create('premium_content_offers', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind');
            $t->string('title', 120)->nullable();
            $t->string('currency', 3);
            $t->decimal('amount', 12, 2);
            $t->string('status')->default('draft');
            $t->string('paused_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_sandbox')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('premium_content_offer_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->constrained('premium_content_offers')->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained('premium_content_assets');
            $t->unsignedInteger('sort_order')->default(0);
            $t->unique(['offer_id', 'asset_id']);
        });
        Schema::create('visitor_content_purchases', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('offer_id')->nullable()->constrained('premium_content_offers')->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('initiated');
            $t->string('offer_kind');
            $t->unsignedInteger('offer_version');
            $t->string('currency', 3);
            foreach (['gross_amount', 'provider_fee', 'creator_credit_amount'] as $col) {
                $t->decimal($col, 12, 2)->default(0);
            }
            $t->json('entitlement_snapshot_json');
            $t->char('visitor_phone_hash', 64)->index();
            $t->string('visitor_phone_masked');
            $t->char('first_device_hash', 64);
            $t->char('public_token_hash', 64);
            $t->char('idempotency_key_hash', 64)->unique();
            $t->boolean('is_sandbox')->default(false);
            $t->timestamp('purchased_at')->nullable();
            $t->timestamp('last_viewed_at')->nullable();
            $t->unsignedInteger('view_count')->default(0);
            $t->json('metadata_json')->nullable();
            $t->timestamps();
        });
        Schema::create('premium_content_purchase_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_id')->constrained('visitor_content_purchases')->cascadeOnDelete();
            $t->char('device_hash', 64);
            $t->timestamp('last_used_at');
            $t->timestamps();
            $t->unique(['purchase_id', 'device_hash'], 'premium_purchase_device_unique');
        });
        Schema::create('premium_content_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('purchase_id')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('kind')->index();
            $t->text('reason')->nullable();
            $t->json('metadata_json')->nullable();
            $t->timestamps();
        });
        Schema::table('kyc_settings', function (Blueprint $t) {
            $t->string('private_content_upload_policy_default')->default('off');
            $t->json('private_content_upload_policy_per_platform')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'paid_media_revision')) {
            Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('paid_media_revision'));
        }
        Schema::table('kyc_settings', fn (Blueprint $t) => $t->dropColumn(['private_content_upload_policy_default', 'private_content_upload_policy_per_platform']));
        foreach (['premium_content_events', 'premium_content_purchase_devices', 'visitor_content_purchases', 'premium_content_offer_items', 'premium_content_offers', 'premium_content_assets', 'client_monetization_passes', 'content_monetization_prices', 'content_monetization_settings', 'content_monetization_system_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
