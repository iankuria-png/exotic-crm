<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('send_love_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_id')->unique()->constrained();
            $t->boolean('enabled')->default(false);
            $t->string('rollout_mode')->default('off');
            $t->boolean('kill_switch')->default(false);
            $t->string('currency', 3);
            $t->unsignedInteger('config_revision')->default(1);
            $t->unsignedInteger('wp_revision')->default(0);
            $t->json('presets_json')->nullable();
            $t->unsignedInteger('default_preset')->default(1000);
            $t->unsignedInteger('custom_min')->default(100);
            $t->unsignedInteger('custom_max')->default(20000);
            $t->json('allowed_providers_json')->nullable();
            $t->unsignedInteger('creator_share_bps')->default(10000);
            foreach (['copy_policy_json', 'message_policy_json', 'eligibility_json', 'limits_json', 'test_client_ids'] as $c) {
                $t->json($c)->nullable();
            }
            $t->text('device_pepper');
            $t->timestamp('heartbeat_at')->nullable();
            $t->timestamps();
        });
        Schema::create('love_gifts', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('platform_id')->constrained();
            $t->foreignId('client_id')->constrained();
            $t->unsignedBigInteger('wp_post_id');
            $t->foreignId('payment_id')->unique()->constrained();
            $t->string('status')->default('pending_payment');
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('tier');
            $t->string('card_line', 60);
            foreach (['creator_credit_amount', 'platform_share_amount', 'provider_fee'] as $c) {
                $t->decimal($c, 12, 2)->default(0);
            }
            $t->string('message', 140)->nullable();
            $t->string('sender_name', 24)->nullable();
            $t->string('message_state')->default('visible');
            $t->string('visitor_phone_hash', 64);
            $t->string('visitor_phone_masked', 20);
            $t->string('device_hash', 64);
            $t->string('idempotency_key_hash', 64)->unique();
            $t->boolean('contact_shared')->default(false);
            $t->timestamp('contact_consent_at')->nullable();
            $t->text('contact_phone_encrypted')->nullable();
            $t->boolean('is_sandbox')->default(false);
            $t->foreignId('wallet_transaction_id')->nullable()->constrained();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('seen_at')->nullable();
            $t->json('metadata_json')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'status', 'sent_at']);
            $t->index(['platform_id', 'visitor_phone_hash', 'created_at']);
        });
        Schema::create('send_love_visibility', function (Blueprint $t) {
            $t->foreignId('client_id')->primary()->constrained();
            $t->boolean('visible')->default(true);
            $t->timestamps();
        });
        Schema::table('content_monetization_system_settings', fn (Blueprint $t) => $t->boolean('send_love_kill_switch')->default(false));
    }

    public function down(): void
    {
        Schema::table('content_monetization_system_settings', fn (Blueprint $t) => $t->dropColumn('send_love_kill_switch'));
        Schema::dropIfExists('send_love_visibility');
        Schema::dropIfExists('love_gifts');
        Schema::dropIfExists('send_love_settings');
    }
};
