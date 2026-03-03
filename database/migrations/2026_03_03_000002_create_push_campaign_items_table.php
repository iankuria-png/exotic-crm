<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_campaign_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('profile_url', 500);
            $table->unsignedInteger('wp_post_id')->nullable();
            $table->string('profile_name', 255)->nullable();
            $table->string('profile_phone', 30)->nullable();
            $table->string('profile_image_url', 500)->nullable();
            $table->string('profile_age', 10)->nullable();
            $table->text('custom_message');
            $table->dateTime('scheduled_at')->nullable();
            $table->string('date_label', 50)->nullable();
            $table->enum('status', ['pending_extraction', 'needs_preset', 'pending', 'scheduled', 'sent', 'failed', 'skipped'])
                ->default('pending_extraction');
            $table->string('provider_notification_id', 100)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('delivery_stats')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['campaign_id', 'scheduled_at']);
            $table->index('client_id');
            $table->index('wp_post_id');

            $table->foreign('campaign_id')->references('id')->on('push_campaigns')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_campaign_items');
    }
};
