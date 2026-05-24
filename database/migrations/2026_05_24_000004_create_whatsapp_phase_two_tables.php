<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->enum('engine', ['meta_cloud_api', 'baileys'])->default('meta_cloud_api');
            $table->string('profile_name', 160);
            $table->string('environment', 32)->default('sandbox');
            $table->boolean('kill_switch_enabled')->default(false);
            $table->string('meta_phone_number_id')->nullable();
            $table->string('meta_business_account_id')->nullable();
            $table->text('meta_access_token')->nullable();
            $table->text('meta_webhook_verify_token')->nullable();
            $table->text('meta_app_secret')->nullable();
            $table->string('meta_api_version', 16)->nullable();
            $table->string('baileys_sidecar_url_override')->nullable();
            $table->json('config_json')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('tested_at')->nullable();
            $table->timestamps();

            $table->foreign('market_id')->references('id')->on('platforms')->cascadeOnDelete();
            $table->unique(['market_id', 'engine', 'profile_name'], 'uniq_whatsapp_profile_name');
            $table->index(['market_id', 'engine', 'active']);
        });

        Schema::create('whatsapp_senders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_profile_id');
            $table->string('phone_e164', 32);
            $table->timestamps();

            $table->foreign('provider_profile_id')->references('id')->on('whatsapp_provider_profiles')->cascadeOnDelete();
            $table->unique('phone_e164', 'uniq_whatsapp_sender_phone');
        });

        Schema::create('whatsapp_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->string('message_type', 64);
            $table->unsignedBigInteger('primary_profile_id')->nullable();
            $table->unsignedBigInteger('fallback_profile_id')->nullable();
            $table->boolean('fallback_to_sms')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('market_id')->references('id')->on('platforms')->cascadeOnDelete();
            $table->foreign('primary_profile_id')->references('id')->on('whatsapp_provider_profiles')->nullOnDelete();
            $table->foreign('fallback_profile_id')->references('id')->on('whatsapp_provider_profiles')->nullOnDelete();
            $table->unique(['market_id', 'message_type'], 'uniq_whatsapp_routing_rule');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id')->nullable();
            $table->enum('direction', ['outbound', 'inbound']);
            $table->enum('engine', ['meta_cloud_api', 'baileys'])->default('meta_cloud_api');
            $table->unsignedBigInteger('provider_profile_id')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('phone_e164', 32);
            $table->text('body')->nullable();
            $table->string('media_url')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('status', 32)->default('queued');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('platform_id')->references('id')->on('platforms')->nullOnDelete();
            $table->foreign('provider_profile_id')->references('id')->on('whatsapp_provider_profiles')->nullOnDelete();
            $table->foreign('sender_id')->references('id')->on('whatsapp_senders')->nullOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('template_id')->references('id')->on('templates')->nullOnDelete();
            $table->index(['client_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->unique('provider_message_id', 'uniq_whatsapp_provider_message_id');
            $table->unique('idempotency_key', 'uniq_whatsapp_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_routing_rules');
        Schema::dropIfExists('whatsapp_senders');
        Schema::dropIfExists('whatsapp_provider_profiles');
    }
};
