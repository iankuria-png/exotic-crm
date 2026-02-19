<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_id');
            $table->integer('wp_post_id');
            $table->integer('wp_user_id')->nullable();
            $table->enum('client_type', ['escort', 'agency'])->default('escort');
            $table->string('name', 255)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->enum('profile_status', ['publish', 'private', 'draft', 'pending'])->nullable();
            $table->boolean('premium')->default(false);
            $table->bigInteger('premium_expire')->nullable();
            $table->boolean('featured')->default(false);
            $table->bigInteger('featured_expire')->nullable();
            $table->bigInteger('escort_expire')->nullable();
            $table->boolean('verified')->default(false);
            $table->string('main_image_url', 500)->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'wp_post_id']);
            $table->index('phone_normalized');
            $table->index('email');
            $table->index('profile_status');
            $table->index('assigned_to');

            $table->foreign('platform_id')->references('id')->on('platforms');
            $table->foreign('assigned_to')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
